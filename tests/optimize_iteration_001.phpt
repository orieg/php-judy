--TEST--
Judy optimizeIteration: which types honour it, and every path that derives one array from another
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// optimizeIteration is a construction-time trade: ordered traversal reads the
// payload out of the key index it is already walking, and every write pays for
// keeping that second copy current. Two things have to hold for it to be safe.
//
// 1. It is honoured only where it can be, and says so. Passing it to a type
//    that cannot mirror is accepted and dropped, so that generic code building
//    a type out of a variable does not have to special-case anything.
//    isIterationOptimized() is what makes that silence discoverable.
//
// 2. It travels with the data. An array derived from a mirrored one that came
//    out unmirrored — or the reverse — would read and write at a different
//    speed than its source, with correct results either way and nothing to
//    show for it but a benchmark. This test enumerates every path in the
//    extension that builds a Judy from an existing Judy and pins each one.
//
// The contents are checked alongside the flag on every path: a propagation bug
// and a mirror bug look identical from userland unless both are asserted.

$allTypes = [
    'BITSET'                   => Judy::BITSET,
    'INT_TO_INT'               => Judy::INT_TO_INT,
    'INT_TO_MIXED'             => Judy::INT_TO_MIXED,
    'INT_TO_PACKED'            => Judy::INT_TO_PACKED,
    'STRING_TO_INT'            => Judy::STRING_TO_INT,
    'STRING_TO_MIXED'          => Judy::STRING_TO_MIXED,
    'STRING_TO_MIXED_HASH'     => Judy::STRING_TO_MIXED_HASH,
    'STRING_TO_INT_HASH'       => Judy::STRING_TO_INT_HASH,
    'STRING_TO_MIXED_ADAPTIVE' => Judy::STRING_TO_MIXED_ADAPTIVE,
    'STRING_TO_INT_ADAPTIVE'   => Judy::STRING_TO_INT_ADAPTIVE,
];

echo "-- honoured by type (asked for true)\n";
foreach ($allTypes as $name => $type) {
    $j = new Judy($type, true);
    printf("%-24s %s\n", $name, $j->isIterationOptimized() ? 'yes' : 'no');
}

echo "\n-- default is off everywhere\n";
$defaults = [];
foreach ($allTypes as $name => $type) {
    $defaults[] = (new Judy($type))->isIterationOptimized();
}
var_dump(in_array(true, $defaults, true));

echo "\n-- explicit false is off too\n";
var_dump((new Judy(Judy::STRING_TO_INT_HASH, false))->isIterationOptimized());

// ---------------------------------------------------------------------------
// Propagation. Every path below constructs a new Judy from an existing one;
// each is run from a mirrored source and an unmirrored one, and must come out
// matching its source. Keys straddle the 8-byte SSO boundary so the ADAPTIVE
// split (short keys are never mirrored, long ones are) is exercised too.

function seeded(int $type, bool $opt): Judy
{
    $j = new Judy($type, $opt);
    foreach (['a1', 'b2', 'long_key_alpha', 'long_key_beta'] as $i => $k) {
        $j[$k] = $i + 1;
    }
    return $j;
}

/** The reference the mirror must agree with: keys() never reads a value and
    point lookup deliberately still reads the value store, so the pair is
    independent of the mirror. */
function truth(Judy $j): array
{
    $out = [];
    foreach ($j->keys() as $k) {
        $out[$k] = $j[$k];
    }
    return $out;
}

function report(string $path, Judy $src, Judy $got, ?array $want = null): void
{
    $flag = $got->isIterationOptimized() === $src->isIterationOptimized()
        ? 'flag ok' : 'FLAG DIVERGED';
    $t = truth($got);
    $content = $t === $got->toArray() && $t === iterator_to_array($got)
        ? 'reads ok' : 'READS DIVERGED';
    if ($want !== null) {
        ksort($want, SORT_STRING);
        $content .= $t === $want ? '' : ' CONTENT WRONG';
    }
    printf("  %-32s %s, %s\n", $path, $flag, $content);
}

