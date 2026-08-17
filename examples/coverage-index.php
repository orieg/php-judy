<?php
/**
 * Line-coverage index: "which tests executed this file:line?"
 *
 * Code-coverage tooling keeps, in memory, a structure shaped roughly like
 *
 *     file -> line -> [test identifier, test identifier, ...]
 *
 * with the test identifiers as strings. Over a large suite that is tens of
 * millions of live zvals, which is why coverage runs are the thing that
 * exhausts memory_limit. (The shape above is a *representative* model used
 * for this demo, not a claim about any specific tool's current internals.)
 *
 * Four Judy properties line up on that workload at once:
 *
 *   1. line numbers are sparse integer keys;
 *   2. intern the test names once, and "which tests cover this line" becomes
 *      a set of small integers instead of a PHP array of strings;
 *   3. merging per-worker indexes (ParaTest, --process-isolation) is one
 *      set-union call running in C rather than a recursive array merge in
 *      PHP — though see the notes at the bottom: an in-place nested-array
 *      merge moves whole test lists by refcount, so the merge column is the
 *      one to check rather than assume;
 *   4. Judy allocates outside PHP's memory manager, so the index does not
 *      count against memory_limit.
 *
 * The representation here is a single BITSET whose key packs the whole
 * triple:
 *
 *     [ file id | line | test id ]   62 bits, always positive
 *
 * Because the key is ordered file-major, every test id for one file:line is
 * a contiguous block, so a query is a range walk and a per-file report is a
 * block-to-block walk. No nested containers, one Judy object per index.
 *
 * The user-visible payoff of that index is test-impact selection: given the
 * file:line pairs a diff touched, run the 40 tests that reach them instead of
 * the whole 12,000-test suite. Section 2 does exactly that, and section 3
 * times it against the nested array, because for this query it is *selection
 * wall time* that matters rather than the memory story.
 *
 * Selection is only sound while the index is current, and the failure mode is
 * silent: a changed line with no recorded coverage must mean "cannot prove
 * safe, run more", never "no tests affected". Getting that backwards is how a
 * test-selection tool quietly stops catching regressions, so the selector
 * below escalates instead of returning an empty set.
 *
 * That selection is written twice — a per-id first()/searchNext() walk and a
 * bulk keys($lo, $hi) range read — and both are timed, because the gap between
 * them is the whole reason keys($lo, $hi) was added (issue #96). Writing this
 * file against the older API is what exposed it: the only bounded bulk read
 * available was slice($lo, $hi)->keys(), which measured slower than the walk
 * it was meant to beat. See the notes at the bottom.
 *
 * Judy memory is invisible to memory_get_usage(), so the comparison at the
 * bottom re-executes this script once per variant and reads peak RSS from
 * getrusage() in each child.
 *
 * Run: php examples/coverage-index.php [files] [linesPerFile] [tests]
 * Set JUDY_SO=/path/to/judy.so if the extension is not enabled in php.ini.
 *
 * Numbers are only meaningful on an idle machine: check the load average
 * before believing any row of the table.
 */

if (!extension_loaded('judy')) {
    fwrite(STDERR, "The judy extension is not loaded.\n");
    exit(1);
}

/* ── Key packing ─────────────────────────────────────────────────────── */

const TEST_BITS = 22;                        // up to 4,194,304 distinct tests
const LINE_BITS = 20;                        // up to 1,048,576 lines per file
const FILE_BITS = 20;                        // up to 1,048,576 files
const TEST_MASK = (1 << TEST_BITS) - 1;
const LINE_MASK = (1 << LINE_BITS) - 1;

function covKey(int $fileId, int $line, int $testId): int
{
    return ($fileId << (LINE_BITS + TEST_BITS)) | ($line << TEST_BITS) | $testId;
}

/** Interns strings to dense integer ids, and back. */
final class Interner
{
    private Judy $ids;                       // name -> id
    private Judy $names;                     // id   -> name
    private int $next = 0;

    public function __construct()
    {
        $this->ids   = new Judy(Judy::STRING_TO_INT_HASH); // point lookups only
        $this->names = new Judy(Judy::INT_TO_MIXED);
    }

    public function intern(string $name): int
    {
        if (isset($this->ids[$name])) {
            return $this->ids[$name];
        }
        $id = $this->next++;
        $this->ids[$name]  = $id;
        $this->names[$id]  = $name;
        return $id;
    }

    /** Lookup without interning: a query must not invent ids for unknown names. */
    public function id(string $name): ?int
    {
        return isset($this->ids[$name]) ? $this->ids[$name] : null;
    }

    public function name(int $id): ?string
    {
        return isset($this->names[$id]) ? $this->names[$id] : null;
    }

