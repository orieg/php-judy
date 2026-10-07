--TEST--
pruneExpired() is safe against value-destructor re-entrancy, throwing
destructors, and miscounting (Steps 3.5)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Step 3.5 hardening of pruneExpired():

 * (1) The walk used the shared intern->key_scratch as its cursor. A value
 *     destructor that re-enters pruneExpired() (or any walk over the same
 *     scratch) mid-pass could in principle clobber the outer cursor. No
 *     failing pre-fix repro could be produced (the key is copied to a stack
 *     buffer before the destructor runs and the cursor is re-seeded from that
 *     copy), so the private-emalloc-cursor fix is hygiene — this test pins
 *     the post-fix contract: the outer walk still prunes every expired key
 *     exactly once, leaves live entries alone, and keeps counter == count().
 *
 * (2) A throwing value destructor must not strand the judy_cache_entry_t
 *     (pre-fix the struct was freed only after zval_ptr_dtor returned; the
 *     fix steals the value zval out of the struct, efree()s the struct
 *     BEFORE the destructor runs, then destroys the stolen zval). Observable
 *     contract: the exception propagates, the remaining expired entries are
 *     still pruned, live entries survive, and the array stays fully usable.
 *
 * (3) Removals are counted only when JSLD reports the key actually gone
 *     (Rc_int == 1) — the return value is the count of confirmed removals.
 *
 * (4) [REV-B, allocator-sensitive, secondary] A per-entry struct leak shows
 *     as unbounded growth in memory_get_usage(). Measured on PHP 8.5/macOS:
 *     the throwing path retains a fixed ~3.5 KB engine plateau (exception
 *     bookkeeping) that is flat from 300 to 3000 rounds, and the quiet path
 *     retains ~0. 1000 throwing rounds x 4 entries would leak
 *     >= 24 B x 4000 = 96 KB if the struct were stranded; the gates below
 *     (quiet < 16 KB, loud < 64 KB) fail on a strand with ~1.5x margin and
 *     pass the engine plateau with >15x headroom. Only yes/no is printed so
 *     the numeric values never pin allocator behaviour into --EXPECT--.
 */

/* (1) Re-entrancy contract. */
class Reenter {
    public static $j;
    public static $fired = 0;
    public function __destruct() {
        self::$fired++;
        /* Re-enter pruneExpired() over the same key span, then walk keys()
         * (which also uses the shared scratch), while the outer walk is
         * paused on this value's destructor. */
        try { self::$j->pruneExpired(); } catch (Throwable $e) {}
        self::$j->keys();
    }
}

$j = new Judy(Judy::STRING_TO_ENTRY);
Reenter::$j = $j;
for ($i = 0; $i < 20; $i++) {
    $j->set(sprintf('k%02d', $i), new Reenter, ttl: -50);
}
$j->set('z-live', 'value', ttl: 999999);

/* The outermost frame prunes exactly its own first key; each re-entrant
 * frame prunes what is left of the span (k01..k19), so the outer return is 1
 * and the total observable state is "everything expired gone, live intact". */
$pruned = $j->pruneExpired();
echo 'outer return == 1: '        . ($pruned === 1         ? 'yes' : 'no(' . $pruned . ')') . "\n";
echo 'count == 1: '               . (count($j) === 1       ? 'yes' : 'no(' . count($j) . ')') . "\n";
echo 'keys: '                     . json_encode($j->keys()) . "\n";
echo 'live value: '               . var_export($j->get('z-live'), true) . "\n";
echo 'every value destroyed once: ' . (Reenter::$fired === 20 ? 'yes' : 'no(' . Reenter::$fired . ')') . "\n";

/* (2) Throwing destructor in the middle of the walk. */
class Boom {
    public function __destruct() { throw new Exception('dtor boom'); }
}

