--TEST--
bench-gate: --derive pools noise floors across operation families when runs < 8 or hosts < 4 (issue #205)
--FILE--
<?php
$run1 = [
    'metadata' => [
        'schema'       => 'judy-bench-gate/1',
        'platform'     => 'test-plat',
        'commit'       => 'c0ffee1',
        'libjudy_tree' => 'tree1',
        'uname'        => 'Linux testvm1 6.1.0 x86_64',
    ],
    'timing' => [
        's_over_c' => [
            'api.setop.union.bitset' => ['ratio' => 1.0],
            'api.setop.diff.bitset'  => ['ratio' => 1.0],
            'core.int_to_int.read'   => ['ratio' => 1.0],
        ]
    ]
];
$run2 = $run1;
$run2['metadata']['uname'] = 'Linux testvm2 6.1.0 x86_64';
// union has 20% drift (1.20 vs 1.00); diff has only 2% drift (1.02 vs 1.00); core has 2% drift
$run2['timing']['s_over_c']['api.setop.union.bitset']['ratio'] = 1.20;
$run2['timing']['s_over_c']['api.setop.diff.bitset']['ratio']  = 1.02;
$run2['timing']['s_over_c']['core.int_to_int.read']['ratio']   = 1.02;

$f1 = tempnam(sys_get_temp_dir(), 'r1');
$f2 = tempnam(sys_get_temp_dir(), 'r2');
file_put_contents($f1, json_encode($run1));
file_put_contents($f2, json_encode($run2));

$gate_script = dirname(__DIR__) . '/scripts/bench-gate.php';
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($gate_script) . " --derive $f1,$f2";
$out = shell_exec($cmd);
@unlink($f1);
@unlink($f2);

$res = json_decode((string) $out, true);
if (!is_array($res) || !isset($res['axes']['timing.s_over_c']['per_cell_floors'])) {
    echo "FAIL: invalid derive JSON output\n";
    echo $out . "\n";
    exit(1);
}

$floors = $res['axes']['timing.s_over_c']['per_cell_floors'];

// 1. diff.bitset must pool with union.bitset to share its floor (25%)
$union_floor = $floors['api.setop.union.bitset']['floor_pct'] ?? null;
$diff_floor  = $floors['api.setop.diff.bitset']['floor_pct'] ?? null;
if ($diff_floor === $union_floor && $diff_floor > 0) {
    echo "family_pooled: OK\n";
} else {
    echo "family_pooled: FAIL (diff=$diff_floor vs union=$union_floor)\n";
}

// 2. diff.bitset must record pooled_worst_drift_pct
if (isset($floors['api.setop.diff.bitset']['pooled_worst_drift_pct'])) {
    echo "pooled_worst_recorded: OK\n";
} else {
    echo "pooled_worst_recorded: FAIL\n";
}

// 3. core.int_to_int.read is not in the setop family, so pooled_worst_drift_pct is not set
if (!isset($floors['core.int_to_int.read']['pooled_worst_drift_pct'])) {
    echo "independent_cell_unaffected: OK\n";
} else {
    echo "independent_cell_unaffected: FAIL\n";
}
?>
--EXPECT--
family_pooled: OK
pooled_worst_recorded: OK
independent_cell_unaffected: OK