    public function count(): int
    {
        return $this->next;
    }
}

/* ── Accumulate, merge, query ────────────────────────────────────────── */

/** @param iterable<array{0:string,1:string,2:int[]}> $stream */
function accumulateJudy(iterable $stream, Interner $files, Interner $tests): Judy
{
    $cov = new Judy(Judy::BITSET);
    foreach ($stream as [$test, $path, $lines]) {
        $fileId = $files->intern($path);
        $testId = $tests->intern($test);
        if ($fileId >> FILE_BITS !== 0 || $testId >> TEST_BITS !== 0) {
            throw new RangeException('id space exhausted: widen the key layout');
        }
        // file id and test id are constant for the whole run of lines
        $base = covKey($fileId, 0, $testId);
        foreach ($lines as $line) {
            $cov[$base | ($line << TEST_BITS)] = true;
        }
    }
    return $cov;
}

/** The equivalent nested-array structure: file -> line -> list of test names. */
function accumulateArray(iterable $stream): array
{
    $cov = [];
    foreach ($stream as [$test, $path, $lines]) {
        foreach ($lines as $line) {
            $cov[$path][$line][] = $test;    // $test is one shared zend_string
        }
    }
    return $cov;
}

/** Merge a worker's array index into $into, in place (the array's best case). */
function mergeArray(array &$into, array $from): void
{
    foreach ($from as $path => $lines) {
        foreach ($lines as $line => $tests) {
            if (isset($into[$path][$line])) {
                foreach ($tests as $t) {
                    $into[$path][$line][] = $t;
                }
            } else {
                $into[$path][$line] = $tests;
            }
        }
    }
}

/**
 * Test ids covering one file:line — the whole id block in one bounded read.
 *
 * Contrast with coveredLines() below. Both are "range" queries, but they want
 * different primitives: this one reads every key in one contiguous span, which
 * is exactly what keys($lo, $hi) does in a single traversal. coveredLines()
 * deliberately does NOT want every key — it wants to skip a whole block at a
 * time — so it stays on first(), which can seek past the keys it does not
 * care about. Reading a span, jumping between spans: different tools.
 */
function testsCovering(Judy $cov, int $fileId, int $line): array
{
    $out = [];
    foreach ($cov->keys(covKey($fileId, $line, 0), covKey($fileId, $line, TEST_MASK)) as $k) {
        $out[] = $k & TEST_MASK;
    }
    return $out;
}

/** Executed lines of one file, jumping block to block instead of scanning. */
function coveredLines(Judy $cov, int $fileId): array
{
    $end   = covKey($fileId, LINE_MASK, TEST_MASK);
    $lines = [];
    $k     = $cov->first(covKey($fileId, 0, 0));
    while ($k !== null && $k <= $end) {
        $line    = ($k >> TEST_BITS) & LINE_MASK;
        $lines[] = $line;
        if ($line >= LINE_MASK) {
            break;
        }
        $k = $cov->first(covKey($fileId, $line + 1, 0)); // skip this line's test ids
    }
    return $lines;
}

/* ── Test-impact selection ───────────────────────────────────────────── */

/*
 * Both selectors answer the same question — "which tests must run for this
 * set of changed file:line pairs?" — and must answer it identically, which
 * the digest check at the bottom enforces.
 *
 * Three outcomes per changed line, and only the first is the happy path:
 *
 *   covered      the line has recorded coverage: select exactly its tests.
 *   line-unknown the file is tracked but nothing ever executed this line --
 *                new code, or code the last coverage run never reached. The
 *                index cannot bound the blast radius, so widen to every test
 *                that touches the file. Returning an empty set here is the
 *                bug that makes a selection tool stop catching regressions.
 *   file-unknown the file is absent from the index entirely. Nothing about
 *                the suite can be proven, so the selection is *unbounded*:
 *                the honest answer is "run everything".
 *
 * The tiering is a policy choice, not a Judy detail; the array selector below
 * implements the identical policy so the comparison is like for like.
 */

/** @param list<array{0:string,1:int}> $changed */
function selectJudy(Judy $cov, Interner $fileIds, Interner $testIds, array $changed): array
{
    $hits      = new Judy(Judy::BITSET);   // union of selected test ids
    $covered   = 0;
    $widened   = 0;
    $unbounded = 0;

    foreach ($changed as [$path, $line]) {
        $fileId = $fileIds->id($path);
        if ($fileId === null) {
            $unbounded++;                  // no coverage recorded for this file
            continue;
        }

        $lo = covKey($fileId, $line, 0);
        $hi = covKey($fileId, $line, TEST_MASK);
        $k  = $cov->first($lo);
        if ($k === null || $k > $hi) {
            // Nothing in this line's block. Widen to the whole file's block:
            // still one contiguous range, just a much longer walk.
            $widened++;
            $hi = covKey($fileId, LINE_MASK, TEST_MASK);
            $k  = $cov->first(covKey($fileId, 0, 0));
        } else {
            $covered++;
        }

        for (; $k !== null && $k <= $hi; $k = $cov->searchNext($k)) {
            $hits[$k & TEST_MASK] = true;
        }
    }

    $names = [];
    for ($id = $hits->first(); $id !== null; $id = $hits->searchNext($id)) {
        $names[] = $testIds->name($id);
    }
    sort($names);                          // ids are dense, names are not

    return [
        'tests'     => $names,
        'covered'   => $covered,
        'widened'   => $widened,
        'unbounded' => $unbounded,
    ];
}

