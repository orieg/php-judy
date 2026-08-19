--TEST--
Judy batched-lookup call sites (getAll/intersect/diff/xor/equals) agree with per-key semantics on adversarial keys (#142 O5)
--DESCRIPTION--
The bundled libJudy resolves bulk lookups through JudyLMultiGet (AMAC
pipelined, #142 O5); system-libJudy builds keep serial loops. This test
asserts observable behavior only, so it must pass identically on both.
Populations exceed the pipelined path's tiny-tree serial threshold
(cJL_MULTIGET_SERIAL_POP1 = 262144) and batches exceed one gather block
(256), so the batched code actually runs on bundled builds. Keys include 0, PHP_INT_MAX, PHP_INT_MIN, negatives,
duplicates, and misses; types (not just values) are asserted.
--INI--
memory_limit=1G
--FILE--
<?php
$N = 300000;

/* Deterministic key set: dense run + LCG-sparse + adversarial edges. */
function keyset(int $n): array {
    $keys = [];
    for ($i = 0; $i < intdiv($n, 2); $i++) $keys[] = $i * 3;
    $x = 88172645463325252;
    for ($i = intdiv($n, 2); $i < $n - 8; $i++) {
        /* xorshift with masking: stays within int range (no float spill) */
        $x = ($x ^ ($x << 13)) & PHP_INT_MAX;
        $x = $x ^ ($x >> 7);
        $x = ($x ^ ($x << 17)) & PHP_INT_MAX;
        $keys[] = $x;
    }
    array_push($keys, 0, 1, PHP_INT_MAX, PHP_INT_MAX - 1, -1, -2, PHP_INT_MIN, PHP_INT_MIN + 1);
    return array_values(array_unique($keys));
}

$keys = keyset($N);

$a = new Judy(Judy::INT_TO_INT);
$ref = [];
foreach ($keys as $i => $k) {
    $a[$k] = $i + 7;
    $ref[$k] = $i + 7;
}

/* --- getAll: hits, misses, duplicates, >1 block, adversarial keys --- */
$probe = [];
foreach ($keys as $i => $k) if ($i % 3 === 0) $probe[] = $k;      // hits
for ($i = 0; $i < 700; $i++) $probe[] = 0x7654321000 + $i * 977;  // misses
$probe[] = PHP_INT_MAX; $probe[] = PHP_INT_MIN; $probe[] = -1;    // edge hits
$probe[] = $probe[0]; $probe[] = $probe[0];                       // duplicates
$got = $a->getAll($probe);
$bad = 0;
foreach ($probe as $k) {
    $want = array_key_exists($k, $ref) ? $ref[$k] : null;
    if (!array_key_exists($k, $got) || $got[$k] !== $want) $bad++;
}
var_dump($bad === 0 && count($got) === count(array_unique($probe)));

/* getAll: empty input, empty Judy, non-IS_LONG keys flushing mid-block */
var_dump($a->getAll([]) === []);
$empty = new Judy(Judy::INT_TO_INT);
var_dump($empty->getAll([1, 2, 3]) === [1 => null, 2 => null, 3 => null]);
$mixedkeys = [];
for ($i = 0; $i < 300; $i++) $mixedkeys[] = $i * 3;               // longs
$mixedkeys[] = "42";                                              // string int
$mixedkeys[] = true;                                              // bool
for ($i = 0; $i < 300; $i++) $mixedkeys[] = 0x123400000 + $i;     // miss longs
$got2 = $a->getAll($mixedkeys);
var_dump($got2[42] === $ref[42] && $got2[1] === $ref[1] && $got2[0x123400000] === null);

/* --- set operations vs PHP-array reference --- */
$b = new Judy(Judy::INT_TO_INT);
$refB = [];
foreach ($keys as $i => $k) {
    if ($i % 2 === 0) { $b[$k] = $i + 900000; $refB[$k] = $i + 900000; }
}
for ($i = 0; $i < 5000; $i++) { $b[0x9990000000 + $i] = $i; $refB[0x9990000000 + $i] = $i; }

function judy_to_ref(Judy $j): array { return $j->toArray(); }

$got = judy_to_ref($a->intersect($b));
$want = array_intersect_key($ref, $refB);            // left-wins: self values
ksort($got); ksort($want);
var_dump($got == $want);

$got = judy_to_ref($a->diff($b));
$want = array_diff_key($ref, $refB);
ksort($got); ksort($want);
var_dump($got == $want);

$got = judy_to_ref($a->xor($b));
$want = array_diff_key($ref, $refB) + array_diff_key($refB, $ref);
ksort($got); ksort($want);
var_dump($got == $want);

/* --- equals: above-threshold arrays, batched membership --- */
$c = new Judy(Judy::INT_TO_INT);
foreach ($ref as $k => $v) $c[$k] = $v;
var_dump($a->equals($c));
$c[$keys[123]] = -999999;                            // one value differs
var_dump($a->equals($c));
$c[$keys[123]] = $ref[$keys[123]];
unset($c[$keys[456]]);
$c[0x7777777000] = 1;                                // same count, one key differs
var_dump($a->equals($c));

/* --- INT_TO_MIXED getAll + equals above threshold --- */
$m = new Judy(Judy::INT_TO_MIXED);
$m2 = new Judy(Judy::INT_TO_MIXED);
for ($i = 0; $i < 300000; $i++) {
    $v = ($i % 1000 === 0) ? "s$i" : $i * 2;
    $m[$i * 7] = $v;
    $m2[$i * 7] = $v;
}
$gm = $m->getAll([0, 7, 7000, 70000001, -1]);
var_dump($gm[0] === "s0" && $gm[7] === 2 && $gm[7000] === "s1000" && $gm[70000001] === null && $gm[-1] === null);
var_dump($m->equals($m2));
$m2[7 * 299999] = "different";
var_dump($m->equals($m2));

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
bool(true)
bool(true)
bool(false)
done
