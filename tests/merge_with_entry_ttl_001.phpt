--TEST--
mergeWith() preserves STRING_TO_ENTRY expires_at/flags verbatim (Step 3.6)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* mergeWith() validated only key category and wrote through the generic write
 * helper, which zeroes expires_at/flags on every ENTRY write — so a merge
 * carried values but silently stripped the source entry's TTL and flags
 * (verified pre-fix: keys merged from the source reported expires_at=0,
 * flags=0 against the source's 456/5 and 789/9). Gate-1 ruling (d):
 * mergeWith() copies TTL/flags verbatim, the same faithful copy slice()
 * makes. The fix routes ENTRY -> ENTRY merges through a dedicated helper
 * (judy_write_entry_verbatim) that copies the struct directly — the value is
 * addref'd before any destructor can run, so an overwritten target value's
 * destructor re-entrancy cannot invalidate the source — instead of
 * materialising the value through the generic helper.
 *
 * The assertion trap (spelled out at Gate 1): assert getEntry() on the key
 * that came FROM the other array. The target's pre-existing entry keeps its
 * own TTL/flags, so asserting it passes vacuously — the stripped metadata is
 * only visible on copied keys.
 *
 * expires_at is a wall-clock timestamp, so verbatim-ness is asserted by
 * comparing the target's value against the source's own getEntry() result,
 * not against a literal number.
 */

$target = new Judy(Judy::STRING_TO_ENTRY);
$target->set('shared', 'target-value', ttl: 111, flags: 7);
$target->set('pre-existing', 'pt', ttl: 333, flags: 3);

$source = new Judy(Judy::STRING_TO_ENTRY);
$source->set('from-source', 'sv', ttl: 456, flags: 5);
$source->set('shared', 'source-value', ttl: 789, flags: 9);

$src_copy  = $source->getEntry('from-source');
$src_merge = $source->getEntry('shared');

$target->mergeWith($source);

$after_copy  = $target->getEntry('from-source');
$after_merge = $target->getEntry('shared');
$after_keep  = $target->getEntry('pre-existing');

echo 'copied key value: '            . var_export($after_copy['value'], true) . "\n";
echo 'copied key expires_at verbatim: ' . ($after_copy['expires_at'] === $src_copy['expires_at'] ? 'yes' : 'no') . "\n";
echo 'copied key flags verbatim: '      . ($after_copy['flags'] === $src_copy['flags'] ? 'yes' : 'no') . "\n";
echo 'copied key not expired: '         . ($after_copy['is_expired'] === false ? 'yes' : 'no') . "\n";

echo 'overwritten key value: '            . var_export($after_merge['value'], true) . "\n";
echo 'overwritten key expires_at verbatim: ' . ($after_merge['expires_at'] === $src_merge['expires_at'] ? 'yes' : 'no') . "\n";
echo 'overwritten key flags verbatim: '      . ($after_merge['flags'] === $src_merge['flags'] ? 'yes' : 'no') . "\n";

echo 'target pre-existing value untouched: ' . var_export($after_keep['value'], true) . "\n";
echo 'target pre-existing flags kept: '      . ($after_keep['flags'] === 3 ? 'yes' : 'no') . "\n";

echo 'count: ' . count($target) . "\n";
echo 'keys: '  . json_encode($target->keys()) . "\n";

/* A plain (non-ENTRY) source merged into an ENTRY target still creates fresh
 * entries with default metadata, exactly like $j[$k] = $v. */
$plain = new Judy(Judy::STRING_TO_INT);
$plain['plain'] = 42;
$t = new Judy(Judy::STRING_TO_ENTRY);
$t->mergeWith($plain);
$e = $t->getEntry('plain');
echo 'plain->entry value: ' . var_export($e['value'], true) . "\n";
echo 'plain->entry default ttl/flags: ' . ($e['expires_at'] === 0 && $e['flags'] === 0 ? 'yes' : 'no') . "\n";

/* An expired source entry is copied verbatim: raw getEntry() shows the
 * metadata (is_expired === true), while value-serving reads hide it
 * (get() === NULL, keys() omits it) — the same visibility the source has. */
$exp = new Judy(Judy::STRING_TO_ENTRY);
$exp->set('gone', 'gv', ttl: -2, flags: 5);
$t2 = new Judy(Judy::STRING_TO_ENTRY);
$t2->mergeWith($exp);
$e2 = $t2->getEntry('gone');
echo 'expired copied entry visible in getEntry: ' . ($e2['is_expired'] === true ? 'yes' : 'no') . "\n";
echo 'expired copied entry hidden in get(): '     . var_export($t2->get('gone'), true) . "\n";
echo 'expired copied entry hidden in keys(): '    . json_encode($t2->keys()) . "\n";
?>
--EXPECT--
copied key value: 'sv'
copied key expires_at verbatim: yes
copied key flags verbatim: yes
copied key not expired: yes
overwritten key value: 'source-value'
overwritten key expires_at verbatim: yes
overwritten key flags verbatim: yes
target pre-existing value untouched: 'pt'
target pre-existing flags kept: yes
count: 3
keys: ["from-source","pre-existing","shared"]
plain->entry value: 42
plain->entry default ttl/flags: yes
expired copied entry visible in getEntry: yes
expired copied entry hidden in get(): NULL
expired copied entry hidden in keys(): []