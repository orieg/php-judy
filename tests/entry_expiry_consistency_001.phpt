--TEST--
Expired STRING_TO_ENTRY entries: value-serving traversals hide them, count() stays pre-prune
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Canonical ENTRY/expiry ruling (b) + (c): value-serving traversals
 * (foreach — both the zend_object_iterator and the manual Iterator methods —
 * toArray, keys, values, and the forEach/filter/map callbacks) hide expired
 * entries so they agree with get()/isset(); count()/size() stay raw-counter
 * O(1) and over-count until pruneExpired() runs.
 *
 * Layout puts expired entries BEFORE the first live key ('gone'), BETWEEN
 * live keys ('mex') and AFTER the last live key ('zzgone'), so the skip
 * logic must fire at the start, in the middle and at the end of every walk.
 * Pre-fix every traversal served the expired entries and got()/isset() hid
 * them — the inconsistency this step removes. */

$j = new Judy(Judy::STRING_TO_ENTRY);
$j->set('gone',  'g', -20, 5);  // expired (TTL 20s in the past)
$j['live1'] = 'a';
$j['live2'] = 'b';
$j->set('mex',   'm', -20, 6);  // expired, mid-walk
$j['live3'] = 'c';
$j->set('zzgone', 'z', -20, 7); // expired, past every live key

$now = time();

/* Point reads: expired -> NULL / false; live entries still served. */
echo 'get(gone)=' . json_encode($j->get('gone')) . "\n";
/* getExpiry() returns the stored timestamp for a present (even expired)
 * entry — null means "key does not exist" — so pin the type, not the
 * time-dependent value. */
echo 'getExpiry(gone) is int: ' . (is_int($j->getExpiry('gone')) ? 'yes' : 'no') . "\n";
echo 'isset(gone)=' . ($j->offsetExists('gone') ? 'yes' : 'no') . "\n";
echo 'get(live2)=' . json_encode($j->get('live2')) . "\n";
$e = $j->getEntry('live2');
echo 'getEntry(live2)[value]=' . json_encode($e['value']) . "\n";
echo 'getEntry(live2)[is_expired]=' . ($e['is_expired'] ? 'yes' : 'no') . "\n";
echo 'getEntry(live2)[flags]=' . $e['flags'] . "\n";

/* count() is pre-prune: all six stored entries, expired included. */
echo 'count before prune: ' . count($j) . "\n";

/* Value-serving traversals hide expired entries. */
echo 'keys=' . json_encode($j->keys()) . "\n";
echo 'toArray=' . json_encode($j->toArray()) . "\n";
echo 'values=' . json_encode($j->values()) . "\n";

$seen = [];
foreach ($j as $k => $v) { $seen[] = "$k=$v"; }
echo 'foreach=' . json_encode($seen) . "\n";

/* Manual Iterator interface (rewind/valid/next/key/current on the class). */
$manual = [];
for ($j->rewind(); $j->valid(); $j->next()) {
    $manual[] = $j->key() . '=' . $j->current();
}
echo 'manual=' . json_encode($manual) . "\n";

/* Callbacks must not see expired entries either. */
$visited = [];
$j->forEach(function ($v, $k) use (&$visited) { $visited[] = "$k=$v"; });
echo 'forEach=' . json_encode($visited) . "\n";

$filtered = $j->filter(fn ($v) => $v !== 'b');
echo 'filtered=' . json_encode(array_keys($filtered->toArray())) . "\n";

$mapped = $j->map(fn ($v) => strtoupper((string)$v));
echo 'mapped=' . json_encode($mapped->toArray()) . "\n";

/* pruneExpired() then everything agrees: count drops to the live three. */
$pruned = $j->pruneExpired($now);
echo 'pruned: ' . $pruned . "\n";
echo 'count after prune: ' . count($j) . "\n";
echo 'keys after prune: ' . json_encode($j->keys()) . "\n";
?>
--EXPECT--
get(gone)=null
getExpiry(gone) is int: yes
isset(gone)=no
get(live2)="b"
getEntry(live2)[value]="b"
getEntry(live2)[is_expired]=no
getEntry(live2)[flags]=0
count before prune: 6
keys=["live1","live2","live3"]
toArray={"live1":"a","live2":"b","live3":"c"}
values=["a","b","c"]
foreach=["live1=a","live2=b","live3=c"]
manual=["live1=a","live2=b","live3=c"]
forEach=["live1=a","live2=b","live3=c"]
filtered=["live1","live3"]
mapped={"live1":"A","live2":"B","live3":"C"}
pruned: 3
count after prune: 3
keys after prune: ["live1","live2","live3"]