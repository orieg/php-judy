--TEST--
deleteRange() frees the STRING_TO_ENTRY struct + value refcount (__destruct fires)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* deleteRange()'s plain-trie branch only captured the value zval for
 * STRING_TO_MIXED: for STRING_TO_ENTRY it ran JSLD (dropping the trie slot)
 * and never touched the judy_cache_entry_t behind it. That leaked the struct
 * AND the value's refcount — verified pre-fix: 1000 deleteRange() frees freed
 * 0 bytes while the same 1000 via unset() freed ~12 000, and __destruct never
 * fired. The fix matches unset()/pruneExpired(): capture the entry before
 * JSLD (delete-before-free, destructor re-entrancy), then
 * zval_ptr_dtor(&entry->value) + efree(entry) after.
 *
 * Primary assertion: __destruct fires for every deleted value.
 * Secondary: memory_get_usage() delta > 0 (allocator-sensitive but stable
 * here — deleteRange() does no other emalloc/free inside the window, and
 * libJudy's trie nodes go through malloc, invisible to both readings).
 * count()==0 + deleted==1000 pin that the deletes themselves still happen. */

class Victim {
    public static $fired = 0;
    public function __destruct() { self::$fired++; }
}

$j = new Judy(Judy::STRING_TO_ENTRY);
for ($i = 0; $i < 1000; $i++) {
    $j['k' . str_pad((string)$i, 4, '0', STR_PAD_LEFT)] = new Victim;
}
echo 'count before: ' . count($j) . "\n";

$before = memory_get_usage();
$deleted = $j->deleteRange('k0000', 'k0999');
$delta = $before - memory_get_usage();

echo "deleted: $deleted\n";
echo 'count after: ' . count($j) . "\n";
echo 'destruct fired: ' . Victim::$fired . "/1000\n";
echo 'memory freed > 0: ' . ($delta > 0 ? 'yes' : "no($delta)") . "\n";
?>
--EXPECT--
count before: 1000
deleted: 1000
count after: 0
destruct fired: 1000/1000
memory freed > 0: yes