foreach (['STRING_TO_INT_HASH' => Judy::STRING_TO_INT_HASH,
          'STRING_TO_INT_ADAPTIVE' => Judy::STRING_TO_INT_ADAPTIVE] as $tn => $type) {
    foreach ([false, true] as $opt) {
        echo "\n-- $tn, optimizeIteration=", var_export($opt, true), "\n";
        $j = seeded($type, $opt);
        $model = truth($j);

        report('clone', $j, clone $j, $model);
        report('__serialize/__unserialize', $j, unserialize(serialize($j)), $model);
        report('slice (whole range)', $j, $j->slice('a', 'zz'), $model);
        report('filter (keep all)', $j, $j->filter(fn($v, $k) => true), $model);
        report('map (identity)', $j, $j->map(fn($v, $k) => $v), $model);

        // Set operations are defined for STRING_TO_INT_HASH and
        // STRING_TO_INT_ADAPTIVE, and take their result type — and so their
        // setting — from the left operand.
        $other = seeded($type, $opt);
        $other['c3'] = 99;
        report('union', $j, $j->union($other), $model + ['c3' => 99]);
        report('intersect', $j, $j->intersect($other), $model);
        report('diff', $j, $j->diff($other), []);
        report('xor', $j, $j->xor($other), ['c3' => 99]);

        // These mutate in place rather than deriving: the setting must simply
        // survive, and the writes must land in both stores.
        $m = clone $j;
        $m->putAll(['a1' => 50, 'zz_long_key' => 60]);
        report('putAll (in place)', $j, $m,
            array_replace($model, ['a1' => 50, 'zz_long_key' => 60]));

        $m2 = clone $j;
        $m2->mergeWith($other);
        report('mergeWith (in place)', $j, $m2, $model + ['c3' => 99]);

        // fromArray() is the one derived path with no source instance to
        // inherit from, so it takes the argument itself.
        $fa = Judy::fromArray($type, $model, $opt);
        report('fromArray', $j, $fa, $model);

        // Re-unserializing an already-populated object must not keep the
        // previous life's setting.
        $recycled = seeded($type, !$opt);
        $recycled->__unserialize($j->__serialize());
        report('__unserialize onto populated', $j, $recycled, $model);
    }
}

// ---------------------------------------------------------------------------
echo "\n-- mixing a mirrored and an unmirrored operand\n";
// The result is a new array and only has to be internally consistent; it takes
// its setting from the left operand, matching the left-wins value rule the set
// operations already document.
$on  = seeded(Judy::STRING_TO_INT_HASH, true);
$off = seeded(Judy::STRING_TO_INT_HASH, false);
$off['c3'] = 99;
foreach (['on.union(off)' => [$on, $off], 'off.union(on)' => [$off, $on]] as $label => [$l, $r]) {
    $u = $l->union($r);
    printf("  %-16s optimized=%s, reads ok=%s\n", $label,
        var_export($u->isIterationOptimized(), true),
        var_export(truth($u) === $u->toArray(), true));
}
$mix = clone $on;
$mix->mergeWith($off);
printf("  %-16s optimized=%s, reads ok=%s\n", 'on.mergeWith(off)',
    var_export($mix->isIterationOptimized(), true),
    var_export(truth($mix) === $mix->toArray(), true));

// ---------------------------------------------------------------------------
echo "\n-- serialization payload\n";
$plain = new Judy(Judy::STRING_TO_INT_HASH);
$plain['k'] = 1;
// Off serializes to exactly the payload it did before the key existed.
var_dump(array_keys($plain->__serialize()));
$opted = new Judy(Judy::STRING_TO_INT_HASH, true);
$opted['k'] = 1;
var_dump(array_keys($opted->__serialize()));

// A payload written by a build that did not know the key must still load, and
// must load unmirrored — the setting is a performance choice, not data.
$legacy = new Judy(Judy::STRING_TO_INT_HASH, true);
$legacy->__unserialize(['type' => Judy::STRING_TO_INT_HASH, 'data' => ['k' => 7]]);
var_dump($legacy->isIterationOptimized(), $legacy->toArray());

// ---------------------------------------------------------------------------
echo "\n-- ADAPTIVE across the SSO boundary with the mirror on\n";
// Short keys live in a JudyL and are never mirrored even here; long keys live
// in the JudyHS and are. Both halves must read back the same either way.
$a = new Judy(Judy::STRING_TO_INT_ADAPTIVE, true);
$model = [];
for ($i = 0; $i < 12; $i++) {
    $short = sprintf('s%d', $i);            // 2 bytes
    $long  = sprintf('long_key_%03d', $i);  // 12 bytes
    $a[$short] = $i;
    $a[$long] = $i + 100;
    $model[$short] = $i;
    $model[$long] = $i + 100;
}
ksort($model, SORT_STRING);
var_dump(truth($a) === $model, $a->toArray() === $model, iterator_to_array($a) === $model);
// Overwrite one on each side of the boundary: the long one has to update two
// stores, the short one only ever had one.
$a['s3'] = 999;
$a['long_key_003'] = 888;
$model['s3'] = 999;
$model['long_key_003'] = 888;
var_dump(truth($a) === $model, $a->toArray() === $model);

