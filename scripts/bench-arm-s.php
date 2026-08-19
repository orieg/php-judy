<?php
/**
 * Materialize and verify **arm S** — the pristine-static comparison arm.
 *
 * Why arm S exists
 * ----------------
 * The three-arm study (BENCHMARK.md, PR #161) needs a reference arm that
 * answers "what did *our source patches* buy?" while holding everything else
 * constant. Arm **B** (a system libJudy) cannot do that job for two reasons:
 *
 *   1. It is not portable. Debian ships `libjudy-dev` *with* the Baskins
 *      `jp_1Index` patch, Homebrew ships pristine 1.0.5, Alpine ships pristine
 *      1.0.5 as a shared lib, and Windows has no package at all. "System
 *      libJudy" is therefore different code on every platform, and the
 *      SourceForge download that used to paper over this was deliberately
 *      deleted from CI (#146) and must not come back.
 *   2. It changes more than the source. Arm B is a *shared object* reached
 *      through the PLT and built with distro hardening flags. #161 measured
 *      that residual at ~1pp on integer paths but ~11pp on string paths — so a
 *      B-vs-C number attributed to "our patches" would be wrong by roughly a
 *      factor of two on strings.
 *
 * Arm S is the same vendored tree with the patches removed, compiled **static
 * into the extension with the identical pinned vendor CFLAGS**. Linkage model,
 * optimization flags, compiler, PHP and extension source are all held constant,
 * so S-vs-C varies *only* the libJudy source patches. It needs no package, no
 * network, and no platform-specific recipe, which is what makes the same
 * comparison runnable on glibc, musl, macOS and Windows alike.
 *
 * How the tree is reconstructed
 * -----------------------------
 * Not by downloading a tarball, and not by reverse-applying patches: by
 * checking out `libjudy/` at the last commit before the first patch landed.
 * That commit is `ARM_S_REF` below. Everything the patch series added since —
 * P1-P7, O1, O3, O4 — is by construction absent, and everything the build
 * integration needs — the per-variant wrapper shims, the pre-generated
 * `Judy1Tables.c`/`JudyLTables.c`, the include layout `config.m4` expects — is
 * by construction present.
 *
 * The reconstruction is then *verified*, not asserted, against the pristine
 * import commit `PRISTINE_REF` (`chore(vendor): import pristine Judy-1.0.5
 * subset under libjudy/`). Every file that upstream itself ships must be
 * byte-identical to its imported blob, with exactly one declared exception:
 *
 *   **P5** (LLP64/Windows-x64 constant widths) is present in arm S, in seven
 *   files. It is not optional: `Word_t` must be `unsigned __int64` under
 *   `_WIN64` or the tree does not compile on Windows at all, which would defeat
 *   the portability that is arm S's entire purpose. On LP64 targets (Linux,
 *   macOS) P5 is a textual change with no semantic effect — `~0UL` and
 *   `~(Word_t)0` are the same 64-bit value when `unsigned long` is 8 bytes — so
 *   it cannot contribute to an S-vs-C delta there. The seven files are pinned
 *   by name in `ARM_S_ALLOWED_DELTA` and the verifier fails if the set of
 *   modified upstream files is anything other than exactly that list.
 *
 * `JudyCommon/JudyNoInline.c` is a P7 *addition* and so does not exist at
 * `ARM_S_REF`, but `config.m4` lists it unconditionally. This script
 * synthesizes an empty translation unit for it. That is not a fudge: P7's real
 * file is wrapped in `#ifdef JU_NOINLINE`, which no benchmark build defines, so
 * the shipped file and the synthesized one compile to the same empty object.
 *
 * What "verified unpatched" means on each platform
 * ------------------------------------------------
 * Two independent checks, and the source-level one is the stronger:
 *
 *   - **Source identity (every platform, including Windows).** Each upstream
 *     file's sha256 in the materialized tree is compared against its blob at
 *     `PRISTINE_REF`. This is exact, needs no toolchain, and cannot be fooled
 *     by a compiler that happened to emit or elide an instruction.
 *   - **Instruction-level (where a disassembler exists).** `--verify-so`
 *     counts `popcnt` (O1's fingerprint) and `bswap` (O3's) in a built `.so`.
 *     Arm S must read popcnt=0; arm C on x86-64 reads dozens. This is the check
 *     #161 used and it is kept as a cross-check, but it is inherently
 *     architecture-specific — arm64 lowers O1 to `cnt` and O3 to `rev`, so the
 *     x86 mnemonics are not a portable test and the script reports what it
 *     found rather than hard-coding an expectation per architecture.
 *
 * Usage
 * -----
 *   # Materialize an arm-S source tree next to the repo and verify it:
 *   php scripts/bench-arm-s.php --dest /tmp/arm-s --manifest /tmp/arm-s.json
 *
 *   # Verify only, against an already-materialized tree:
 *   php scripts/bench-arm-s.php --dest /tmp/arm-s --verify-only
 *
 *   # Cross-check a built .so at the instruction level:
 *   php scripts/bench-arm-s.php --verify-so /tmp/arm-s/modules/judy.so
 *
 * `--dest` receives a COMPLETE copy of the extension source tree (php_judy.c,
 * config.m4, Judy.stub.php, ...) with `libjudy/` replaced by the arm-S tree, so
 * it can be built with the ordinary `phpize && ./configure && make`. Only
 * git-tracked files are copied, so a dirty build directory in the source repo
 * cannot leak in.
 *
 * Exit codes: 0 verified, 1 verification failed, 2 usage/environment error.
 */

