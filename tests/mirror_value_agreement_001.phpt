--TEST--
Judy *_HASH / *_ADAPTIVE: ordered traversal and point lookup agree after mixed writes
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// The four key_index-backed types answer "what keys are there, in order?"
// from a JudySL index and "what is the value?" from a separate store. Any
// write path that updates one and not the other produces two valid pointers
// holding different answers — no crash, no leak, only a wrong result. This
// test pins the agreement across every write shape the extension has.
//
// ADAPTIVE keys are split deliberately across the 8-byte SSO boundary, since
// short and long keys land in different value stores.
$types = [
    'STRING_TO_INT_HASH'       => Judy::STRING_TO_INT_HASH,
    'STRING_TO_MIXED_HASH'     => Judy::STRING_TO_MIXED_HASH,
    'STRING_TO_INT_ADAPTIVE'   => Judy::STRING_TO_INT_ADAPTIVE,
    'STRING_TO_MIXED_ADAPTIVE' => Judy::STRING_TO_MIXED_ADAPTIVE,
];

// Keys straddling the SSO boundary: "k00".."k07" are 3 bytes, the "long_"
// ones are 8+.
function keys_for(int $n): array {
    $keys = [];
    for ($i = 0; $i < $n; $i++) {
        $keys[] = sprintf('k%02d', $i);
        $keys[] = sprintf('long_key_%04d', $i);
    }
    return $keys;
}

function check(string $label, Judy $j, array $expected): void {
    $seen = [];
    $problems = [];

    foreach ($j as $k => $v) {
        $seen[$k] = $v;
        // The value ordered traversal reports must be the value a point
        // lookup reports for the same key.
        if ($j[$k] !== $v) {
            $problems[] = "traversal/lookup disagree at '$k': " .
                var_export($v, true) . " vs " . var_export($j[$k], true);
        }
    }

    if (count($seen) !== $j->count()) {
        $problems[] = "count() " . $j->count() . " != iterated " . count($seen);
    }
    if (array_keys($seen) !== array_keys($j->toArray())) {
        $problems[] = "toArray() key set differs from foreach";
    }

    $sorted = array_keys($seen);
    $want = $sorted;
    sort($want, SORT_STRING);
    if ($sorted !== $want) {
        $problems[] = "keys not in sorted order";
    }

    ksort($expected, SORT_STRING);
    if ($seen !== $expected) {
        $problems[] = "contents differ from the model";
    }

    echo $label . ": " . ($problems ? implode("; ", $problems) : "ok") . "\n";
}

foreach ($types as $name => $type) {
    $mixed = str_contains($name, 'MIXED');
    $j = new Judy($type);
    $model = [];

    // 1. plain inserts
    foreach (keys_for(8) as $i => $k) {
        $v = $mixed ? "v$i" : $i;
        $j[$k] = $v;
        $model[$k] = $v;
    }
    check("$name insert", $j, $model);

    // 2. overwrite every key in place
    foreach (array_keys($model) as $i => $k) {
        $v = $mixed ? "over$i" : ($i + 1000);
        $j[$k] = $v;
        $model[$k] = $v;
    }
    check("$name overwrite", $j, $model);

    // 3. increment() where the type supports it — the one in-place mutator
    //    that is not offsetSet
    if (!$mixed && $type === Judy::STRING_TO_INT_HASH) {
        foreach (array_keys($model) as $k) {
            $model[$k] = $j->increment($k, 7);
        }
        check("$name increment", $j, $model);
    }

    // 4. putAll / fromArray over a mix of new and existing keys
    $batch = [];
    foreach (['k03', 'k99', 'long_key_0003', 'long_key_9999'] as $i => $k) {
        $batch[$k] = $mixed ? "batch$i" : ($i + 2000);
    }
    $j->putAll($batch);
    $model = array_merge($model, $batch);
    check("$name putAll", $j, $model);

    // 5. deletes, then re-insert some of the deleted keys
    foreach (['k00', 'k03', 'long_key_0000', 'long_key_9999'] as $k) {
        unset($j[$k]);
        unset($model[$k]);
    }
    check("$name unset", $j, $model);
    foreach (['k00', 'long_key_9999'] as $i => $k) {
        $v = $mixed ? "again$i" : ($i + 3000);
        $j[$k] = $v;
        $model[$k] = $v;
    }
    check("$name reinsert", $j, $model);

    // 6. clone must carry both stores across intact
    $c = clone $j;
    check("$name clone", $c, $model);

    // 7. serialize round trip
    $u = unserialize(serialize($j));
    check("$name unserialize", $u, $model);

    // 8. mutating the clone must not disturb the original
    $c["only_on_clone"] = $mixed ? "x" : 42;
    check("$name original after clone write", $j, $model);
}
?>
--EXPECT--
STRING_TO_INT_HASH insert: ok
STRING_TO_INT_HASH overwrite: ok
STRING_TO_INT_HASH increment: ok
STRING_TO_INT_HASH putAll: ok
STRING_TO_INT_HASH unset: ok
STRING_TO_INT_HASH reinsert: ok
STRING_TO_INT_HASH clone: ok
STRING_TO_INT_HASH unserialize: ok
STRING_TO_INT_HASH original after clone write: ok
STRING_TO_MIXED_HASH insert: ok
STRING_TO_MIXED_HASH overwrite: ok
STRING_TO_MIXED_HASH putAll: ok
STRING_TO_MIXED_HASH unset: ok
STRING_TO_MIXED_HASH reinsert: ok
STRING_TO_MIXED_HASH clone: ok
STRING_TO_MIXED_HASH unserialize: ok
STRING_TO_MIXED_HASH original after clone write: ok
STRING_TO_INT_ADAPTIVE insert: ok
STRING_TO_INT_ADAPTIVE overwrite: ok
STRING_TO_INT_ADAPTIVE putAll: ok
STRING_TO_INT_ADAPTIVE unset: ok
STRING_TO_INT_ADAPTIVE reinsert: ok
STRING_TO_INT_ADAPTIVE clone: ok
STRING_TO_INT_ADAPTIVE unserialize: ok
STRING_TO_INT_ADAPTIVE original after clone write: ok
STRING_TO_MIXED_ADAPTIVE insert: ok
STRING_TO_MIXED_ADAPTIVE overwrite: ok
STRING_TO_MIXED_ADAPTIVE putAll: ok
STRING_TO_MIXED_ADAPTIVE unset: ok
STRING_TO_MIXED_ADAPTIVE reinsert: ok
STRING_TO_MIXED_ADAPTIVE clone: ok
STRING_TO_MIXED_ADAPTIVE unserialize: ok
STRING_TO_MIXED_ADAPTIVE original after clone write: ok