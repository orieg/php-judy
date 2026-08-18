--TEST--
Judy mergeWith() - contents are exact for every type, with and without optimizeIteration
--DESCRIPTION--
Regression guard for issue #121: mergeWith() materialises each value from the
value slot its own cursor is already standing on instead of descending from the
root a second time. The conversion differs per type (packed representation,
refcounted zval*, plain word) and for the *_HASH / *_ADAPTIVE types the cursor
walks key_index, whose slot is only the value when optimizeIteration mirrored
the payload into it. This asserts the merged contents byte for byte on all ten
types, both mirror settings, short and long ADAPTIVE keys, and high-byte keys.
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
function fmt($v) {
    if (is_array($v)) return 'array' . json_encode($v);
    if (is_object($v)) return get_class($v) . json_encode(get_object_vars($v));
    // Keep the expectation block ASCII: high-byte payloads print as hex.
    if (is_string($v) && preg_match('/[^\\x20-\\x7e]/', $v)) return 'hex:' . bin2hex($v);
    return var_export($v, true);
}
function k($k) { return is_int($k) ? (string)$k : "'" . bin2hex($k) . "'"; }
function show(string $label, Judy $j): void {
    echo $label . " count=" . count($j) . " optimized=" . var_export($j->isIterationOptimized(), true) . "\n";
    foreach ($j->keys() as $key) {
        echo "  " . k($key) . " => " . fmt($j[$key]) . "\n";
    }
}

echo "=== BITSET ===\n";
$d = new Judy(Judy::BITSET); $d[1] = true; $d[2] = true;
$s = new Judy(Judy::BITSET); $s[2] = true; $s[9] = true;
$d->mergeWith($s); show("dst", $d); show("src", $s);

echo "=== INT_TO_INT ===\n";
$d = new Judy(Judy::INT_TO_INT); $d[1] = 10; $d[2] = 20;
$s = new Judy(Judy::INT_TO_INT); $s[2] = 222; $s[-1] = 7;
$d->mergeWith($s); show("dst", $d); show("src", $s);

echo "=== INT_TO_PACKED ===\n";
$d = new Judy(Judy::INT_TO_PACKED); $d[1] = 'keep'; $d[2] = 'gone';
$s = new Judy(Judy::INT_TO_PACKED);
$s[2] = 3.5; $s[3] = PHP_INT_MIN; $s[4] = true; $s[5] = false; $s[6] = null;
$s[7] = 'a string'; $s[8] = [1, 'x' => 2];
$d->mergeWith($s); show("dst", $d); show("src", $s);

echo "=== INT_TO_MIXED ===\n";
$d = new Judy(Judy::INT_TO_MIXED); $d[1] = 'keep'; $d[2] = 'gone';
$s = new Judy(Judy::INT_TO_MIXED); $s[2] = ['a' => 1]; $s[3] = (object)['p' => 9]; $s[4] = null; $s[5] = 1.25;
$d->mergeWith($s); show("dst", $d); show("src", $s);

foreach ([
    ['STRING_TO_INT', Judy::STRING_TO_INT, false],
    ['STRING_TO_MIXED', Judy::STRING_TO_MIXED, false],
    ['STRING_TO_INT_HASH', Judy::STRING_TO_INT_HASH, false],
    ['STRING_TO_INT_HASH opt', Judy::STRING_TO_INT_HASH, true],
    ['STRING_TO_MIXED_HASH', Judy::STRING_TO_MIXED_HASH, false],
    ['STRING_TO_MIXED_HASH opt', Judy::STRING_TO_MIXED_HASH, true],
    ['STRING_TO_INT_ADAPTIVE', Judy::STRING_TO_INT_ADAPTIVE, false],
    ['STRING_TO_INT_ADAPTIVE opt', Judy::STRING_TO_INT_ADAPTIVE, true],
    ['STRING_TO_MIXED_ADAPTIVE', Judy::STRING_TO_MIXED_ADAPTIVE, false],
    ['STRING_TO_MIXED_ADAPTIVE opt', Judy::STRING_TO_MIXED_ADAPTIVE, true],
] as [$name, $type, $opt]) {
    echo "=== $name ===\n";
    $mixed = str_contains($name, 'MIXED');
    $d = new Judy($type, optimizeIteration: $opt);
    $s = new Judy($type, optimizeIteration: $opt);
    // 'ab' is below the ADAPTIVE SSO boundary, the others are at or above it.
    $d['ab'] = $mixed ? 'keep-short' : 1;
    $d['a_long_key_here'] = $mixed ? 'keep-long' : 2;
    $d['zz'] = $mixed ? 'gone' : 3;
    $s['zz'] = $mixed ? ['ov' => 1] : 33;
    $s['a_much_longer_key'] = $mixed ? "\xff\xfe binary" : 44;
    $s["\xffhi"] = $mixed ? 5.5 : 55;
    $d->mergeWith($s);
    show("dst", $d);
    show("src", $s);
}

