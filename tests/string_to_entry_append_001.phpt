--TEST--
Judy STRING_TO_ENTRY rejects $j[] = (append without key) — segfault guard
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Pre-fix: this fell through to Z_STRVAL_P(NULL) and crashed with SIGSEGV
 * (exit 139) — the append guard enumerated 6 of the 7 string-keyed types
 * and omitted TYPE_STRING_TO_ENTRY. */
$judy = new Judy(Judy::STRING_TO_ENTRY);
try {
    $judy[] = 1;
} catch (\Exception $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECT--
Caught: Judy STRING_TO_INT, STRING_TO_MIXED, STRING_TO_MIXED_HASH, STRING_TO_INT_HASH, STRING_TO_MIXED_ADAPTIVE, STRING_TO_INT_ADAPTIVE and STRING_TO_ENTRY values cannot be set without specifying a key
Done
