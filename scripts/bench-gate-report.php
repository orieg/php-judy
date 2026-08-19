<?php
/**
 * Render the cross-platform regression gate's artifacts as a Markdown summary.
 *
 *   php scripts/bench-gate-report.php <dir-of-downloaded-artifacts>
 *
 * Reads every `gate-*.json` under the directory (recursively — GitHub's
 * download-artifact puts each platform in its own subdirectory) and prints one
 * table per axis with every platform side by side, then the findings.
 *
 * The output is deliberately blunt about provenance. Every row carries the
 * platform, the toolchain and the confidence tier, because the project's rule is
 * that a directional number presented as claim-grade is a defect, and a summary
 * that drops the tier is exactly how that happens.
 */

$dir = $argv[1] ?? '.';

// The gate records, per cell, whether a ratio is resolvable at all: small memory
// cells are page-quantised and move tens of percent between identical runs, so
// bench-gate.php marks them `gateable: false` and refuses to derive a floor from
// them. That judgement lives in the committed baseline, and until now this report
// did not read it -- so a cell the tooling knows is noise rendered with exactly
// the same authority as one measured to 2%. A dense Judy1 bitset is the worst
// case: at 1e6 keys it is ~128 KB, comparable to the RSS floor's own scatter, and
// on musl it has come out anywhere from ~23x to ~223x across runs.
$baseline_path = getenv('JUDY_ARM_RATIOS') ?: (__DIR__ . '/../baselines/arm-ratios.json');
$baseline = is_file($baseline_path)
    ? (json_decode((string) @file_get_contents($baseline_path), true) ?: [])
    : [];

/** The baseline's verdict for one cell, or null when it has never been derived. */
function judy_cell_quality(array $baseline, string $plat, string $axis, string $cell): ?array {
    [$section, $key] = explode('.', $axis, 2);
    $c = $baseline['platforms'][$plat][$section][$key][$cell] ?? null;
    return is_array($c) ? $c : null;
}

/** Render a ratio, marking it when the baseline says it is not resolvable. */
function judy_fmt_ratio(?float $v, string $fmt, array $baseline, string $plat,
                        string $axis, string $cell, array &$flagged): string {
    if ($v === null) { return '—'; }
    $q = judy_cell_quality($baseline, $plat, $axis, $cell);
    if ($q !== null && ($q['gateable'] ?? true) === false) {
        $flagged["$plat|$cell"] = $q['spread_pct'] ?? null;
        return '~' . sprintf($fmt, $v) . ' ⚠';
    }
    return sprintf($fmt, $v);
}
if (!is_dir($dir)) {
    fwrite(STDERR, "not a directory: $dir\n");
    exit(2);
}

$runs = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!preg_match('/^gate-.*\.json$/', $f->getFilename())) { continue; }
    $j = json_decode((string) @file_get_contents($f->getPathname()), true);
    if (!is_array($j) || ($j['metadata']['schema'] ?? null) !== 'judy-bench-gate/1') { continue; }
    // Several repeats of one platform: keep the last, which is the gated one.
    $runs[$j['metadata']['platform']] = $j;
}

if (!$runs) {
    echo "## Benchmark Gate\n\nNo gate results were produced.\n";
    exit(0);
}
ksort($runs);

$worst = 'PASS';
$rank  = ['PASS' => 0, 'not-gated' => 0, 'no-baseline-for-platform' => 1,
          'INCONCLUSIVE' => 2, 'FAIL' => 3];
foreach ($runs as $r) {
    if (($rank[$r['gate']['status']] ?? 0) > ($rank[$worst] ?? 0)) { $worst = $r['gate']['status']; }
}

echo "## Benchmark Gate — $worst\n\n";
echo "Relative regression detection. Every quantity below is a **ratio of two arms measured "
   . "in the same interleaved rounds on the same runner**, compared against the ratio stored "
   . "in `baselines/arm-ratios.json`. Absolute times are never compared across runs: on a "
   . "shared runner they mean nothing, and this project has the false-regression history to "
   . "prove it (#87, #153).\n\n";