// ── The two pinned commits ──────────────────────────────────────────────────
//
// These are deliberately hard-coded rather than derived. Deriving "the commit
// before the first patch" from history would silently change meaning the next
// time the vendored tree is touched, and a regression baseline whose reference
// arm moves underneath it measures nothing. Bumping either of these is a
// deliberate act that re-bases the baseline, and belongs in its own commit —
// the same rule `baselines/` already works to.

/** Last commit touching libjudy/ before P1. Pristine upstream + build scaffolding + P5. */
const ARM_S_REF = 'f366fdb42f17a65f031305b00d908f0c6e241b8a';

/** The pristine import: `chore(vendor): import pristine Judy-1.0.5 subset under libjudy/`. */
const PRISTINE_REF = '0f687cb19f326eed52a9d3db550261701226fa76';

/**
 * Upstream files arm S is ALLOWED to differ from the pristine import in, and
 * the only patch responsible. Any other difference is a reconstruction bug.
 */
const ARM_S_ALLOWED_DELTA = [
    'libjudy/src/Judy.h'                            => 'P5 (LLP64 Word_t + constant widths)',
    'libjudy/src/JudyCommon/JudyInsArray.c'         => 'P5 (LLP64 constant widths)',
    'libjudy/src/JudyCommon/JudyMallocIF.c'         => 'P5 (LLP64 constant widths)',
    'libjudy/src/JudyCommon/JudyPrevNextEmpty.c'    => 'P5 (LLP64 shift widths)',
    'libjudy/src/JudyCommon/JudyPrivate.h'          => 'P5 (LLP64 constant widths)',
    'libjudy/src/JudyCommon/JudyPrivateBranch.h'    => 'P5 (LLP64 constant widths)',
    'libjudy/src/JudySL/JudySL.c'                   => 'P5 (LLP64 constant widths)',
];

/** Synthesized stand-in for the P7 addition config.m4 lists unconditionally. */
const ARM_S_NOINLINE_STUB = <<<'C'
/*
 * php-judy ARM-S BUILD STUB — not part of upstream Judy-1.0.5 and not part of
 * the shipped php-judy tree.
 *
 * The bundled build lists JudyCommon/JudyNoInline.c unconditionally, but that
 * file is a php-judy addition (patch P7) and therefore does not exist in the
 * pre-patch tree arm S is reconstructed from. P7's real file is wrapped in
 * `#ifdef JU_NOINLINE`, which no benchmark or production build defines, so it
 * compiles to an empty object exactly as this file does. Substituting this stub
 * keeps the compiled-unit list identical between arms S and C without importing
 * any of P7's (or O1's, which also edits that file) code into arm S.
 *
 * ISO C forbids an empty translation unit; the typedef below satisfies it.
 */
typedef int judy_arm_s_noinline_stub_translation_unit_not_empty;