/**
 * The same policy, same answer, one bulk range read instead of a per-id walk.
 *
 * selectJudy() above crosses from PHP into C once per test id in the block:
 * one first(), then one searchNext() each. That is the per-element-dispatch
 * shape BENCHMARK.md's bulk-operation tables warn about. Here each block is
 * lifted whole by a single call:
 *
 *   keys($lo, $hi)   the block as a PHP array, one bounded C traversal.
 *
 * The mask then runs at VM speed over a plain array — the same kind of work
 * the array baseline does when it iterates a line's test list.
 *
 * This example is the reason keys($lo, $hi) exists. Writing it first is what
 * exposed the gap: getAll() and toArray() are fast because they make ONE
 * traversal writing straight into the destination PHP array, and until #96
 * there was no range-limited form of that, so a bounded read had to go
 * through slice($lo, $hi)->keys(). slice() is a copy constructor rather than
 * a projection — it runs the same J1F/J1N traversal the walk does, but
 * J1S-inserts every key into a freshly allocated Judy, which keys() then
 * traverses a second time. Two traversals, an insert per key and an
 * allocation per changed line, to save one method dispatch per key. Measured,
 * that shape came out SLOWER than the walk it was supposed to beat, which is
 * how a missing primitive shows up as an API-guidance problem: the repo's own
 * "prefer bulk operations" rule pointed at the wrong tool because the right
 * one did not exist.
 *
 * With the primitive in place the rule holds again, and the win is not just
 * swapping slice() for keys(). It is dropping to ONE crossing per changed
 * line. The earlier shape also called populationCount($lo, $hi) first to
 * price the block without materialising it — sound reasoning when the read
 * that follows is expensive, but a bounded keys() already answers "is this
 * block empty?" for free by returning an empty array. Paying an extra
 * crossing to avoid a cheap call is a net loss at these block sizes, and
 * removing it is worth more than the slice() swap. See the notes at the
 * bottom for both effects measured separately.
 *
 * @param list<array{0:string,1:int}> $changed
 */
function selectJudyBulk(Judy $cov, Interner $fileIds, Interner $testIds, array $changed): array
{
    $hits      = [];                       // test id => true, deduped by the VM
    $covered   = 0;
    $widened   = 0;
    $unbounded = 0;

    foreach ($changed as [$path, $line]) {
        $fileId = $fileIds->id($path);
        if ($fileId === null) {
            $unbounded++;
            continue;
        }

        // One crossing. An empty array IS the "nothing covers this line"
        // answer, so no separate emptiness probe is needed.
        $block = $cov->keys(covKey($fileId, $line, 0), covKey($fileId, $line, TEST_MASK));
        if ($block === []) {
            // Widen to the whole file's block: still one contiguous range,
            // just a much longer one.
            $widened++;
            $block = $cov->keys(covKey($fileId, 0, 0), covKey($fileId, LINE_MASK, TEST_MASK));
        } else {
            $covered++;
        }

        foreach ($block as $k) {
            $hits[$k & TEST_MASK] = true;
        }
    }

    $names = [];
    foreach (array_keys($hits) as $id) {
        $names[] = $testIds->name($id);
    }
    sort($names);

    return [
        'tests'     => $names,
        'covered'   => $covered,
        'widened'   => $widened,
        'unbounded' => $unbounded,
    ];
}

/** The same policy over the nested array. @param list<array{0:string,1:int}> $changed */
function selectArray(array $cov, array $changed): array
{
    $hits      = [];
    $covered   = 0;
    $widened   = 0;
    $unbounded = 0;

    foreach ($changed as [$path, $line]) {
        if (!isset($cov[$path])) {
            $unbounded++;
            continue;
        }
        if (isset($cov[$path][$line])) {
            $covered++;
            foreach ($cov[$path][$line] as $t) {
                $hits[$t] = true;
            }
        } else {
            $widened++;
            foreach ($cov[$path] as $list) {
                foreach ($list as $t) {
                    $hits[$t] = true;
                }
            }
        }
    }

    $names = array_keys($hits);
    sort($names);

    return [
        'tests'     => $names,
        'covered'   => $covered,
        'widened'   => $widened,
        'unbounded' => $unbounded,
    ];
}

