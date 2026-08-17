--TEST--
Judy bounded keys()/values()/toArray() agree with the unmirrored reference under optimizeIteration
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// optimizeIteration makes ordered traversal read the payload out of the key
// index it is already walking, and keys()/values()/toArray() are on that path.
// Those three also accept an inclusive key range, so the mirror and the range
// bound share one traversal: the mirror decides where each VALUE comes from,
// the range decides which KEYS are visited at all. Nothing forces those two to
// be independent, and a bug where they are not would be invisible without a
// range in the picture — which is how this combination arrived untested, each
// feature covered on its own.
//
// The reference is the same array built with the mirror OFF. Mirror-off reads
// the value store directly, so if a bounded read disagrees between the two the
// mirror is wrong, the bound is wrong, or the two interfere.

$types = [
    'STRING_TO_INT_HASH'       => Judy::STRING_TO_INT_HASH,
    'STRING_TO_INT_ADAPTIVE'   => Judy::STRING_TO_INT_ADAPTIVE,
    'STRING_TO_MIXED_HASH'     => Judy::STRING_TO_MIXED_HASH,   // cannot mirror
    'STRING_TO_INT'            => Judy::STRING_TO_INT,          // cannot mirror
];

// Straddles the adaptive SSO boundary on purpose: keys of 7 bytes or fewer are
// packed into a JudyL, 8 or more go to JudyHS, and only the latter can mirror.
// A range spanning both exercises the two stores in one traversal.
$data = [
    'aa'           => 1,
    'apple'        => 2,
    'apricot_long' => 3,
    'banana'       => 4,
    'blackcurrant' => 5,
    'cherry'       => 6,
    'zz'           => 7,
];

$ranges = [
    ['b', 'c'],                 // ordinary span
    ['a', 'b'],                 // span ending mid-data
    ['apple', 'cherry'],        // bounds that are themselves keys
    ['aa', 'aa'],               // single key, and an SSO one
    ['apricot_long', 'zz'],     // starts on a long (JudyHS) key
    ['c', 'b'],                 // inverted: must be empty
    ['zzz', 'zzzz'],            // past the last key
    [null, 'b'],                // unbounded start
    ['b', null],                // unbounded end
    [null, null],               // unbounded both: the whole array
];

function readAll(Judy $j, array $ranges): array
{
    $out = [];
    foreach ($ranges as $i => [$lo, $hi]) {
        $out[$i] = [$j->keys($lo, $hi), $j->values($lo, $hi), $j->toArray($lo, $hi)];
    }
    return $out;
}

function seed(int $type, bool $opt, array $data): Judy
{
    $j = new Judy($type, $opt);
    foreach ($data as $k => $v) {
        $j[$k] = $type === Judy::STRING_TO_MIXED_HASH ? "v$v" : $v;
    }
    return $j;
}

foreach ($types as $name => $type) {
    $off = seed($type, false, $data);
    $on  = seed($type, true, $data);

    printf(
        "%-24s honoured: %-3s  bounded reads match unmirrored: %s\n",
        $name,
        $on->isIterationOptimized() ? 'yes' : 'no',
        readAll($off, $ranges) === readAll($on, $ranges) ? 'yes' : 'NO'
    );
}

// slice() inherits the mirror, and slice() is itself a range operation, so the
// equivalence the range tests assert unmirrored must hold mirrored too.
echo "\n-- slice() equivalence under the mirror\n";
foreach (['STRING_TO_INT_HASH' => Judy::STRING_TO_INT_HASH,
          'STRING_TO_INT_ADAPTIVE' => Judy::STRING_TO_INT_ADAPTIVE] as $name => $type) {
    $j = seed($type, true, $data);
    $same = true;
    $inherited = true;
    foreach ([['a', 'c'], ['apple', 'cherry'], ['aa', 'aa'], ['c', 'b']] as [$lo, $hi]) {
        $s = $j->slice($lo, $hi);
        $inherited = $inherited && $s->isIterationOptimized() === $j->isIterationOptimized();
        $same = $same
            && $j->keys($lo, $hi) === $s->keys()
            && $j->toArray($lo, $hi) === $s->toArray();
    }
    printf("%-24s flag inherited: %-3s  keys(lo,hi) === slice(lo,hi)->keys(): %s\n",
        $name, $inherited ? 'yes' : 'NO', $same ? 'yes' : 'NO');
}

// A bounded read walks the shared key buffer; an in-progress foreach must not
// notice. (Unmirrored coverage of this lives in the keys_range_* tests.)
echo "\n-- iteration is undisturbed by bounded reads under the mirror\n";
$j = seed(Judy::STRING_TO_INT_HASH, true, $data);
$seen = [];
foreach ($j as $k => $v) {
    $seen[] = $k;
    $j->keys('a', 'c');
    $j->toArray('b', null);
    $j->values(null, 'b');
}
$want = array_keys($data);
sort($want, SORT_STRING);
var_dump($seen === $want);

// fromArray() takes the flag too, and must reach the same bounded results.
echo "\n-- fromArray() with the flag\n";
$f = Judy::fromArray(Judy::STRING_TO_INT_HASH, $data, true);
var_dump($f->isIterationOptimized());
var_dump($f->keys('a', 'c'));
var_dump($f->toArray('apple', 'banana'));
var_dump($f->values('b', 'c'));
?>
--EXPECT--
STRING_TO_INT_HASH       honoured: yes  bounded reads match unmirrored: yes
STRING_TO_INT_ADAPTIVE   honoured: yes  bounded reads match unmirrored: yes
STRING_TO_MIXED_HASH     honoured: no   bounded reads match unmirrored: yes
STRING_TO_INT            honoured: no   bounded reads match unmirrored: yes

-- slice() equivalence under the mirror
STRING_TO_INT_HASH       flag inherited: yes  keys(lo,hi) === slice(lo,hi)->keys(): yes
STRING_TO_INT_ADAPTIVE   flag inherited: yes  keys(lo,hi) === slice(lo,hi)->keys(): yes

-- iteration is undisturbed by bounded reads under the mirror
bool(true)

-- fromArray() with the flag
bool(true)
array(5) {
  [0]=>
  string(2) "aa"
  [1]=>
  string(5) "apple"
  [2]=>
  string(12) "apricot_long"
  [3]=>
  string(6) "banana"
  [4]=>
  string(12) "blackcurrant"
}
array(3) {
  ["apple"]=>
  int(2)
  ["apricot_long"]=>
  int(3)
  ["banana"]=>
  int(4)
}
array(2) {
  [0]=>
  int(4)
  [1]=>
  int(5)
}
