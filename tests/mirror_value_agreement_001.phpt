--TEST--
Judy *_HASH / *_ADAPTIVE: ordered traversal and point lookup agree after mixed writes, mirrored and not
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
// With optimizeIteration on, STRING_TO_INT_HASH and long-keyed
// STRING_TO_INT_ADAPTIVE mirror their payload into the key_index slot, so
// ordered traversal reads it from there while point lookup still reads the
// value store. Every ordered read surface is therefore cross-examined against
// point lookup, not just foreach: they are separate call sites and a mirror is
// only as good as the site that reads it.
//
// Each type runs twice, off and on. The off runs are the ones that pin "the
// default is exactly what it was": they must produce identical output while
// isIterationOptimized() reports false. The on runs on a _MIXED type pin the
// accept-and-ignore rule — the argument is taken and dropped.
//
// ADAPTIVE keys are split deliberately across the 8-byte SSO boundary, since
// short and long keys land in different value stores.
$types = [];
foreach ([
    'STRING_TO_INT_HASH'       => Judy::STRING_TO_INT_HASH,
    'STRING_TO_MIXED_HASH'     => Judy::STRING_TO_MIXED_HASH,
    'STRING_TO_INT_ADAPTIVE'   => Judy::STRING_TO_INT_ADAPTIVE,
    'STRING_TO_MIXED_ADAPTIVE' => Judy::STRING_TO_MIXED_ADAPTIVE,
] as $n => $t) {
    $types["$n off"] = [$t, false];
    $types["$n on"]  = [$t, true];
}

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
    if ($j->toArray() !== $seen) {
        $problems[] = "toArray() differs from foreach";
    }

    // Each of these is its own ordered-traversal call site in C, and each one
    // reads the mirrored payload independently. keys() is the control: it
    // never touches a value, so a keys()/values() mismatch localises the fault
    // to the value read rather than the walk.
    $ks = $j->keys();
    $vs = $j->values();
    if (count($ks) !== count($vs) || array_combine($ks, $vs) !== $seen) {
        $problems[] = "keys()/values() differ from foreach";
    }
    if ($ks && $j->getAll($ks) !== $seen) {
        $problems[] = "getAll() differs from foreach";
    }

    $viaCallback = [];
    $j->forEach(function ($v, $k) use (&$viaCallback) { $viaCallback[$k] = $v; });
    if ($viaCallback !== $seen) {
        $problems[] = "forEach() differs from foreach";
    }

    // The Iterator-interface methods are a separate walk from the one the
    // foreach opcode drives.
    $manual = [];
    for ($j->rewind(); $j->valid(); $j->next()) {
        $manual[$j->key()] = $j->current();
    }
    if ($manual !== $seen) {
        $problems[] = "rewind()/next() walk differs from foreach";
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

foreach ($types as $name => [$type, $opt]) {
    $mixed = str_contains($name, 'MIXED');
    $j = new Judy($type, $opt);
    // What the request actually bought: true only where the type can honour it.
    echo "$name optimized: ", var_export($j->isIterationOptimized(), true), "\n";
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

    // 7b. the setting itself has to survive both, or the copy would iterate
    //     at a different speed than the original with no way to tell.
    echo "$name derived optimized: ",
        ($c->isIterationOptimized() === $j->isIterationOptimized()
            && $u->isIterationOptimized() === $j->isIterationOptimized()
            ? "match" : "DIVERGED"), "\n";

    // 8. mutating the clone must not disturb the original
    $c["only_on_clone"] = $mixed ? "x" : 42;
    check("$name original after clone write", $j, $model);
}
?>
--EXPECT--
STRING_TO_INT_HASH off optimized: false
STRING_TO_INT_HASH off insert: ok
STRING_TO_INT_HASH off overwrite: ok
STRING_TO_INT_HASH off increment: ok
STRING_TO_INT_HASH off putAll: ok
STRING_TO_INT_HASH off unset: ok
STRING_TO_INT_HASH off reinsert: ok
STRING_TO_INT_HASH off clone: ok
STRING_TO_INT_HASH off unserialize: ok
STRING_TO_INT_HASH off derived optimized: match
STRING_TO_INT_HASH off original after clone write: ok
STRING_TO_INT_HASH on optimized: true
STRING_TO_INT_HASH on insert: ok
STRING_TO_INT_HASH on overwrite: ok
STRING_TO_INT_HASH on increment: ok
STRING_TO_INT_HASH on putAll: ok
STRING_TO_INT_HASH on unset: ok
STRING_TO_INT_HASH on reinsert: ok
STRING_TO_INT_HASH on clone: ok
STRING_TO_INT_HASH on unserialize: ok
STRING_TO_INT_HASH on derived optimized: match
STRING_TO_INT_HASH on original after clone write: ok
STRING_TO_MIXED_HASH off optimized: false
STRING_TO_MIXED_HASH off insert: ok
STRING_TO_MIXED_HASH off overwrite: ok
STRING_TO_MIXED_HASH off putAll: ok
STRING_TO_MIXED_HASH off unset: ok
STRING_TO_MIXED_HASH off reinsert: ok
STRING_TO_MIXED_HASH off clone: ok
STRING_TO_MIXED_HASH off unserialize: ok
STRING_TO_MIXED_HASH off derived optimized: match
STRING_TO_MIXED_HASH off original after clone write: ok
STRING_TO_MIXED_HASH on optimized: false
STRING_TO_MIXED_HASH on insert: ok
STRING_TO_MIXED_HASH on overwrite: ok
STRING_TO_MIXED_HASH on putAll: ok
STRING_TO_MIXED_HASH on unset: ok
STRING_TO_MIXED_HASH on reinsert: ok
STRING_TO_MIXED_HASH on clone: ok
STRING_TO_MIXED_HASH on unserialize: ok
STRING_TO_MIXED_HASH on derived optimized: match
STRING_TO_MIXED_HASH on original after clone write: ok
STRING_TO_INT_ADAPTIVE off optimized: false
STRING_TO_INT_ADAPTIVE off insert: ok
STRING_TO_INT_ADAPTIVE off overwrite: ok
STRING_TO_INT_ADAPTIVE off putAll: ok
STRING_TO_INT_ADAPTIVE off unset: ok
STRING_TO_INT_ADAPTIVE off reinsert: ok
STRING_TO_INT_ADAPTIVE off clone: ok
STRING_TO_INT_ADAPTIVE off unserialize: ok
STRING_TO_INT_ADAPTIVE off derived optimized: match
STRING_TO_INT_ADAPTIVE off original after clone write: ok
STRING_TO_INT_ADAPTIVE on optimized: true
STRING_TO_INT_ADAPTIVE on insert: ok
STRING_TO_INT_ADAPTIVE on overwrite: ok
STRING_TO_INT_ADAPTIVE on putAll: ok
STRING_TO_INT_ADAPTIVE on unset: ok
STRING_TO_INT_ADAPTIVE on reinsert: ok
STRING_TO_INT_ADAPTIVE on clone: ok
STRING_TO_INT_ADAPTIVE on unserialize: ok
STRING_TO_INT_ADAPTIVE on derived optimized: match
STRING_TO_INT_ADAPTIVE on original after clone write: ok
STRING_TO_MIXED_ADAPTIVE off optimized: false
STRING_TO_MIXED_ADAPTIVE off insert: ok
STRING_TO_MIXED_ADAPTIVE off overwrite: ok
STRING_TO_MIXED_ADAPTIVE off putAll: ok
STRING_TO_MIXED_ADAPTIVE off unset: ok
STRING_TO_MIXED_ADAPTIVE off reinsert: ok
STRING_TO_MIXED_ADAPTIVE off clone: ok
STRING_TO_MIXED_ADAPTIVE off unserialize: ok
STRING_TO_MIXED_ADAPTIVE off derived optimized: match
STRING_TO_MIXED_ADAPTIVE off original after clone write: ok
STRING_TO_MIXED_ADAPTIVE on optimized: false
STRING_TO_MIXED_ADAPTIVE on insert: ok
STRING_TO_MIXED_ADAPTIVE on overwrite: ok
STRING_TO_MIXED_ADAPTIVE on putAll: ok
STRING_TO_MIXED_ADAPTIVE on unset: ok
STRING_TO_MIXED_ADAPTIVE on reinsert: ok
STRING_TO_MIXED_ADAPTIVE on clone: ok
STRING_TO_MIXED_ADAPTIVE on unserialize: ok
STRING_TO_MIXED_ADAPTIVE on derived optimized: match
STRING_TO_MIXED_ADAPTIVE on original after clone write: ok
