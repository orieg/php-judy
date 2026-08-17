<?php
/**
 * Prefix-scoped invalidation over namespaced keys ("user:123:*").
 *
 * Ordered string keys put every key sharing a prefix next to each other, so
 * dropping a namespace means walking that slice and nothing else: seek to the
 * prefix with first(), then searchNext() until the prefix stops matching.
 *
 * A hash table has no such adjacency. Invalidating a namespace there means
 * testing every key in the store, so the cost tracks total cache size rather
 * than the number of keys actually being dropped — the reason APCu's
 * regex-based invalidation scales linearly with the whole keyspace.
 *
 * This is the pattern behind orieg/judy-cache's deletePrefix().
 *
 * Run: php examples/prefix-invalidation.php
 */

if (!extension_loaded('judy')) {
    fwrite(STDERR, "The judy extension is not loaded.\n");
    exit(1);
}

/**
 * Delete every key starting with $prefix. Returns [deleted, keysVisited].
 *
 * The walk is written out longhand so that keys visited stays countable — that
 * count is what this example exists to show. In production the same deletion is
 * one call, deleteRange($start, $end), over the same inclusive key bounds.
 *
 * @return array{0:int,1:int}
 */
function deletePrefix(Judy $store, string $prefix): array
{
    $deleted = 0;
    $visited = 0;

    for (
        $key = $store->first($prefix);                       // seek straight to the slice
        $key !== null && str_starts_with($key, $prefix);     // stop at the first non-match
        $key = $store->searchNext($key)
    ) {
        $visited++;
        unset($store[$key]);
        $deleted++;
    }

    return [$deleted, $visited + 1]; // +1 for the key that ended the walk
}

/** The same operation on a hash table: every key must be tested. */
function deletePrefixArray(array &$store, string $prefix): array
{
    $deleted = 0;
    $visited = 0;

    foreach (array_keys($store) as $key) {
        $visited++;
        if (str_starts_with($key, $prefix)) {
            unset($store[$key]);
            $deleted++;
        }
    }

    return [$deleted, $visited];
}

// Build a cache holding many tenants, one of which we will invalidate.
const TENANTS      = 2000;
const KEYS_PER_USER = 5;

$judy  = new Judy(Judy::STRING_TO_MIXED);
$array = [];

for ($u = 0; $u < TENANTS; $u++) {
    foreach (['profile', 'settings', 'avatar', 'sessions', 'flags'] as $field) {
        $key = sprintf('user:%d:%s', $u, $field);
        $judy[$key] = $field;
        $array[$key] = $field;
    }
}

printf("cache holds %d keys across %d tenants\n\n", count($judy), TENANTS);

// Invalidate a single tenant's namespace.
$prefix = 'user:1234:';

[$jDeleted, $jVisited] = deletePrefix($judy, $prefix);
[$aDeleted, $aVisited] = deletePrefixArray($array, $prefix);

printf("invalidating %s\n", $prefix);
printf("  Judy   deleted=%d  keys visited=%d\n", $jDeleted, $jVisited);
printf("  array  deleted=%d  keys visited=%d\n", $aDeleted, $aVisited);
printf(
    "\nJudy touched %.2f%% of the store; the hash table touched 100%%.\n",
    $jVisited / (TENANTS * KEYS_PER_USER) * 100,
);
echo "That ratio is the whole point: Judy's cost follows the slice being\n";
echo "dropped, the hash table's follows the cache size.\n";

// Range reads work the same way — list a namespace without scanning. Here the
// consumer wants the whole slice rather than the first few, so this is one
// bounded bulk read instead of the per-element walk above: keys($start, $end)
// makes a single C traversal writing straight into the PHP array.
//
// The bounds are inclusive KEYS, so the upper one is the prefix's successor
// ('user:7;' — ':' is 0x3A, ';' is 0x3B), not the prefix itself. That bound can
// over-reach by exactly one key, the one spelled like the bound. Deriving it
// safely for arbitrary binary keys is examples/symbol-table-prefix.php.
$listed = $judy->keys('user:7:', 'user:7;');
printf("\nkeys under user:7: -> %s\n", implode(', ', $listed));