echo "=== empty source ===\n";
$d = new Judy(Judy::INT_TO_MIXED); $d[1] = 'x';
$d->mergeWith(new Judy(Judy::INT_TO_MIXED)); show("dst", $d);
$d = new Judy(Judy::STRING_TO_MIXED_HASH); $d['k'] = 'x';
$d->mergeWith(new Judy(Judy::STRING_TO_MIXED_HASH)); show("dst", $d);

echo "=== empty destination ===\n";
$s = new Judy(Judy::INT_TO_PACKED); $s[5] = 'v';
$d = new Judy(Judy::INT_TO_PACKED); $d->mergeWith($s); show("dst", $d);
$s = new Judy(Judy::STRING_TO_INT_ADAPTIVE, optimizeIteration: true); $s['aaaaaaaaaa'] = 1; $s['bb'] = 2;
$d = new Judy(Judy::STRING_TO_INT_ADAPTIVE, optimizeIteration: true); $d->mergeWith($s); show("dst", $d);

echo "=== self merge guard ===\n";
$d = new Judy(Judy::STRING_TO_MIXED); $d['a'] = 1;
$d->mergeWith($d); show("dst", $d);

echo "=== cross-type merge (source drives conversion) ===\n";
$d = new Judy(Judy::INT_TO_MIXED); $d[1] = 'x';
$s = new Judy(Judy::INT_TO_INT); $s[1] = 9; $s[2] = 8;
$d->mergeWith($s); show("dst", $d);
$d = new Judy(Judy::INT_TO_INT); $d[1] = 1;
$s = new Judy(Judy::BITSET); $s[3] = true;
$d->mergeWith($s); show("dst", $d);

echo "Done.\n";
?>
--EXPECT--
=== BITSET ===
dst count=3 optimized=false
  1 => true
  2 => true
  9 => true
src count=2 optimized=false
  2 => true
  9 => true
=== INT_TO_INT ===
dst count=3 optimized=false
  1 => 10
  2 => 222
  -1 => 7
src count=2 optimized=false
  2 => 222
  -1 => 7
=== INT_TO_PACKED ===
dst count=8 optimized=false
  1 => 'keep'
  2 => 3.5
  3 => -9223372036854775807-1
  4 => true
  5 => false
  6 => NULL
  7 => 'a string'
  8 => array{"0":1,"x":2}
src count=7 optimized=false
  2 => 3.5
  3 => -9223372036854775807-1
  4 => true
  5 => false
  6 => NULL
  7 => 'a string'
  8 => array{"0":1,"x":2}
=== INT_TO_MIXED ===
dst count=5 optimized=false
  1 => 'keep'
  2 => array{"a":1}
  3 => stdClass{"p":9}
  4 => NULL
  5 => 1.25
src count=4 optimized=false
  2 => array{"a":1}
  3 => stdClass{"p":9}
  4 => NULL
  5 => 1.25
=== STRING_TO_INT ===
dst count=5 optimized=false
  '615f6c6f6e675f6b65795f68657265' => 2
  '615f6d7563685f6c6f6e6765725f6b6579' => 44
  '6162' => 1
  '7a7a' => 33
  'ff6869' => 55
src count=3 optimized=false
  '615f6d7563685f6c6f6e6765725f6b6579' => 44
  '7a7a' => 33
  'ff6869' => 55
=== STRING_TO_MIXED ===
dst count=5 optimized=false
  '615f6c6f6e675f6b65795f68657265' => 'keep-long'
  '615f6d7563685f6c6f6e6765725f6b6579' => hex:fffe2062696e617279
  '6162' => 'keep-short'
  '7a7a' => array{"ov":1}
  'ff6869' => 5.5
src count=3 optimized=false
  '615f6d7563685f6c6f6e6765725f6b6579' => hex:fffe2062696e617279
  '7a7a' => array{"ov":1}
  'ff6869' => 5.5
=== STRING_TO_INT_HASH ===
dst count=5 optimized=false
  '615f6c6f6e675f6b65795f68657265' => 2
  '615f6d7563685f6c6f6e6765725f6b6579' => 44
  '6162' => 1
  '7a7a' => 33
  'ff6869' => 55
