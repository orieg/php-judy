<?php
/**
 * Prefix search / autocomplete with a sorted string-keyed Judy array.
 *
 * STRING_TO_MIXED is trie-based and keeps keys in lexicographic order,
 * so "all keys starting with $prefix" is one contiguous slice of the key
 * space — no full scan, no separate index structure.
 *
 * This file walks that slice with first() + searchNext() because completion
 * is the case where a walk beats a bounded bulk read: an editor dropdown
 * wants the first few matches and stops, and only a walk can stop. A bounded
 * read cannot — it materialises the whole slice, however large "ma" turns out
 * to be in a real dictionary.
 *
 * When you do want the whole slice, do NOT copy this shape. Reach for the
 * range-limited bulk extractors, which make one C traversal writing straight
 * into the PHP array instead of crossing back per element:
 *
 *     $judy->keys($lo, $hi);      $judy->values($lo, $hi);
 *     $judy->toArray($lo, $hi);
 *
 * The bounds are a pair of inclusive KEYS, and deriving them from a prefix
 * correctly (binary-safe, with the carry and over-reach cases) is the subject
 * of examples/symbol-table-prefix.php, which also counts what each shape costs.
 *
 * Run: php examples/autocomplete-trie.php [prefix]
 */

if (!extension_loaded('judy')) {
    fwrite(STDERR, "The judy extension is not loaded.\n");
    exit(1);
}

$prefix = $argv[1] ?? 'ma';

$dictionary = new Judy(Judy::STRING_TO_MIXED);
foreach (
    [
        'magma' => 1187, 'magnet' => 907, 'magnitude' => 543,
        'mango' => 2210, 'manifest' => 801, 'map' => 5120,
        'maple' => 640, 'marble' => 380, 'mars' => 1500,
        'php' => 9001, 'judy' => 4242, 'sparse' => 77,
    ] as $word => $frequency
) {
    $dictionary[$word] = $frequency;
}

/**
 * Return at most $limit [key => value] pairs whose key starts with $prefix.
 *
 * The $limit is what justifies the walk: the loop stops as soon as the
 * dropdown is full, so a prefix matching ten thousand words still costs ten
 * steps. Drop the limit and this should become one keys($lo, $hi) call.
 */
function prefixSearch(Judy $sorted, string $prefix, int $limit = 10): array
{
    $results = [];
    // first() is an inclusive search: the smallest key >= $prefix.
    for ($key = $sorted->first($prefix);
         $key !== null && str_starts_with($key, $prefix) && count($results) < $limit;
         $key = $sorted->searchNext($key)) {
        $results[$key] = $sorted[$key];
    }
    return $results;
}

echo "completions for '$prefix':\n";
foreach (prefixSearch($dictionary, $prefix) as $word => $frequency) {
    printf("  %-12s (frequency %d)\n", $word, $frequency);
}
