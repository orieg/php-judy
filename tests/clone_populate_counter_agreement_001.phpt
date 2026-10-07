--TEST--
Bulk-copy counter agreement: clone / fromArray / putAll keep counter == actual elements
--FILE--
<?php
/**
 * Step 3.4 regression net. The JERR (malloc failure) path itself is not
 * inducible from PHP, so this pins the invariant the fix protects: after
 * clone / fromArray / putAll complete, count() equals the number of keys
 * actually present, on every reachable type. A future regression that
 * swallows a failed insert (silently dropping an element while the counter
 * counts it) breaks this test. Under --enable-judy-debug-mirror the C-side
 * mirror asserts run on the same operations.
 */

$types = array(
    'BITSET'               => Judy::BITSET,
    'INT_TO_INT'           => Judy::INT_TO_INT,
    'INT_TO_MIXED'         => Judy::INT_TO_MIXED,
    'INT_TO_PACKED'        => Judy::INT_TO_PACKED,
    'STRING_TO_INT'        => Judy::STRING_TO_INT,
    'STRING_TO_MIXED'      => Judy::STRING_TO_MIXED,
    'STRING_TO_INT_HASH'   => Judy::STRING_TO_INT_HASH,
    'STRING_TO_MIXED_HASH' => Judy::STRING_TO_MIXED_HASH,
    'STRING_TO_INT_ADAPTIVE'   => Judy::STRING_TO_INT_ADAPTIVE,
    'STRING_TO_MIXED_ADAPTIVE' => Judy::STRING_TO_MIXED_ADAPTIVE,
);

/* Data per type: BITSET consumes a flat array of indices; the others key by
 * integer (integer-keyed types) or string (string-keyed types). */
$data = array(
    'BITSET' => array(3, 1, 20, 7),                      /* values are indices */
    'INT_TO_INT' => array(5 => 10, 3 => 30, 99 => 7),
    'INT_TO_MIXED' => array(5 => 'five', 3 => array('three'), 99 => 7.5),
    'INT_TO_PACKED' => array(5 => 10, 3 => 30, 99 => 7),
    'STRING_TO_INT' => array('alpha' => 1, 'beta' => 2, 'gamma' => 3),
    'STRING_TO_MIXED' => array('alpha' => 'one', 'beta' => array('two'), 'gamma' => 3),
    'STRING_TO_INT_HASH' => array('alpha' => 1, 'beta' => 2, 'gamma' => 3),
    'STRING_TO_MIXED_HASH' => array('alpha' => 'one', 'beta' => array('two'), 'gamma' => 3),
    'STRING_TO_INT_ADAPTIVE' => array('alpha' => 1, 'beta' => 2, 'gamma' => 3),
    'STRING_TO_MIXED_ADAPTIVE' => array('alpha' => 'one', 'beta' => array('two'), 'gamma' => 3),
);

foreach ($types as $name => $type) {
    $expected = count($data[$name]);

    /* fromArray: counter must equal the number of keys actually present. */
    $j = Judy::fromArray($type, $data[$name]);
    $c = count($j);
    $k = count($j->keys());
    if ($c !== $expected || $k !== $expected) {
        echo "FAIL $name fromArray: count=$c keys=$k expected=$expected\n";
    }

    /* clone: same population, same counter, same keys. */
    $c2 = clone $j;
    if (count($c2) !== $c || count($c2->keys()) !== $c || $c2->keys() !== $j->keys()) {
        echo "FAIL $name clone: count=", count($c2), " keys=", count($c2->keys()), "\n";
    }

    /* putAll onto a fresh instance: same agreement. */
    $p = new Judy($type);
    $p->putAll($data[$name]);
    if (count($p) !== $expected || count($p->keys()) !== $expected) {
        echo "FAIL $name putAll: count=", count($p), "\n";
    }

    /* A one-element array round-trips too (smallest non-empty shape). */
    $one = Judy::fromArray($type, array_slice($data[$name], 0, 1, true));
    if (count($one) !== 1 || count($one->keys()) !== 1) {
        echo "FAIL $name single-element fromArray: count=", count($one), "\n";
    }

    $j = null; $c2 = null; $p = null; $one = null;
}

/* STRING_TO_ENTRY: clone must carry TTL/flags verbatim and keep the counter
 * honest (canonical ruling (d) applies to mergeWith; clone already copies
 * entries wholesale through judy_object_clone). */
$e = new Judy(Judy::STRING_TO_ENTRY);
$e['plain'] = 'value';
$e->set('ttlkey', 42, ttl: 3600, flags: 42);
$ce = clone $e;
if (count($ce) !== 2 || count($ce->keys()) !== 2) {
    echo "FAIL ENTRY clone counter: count=", count($ce), "\n";
}
$ee = $ce->getEntry('ttlkey');
if ($ee === null || $ee['value'] !== 42 || $ee['flags'] !== 42
        || $ee['expires_at'] <= time() || $ee['is_expired'] !== false) {
    echo "FAIL ENTRY clone TTL/flags: ", var_export($ee, true), "\n";
}
$pe = new Judy(Judy::STRING_TO_ENTRY);
$pe->putAll(array('x' => 1, 'y' => 2));
if (count($pe) !== 2 || count($pe->keys()) !== 2) {
    echo "FAIL ENTRY putAll counter: count=", count($pe), "\n";
}

echo "done\n";
?>
--EXPECT--
done