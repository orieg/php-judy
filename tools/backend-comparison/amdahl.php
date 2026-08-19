<?php
// Same access pattern through the PHP extension boundary.
// Both loops do identical work except the Judy access itself, so the
// difference isolates (extension boundary + Judy) from interpreter overhead.
$n    = (int)($argv[1] ?? 1000000);
$reps = (int)($argv[2] ?? 5000000);

$j = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < $n; $i++) { $j[$i] = $i * 3; }

// (a) floor: loop + xorshift key generation, no Judy access
$s = 88172645463325252; $sink = 0;
$t0 = hrtime(true);
for ($r = 0; $r < $reps; $r++) {
    $s ^= ($s << 13) & PHP_INT_MAX; $s ^= $s >> 7; $s ^= ($s << 17) & PHP_INT_MAX;
    $sink += $s % $n;
}
$t1 = hrtime(true);
$floor = ($t1 - $t0) / $reps;

// (b) same loop plus one Judy point lookup
$s = 88172645463325252; $sink2 = 0;
$t2 = hrtime(true);
for ($r = 0; $r < $reps; $r++) {
    $s ^= ($s << 13) & PHP_INT_MAX; $s ^= $s >> 7; $s ^= ($s << 17) & PHP_INT_MAX;
    $sink2 += $j[$s % $n];
}
$t3 = hrtime(true);
$total = ($t3 - $t2) / $reps;

printf("PHP interpreter floor  %7.2f ns/op\n", $floor);
printf("PHP floor + \$j[\$k]     %7.2f ns/op\n", $total);
printf("delta (boundary+judy)  %7.2f ns/op\n", $total - $floor);
