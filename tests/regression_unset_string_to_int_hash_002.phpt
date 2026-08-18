--TEST--
Judy STRING_TO_INT_HASH: unset() over an adversarial key corpus and under collision churn
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
/* The JudyHS hash is length-sensitive and byte-sensitive, so the unset path
 * has to be exercised with more than ASCII words: high bytes, the empty key,
 * one-byte keys, keys long enough to leave the short-key fast paths, and
 * numeric-looking strings. Values are asserted by type as well as by content
 * so a silently coerced key cannot pass. */

$corpus = [
    "empty"     => "",
    "one"       => "a",
    "two"       => "\x00",          /* NOT a key: embedded NUL, must throw */
    "highff"    => "\xff",
    "highmix"   => "\x80\xfe\x01\xff",
    "numeric"   => "42",
    "numzero"   => "07",
    "negzero"   => "-0",
    "space"     => " 42",
    "long256"   => str_repeat("L", 256),
    "long4096"  => str_repeat("x", 4096),
    "prefix"    => "ab",
    "prefixlen" => "abc",
];

foreach ([false, true] as $opt) {
    echo "--- optimizeIteration=", var_export($opt, true), " ---\n";
    $j = new Judy(Judy::STRING_TO_INT_HASH, optimizeIteration: $opt);

    /* An embedded NUL is refused on unset just as on any other key-taking
     * method — the validation sits ahead of the branch that was changed. */
    $j["seed"] = 0;
    try {
        unset($j["a\0b"]);
        echo "NUL: no exception\n";
    } catch (Throwable $e) {
        echo "NUL: ", get_class($e), ": ", $e->getMessage(), "\n";
    }
    echo "NUL left count=", count($j), "\n";
    unset($j["seed"]);

    $n = 0;
    foreach ($corpus as $label => $key) {
        if ($label === "two") {
            continue;
        }
        $j[$key] = ++$n;
    }
    $filled = count($j);
    $filled_mem = $j->memoryUsage();
    echo "filled count=", $filled, "\n";

    /* Absent keys that are near-misses of stored ones: same prefix, same
     * length-minus-one, same bytes with one flipped. None may move anything. */
    $near = ["abd", "abcd", "b", "\xfe", "\x80\xfe\x01\xfd", "43", "7",
             str_repeat("L", 255), str_repeat("L", 257), str_repeat("x", 4095)];
    foreach ($near as $probe) {
        unset($j[$probe]);
    }
    echo "after near-miss unsets: count=", count($j),
         " unchanged=", var_export(count($j) === $filled, true),
         " mem_unchanged=", var_export($j->memoryUsage() === $filled_mem, true), "\n";

    /* Remove each corpus key one at a time; the counter must fall by exactly
     * one per removal, the key must leave both stores, and a repeat removal
     * must be a no-op. */
    $expect = $filled;
    $bad = [];
    foreach ($corpus as $label => $key) {
        if ($label === "two") {
            continue;
        }
        unset($j[$key]);
        $expect--;
        if (count($j) !== $expect) {
            $bad[] = "$label: counter " . count($j) . " != $expect";
        }
        if (isset($j[$key])) {
            $bad[] = "$label: still in value store";
        }
        if (in_array($key, $j->keys(), true)) {
            $bad[] = "$label: still in key_index";
        }
        unset($j[$key]);          /* repeat: absent case */
        if (count($j) !== $expect) {
            $bad[] = "$label: repeat unset moved the counter";
        }
    }
    echo "one-at-a-time: count=", count($j), " problems=",
         ($bad === [] ? "none" : implode("; ", $bad)), "\n";
    echo "drained mem=", $j->memoryUsage(), " keys=", count($j->keys()), "\n";

    /* Collision churn: many same-length keys (which is what drives JudyHS
     * into its per-length collision trees), repeatedly unset and reinserted,
     * interleaved with unsets of keys that are not there. */
    $churn = new Judy(Judy::STRING_TO_INT_HASH, optimizeIteration: $opt);
    for ($i = 0; $i < 512; $i++) {
        $churn[sprintf("k%07d", $i)] = $i;
    }
    for ($round = 0; $round < 3; $round++) {
        for ($i = 0; $i < 512; $i += 2) {
            unset($churn[sprintf("k%07d", $i)]);
            unset($churn[sprintf("absent%03d", $i)]);   /* never inserted */
        }
        if (count($churn) !== 256) {
            echo "churn round $round: count=", count($churn), " (expected 256)\n";
        }
        for ($i = 0; $i < 512; $i += 2) {
            $churn[sprintf("k%07d", $i)] = $i;
        }
        if (count($churn) !== 512) {
            echo "churn round $round: refill count=", count($churn), " (expected 512)\n";
        }
    }
    $keys = $churn->keys();
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    $values_ok = true;
    foreach ($keys as $k) {
        if ($churn[$k] !== (int)substr($k, 1)) {
            $values_ok = false;
            break;
        }
    }
    echo "churn: count=", count($churn), " keys=", count($keys),
         " ordered=", var_export($keys === $sorted, true),
         " values_ok=", var_export($values_ok, true), "\n";

    /* Full drain through unset only, then confirm the array is truly empty. */
    foreach ($churn->keys() as $k) {
        unset($churn[$k]);
    }
    echo "churn drained: count=", count($churn), " keys=", count($churn->keys()),
         " mem=", $churn->memoryUsage(), "\n";
}

echo "Done\n";
?>
--EXPECT--
--- optimizeIteration=false ---
NUL: Exception: Judy STRING_TO_INT_HASH keys must not contain embedded null bytes
NUL left count=1
filled count=12
after near-miss unsets: count=12 unchanged=true mem_unchanged=true
one-at-a-time: count=0 problems=none
drained mem=0 keys=0
churn: count=512 keys=512 ordered=true values_ok=true
churn drained: count=0 keys=0 mem=0
--- optimizeIteration=true ---
NUL: Exception: Judy STRING_TO_INT_HASH keys must not contain embedded null bytes
NUL left count=1
filled count=12
after near-miss unsets: count=12 unchanged=true mem_unchanged=true
one-at-a-time: count=0 problems=none
drained mem=0 keys=0
churn: count=512 keys=512 ordered=true values_ok=true
churn drained: count=0 keys=0 mem=0
Done