C;

// ── CLI ─────────────────────────────────────────────────────────────────────

$opts = getopt('', [
    'repo:', 'dest:', 'manifest:', 'ref:', 'pristine-ref:',
    'verify-only', 'verify-so:', 'quiet', 'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, "See the header comment of " . __FILE__ . "\n");
    exit(0);
}

$quiet = isset($opts['quiet']);
$repo  = realpath($opts['repo'] ?? dirname(__DIR__));
if ($repo === false || !is_dir("$repo/.git") && !is_file("$repo/.git")) {
    fwrite(STDERR, "not a git repository: " . ($opts['repo'] ?? dirname(__DIR__)) . "\n");
    exit(2);
}

$arm_s_ref    = $opts['ref']          ?? ARM_S_REF;
$pristine_ref = $opts['pristine-ref'] ?? PRISTINE_REF;

function say(string $s): void
{
    global $quiet;
    if (!$quiet) { fwrite(STDERR, $s); }
}

/** Run a git command in the repo, returning [stdout, exit status]. */
function git_raw(string $args): array
{
    global $repo;
    $err = tempnam(sys_get_temp_dir(), 'arm-s-git');
    $out = shell_exec('git -C ' . escapeshellarg($repo) . ' ' . $args . ' 2> ' . escapeshellarg($err));
    $status = 0;
    // shell_exec discards the status; re-run cheaply through exec for it only
    // when the output is empty, which is the only ambiguous case.
    if ($out === null || $out === '') {
        exec('git -C ' . escapeshellarg($repo) . ' ' . $args . ' > /dev/null 2>&1', $_, $status);
    }
    @unlink($err);
    return [(string) $out, $status];
}

function git(string $args): string
{
    [$out, $status] = git_raw($args);
    if ($status !== 0 && $out === '') {
        fwrite(STDERR, "git $args failed\n");
        exit(2);
    }
    return $out;
}

// ── Instruction-level cross-check (optional, --verify-so) ───────────────────

/**
 * Count the x86-64 fingerprints of O1 and O3 in a built object.
 *
 * This is a CROSS-CHECK, not the primary evidence: it only works where a
 * disassembler is installed, and the mnemonics are architecture-specific (arm64
 * lowers O1's popcount to `cnt` and O3's byte-swap to `rev`). The source-hash
 * verification is the portable, exact check; this catches the different failure
 * mode of "the right sources were compiled, but not the ones we think".
 */
function arm_s_instruction_census(string $so): ?array
{
    $tool = null;
    foreach (['objdump', 'llvm-objdump', 'gobjdump'] as $t) {
        if (trim((string) @shell_exec('command -v ' . escapeshellarg($t) . ' 2>/dev/null')) !== '') {
            $tool = $t;
            break;
        }
    }
    if ($tool === null && PHP_OS_FAMILY === 'Darwin'
        && trim((string) @shell_exec('command -v otool 2>/dev/null')) !== '') {
        $tool = 'otool';
    }
    if ($tool === null) { return null; }

    $cmd = $tool === 'otool'
        ? 'otool -tV ' . escapeshellarg($so) . ' 2>/dev/null'
        : escapeshellarg($tool) . ' -d ' . escapeshellarg($so) . ' 2>/dev/null';
    $asm = (string) @shell_exec($cmd);
    if ($asm === '') { return null; }

    $count = static function (string $mnemonic) use ($asm): int {
        return preg_match_all('/\b' . preg_quote($mnemonic, '/') . '\b/i', $asm);
    };

    $arch = php_uname('m');
    $census = [
        'tool'   => $tool,
        'arch'   => $arch,
        // x86-64 fingerprints: O1 lowers to POPCNT (under -mpopcnt), O3 to BSWAP.
        'popcnt' => $count('popcnt'),
        'bswap'  => $count('bswap'),
        // arm64 fingerprints: O1 lowers to CNT (base ISA, no flag needed), O3
        // to REV. Both mnemonics have legitimate non-patch uses, which is why
        // the test below is "arm S must be far below arm C", not "must be zero".
        'cnt'    => $count('cnt'),
        'rev'    => $count('rev'),
    ];

    // Which pair carries the signal on this architecture. Measured pairs:
    //   x86-64 (gcc 14.2): arm S popcnt=0  bswap=12   arm C popcnt=89 bswap=985
    //   arm64  (Apple clang 21): arm S cnt=0 rev=32   arm C cnt=88   rev=673
    // The O1 mnemonic reads exactly 0 in arm S on both, which is the sharp test;
    // the O3 mnemonic has incidental uses and is reported as corroboration.
    if (preg_match('/^(arm64|aarch64)$/i', $arch)) {
        $census['fingerprint'] = ['o1' => 'cnt', 'o3' => 'rev'];
        $census['o1_count']    = $census['cnt'];
        $census['o3_count']    = $census['rev'];
    } elseif (preg_match('/^(x86_64|amd64|AMD64)$/i', $arch)) {
        $census['fingerprint'] = ['o1' => 'popcnt', 'o3' => 'bswap'];
        $census['o1_count']    = $census['popcnt'];
        $census['o3_count']    = $census['bswap'];
    } else {
        // Some other target. Say so rather than assert against the wrong pair.
        $census['fingerprint'] = null;
        $census['o1_count']    = null;
        $census['o3_count']    = null;
    }
    $census['note'] = $census['fingerprint'] === null
        ? 'unrecognised architecture: the instruction cross-check does not apply here, '
        . 'and the source-hash verification is the operative evidence'
        : sprintf('arm S must read %s == 0; arm C reads it in the dozens. The %s count is '
            . 'corroboration only (the mnemonic has incidental non-O3 uses).',
            $census['fingerprint']['o1'], $census['fingerprint']['o3']);

    return $census;
}

if (isset($opts['verify-so'])) {
    $so = $opts['verify-so'];
    if (!is_file($so)) {
        fwrite(STDERR, "no such object: $so\n");
        exit(2);
    }
    $census = arm_s_instruction_census($so);
    if ($census === null) {
        fwrite(STDOUT, json_encode(['object' => $so, 'census' => null,
            'reason' => 'no disassembler available'], JSON_PRETTY_PRINT) . "\n");
        exit(0);
    }
    fwrite(STDOUT, json_encode(['object' => $so, 'census' => $census], JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

// ── Materialize ─────────────────────────────────────────────────────────────

$dest = $opts['dest'] ?? null;
if ($dest === null) {
    fwrite(STDERR, "--dest is required (a directory to materialize the arm-S source tree into)\n");
    exit(2);
}

/** Recursively remove a directory. */
function rmtree(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

if (!isset($opts['verify-only'])) {
    say("arm S: materializing into $dest\n");
    rmtree($dest);
    if (!mkdir($dest, 0755, true)) {
        fwrite(STDERR, "cannot create $dest\n");
        exit(2);
    }

    // 1. The extension source tree at HEAD, minus libjudy/. Only git-tracked
    //    files: a dirty build directory in the source repo must not leak into
    //    the arm being measured.
    $tar_ext = "$dest/.arm-s-ext.tar";
    exec('git -C ' . escapeshellarg($repo) . ' archive --format=tar HEAD'
        . ' > ' . escapeshellarg($tar_ext), $_, $st);
    if ($st !== 0) {
        fwrite(STDERR, "git archive HEAD failed\n");
        exit(2);
    }
    exec('tar -x -f ' . escapeshellarg($tar_ext) . ' -C ' . escapeshellarg($dest), $_, $st);
    @unlink($tar_ext);
    if ($st !== 0) {
        fwrite(STDERR, "extracting the extension tree failed\n");
        exit(2);
    }
    rmtree("$dest/libjudy");

    // 2. libjudy/ at the arm-S ref, replacing it wholesale.
    $tar_lib = "$dest/.arm-s-lib.tar";
    exec('git -C ' . escapeshellarg($repo) . ' archive --format=tar '
        . escapeshellarg($arm_s_ref) . ' libjudy > ' . escapeshellarg($tar_lib), $_, $st);
    if ($st !== 0) {
        fwrite(STDERR, "git archive $arm_s_ref libjudy failed — is the ref present in this clone?\n");
        fwrite(STDERR, "  (a shallow clone will not have it; fetch with --unshallow or fetch the ref)\n");
        exit(2);
    }
    exec('tar -x -f ' . escapeshellarg($tar_lib) . ' -C ' . escapeshellarg($dest), $_, $st);
    @unlink($tar_lib);
    if ($st !== 0) {
        fwrite(STDERR, "extracting the arm-S libjudy tree failed\n");
        exit(2);
    }

    // 3. The P7 stub, so the compiled-unit list matches arm C exactly.
    $noinline = "$dest/libjudy/src/JudyCommon/JudyNoInline.c";
    if (!is_file($noinline)) {
        file_put_contents($noinline, ARM_S_NOINLINE_STUB);
        say("arm S: synthesized the empty JudyNoInline.c stub (P7 is absent by construction)\n");
    } else {
        fwrite(STDERR, "arm S: JudyNoInline.c already exists at $arm_s_ref — the ref is past P7, "
            . "which means the reconstruction is NOT unpatched\n");
        exit(1);
    }

    // 4. A marker, so a stray arm-S tree is identifiable on disk later.
    file_put_contents("$dest/ARM_S_PROVENANCE.txt",
        "php-judy arm S — pristine-static comparison arm\n"
        . "extension sources: HEAD of " . $repo . "\n"
        . "libjudy/ tree:     " . $arm_s_ref . "\n"
        . "verified against:  " . $pristine_ref . " (pristine Judy-1.0.5 import)\n"
        . "generated:         " . date('c') . "\n"
        . "This tree is a BENCHMARK ARTIFACT. Do not ship it and do not develop in it.\n");
}

if (!is_dir("$dest/libjudy/src")) {
    fwrite(STDERR, "no arm-S tree at $dest\n");
    exit(2);
}

// ── Verify ──────────────────────────────────────────────────────────────────
//
// Every file upstream ships must be byte-identical to its pristine-import blob,
// except exactly the declared P5 set. Files php-judy ADDED (the wrappers, the
// pre-generated tables, the static-assert pins, the synthesized stub) are build
// scaffolding, are identical in arms S and C, and are checked only for presence.

$pristine_files = array_values(array_filter(array_map('trim',
    explode("\n", git('ls-tree -r --name-only ' . escapeshellarg($pristine_ref) . ' libjudy/')))));
if (!$pristine_files) {
    fwrite(STDERR, "could not list the pristine import at $pristine_ref\n");
    exit(2);
}

$verification = [
    'arm_s_ref'      => $arm_s_ref,
    'pristine_ref'   => $pristine_ref,
    'upstream_files' => 0,
    'identical'      => 0,
    'allowed_delta'  => [],
    'unexpected'     => [],
    'missing'        => [],
];

foreach ($pristine_files as $path) {
    // Only source matters. The import also carries COPYING and friends.
    if (!preg_match('/\.(c|h)$/i', $path)) { continue; }
    $verification['upstream_files']++;

    $local = "$dest/$path";
    if (!is_file($local)) {
        $verification['missing'][] = $path;
        continue;
    }
    $want = hash('sha256', git('cat-file blob ' . escapeshellarg("$pristine_ref:$path")));
    $have = hash_file('sha256', $local);

    if ($want === $have) {
        $verification['identical']++;
    } elseif (isset(ARM_S_ALLOWED_DELTA[$path])) {
        $verification['allowed_delta'][$path] = ARM_S_ALLOWED_DELTA[$path];
    } else {
        $verification['unexpected'][$path] = ['pristine_sha256' => $want, 'arm_s_sha256' => $have];
    }
}

// The allowed set must match EXACTLY: a file that is supposed to carry P5 and
// does not is as much a reconstruction bug as an unexpected difference, because
// it means the ref moved.
$missing_allowed = array_diff(array_keys(ARM_S_ALLOWED_DELTA), array_keys($verification['allowed_delta']));

$ok = !$verification['unexpected'] && !$verification['missing'] && !$missing_allowed;

// Positive control: NO PATCHED FILE MAY LEAK IN.
//
// The hash comparison above proves arm S matches the pristine import. This
// proves the converse independently — that it does *not* match HEAD wherever
// HEAD is patched. The two checks fail differently: a mis-set `--pristine-ref`
// would make the first vacuously pass, and this one would still catch it. It
// also needs no knowledge of what the patches say, so it keeps working when the
// patch series grows, which a hand-written list of grep fingerprints does not.
$leaked = [];
foreach ($pristine_files as $path) {
    if (!preg_match('/\.(c|h)$/i', $path)) { continue; }
    $local = "$dest/$path";
    if (!is_file($local)) { continue; }
    $head_hash = hash('sha256', git('cat-file blob ' . escapeshellarg("HEAD:$path")));
    $ref_hash  = hash('sha256', git('cat-file blob ' . escapeshellarg("$arm_s_ref:$path")));
    // Nothing landed on this file after the arm-S ref, so arm S and HEAD are
    // legitimately identical (this is the case for the P5-only files, whose
    // sole modification predates the ref). No leak is possible here.
    if ($head_hash === $ref_hash) { continue; }
    if (hash_file('sha256', $local) === $head_hash) {
        $leaked[$path] = 'identical to HEAD, which carries a post-' . substr($arm_s_ref, 0, 7) . ' patch here';
    }
}
if ($leaked) { $ok = false; }

// Two unambiguous grep fingerprints, retained so a reader can re-run the check
// by hand without this script. Both strings are introduced by a patch and
// appear nowhere in upstream 1.0.5.
$fingerprints = [
    'O1 hardware popcount (__POPCNT__)'    => ['libjudy/src/JudyCommon/JudyPrivate.h', '__POPCNT__'],
    'O3 word-access DCD (__builtin_bswap)' => ['libjudy/src/JudyCommon/JudyPrivate.h', '__builtin_bswap64'],
];
$present = [];
foreach ($fingerprints as $label => [$file, $needle]) {
    $full = "$dest/$file";
    if (is_file($full) && str_contains((string) file_get_contents($full), $needle)) {
        $present[$label] = $file;
    }
}
if ($present) { $ok = false; }

$verification['missing_allowed_delta']   = array_values($missing_allowed);
$verification['leaked_patched_files']    = $leaked;
$verification['patch_fingerprints_found'] = $present;
$verification['verdict'] = $ok ? 'UNPATCHED' : 'FAILED';

// Optional instruction-level cross-check if a built .so is sitting there.
$built = "$dest/modules/judy.so";
$verification['instruction_census'] = is_file($built) ? arm_s_instruction_census($built) : null;

if (isset($opts['manifest'])) {
    file_put_contents($opts['manifest'],
        json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

if (!$quiet) {
    fwrite(STDERR, "\narm S verification against the pristine import\n");
    fwrite(STDERR, sprintf("  upstream .c/.h files checked : %d\n", $verification['upstream_files']));
    fwrite(STDERR, sprintf("  byte-identical to pristine   : %d\n", $verification['identical']));
    fwrite(STDERR, sprintf("  declared P5 deltas present   : %d of %d\n",
        count($verification['allowed_delta']), count(ARM_S_ALLOWED_DELTA)));
    foreach ($verification['unexpected'] as $p => $_h) { fwrite(STDERR, "  UNEXPECTED DIFFERENCE: $p\n"); }
    foreach ($verification['missing'] as $p)           { fwrite(STDERR, "  MISSING FILE: $p\n"); }
    foreach ($missing_allowed as $p)                   { fwrite(STDERR, "  DECLARED P5 FILE NOT MODIFIED (ref moved?): $p\n"); }
    foreach ($leaked as $p => $why)                    { fwrite(STDERR, "  PATCHED FILE LEAKED IN: $p ($why)\n"); }
    foreach ($present as $label => $f)                 { fwrite(STDERR, "  PATCH FINGERPRINT PRESENT: $label in $f\n"); }
    if ($verification['instruction_census']) {
        $c = $verification['instruction_census'];
        fwrite(STDERR, sprintf("  instruction census (%s, %s): popcnt=%d bswap=%d cnt=%d rev=%d\n",
            $c['tool'], $c['arch'], $c['popcnt'], $c['bswap'], $c['cnt'], $c['rev']));
    }
    fwrite(STDERR, "  verdict: {$verification['verdict']}\n");
}

exit($ok ? 0 : 1);
