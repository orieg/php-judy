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

/** Test ids covering one file:line — a range walk over the id block. */
function testsCovering(Judy $cov, int $fileId, int $line): array
{
    $hi  = covKey($fileId, $line, TEST_MASK);
    $out = [];
    for ($k = $cov->first(covKey($fileId, $line, 0)); $k !== null && $k <= $hi; $k = $cov->searchNext($k)) {
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

/* ── Child: run one variant, report peak RSS ─────────────────────────── */

$files        = (int) ($argv[1] ?? 800);
$linesPerFile = (int) ($argv[2] ?? 300);
$tests        = (int) ($argv[3] ?? 2000);
$mode         = $argv[4] ?? null;

if ($mode !== null) {
    $probes  = probeSet($files, $linesPerFile);
    $answers = [];
    $t0      = $t1 = $t2 = $t3 = hrtime(true);
    $triples = 0;
    $indexBytes = null;

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
        'peak'       => getrusage()['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024),
        'indexBytes' => $indexBytes,
        'digest'     => md5(implode("\n", $answers)),
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

echo "2. Peak RSS and wall time, one process per variant\n\n";
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
        "   %-20s pairs: %-12s index: %8.1f MB   peak RSS: %8.1f MB   accumulate: %6.2fs   merge: %6.3fs   query: %6.3fs\n",
        $variant === 'array' ? 'array (nested)' : "judy ($variant)",
        number_format($row['triples']),
        ($row['peak'] - $rows['floor']['peak']) / 1048576,
        $row['peak'] / 1048576,
        $row['accumulate'],
        $row['merge'],
        $row['query'],
    );
}

echo "\n";
$digests = array_unique([$rows['array']['digest'], $rows['union']['digest'], $rows['mergeWith']['digest']]);
if (count($digests) !== 1) {
    echo "   WARNING: the variants disagree on the probe queries.\n";
} else {
    printf("   every variant answers every probe query identically (%s)\n", $rows['array']['digest']);
}
printf("   Judy::memoryUsage() for the merged index: %.1f MB\n", $rows['union']['indexBytes'] / 1048576);

echo "\n   array / judy  (>1 favours Judy)\n";
foreach (['union', 'mergeWith'] as $variant) {
    printf(
        "     %-10s %5.2fx peak RSS   %5.2fx index   %5.2fx merge time\n",
        $variant,
        $rows['array']['peak'] / max(1, $rows[$variant]['peak']),
        ($rows['array']['peak'] - $rows['floor']['peak']) / max(1, $rows[$variant]['peak'] - $rows['floor']['peak']),
        $rows['array']['merge'] / max(1e-9, $rows[$variant]['merge']),
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
echo "    the two workers overlap, not on Judy alone.\n";
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
echo "  - a loaded machine invalidates every number above. Re-run when idle.\n";
