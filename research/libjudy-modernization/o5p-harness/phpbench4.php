<?php
/* O5 REOPEN PHP-level A/B cells (#142).
 * usage: php phpbench4.php <op> <shape> <n> <reps>
 * ops:   getall  -- $a->getAll($probe), 200K random-order user keys
 *        foreach -- foreach($probe) $a[$k] (offsetGet), same probes
 * shape: sparse  (homogeneous xorshift)
 *        mix     (half dense strided x3, half sparse -- the cell that
 *                 killed the original O5 drop at n=1e6)
 *        dense90 (90% dense strided x3, 10% sparse -- the partition's
 *                 worst measured C-level ratio)
 *        clust   (50% CLUSTERED dense: 1024-key runs at random 40-bit
 *                 bases, + 50% sparse -- the PHP analog of the C gate's
 *                 wmixc50, the worst measured cell for the batched path;
 *                 clustering at random high-order bases is what defeats
 *                 the partition's discriminating-byte heuristic)
 *        dense   (100% dense strided x3 -- the cheap-descend tree where
 *                 the C gate shows the BATCHED PATH ITSELF losing under
 *                 the corrected serial baseline, archived impl included;
 *                 the adoption's worst case, not the partition's)
 * Emits: PHPRES4,<op>,<shape>,<n>,<median_ms>,<cks>
 */
$op = $argv[1]; $shape = $argv[2]; $n = (int)$argv[3]; $reps = (int)$argv[4];
$dense = 0;
if ($shape === 'mix') $dense = intdiv($n, 2);
elseif ($shape === 'dense90') $dense = intdiv($n * 9, 10);
elseif ($shape === 'dense') $dense = $n;
elseif ($shape === 'clust') $dense = intdiv($n, 2);
$keys = []; $x = 88172645463325252; $clustbase = 0;
for ($i = 0; $i < $n; $i++) {
    if ($i < $dense) {
        if ($shape === 'clust') {
            if (($i & 1023) === 0) {
                $x = ($x ^ ($x << 13)) & PHP_INT_MAX; $x = $x ^ ($x >> 7); $x = ($x ^ ($x << 17)) & PHP_INT_MAX;
                $clustbase = $x & ((1 << 40) - 1);
            }
            $keys[] = $clustbase + ($i & 1023);
        } else {
            $keys[] = $i * 3;
        }
        continue;
    }
    $x = ($x ^ ($x << 13)) & PHP_INT_MAX; $x = $x ^ ($x >> 7); $x = ($x ^ ($x << 17)) & PHP_INT_MAX;
    $keys[] = $x;
}
$a = new Judy(Judy::INT_TO_INT);
foreach ($keys as $i => $k) $a[$k] = $i + 1;
$probe = []; $x = 12345; $m = min(200000, $n);
for ($i = 0; $i < $m; $i++) {
    $x = ($x ^ ($x << 13)) & PHP_INT_MAX; $x = $x ^ ($x >> 7); $x = ($x ^ ($x << 17)) & PHP_INT_MAX;
    $probe[] = $keys[$x % $n];
}
unset($keys);
$times = []; $cks = 0;
for ($r = 0; $r < $reps; $r++) {
    $t0 = hrtime(true);
    if ($op === 'getall') { $res = $a->getAll($probe); $cks = count($res); unset($res); }
    else { $s = 0; foreach ($probe as $k) { if ($a[$k] !== null) $s++; } $cks = $s; }
    $times[] = (hrtime(true) - $t0) / 1e6;
}
sort($times);
printf("PHPRES4,%s,%s,%d,%.4f,%d\n", $op, $shape, $n, $times[intdiv(count($times),2)], $cks);