// ── Platforms ───────────────────────────────────────────────────────────────
echo "### Platforms measured\n\n";
echo "| platform | tier | toolchain | arms | rebuild control | hygiene | gate |\n";
echo "| --- | --- | --- | --- | --- | --- | --- |\n";
foreach ($runs as $plat => $r) {
    $m = $r['metadata'];
    $roles = [];
    foreach ($m['arms'] as $a) { $roles[$a['role']] = ($roles[$a['role']] ?? 0) + 1; }
    ksort($roles);
    $armstr = implode(' ', array_map(fn($k, $v) => "$k x$v", array_keys($roles), $roles));
    printf("| `%s` | %s | %s | %s | %s | %s | **%s** |\n",
        $plat,
        $m['tier'],
        $m['toolchain'] ?? '?',
        $armstr,
        $m['rebuild_control_available'] ? 'yes' : '**no**',
        $r['hygiene']['contaminated'] ? 'contaminated' : 'clean',
        $r['gate']['status']);
}

// ── Controls ────────────────────────────────────────────────────────────────
echo "\n### Controls\n\n";
echo "The PHP-array control sees runner drift. The C-vs-C rebuild control — two independently "
   . "linked builds of *identical* source — is the one that matters, because it shares Judy's "
   . "memory-access character (pointer-chasing, DRAM-bound) and can therefore see the LLC and "
   . "memory-bandwidth contention the array control is structurally blind to. Its scatter is "
   . "each run's own noise floor and raises that run's threshold when it exceeds the stored one.\n\n";
echo "| platform | PHP-array control | C-vs-C control | C-vs-C scatter |\n";
echo "| --- | ---: | ---: | ---: |\n";
foreach ($runs as $plat => $r) {
    $c = $r['controls'];
    printf("| `%s` | %+.2f%% (scatter %.2f%%) | %s | %s |\n",
        $plat,
        ($c['php_only']['ratio'] - 1.0) * 100.0,
        $c['php_only']['scatter_pct'],
        $c['cc_rebuild']['available']
            ? sprintf('%+.2f%%', ($c['cc_rebuild']['ratio'] - 1.0) * 100.0) : 'unavailable',
        $c['cc_rebuild']['scatter_pct'] === null
            ? 'n/a' : sprintf('%.2f%%', $c['cc_rebuild']['scatter_pct']));
}

// ── Thresholds ──────────────────────────────────────────────────────────────
$any_threshold = false;
foreach ($runs as $r) { if ($r['gate']['thresholds']) { $any_threshold = true; } }
if ($any_threshold) {
    echo "\n### Thresholds applied\n\n";
    echo "| platform | axis | applied | offline floor | this run's control | governed by |\n";
    echo "| --- | --- | ---: | ---: | ---: | --- |\n";
    foreach ($runs as $plat => $r) {
        foreach ($r['gate']['thresholds'] as $axis => $t) {
            printf("| `%s` | `%s` | %.2f%% | %.2f%% | %s | %s |\n",
                $plat, $axis, $t['applied_pct'], $t['offline_floor_pct'],
                $t['run_control_scatter_pct'] === null ? 'n/a'
                    : sprintf('%.2f%%', $t['run_control_scatter_pct']),
                $t['governed_by']);
        }
    }
}

// ── Headline ratios ─────────────────────────────────────────────────────────
$axis_titles = [
    's_over_c' => 'S → C — the vendored libJudy patches (below 1.00 means the patches are faster)',
    'a_over_c' => 'A → C — php-judy against a PHP native array (above 1.00 means the array is faster)',
];
foreach ($axis_titles as $key => $title) {
    $cells = [];
    foreach ($runs as $plat => $r) {
        foreach ($r['timing'][$key] ?? [] as $id => $c) { $cells[$id][$plat] = $c['ratio']; }
    }
    if (!$cells) { continue; }
    echo "\n### $title\n\n";
    $plats = array_keys($runs);
    echo '| benchmark | ' . implode(' | ', array_map(fn($p) => "`$p`", $plats)) . " |\n";
    echo '| --- |' . str_repeat(' ---: |', count($plats)) . "\n";
    ksort($cells);
    foreach ($cells as $id => $byplat) {
        $row = array_map(fn($p) => isset($byplat[$p]) ? sprintf('%.3f', $byplat[$p]) : '—', $plats);
        echo "| `$id` | " . implode(' | ', $row) . " |\n";
    }
}