/** How a runner should read a selection result. */
function selectionVerdict(array $sel, int $suiteSize): string
{
    if ($sel['unbounded'] > 0) {
        return sprintf(
            'UNBOUNDED - %d changed file(s) absent from the index; run all %s tests',
            $sel['unbounded'],
            number_format($suiteSize),
        );
    }
    return sprintf('%s of %s tests', number_format(count($sel['tests'])), number_format($suiteSize));
}

/* ── Synthetic workload ──────────────────────────────────────────────── */

const HOT_FILES    = 8;     // bootstrap/framework files nearly every test loads
const HOT_PER_TEST = 3;
const OWN_PER_TEST = 2;     // files a test exercises on its own
const HIT_RATIO    = 0.10;  // fraction of a file's lines one test executes

function filePath(int $f): string
{
    return sprintf('/app/src/Module%02d/Class%05d.php', intdiv($f, 100), $f);
}

/**
 * Yields [testName, filePath, lines[]] — one item per (test, file) pair, so
 * each name is built once and both variants see the same shared strings.
 *
 * @return Generator<array{0:string,1:string,2:int[]}>
 */
function coverageStream(int $files, int $linesPerFile, int $tests, int $worker, int $workers): Generator
{
    $hot = min(HOT_FILES, $files);
    $run = max(1, (int) round($linesPerFile * HIT_RATIO));
    mt_srand(20260817 + $worker);

    for ($t = $worker; $t < $tests; $t += $workers) {
        $test   = sprintf('App\\Tests\\Feature%dTest::testCase%d', intdiv($t, 20), $t % 20);
        $picked = [];
        for ($i = 0; $i < HOT_PER_TEST; $i++) {
            $picked[mt_rand(0, $hot - 1)] = true;
        }
        for ($i = 0; $i < OWN_PER_TEST; $i++) {
            $picked[mt_rand(0, $files - 1)] = true;
        }
        foreach (array_keys($picked) as $f) {
            $path  = filePath($f);
            $start = mt_rand(1, max(1, $linesPerFile - $run));
            $lines = [];
            for ($i = 0; $i < $run; $i++) {
                if ($i % 3 !== 2) {          // roughly one line in three is a branch not taken
                    $lines[] = $start + $i;
                }
            }
            yield [$test, $path, $lines];
        }
    }
}

/** Fixed probe set both variants answer, so their answers can be compared. */
function probeSet(int $files, int $linesPerFile): array
{
    $step   = max(1, intdiv($linesPerFile, 12));
    $probes = [];
    foreach ([0, 1, 2, 3, intdiv($files, 2), $files - 1] as $f) {
        for ($l = 1; $l <= $linesPerFile; $l += $step) {
            $probes[] = [$f, $l];
        }
    }
    return $probes;
}

const CHANGED_FILES = 24;   // file:line pairs in the synthetic diff

/**
 * A synthetic diff: the file:line pairs a change touched.
 *
 * Drawn from the same deterministic stream both variants index, so these are
 * genuinely executed lines — a real diff mostly touches code that runs. Hot
 * files are skipped on purpose: a diff in framework bootstrap selects the
 * whole suite and there is nothing to demonstrate. Two pairs are appended
 * that the index deliberately cannot answer, to exercise both escalations.
 *
 * @return list<array{0:string,1:int}>
 */
function changedLines(int $files, int $linesPerFile, int $tests): array
{
    $hot = [];
    for ($f = 0; $f < min(HOT_FILES, $files); $f++) {
        $hot[filePath($f)] = true;
    }

    $changed = [];
    $seen    = 0;
    foreach (coverageStream($files, $linesPerFile, $tests, 0, 2) as [, $path, $lines]) {
        if ($lines === [] || isset($hot[$path])) {
            continue;
        }
        if ($seen++ % 37 !== 0) {          // spread the diff across the suite
            continue;
        }
        $changed[] = [$path, $lines[intdiv(count($lines), 2)]];
        if (count($changed) >= CHANGED_FILES) {
            break;
        }
    }

    // A line inside a tracked file that no test has ever executed. Deliberately
    // not a hot file: widening one of those selects the suite and hides the
    // difference between "widened" and "unbounded".
    $changed[] = [$changed[0][0] ?? filePath($files - 1), $linesPerFile + 7];
    // A file the index has never seen: added by this very diff.
    $changed[] = ['/app/src/BrandNewClass.php', 12];

    return $changed;
}

/* ── Child: run one variant, report peak RSS ─────────────────────────── */

