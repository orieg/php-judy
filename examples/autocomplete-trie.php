<?php
/**
 * Prefix search / autocomplete with a sorted string-keyed Judy array.
 *
 * STRING_TO_MIXED is trie-based and keeps keys in lexicographic order,
 * so "all keys starting with $prefix" is a first() + searchNext() walk —
 * no full scan, no separate index structure.
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
 * Return every [key => value] whose key starts with $prefix.
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