src count=3 optimized=false
  '615f6d7563685f6c6f6e6765725f6b6579' => 44
  '7a7a' => 33
  'ff6869' => 55
=== STRING_TO_INT_HASH opt ===
dst count=5 optimized=true
  '615f6c6f6e675f6b65795f68657265' => 2
  '615f6d7563685f6c6f6e6765725f6b6579' => 44
  '6162' => 1
  '7a7a' => 33
  'ff6869' => 55
src count=3 optimized=true
  '615f6d7563685f6c6f6e6765725f6b6579' => 44
  '7a7a' => 33
  'ff6869' => 55
=== STRING_TO_MIXED_HASH ===
dst count=5 optimized=false
  '615f6c6f6e675f6b65795f68657265' => 'keep-long'
  '615f6d7563685f6c6f6e6765725f6b6579' => hex:fffe2062696e617279
  '6162' => 'keep-short'
  '7a7a' => array{"ov":1}
  'ff6869' => 5.5
src count=3 optimized=false
  '615f6d7563685f6c6f6e6765725f6b6579' => hex:fffe2062696e617279
  '7a7a' => array{"ov":1}
  'ff6869' => 5.5
=== STRING_TO_MIXED_HASH opt ===
dst count=5 optimized=false
  '615f6c6f6e675f6b65795f68657265' => 'keep-long'
  '615f6d7563685f6c6f6e6765725f6b6579' => hex:fffe2062696e617279
  '6162' => 'keep-short'
  '7a7a' => array{"ov":1}
  'ff6869' => 5.5
src count=3 optimized=false
  '615f6d7563685f6c6f6e6765725f6b6579' => hex:fffe2062696e617279
  '7a7a' => array{"ov":1}
  'ff6869' => 5.5
=== STRING_TO_INT_ADAPTIVE ===
dst count=5 optimized=false
  '615f6c6f6e675f6b65795f68657265' => 2
  '615f6d7563685f6c6f6e6765725f6b6579' => 44
  '6162' => 1
  '7a7a' => 33
  'ff6869' => 55
src count=3 optimized=false
  '615f6d7563685f6c6f6e6765725f6b6579' => 44
  '7a7a' => 33
  'ff6869' => 55
=== STRING_TO_INT_ADAPTIVE opt ===
dst count=5 optimized=true
  '615f6c6f6e675f6b65795f68657265' => 2
  '615f6d7563685f6c6f6e6765725f6b6579' => 44
  '6162' => 1
  '7a7a' => 33
  'ff6869' => 55
src count=3 optimized=true
  '615f6d7563685f6c6f6e6765725f6b6579' => 44
  '7a7a' => 33
  'ff6869' => 55
=== STRING_TO_MIXED_ADAPTIVE ===
dst count=5 optimized=false
  '615f6c6f6e675f6b65795f68657265' => 'keep-long'
  '615f6d7563685f6c6f6e6765725f6b6579' => hex:fffe2062696e617279
  '6162' => 'keep-short'
  '7a7a' => array{"ov":1}
  'ff6869' => 5.5
src count=3 optimized=false
  '615f6d7563685f6c6f6e6765725f6b6579' => hex:fffe2062696e617279
  '7a7a' => array{"ov":1}
  'ff6869' => 5.5
=== STRING_TO_MIXED_ADAPTIVE opt ===
dst count=5 optimized=false
  '615f6c6f6e675f6b65795f68657265' => 'keep-long'
  '615f6d7563685f6c6f6e6765725f6b6579' => hex:fffe2062696e617279
  '6162' => 'keep-short'
  '7a7a' => array{"ov":1}
  'ff6869' => 5.5
src count=3 optimized=false
  '615f6d7563685f6c6f6e6765725f6b6579' => hex:fffe2062696e617279
  '7a7a' => array{"ov":1}
  'ff6869' => 5.5
=== empty source ===
dst count=1 optimized=false
  1 => 'x'
dst count=1 optimized=false
  '6b' => 'x'
=== empty destination ===
dst count=1 optimized=false
  5 => 'v'
dst count=2 optimized=true
  '61616161616161616161' => 1
  '6262' => 2
=== self merge guard ===
dst count=1 optimized=false
  '61' => 1
=== cross-type merge (source drives conversion) ===
dst count=2 optimized=false
  1 => 9
  2 => 8
dst count=2 optimized=false
  1 => 1
  3 => 1
Done.