// ---------------------------------------------------------------------------
echo "\n-- argument validation\n";
try {
    new Judy(Judy::STRING_TO_INT_HASH, true, 1);
} catch (ArgumentCountError $e) {
    echo "  ctor: ", $e->getMessage(), "\n";
}
try {
    Judy::fromArray(Judy::STRING_TO_INT_HASH, [], true, 1);
} catch (ArgumentCountError $e) {
    echo "  fromArray: ", $e->getMessage(), "\n";
}
try {
    new Judy(Judy::STRING_TO_INT_HASH, []);
} catch (TypeError $e) {
    echo "  ctor type: ", $e->getMessage(), "\n";
}
// isIterationOptimized() is a pure reader and takes nothing.
try {
    (new Judy(Judy::STRING_TO_INT_HASH))->isIterationOptimized(1);
} catch (ArgumentCountError $e) {
    echo "  getter: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
-- honoured by type (asked for true)
BITSET                   no
INT_TO_INT               no
INT_TO_MIXED             no
INT_TO_PACKED            no
STRING_TO_INT            no
STRING_TO_MIXED          no
STRING_TO_MIXED_HASH     no
STRING_TO_INT_HASH       yes
STRING_TO_MIXED_ADAPTIVE no
STRING_TO_INT_ADAPTIVE   yes

-- default is off everywhere
bool(false)

-- explicit false is off too
bool(false)

-- STRING_TO_INT_HASH, optimizeIteration=false
  clone                            flag ok, reads ok
  __serialize/__unserialize        flag ok, reads ok
  slice (whole range)              flag ok, reads ok
  filter (keep all)                flag ok, reads ok
  map (identity)                   flag ok, reads ok
  union                            flag ok, reads ok
  intersect                        flag ok, reads ok
  diff                             flag ok, reads ok
  xor                              flag ok, reads ok
  putAll (in place)                flag ok, reads ok
  mergeWith (in place)             flag ok, reads ok
  fromArray                        flag ok, reads ok
  __unserialize onto populated     flag ok, reads ok

-- STRING_TO_INT_HASH, optimizeIteration=true
  clone                            flag ok, reads ok
  __serialize/__unserialize        flag ok, reads ok
  slice (whole range)              flag ok, reads ok
  filter (keep all)                flag ok, reads ok
  map (identity)                   flag ok, reads ok
  union                            flag ok, reads ok
  intersect                        flag ok, reads ok
  diff                             flag ok, reads ok
  xor                              flag ok, reads ok
  putAll (in place)                flag ok, reads ok
  mergeWith (in place)             flag ok, reads ok
  fromArray                        flag ok, reads ok
  __unserialize onto populated     flag ok, reads ok

-- STRING_TO_INT_ADAPTIVE, optimizeIteration=false
  clone                            flag ok, reads ok
  __serialize/__unserialize        flag ok, reads ok
  slice (whole range)              flag ok, reads ok
  filter (keep all)                flag ok, reads ok
  map (identity)                   flag ok, reads ok
  union                            flag ok, reads ok
  intersect                        flag ok, reads ok
  diff                             flag ok, reads ok
  xor                              flag ok, reads ok
  putAll (in place)                flag ok, reads ok
  mergeWith (in place)             flag ok, reads ok
  fromArray                        flag ok, reads ok
  __unserialize onto populated     flag ok, reads ok

-- STRING_TO_INT_ADAPTIVE, optimizeIteration=true
  clone                            flag ok, reads ok
  __serialize/__unserialize        flag ok, reads ok
  slice (whole range)              flag ok, reads ok
  filter (keep all)                flag ok, reads ok
  map (identity)                   flag ok, reads ok
  union                            flag ok, reads ok
  intersect                        flag ok, reads ok
  diff                             flag ok, reads ok
  xor                              flag ok, reads ok
  putAll (in place)                flag ok, reads ok
  mergeWith (in place)             flag ok, reads ok
  fromArray                        flag ok, reads ok
  __unserialize onto populated     flag ok, reads ok

-- mixing a mirrored and an unmirrored operand
  on.union(off)    optimized=true, reads ok=true
  off.union(on)    optimized=false, reads ok=true
  on.mergeWith(off) optimized=true, reads ok=true

-- serialization payload
array(2) {
  [0]=>
  string(4) "type"
  [1]=>
  string(4) "data"
}
array(3) {
  [0]=>
  string(4) "type"
  [1]=>
  string(17) "optimizeIteration"
  [2]=>
  string(4) "data"
}
bool(false)
array(1) {
  ["k"]=>
  int(7)
}

-- ADAPTIVE across the SSO boundary with the mirror on
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)

-- argument validation
  ctor: Judy::__construct() expects at most 2 arguments, 3 given
  fromArray: Judy::fromArray() expects at most 3 arguments, 4 given
  ctor type: Judy::__construct(): Argument #2 ($optimizeIteration) must be of type bool, array given
  getter: Judy::isIterationOptimized() expects exactly 0 arguments, 1 given