$j2 = new Judy(Judy::STRING_TO_ENTRY);
$j2->set('a', 'plain', ttl: -1);
$j2->set('b', new Boom, ttl: -1);
$j2->set('c', 'plain', ttl: -1);
$j2->set('keep', 'x', ttl: 999999);

$thrown = null;
try {
    $j2->pruneExpired();
    $thrown = 'no throw';
} catch (Throwable $e) {
    $thrown = $e->getMessage();
}
echo 'exception propagated: ' . var_export($thrown, true) . "\n";
echo 'post-throw count == keys: ' . (count($j2) === count($j2->keys()) ? 'yes' : 'no') . "\n";
echo 'post-throw count: '      . count($j2) . "\n";
echo 'post-throw keys: '       . json_encode($j2->keys()) . "\n";
echo 'live survived: '         . var_export($j2->get('keep'), true) . "\n";

/* The array must stay fully usable after the throw: add a new expired entry
 * and prune again — accounting must stay consistent. */
$j2->set('late', 'v', ttl: -1);
$r2 = $j2->pruneExpired();
echo 'second prune == 1: ' . ($r2 === 1 ? 'yes' : 'no(' . $r2 . ')') . "\n";
echo 'final count == 1: '  . (count($j2) === 1 ? 'yes' : 'no(' . count($j2) . ')') . "\n";
echo 'final keys: '        . json_encode($j2->keys()) . "\n";

/* (3) No throw: return value counts exactly the confirmed removals. */
$j3 = new Judy(Judy::STRING_TO_ENTRY);
$j3->set('x1', 'v', ttl: -1);
$j3->set('x2', 'v', ttl: -1);
$j3->set('x3', 'v', ttl: -1);
$j3->set('x4', 'v', ttl: 100000);
echo 'quiet prune == 3: ' . ($j3->pruneExpired() === 3 ? 'yes' : 'no') . "\n";
echo 'quiet count == 1: ' . (count($j3) === 1 ? 'yes' : 'no(' . count($j3) . ')') . "\n";
echo 'quiet keys: '       . json_encode($j3->keys()) . "\n";

/* (4) [REV-B] Leak gate. Warm the allocator with quiet rounds first (bounded,
 *     typically 0), then measure throwing rounds against a fixed cap. */
class Quiet { public function __destruct() {} }

gc_collect_cycles();
$qbase = memory_get_usage();
for ($r = 0; $r < 40; $r++) {
    $jq = new Judy(Judy::STRING_TO_ENTRY);
    for ($i = 0; $i < 3; $i++) { $jq->set('q' . $i, new Quiet, ttl: -1); }
    $jq->pruneExpired();
    unset($jq);
}
gc_collect_cycles();
$quiet = memory_get_usage() - $qbase;

gc_collect_cycles();
$lbase = memory_get_usage();
for ($r = 0; $r < 1000; $r++) {
    $jl = new Judy(Judy::STRING_TO_ENTRY);
    for ($i = 0; $i < 4; $i++) { $jl->set('l' . $i, new Boom, ttl: -1); }
    try { $jl->pruneExpired(); } catch (Throwable $e) {}
    unset($jl);
}
gc_collect_cycles();
$loud = memory_get_usage() - $lbase;

echo 'quiet bounded (<16384): ' . ($quiet < 16384 ? 'yes' : 'no(' . $quiet . ')') . "\n";
echo 'loud bounded (<65536): '  . ($loud  < 65536 ? 'yes' : 'no(' . $loud  . ')') . "\n";
?>
--EXPECT--
outer return == 1: yes
count == 1: yes
keys: ["z-live"]
live value: 'value'
every value destroyed once: yes
exception propagated: 'dtor boom'
post-throw count == keys: yes
post-throw count: 1
post-throw keys: ["keep"]
live survived: 'x'
second prune == 1: yes
final count == 1: yes
final keys: ["keep"]
quiet prune == 3: yes
quiet count == 1: yes
quiet keys: ["x4"]
quiet bounded (<16384): yes
loud bounded (<65536): yes