$files        = (int) ($argv[1] ?? 800);
$linesPerFile = (int) ($argv[2] ?? 300);
$tests        = (int) ($argv[3] ?? 2000);
$mode         = $argv[4] ?? null;

const SELECT_ROUNDS = 25;   // selection is fast; average over a few rounds

if ($mode !== null) {
    $probes  = probeSet($files, $linesPerFile);
    $changed = changedLines($files, $linesPerFile, $tests);
    $answers = [];
    $t0      = $t1 = $t2 = $t3 = $t4 = hrtime(true);
    $triples = 0;
    $indexBytes = null;
    $sel        = ['tests' => [], 'covered' => 0, 'widened' => 0, 'unbounded' => 0];
    $bulkTime   = null;                    // judy variants only: the slice() shape
    $bulkDigest = null;

    if ($mode === 'floor') {
        // An otherwise identical process that builds no index: the PHP runtime's
        // own footprint, which both variants below pay before storing anything.
    } elseif ($mode === 'union' || $mode === 'mergeWith') {
        $fileIds = new Interner();
        $testIds = new Interner();

        $t0 = hrtime(true);
        $a  = accumulateJudy(coverageStream($files, $linesPerFile, $tests, 0, 2), $fileIds, $testIds);
        $b  = accumulateJudy(coverageStream($files, $linesPerFile, $tests, 1, 2), $fileIds, $testIds);
        $t1 = hrtime(true);

        // One C call for a whole worker index. Both indexes must share the same
        // id space for this to be free — here the interners are shared; across
        // real worker processes the coordinator must hand out the ids (or the
        // ids must be remapped before the merge).
        if ($mode === 'union') {
            $cov = $a->union($b);     // new index; both inputs stay alive
        } else {
            $a->mergeWith($b);        // in place, like the array merge below
            $cov = $a;
        }
        $t2 = hrtime(true);

        foreach ($probes as [$f, $l]) {
            // probes address files by path; the index addresses them by id
            $names = array_map($testIds->name(...), testsCovering($cov, $fileIds->intern(filePath($f)), $l));
            sort($names);
            $answers[] = implode(',', $names);
        }
        $t3 = hrtime(true);

        // Shape 1: the per-id first()/searchNext() walk.
        for ($r = 0; $r < SELECT_ROUNDS; $r++) {
            $sel = selectJudy($cov, $fileIds, $testIds, $changed);
        }
        $t4 = hrtime(true);

        // Shape 2: bulk slice() + keys(). Same answer, different cost profile.
        for ($r = 0; $r < SELECT_ROUNDS; $r++) {
            $bulkSel = selectJudyBulk($cov, $fileIds, $testIds, $changed);
        }
        $bulkTime   = (hrtime(true) - $t4) / 1e9 / SELECT_ROUNDS;
        $bulkDigest = md5(implode("\n", $bulkSel['tests']));

        $triples   = count($cov);
        $indexBytes = $cov->memoryUsage();
    } else {
        $t0 = hrtime(true);
        $a  = accumulateArray(coverageStream($files, $linesPerFile, $tests, 0, 2));
        $b  = accumulateArray(coverageStream($files, $linesPerFile, $tests, 1, 2));
        $t1 = hrtime(true);

        mergeArray($a, $b);
        $cov = $a;
        $t2  = hrtime(true);

        foreach ($probes as [$f, $l]) {
            $names = $cov[filePath($f)][$l] ?? [];
            sort($names);
            $answers[] = implode(',', $names);
        }
        $t3 = hrtime(true);

        for ($r = 0; $r < SELECT_ROUNDS; $r++) {
            $sel = selectArray($cov, $changed);
        }
        $t4 = hrtime(true);

        $triples = 0;
        foreach ($cov as $lines) {
            foreach ($lines as $list) {
                $triples += count($list);
            }
        }
        $indexBytes = null;
    }

    // ru_maxrss is bytes on macOS, kilobytes on Linux.
    echo json_encode([
        'variant'    => $mode,
        'triples'    => $triples,
        'accumulate' => ($t1 - $t0) / 1e9,
        'merge'      => ($t2 - $t1) / 1e9,
        'query'      => ($t3 - $t2) / 1e9,
        'select'     => ($t4 - $t3) / 1e9 / SELECT_ROUNDS,
        'selectBulk' => $bulkTime,
        'bulkDigest' => $bulkDigest,
        'peak'       => getrusage()['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024),
        'indexBytes' => $indexBytes,
        'digest'     => md5(implode("\n", $answers)),
        'selected'   => count($sel['tests']),
        'covered'    => $sel['covered'],
        'widened'    => $sel['widened'],
        'unbounded'  => $sel['unbounded'],
        'selDigest'  => md5(implode("\n", $sel['tests'])),
    ]), "\n";
    exit(0);
}

/* ── Parent: narrated demo, then the side-by-side ────────────────────── */

echo "1. Accumulate, merge, query\n\n";

$fileIds = new Interner();
$testIds = new Interner();

// Two "workers", as ParaTest or --process-isolation would produce.
$worker1 = accumulateJudy([
    ['SuiteA::testLogin',  '/app/src/Auth.php', [10, 11, 12, 40]],
    ['SuiteA::testLogout', '/app/src/Auth.php', [10, 11, 55]],
], $fileIds, $testIds);
$worker2 = accumulateJudy([
    ['SuiteB::testExpiry', '/app/src/Auth.php', [10, 60, 61]],
    ['SuiteB::testExpiry', '/app/src/Clock.php', [7]],
], $fileIds, $testIds);

$merged = $worker1->union($worker2);          // whole-index merge, in C
printf(
    "   worker 1: %d line/test pairs, worker 2: %d, merged: %d\n",
    count($worker1),
    count($worker2),
    count($merged),
);

$auth = $fileIds->intern('/app/src/Auth.php');
printf("   lines covered in Auth.php: %s\n", implode(', ', coveredLines($merged, $auth)));

foreach ([10, 55] as $line) {
    $names = array_map($testIds->name(...), testsCovering($merged, $auth, $line));
    printf("   Auth.php:%-3d covered by %d test(s): %s\n", $line, count($names), implode(', ', $names));
}
// Counting needs no materialisation at all.
printf(
    "   populationCount over the Auth.php:10 block: %d\n",
    $merged->populationCount(covKey($auth, 10, 0), covKey($auth, 10, TEST_MASK)),
);
printf(
    "   %d test names and %d paths are stored once, as ids everywhere else\n\n",
    $testIds->count(),
    $fileIds->count(),
);

echo "2. Test-impact selection: which tests must this diff run?\n\n";

// A diff against the tiny index above: one covered line, one line in a tracked
// file that no test reaches, one file the index has never seen.
$diff = [
    ['/app/src/Auth.php', 10],
    ['/app/src/Auth.php', 99],
    ['/app/src/Clock.php', 7],
];
$suiteSize = $testIds->count();

$sel = selectJudy($merged, $fileIds, $testIds, $diff);
foreach ($diff as [$path, $line]) {
    $id  = $fileIds->id($path);
    $lo  = $id === null ? 0 : covKey($id, $line, 0);
    $hi  = $id === null ? 0 : covKey($id, $line, TEST_MASK);
    // populationCount answers "is this line covered, and by how many tests?"
    // over the block without materialising a single test id.
    $n   = $id === null ? 0 : $merged->populationCount($lo, $hi);
    printf(
        "   %-22s %-24s -> %s\n",
        $path . ':' . $line,
        $id === null ? 'file not in index' : $n . ' test(s) recorded',
        $id === null
            ? 'UNBOUNDED: run the whole suite'
            : ($n === 0 ? 'no coverage: widen to every test touching the file' : 'select those tests'),
    );
}
printf("   selection: %s\n", selectionVerdict($sel, $suiteSize));
printf("   tests: %s\n", implode(', ', $sel['tests']));
printf(
    "   %d line(s) covered, %d widened to file scope, %d file(s) not indexed\n\n",
    $sel['covered'],
    $sel['widened'],
    $sel['unbounded'],
);

// Same policy, same answer, bulk ops: one populationCount() + slice() + keys()
// per changed line instead of a first()/searchNext() dispatch per test id.
$bulk = selectJudyBulk($merged, $fileIds, $testIds, $diff);
printf(
    "   the bulk keys(\$lo, \$hi) shape selects the same set: %s\n\n",
    $bulk['tests'] === $sel['tests'] ? 'yes' : 'NO — the two shapes disagree',
);

// The same diff without the unindexed file: now the selection is bounded.
$bounded = selectJudy($merged, $fileIds, $testIds, [['/app/src/Auth.php', 55]]);
printf("   Auth.php:55 alone selects %s\n\n", selectionVerdict($bounded, $suiteSize));

echo "   A changed line with no recorded coverage is NOT 'no tests affected'.\n";
echo "   It is 'this index cannot prove which tests reach it', and the only\n";
echo "   safe response is to widen — to the file, or to the whole suite when\n";
echo "   the file is unknown. A selector that returns the empty set there\n";
echo "   stops catching regressions without ever reporting a failure.\n\n";

echo "3. Peak RSS and wall time, one process per variant\n\n";
printf(
    "   workload: %s files x %s lines, %s tests (%d hot files every test loads)\n\n",
    number_format($files),
    number_format($linesPerFile),
    number_format($tests),
    min(HOT_FILES, $files),
);

// A child gets an explicit -n so an already-enabled judy.so in conf.d cannot
// silently shadow the build under test, and memory_limit=-1 so the array
// variant is not the one that dies (that it needs this and the Judy variant
// does not is itself part of the story).
$so = getenv('JUDY_SO') ?: (is_file(__DIR__ . '/../modules/judy.so') ? __DIR__ . '/../modules/judy.so' : null);
$flags = $so !== null
    ? '-n -d memory_limit=-1 -d extension=' . escapeshellarg($so)
    : '-d memory_limit=-1';
$self = escapeshellarg(__FILE__);

$rows = [];
foreach (['floor', 'array', 'union', 'mergeWith'] as $variant) {
    $out  = shell_exec(sprintf('%s %s %s %d %d %d %s', PHP_BINARY, $flags, $self, $files, $linesPerFile, $tests, $variant));
    $last = trim(strrchr("\n" . trim((string) $out), "\n") ?: '');
    $row  = json_decode($last, true);
    if (!is_array($row)) {
        fwrite(STDERR, "child run failed for '$variant':\n$out\n");
        exit(1);
    }
    $rows[$variant] = $row;

    if ($variant === 'floor') {
        printf("   %-20s %-33s peak RSS: %8.1f MB\n", 'floor', '(empty process, no index)', $row['peak'] / 1048576);
        continue;
    }
    printf(
        "   %-20s pairs: %-12s index: %8.1f MB   peak RSS: %8.1f MB   accumulate: %6.2fs   merge: %6.3fs   query: %6.3fs   select: %8.3f ms%s\n",
        $variant === 'array' ? 'array (nested)' : "judy ($variant)",
        number_format($row['triples']),
        ($row['peak'] - $rows['floor']['peak']) / 1048576,
        $row['peak'] / 1048576,
        $row['accumulate'],
        $row['merge'],
        $row['query'],
        $row['select'] * 1000,
        $row['selectBulk'] === null ? '' : sprintf('  (bulk keys($lo,$hi): %8.3f ms)', $row['selectBulk'] * 1000),
    );
}

echo "\n";
$digests = array_unique([$rows['array']['digest'], $rows['union']['digest'], $rows['mergeWith']['digest']]);
if (count($digests) !== 1) {
    echo "   WARNING: the variants disagree on the probe queries.\n";
} else {
    printf("   every variant answers every probe query identically (%s)\n", $rows['array']['digest']);
}
// Every shape — nested array, per-id walk, bulk keys($lo, $hi) — must select
// byte-identically. This assertion is the one thing here machine load cannot
// invalidate, so it is the one thing worth trusting on a busy box.
$selDigests = array_unique([
    $rows['array']['selDigest'],
    $rows['union']['selDigest'],
    $rows['union']['bulkDigest'],
    $rows['mergeWith']['selDigest'],
    $rows['mergeWith']['bulkDigest'],
]);
if (count($selDigests) !== 1) {
    echo "   WARNING: the variants select different test sets for the same diff.\n";
} else {
    printf(
        "   array, per-id walk and bulk keys(\$lo, \$hi) select the identical test\n"
        . "   set for the diff (%s)\n",
        $rows['array']['selDigest'],
    );
}
printf("   Judy::memoryUsage() for the merged index: %.1f MB\n", $rows['union']['indexBytes'] / 1048576);

$sel = $rows['union'];
printf(
    "\n   diff of %d file:line pairs -> %s selected of %s tests in the suite\n",
    $sel['covered'] + $sel['widened'] + $sel['unbounded'],
    number_format($sel['selected']),
    number_format($tests),
);
printf(
    "   %d covered exactly, %d widened to file scope (line not in the index),\n   %d file(s) absent from the index\n",
    $sel['covered'],
    $sel['widened'],
    $sel['unbounded'],
);
if ($sel['unbounded'] > 0) {
    printf(
        "   -> the selection is UNBOUNDED: a correct runner ignores the %s above\n"
        . "      and runs all %s tests until the index covers those files.\n",
        number_format($sel['selected']),
        number_format($tests),
    );
}

echo "\n   array / judy  (>1 favours Judy)\n";
foreach (['union', 'mergeWith'] as $variant) {
    printf(
        "     %-10s %5.2fx peak RSS   %5.2fx index   %5.2fx merge time   %5.2fx selection (walk)   %5.2fx selection (bulk)\n",
        $variant,
        $rows['array']['peak'] / max(1, $rows[$variant]['peak']),
        ($rows['array']['peak'] - $rows['floor']['peak']) / max(1, $rows[$variant]['peak'] - $rows['floor']['peak']),
        $rows['array']['merge'] / max(1e-9, $rows[$variant]['merge']),
        $rows['array']['select'] / max(1e-9, $rows[$variant]['select']),
        $rows['array']['select'] / max(1e-9, $rows[$variant]['selectBulk']),
    );
}

echo "\n";
echo "Notes:\n";
echo "  - peak RSS includes the PHP runtime, so the ratio understates the index\n";
echo "    difference; the 'index' column is peak RSS minus the floor row.\n";
echo "  - the array merge is measured in place, its best case: where a line is new\n";
echo "    to the target it moves the whole test list by refcount, so its work is\n";
echo "    O(distinct lines) plus the overlap. union() and mergeWith() are O(keys)\n";
echo "    in C. Which side wins the merge column therefore depends on how much\n";
echo "    the two workers overlap, not on Judy alone. On an idle 24-core host\n";
echo "    at this workload's overlap ratio the ARRAY won it, by ~10% at the\n";
echo "    large scale — see 'Measured: the coverage-index workload' in\n";
echo "    BENCHMARK.md. Memory is where this index pays off, not merge time.\n";
echo "  - union() builds a third index, so that row pays a transient copy of the\n";
echo "    result; mergeWith() is the like-for-like in-place comparison.\n";
echo "  - either merge is only free when both indexes share one test-id space.\n";
echo "    Workers that intern independently must be remapped first.\n";
echo "  - both merges here happen in one process. Real per-process workers must\n";
echo "    also ship their index to the coordinator: the nested array serialises to\n";
echo "    a structure as large as itself, while a BITSET's keys are a flat run of\n";
echo "    integers (pack('J*', ...) over keys(), or one packed key per line).\n";
echo "  - the Judy index does not count against memory_limit at all; the array\n";
echo "    one does, which is what turns a large coverage run into a fatal error.\n";
echo "  - the two selection columns are the same query written two ways: a per-id\n";
echo "    first()/searchNext() walk, and one bulk keys(\$lo, \$hi) range read.\n";
echo "    The walk crosses PHP into C once per test id; the range read crosses\n";
echo "    once per changed line and masks at VM speed.\n";
echo "  - this example is why keys(\$lo, \$hi) exists (issue #96). Written against\n";
echo "    the older API the only bounded bulk read was slice(\$lo,\$hi)->keys(),\n";
echo "    and slice() copies the range into a newly allocated Judy (a J1S insert\n";
echo "    per key) before keys() traverses it a second time. Two traversals plus\n";
echo "    an allocation to save one dispatch per key — which measured SLOWER\n";
echo "    than the walk. A missing primitive had turned into bad guidance: the\n";
echo "    'prefer bulk operations' rule pointed at the wrong tool because the\n";
echo "    right one did not exist. With keys(\$lo, \$hi) the rule holds again.\n";
echo "  - the larger half of that win is crossing count, not the slice() swap.\n";
echo "    An earlier shape probed populationCount(\$lo,\$hi) first to price the\n";
echo "    block without materialising it; a bounded keys() already reports an\n";
echo "    empty block for free, so that probe is a third crossing bought for\n";
echo "    nothing. Dropping it moved this selection more than the swap did.\n";
echo "  - still measure it for your own diff shape rather than reasoning about\n";
echo "    it: the crossover depends on how many tests cover a changed line, and\n";
echo "    a diff mixes tiny exactly-covered blocks with whole-file widenings.\n";
echo "  - either way, do not conclude anything about the representation from the\n";
echo "    selection columns alone. What Judy buys here is that the index still\n";
echo "    exists at suite scale (the memory columns above), and that a bounded\n";
echo "    range is addressable at all: keys(\$lo, \$hi) reads one file:line block\n";
echo "    out of millions of triples for the cost of that block, not the index.\n";
echo "  - populationCount() is still the right call when you want a block's SIZE\n";
echo "    without its contents, or when the read that would follow is expensive\n";
echo "    — 'how many tests cover this hot line?' costs one call and allocates\n";
echo "    nothing. It is only redundant HERE, where the very next thing we do is\n";
echo "    read the block anyway and an empty array answers the same question.\n";
echo "  - a widened line walks the file's whole block, which for a hot file is\n";
echo "    most of the suite. Both shapes pay that; it is the price of being\n";
echo "    sound rather than the price of the representation.\n";
echo "  - selection is only as good as the index is fresh. Every line the last\n";
echo "    coverage run did not reach widens, so a stale index degrades toward\n";
echo "    'run everything' — which is safe. The dangerous variant is a selector\n";
echo "    that treats an unrecorded line as 'no tests affected': it degrades\n";
echo "    toward running nothing, silently, and no test failure ever reports it.\n";
echo "  - a loaded machine invalidates every number above. Re-run when idle.\n";