// Memory, which is the least equivocal axis and deserves its own table.
$mem = [];
foreach ($runs as $plat => $r) {
    foreach ($r['memory']['a_over_c'] ?? [] as $k => $c) { $mem[$k][$plat] = (float) $c['ratio']; }
}
$mem_flagged = [];
if ($mem) {
    echo "\n### Memory — PHP array bytes / php-judy bytes (above 1.00 is a php-judy win)\n\n";
    $plats = array_keys($runs);
    echo '| workload | ' . implode(' | ', array_map(fn($p) => "`$p`", $plats)) . " |\n";
    echo '| --- |' . str_repeat(' ---: |', count($plats)) . "\n";
    ksort($mem);
    foreach ($mem as $k => $byplat) {
        // A regular closure, not an arrow function: `fn()` captures by value, so a
        // by-reference accumulator silently collects nothing.
        $row = array_map(
            function ($p) use ($byplat, $baseline, $k, &$mem_flagged) {
                return judy_fmt_ratio($byplat[$p] ?? null, '%.2fx', $baseline, $p,
                                      'memory.a_over_c', $k, $mem_flagged);
            },
            $plats);
        echo "| `$k` | " . implode(' | ', $row) . " |\n";
    }
    if ($mem_flagged) {
        echo "\n> ⚠ Not resolvable by this instrument, and therefore **not gated**: the";
        echo " structure is small enough that run-to-run scatter swamps the signal.";
        echo " Recorded for completeness; do not quote these as measurements.";
        foreach ($mem_flagged as $what => $spread) {
            [$p, $cell] = explode('|', $what, 2);
            echo "\n> `$cell` on `$p` — baseline spread "
                . ($spread === null ? 'unknown' : sprintf('%.1f%%', $spread)) . '.';
        }
        echo "\n";
    }
}

// ── Arm S verification ──────────────────────────────────────────────────────
echo "\n### Arm S — verified unpatched on each platform\n\n";
echo "| platform | O1 fingerprint (arm S) | O1 fingerprint (arm C) | O3 (S / C) |\n";
echo "| --- | ---: | ---: | ---: |\n";
foreach ($runs as $plat => $_r) {
    // The census files sit beside the run JSON in the same artifact.
    // download-artifact lays each platform out as <dir>/bench-gate-<platform>/.
    $s = $c = null;
    foreach (['S' => &$s, 'C' => &$c] as $role => &$slot) {
        foreach (glob("$dir/*/census-$role.json") ?: [] as $p) {
            if (!str_contains($p, $plat)) { continue; }
            $j = json_decode((string) file_get_contents($p), true);
            $slot = $j['census'] ?? null;
        }
    }
    unset($slot);
    if ($s === null && $c === null) {
        printf("| `%s` | — | — | source-hash verification only |\n", $plat);
        continue;
    }
    $fp = $s['fingerprint']['o1'] ?? ($c['fingerprint']['o1'] ?? '?');
    printf("| `%s` | `%s` = %s | `%s` = %s | %s / %s |\n",
        $plat, $fp, $s['o1_count'] ?? '?', $fp, $c['o1_count'] ?? '?',
        $s['o3_count'] ?? '?', $c['o3_count'] ?? '?');
}
echo "\nThe operative evidence is the source-hash manifest (`arm-s-manifest.json`), which "
   . "compares every upstream file against the pristine import commit and works on every "
   . "platform including Windows. The instruction census above is an architecture-specific "
   . "cross-check: x86-64 fingerprints O1 as `popcnt` and O3 as `bswap`, arm64 as `cnt` and "
   . "`rev`.\n";

// ── Findings ────────────────────────────────────────────────────────────────
$all = [];
foreach ($runs as $r) { $all = array_merge($all, $r['gate']['findings']); }
echo "\n### Findings\n\n";
if (!$all) {
    echo "No cell on any platform moved past its threshold in either direction.\n";
} else {
    echo "| status | platform | axis | cell | baseline | current | drift | threshold |\n";
    echo "| --- | --- | --- | --- | ---: | ---: | ---: | ---: |\n";
    usort($all, fn($x, $y) => [$y['status'], $y['drift_pct']] <=> [$x['status'], $x['drift_pct']]);
    foreach ($all as $f) {
        printf("| **%s** | `%s` | `%s` | `%s` | %.4f | %.4f | %+.2f%% [%+.2f, %+.2f] | %.2f%% |\n",
            $f['status'], $f['platform'], $f['axis'], $f['cell'],
            $f['baseline_ratio'], $f['current_ratio'], $f['drift_pct'],
            $f['drift_ci_pct'][0], $f['drift_ci_pct'][1], $f['threshold_pct']);
    }
    echo "\nA cell is flagged only when its **whole** bootstrap CI clears the threshold in the "
       . "adverse direction. `suppressed` means the run's hygiene failed, so it measured "
       . "movement but is not permitted to accuse.\n";
}

exit(0);
