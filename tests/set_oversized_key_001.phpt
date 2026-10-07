--TEST--
Judy::set() rejects oversized keys like increment() and offsetSet() do
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Pre-fix: set() checked for NUL bytes but never enforced
 * PHP_JUDY_MAX_LENGTH, while both sibling writers do (offsetSet in
 * judy_object_write_dimension_helper, increment()). A 70 000-byte key was
 * stored, and the next ordered traversal (keys()/toArray()/first()) seeds
 * the fixed 64 KB intern->key_scratch from JSLF, which copies the found key
 * back unbounded — a heap overflow. The fix rejects oversized keys at the
 * gate, before anything is stored, with increment()'s exact message. */
$j = new Judy(Judy::STRING_TO_ENTRY);
$big = str_repeat("x", 70000);

try {
    $j->set($big, "v");
    echo "set() accepted oversized key\n";
} catch (\Throwable $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

var_dump(count($j));

// Nothing was stored, so every traversal is empty — and, being empty, never
// copies the oversized key into key_scratch.
var_dump($j->keys());
var_dump($j->toArray());
var_dump($j->first());

// The same cap still admits a key just under the limit.
$ok = str_repeat("y", 65535);
$j->set($ok, "v");
var_dump(count($j));

echo "Done\n";
?>
--EXPECT--
Caught: Judy string key length (70000) exceeds maximum of 65535 bytes
int(0)
array(0) {
}
array(0) {
}
NULL
int(1)
Done
