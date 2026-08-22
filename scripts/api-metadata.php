<?php
/**
 * Supplemental metadata for API.md generation.
 *
 * Parsed by scripts/generate-api-docs.php alongside Judy.stub.php.
 * The stub provides method signatures and PHPDoc descriptions.
 * This file provides everything else: type groupings, examples,
 * supported-type annotations, behavioral notes, and the compatibility matrix.
 */
/*
 * NOTE ON PRECEDENCE: generate-api-docs.php prefers a 'description' here over
 * the method's PHPDoc in Judy.stub.php, falling back to the stub only when no
 * description is set. A method listed here with a description therefore takes
 * ALL of its API.md prose from this file — editing its stub docblock changes
 * IDE/PHPStan output and nothing else. Add reader-facing warnings in both
 * places, and confirm API.md actually changed rather than trusting
 * `generate-api-docs.php --check`, which passes whenever the committed file
 * matches what regeneration produces.
 */
return [
    // ── Document header ─────────────────────────────────────────
    'title' => 'PHP Judy API Reference',
    'intro' => 'Complete API reference for the PHP Judy extension. For installation instructions and an introduction, see [README.md](README.md). For performance benchmarks, see [BENCHMARK.md](BENCHMARK.md).',

    // ── Type constant groupings ─────────────────────────────────
    'type_groups' => [
        'Integer-Keyed Types' => [
            'BITSET'        => ['value' => 1, 'description' => '1-bit per index, stores boolean presence',          'backing' => 'Judy1'],
            'INT_TO_INT'    => ['value' => 2, 'description' => 'Integer keys, integer values',                      'backing' => 'JudyL'],
            'INT_TO_MIXED'  => ['value' => 3, 'description' => 'Integer keys, mixed PHP values',                    'backing' => 'JudyL + heap zvals'],
            'INT_TO_PACKED' => ['value' => 6, 'description' => 'Integer keys, serialized values stored outside GC', 'backing' => 'JudyL + packed buffers'],
        ],
        'String-Keyed Types (Trie-Based)' => [
            'STRING_TO_INT'   => ['value' => 4, 'description' => 'String keys (sorted), integer values',   'backing' => 'JudySL'],
            'STRING_TO_MIXED' => ['value' => 5, 'description' => 'String keys (sorted), mixed PHP values', 'backing' => 'JudySL + heap zvals'],
        ],
        'String-Keyed Types (Hash-Based)' => [
            'STRING_TO_INT_HASH'   => ['value' => 8, 'description' => 'String keys (hash lookup), integer values',   'backing' => 'JudyHS + JudySL key index'],
            'STRING_TO_MIXED_HASH' => ['value' => 7, 'description' => 'String keys (hash lookup), mixed PHP values', 'backing' => 'JudyHS + JudySL key index'],
        ],
        'String-Keyed Types (Adaptive / SSO)' => [
            'STRING_TO_INT_ADAPTIVE'   => ['value' => 10, 'description' => 'Adaptive string keys, integer values',   'backing' => 'JudyL (SSO) + JudyHS + JudySL key index'],
            'STRING_TO_MIXED_ADAPTIVE' => ['value' => 9,  'description' => 'Adaptive string keys, mixed PHP values', 'backing' => 'JudyL (SSO) + JudyHS + JudySL key index'],
        ],
        'Cache & TTL Types' => [
            'STRING_TO_ENTRY' => ['value' => 11, 'description' => 'String keys (sorted), cache entries with TTL timestamp, flags, and mixed PHP values', 'backing' => 'JudySL + judy_cache_entry_t'],
        ],
    ],

    'type_group_notes' => [
        'String-Keyed Types (Hash-Based)' =>
            'Hash types use JudyHS for O(1) average-case lookups with a parallel JudySL index for sorted iteration.',
        'String-Keyed Types (Adaptive / SSO)' =>
            'Adaptive types use Short-String Optimization (SSO): keys of 7 bytes or fewer are packed into a 64-bit integer and stored in a JudyL array, avoiding hashing overhead. Longer keys fall back to JudyHS. Both maintain a JudySL key index for sorted iteration.',
    ],

    // ── Section definitions (controls API.md structure & ordering) ──
    // Each section lists its methods and optional intro/description text.
    // Methods are rendered with their stub PHPDoc as the primary description.
    // The 'description' key here is extra prose added AFTER the section heading.
    'sections' => [
        'Constructor' => [
            'methods' => ['__construct'],
        ],
        'Core Methods' => [
            'methods' => ['getType', 'isIterationOptimized', 'free', 'memoryUsage', 'size', 'count'],
        ],
        'Array Access' => [
            'methods' => ['offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset'],
            'description' => "Judy arrays implement PHP's `ArrayAccess` interface, so they can be used with standard array syntax.",
            'example' => <<<'PHP'
$judy = new Judy(Judy::INT_TO_INT);

// Write
$judy[0] = 42;

// Read
echo $judy[0]; // 42

// Check existence
if (isset($judy[0])) { /* ... */ }

// Delete
unset($judy[0]);
PHP,
            // Array Access methods are hidden individually; the section example covers them.
            'hide_individual_methods' => true,
        ],
        'Navigation' => [
            'methods' => ['first', 'searchNext', 'last', 'prev', 'firstEmpty', 'nextEmpty', 'lastEmpty', 'prevEmpty', 'byCount'],
            'description' => 'Methods for traversing the sorted key space. For string-keyed types, keys are sorted lexicographically.',
            // firstEmpty/nextEmpty/lastEmpty/prevEmpty are grouped together
            'method_groups' => [
                'firstEmpty' => [
                    'group_heading' => 'firstEmpty() / nextEmpty() / lastEmpty() / prevEmpty()',
                    'group_members' => ['firstEmpty', 'nextEmpty', 'lastEmpty', 'prevEmpty'],
                    'group_description' => 'Find empty (unset) indices. Only supported for integer-keyed types (BITSET, INT_TO_INT, INT_TO_MIXED, INT_TO_PACKED). Returns `null` for string-keyed types.',
                ],
            ],
        ],
        'Set Operations' => [
            'methods' => ['union', 'intersect', 'diff', 'xor', 'mergeWith'],
            'description' => "Set operations create a new Judy array from two arrays of the same type.\n\n**Supported types**: `BITSET`, `INT_TO_INT`, `STRING_TO_INT`, `STRING_TO_INT_HASH`\n\nBoth operands must be the same type. Throws an exception for unsupported or mismatched types.",
        ],
        'Range Operations' => [
            'methods' => ['slice', 'deleteRange', 'populationCount'],
            'description' => <<<'MD'
**Every range in this API is a pair of inclusive *keys*, never an offset and a length.**

Think `range($start, $end)`, not `array_slice($a, $offset, $length)`. PHP splits
the convention by data shape: offset/length suits *sequences* (`array_slice`,
`substr`), where positions are dense and meaningful; start/end suits *ordered
value domains* (`range()`, `DatePeriod`). A Judy array is the second kind — a
sparse ordered map — so `slice(5, 10)` means "keys 5 through 10 inclusive", and
returns nothing at all if no key in that span is set.

The distinction is not cosmetic, because both operations exist here and they are
different:

| Question | Method | How it works |
| --- | --- | --- |
| "the element at position N" | `byCount($nth_index)` | positional; counts elements |
| "elements with keys in [lo, hi]" | `slice`, `deleteRange`, `populationCount`, `size`, and the bounded forms of `keys`/`values`/`toArray` | key-space; seeks directly |

Key-space is where Judy is fast: bounding by key is a seek to the first key at or
above `$start` and then a walk, so reading a narrow range out of a huge array
costs the range, not the array. An offset-based equivalent would have to count
from the beginning to find where to start — which is exactly the work `byCount()`
does, and exactly the work the key-bounded methods exist to avoid.

Bounds are inclusive on both ends, an inverted range (`$start > $end`) yields
nothing rather than erroring, and string-keyed types compare bounds
lexicographically with `strcmp()`.
MD,
        ],
        'Batch Operations' => [
            'methods' => ['fromArray', 'toArray', 'putAll', 'getAll', 'keys', 'values', 'increment'],
        ],
        'Functional Methods' => [
            'methods' => ['forEach', 'filter', 'map'],
            'description' => 'These methods iterate in C, bypassing the PHP Iterator protocol overhead.',
        ],
        'Aggregation' => [
            'methods' => ['sumValues', 'averageValues'],
        ],
        'Comparison' => [
            'methods' => ['equals'],
        ],
        'Cache & TTL Operations' => [
            'methods' => ['set', 'get', 'pruneExpired', 'getEntry', 'getExpiry', 'getFlags'],
            'description' => "Methods for cache and TTL workloads using `Judy::STRING_TO_ENTRY`. These provide native in-C key expiration without userland looping.",
        ],
        'Serialization' => [
            'methods' => ['__serialize', '__unserialize', 'jsonSerialize'],
            // __serialize and __unserialize are grouped together
            'method_groups' => [
                '__serialize' => [
                    'group_heading' => '__serialize() / __unserialize()',
                    'group_members' => ['__serialize', '__unserialize'],
                    'group_description' => "PHP native serialization support. Judy arrays can be serialized with `serialize()` and restored with `unserialize()`.",
                ],
            ],
        ],
        'Iterator Interface' => [
            'methods' => ['rewind', 'valid', 'current', 'key', 'next'],
            'description' => "Judy arrays implement PHP's `Iterator` interface for use in `foreach` loops.",
            // Iterator section uses a single example + summary instead of individual method docs.
            'hide_individual_methods' => true,
            'example' => <<<'PHP'
$judy = Judy::fromArray(Judy::INT_TO_INT, [1 => 10, 5 => 50, 10 => 100]);

foreach ($judy as $key => $value) {
    echo "$key => $value\n";
}
PHP,
            'notes' => "Keys are iterated in sorted order (ascending integer order for integer-keyed types, lexicographic for string-keyed types).\n\nIndividual iterator methods: `rewind()`, `valid()`, `current()`, `key()`, `next()`.",
        ],
    ],

    // ── Per-method supplemental data ────────────────────────────
    // Keys here override or augment the stub's PHPDoc description.
    // 'description' replaces the stub description entirely.
    // 'extra_description' is appended after the stub description.
    // 'supported_types', 'notes', 'example' are rendered below the description.
    'methods' => [
        '__construct' => [
            'description' => "Creates a new Judy array of the specified type.\n\n"
                . "`\$optimizeIteration` trades write speed for ordered-read speed, for the life of that array. "
                . "With it on, ordered traversal reads each value out of the key index it is already walking instead "
                . "of looking it up a second time; in exchange every write updates both. Measured: `foreach` 24-38% "
                . "faster and `values()` 29-47% faster depending on key length, against 8-20% slower on overwrite and "
                . "on `increment()`. Turn it on for a read-dominated cache; leave it off for a counter-heavy workload. "
                . "It is inherited by every array derived from this one — `clone`, `slice()`, `filter()`, `map()`, the "
                . "set operations, serialization round-trips — and only `STRING_TO_INT_HASH` and `STRING_TO_INT_ADAPTIVE` "
                . "can honour it; see `isIterationOptimized()`.",
            'example' => <<<'PHP'
$judy = new Judy(Judy::INT_TO_INT);

// Read-dominated cache: pay on writes, save on every ordered read.
$cache = new Judy(Judy::STRING_TO_INT_HASH, optimizeIteration: true);
PHP,
        ],
        'getType' => [
            'description' => 'Returns the type constant of the Judy array.',
        ],
        'isIterationOptimized' => [
            'description' => 'Returns whether `optimizeIteration` is actually in effect on this array — what was honoured, not what was requested. Types that cannot honour it accept the constructor argument and return `false` here.',
            'table' => [
                'headers' => ['Type', 'Can honour `optimizeIteration`'],
                'rows' => [
                    ['STRING_TO_INT_HASH', 'yes'],
                    ['STRING_TO_INT_ADAPTIVE', 'yes, for keys of 8 bytes or more'],
                    ['All other types', 'no — argument accepted and ignored'],
                ],
            ],
            'example' => <<<'PHP'
$j = new Judy(Judy::STRING_TO_MIXED_HASH, optimizeIteration: true);
var_dump($j->isIterationOptimized()); // bool(false) — MIXED cannot mirror
PHP,
        ],
        'free' => [
            'description' => 'Frees all memory used by the Judy array and resets the element count. Returns the number of bytes freed (or 0 for string-keyed types).',
        ],
        'memoryUsage' => [
            'description' => "Returns the number of bytes used by the internal Judy structure. **The number means two different things depending on the key category, and callers must know which.** Integer-keyed types return libJudy's *exact* figure. String-keyed types return an *approximate* figure the extension maintains itself, because JudySL/JudyHS expose no accounting: it counts only the payload — stored key bytes (counted twice for the `_HASH` types, which hold each key in both the value store and the key index), one machine word per value slot, and the `zval` box allocated for each `_MIXED` value — and excludes everything libJudy allocates for its own trie and hash nodes. Treat it as a **lower bound**, good for tracking growth within one array, not for comparing against the exact figure. Both variants are O(1) and return `0` for a newly constructed or emptied array.",
            'table' => [
                'headers' => ['Supported Types', 'Returns'],
                'rows' => [
                    ['BITSET, INT_TO_INT, INT_TO_MIXED, INT_TO_PACKED', '`int` (bytes, exact — `Judy1MemUsed`/`JudyLMemUsed`)'],
                    ['All string-keyed types', '`int` (bytes, **approximate** — payload only, excludes libJudy node overhead)'],
                ],
            ],
        ],
        'size' => [
            'description' => 'Returns the number of elements. When called with arguments, returns the count of keys in the inclusive `[$start, $end]` range, where `null` leaves that side unbounded. The bounds are keys, not offsets — see [Range Operations](#range-operations). Unbounded, this is O(1) for every type; so is a bounded count on an integer-keyed type, which libJudy answers from its population cache. A bounded count on a string-keyed type walks the key index between the bounds — the cost of the range, not of the array, and cheaper than `count($judy->keys($start, $end))`, which builds the array first.',
            'supported_types' => 'All types. String-keyed types require string bounds and compare them lexicographically.',
            'example' => <<<'PHP'
$judy = Judy::fromArray(Judy::INT_TO_INT, [1 => 10, 5 => 50, 1000 => 100]);

$judy->size();          // 3
$judy->size(0, 100);    // 2  — keys 1 and 5; key 1000 is outside the range
$judy->size(0, -1);     // 3  — -1 is the maximum bound, so this is "everything"

$sym = new Judy(Judy::STRING_TO_MIXED);
$sym['App\Domain\Order'] = true;
$sym['App\Domain\Cart']  = true;
$sym['App\Http\Y']       = true;

// "How many classes are under this namespace?" — bound with the prefix and
// its successor, since an upper bound is a bound and not a prefix match.
$sym->size('App\Domain\\', 'App\Domain]');  // 2
PHP,
        ],
        'count' => [
            'description' => "Returns the number of elements. Implements PHP's `Countable` interface.",
            'example' => <<<'PHP'
$judy = new Judy(Judy::INT_TO_INT);
$judy[0] = 100;
$judy[1] = 200;
echo count($judy); // 2
PHP,
        ],
        'first' => [
            'description' => 'Returns the first index in the array. If `$index` is provided, returns the first index equal to or greater than `$index`.',
        ],
        'searchNext' => [
            'description' => 'Returns the next index strictly greater than `$index`.',
        ],
        'last' => [
            'description' => 'Returns the last index in the array. If `$index` is provided, returns the last index equal to or less than `$index`.',
        ],
        'prev' => [
            'description' => 'Returns the previous index strictly less than `$index`.',
        ],
        'byCount' => [
            'description' => 'Returns the index of the Nth element (1-based). Only supported for integer-keyed types. Returns `null` for string-keyed types.',
            'example' => <<<'PHP'
$judy = new Judy(Judy::INT_TO_INT);
$judy[10] = 100;
$judy[20] = 200;
$judy[30] = 300;
echo $judy->byCount(2); // 20 (second element)
PHP,
        ],
        'union' => [
            'description' => 'Returns a new Judy array containing all elements from both arrays. For integer-valued types, values from `$other` overwrite values in `$this` for duplicate keys.',
        ],
        'intersect' => [
            'description' => 'Returns a new Judy array containing only elements present in both arrays.',
        ],
        'diff' => [
            'description' => 'Returns a new Judy array containing elements in `$this` that are not in `$other`.',
        ],
        'xor' => [
            'description' => 'Returns a new Judy array containing elements in either array but not both (symmetric difference).',
        ],
        'mergeWith' => [
            'description' => 'Merges elements from `$other` into `$this` in-place. Both arrays must use the same key category (both integer-keyed or both string-keyed). Does not require the same type.',
            'supported_types' => 'All types. Throws exception only if mixing integer-keyed with string-keyed arrays.',
            'example' => <<<'PHP'
$a = new Judy(Judy::INT_TO_INT);
$a[0] = 1; $a[1] = 2;

$b = new Judy(Judy::INT_TO_INT);
$b[1] = 20; $b[2] = 30;

$a->mergeWith($b);
// $a now contains: [0 => 1, 1 => 20, 2 => 30]
PHP,
        ],
        'slice' => [
            'description' => 'Returns a new Judy array containing elements with keys in the range `[$start, $end]` (inclusive). For string-keyed types, `$start` and `$end` must be strings and comparison is lexicographic.',
            'supported_types' => 'All types.',
            'example' => <<<'PHP'
$judy = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < 100; $i++) $judy[$i] = $i * 10;

$slice = $judy->slice(10, 20);
// $slice contains keys 10..20
PHP,
        ],
        'deleteRange' => [
            'description' => 'Deletes all elements with keys in the range `[$start, $end]` (inclusive). Returns the number of elements deleted.',
            'supported_types' => 'All types.',
        ],
        'populationCount' => [
            'description' => "Returns the number of elements with keys in the range `[\$start, \$end]`. Uses Judy's internal population cache for O(1) counting, which is why it is integer-keyed only: JudySL and JudyHS keep no population count. For a ranged count on a string-keyed type, use [`size()`](#size), which walks the key index between the bounds.",
            'supported_types' => 'Integer-keyed types only (BITSET, INT_TO_INT, INT_TO_MIXED, INT_TO_PACKED). Throws exception for string-keyed types.',
        ],
        'fromArray' => [
            'description' => 'Creates a new Judy array from a PHP array.',
            'example' => '$judy = Judy::fromArray(Judy::INT_TO_INT, [0 => 100, 5 => 200, 10 => 300]);',
        ],
        'toArray' => [
            'description' => 'Converts the Judy array to a PHP array. Uses native C iteration internally, 2-3x faster than manual `foreach`. With `$start` and/or `$end`, only the inclusive `[$start, $end]` key range is returned; `null` leaves that side unbounded.'
                . "\n\n" . '**On string-keyed types, a key that looks like an integer comes back as one.** The result is a PHP array, and PHP array keys cannot hold the string `"42"` — a canonical decimal integer within `PHP_INT` range is coerced, so `"42"` and `"-7"` return as `int`, while `"07"`, `"-0"`, `" 42"`, `"4.0"` and out-of-range values stay strings. Feeding such a key straight back into the array throws `TypeError`, because a string-keyed Judy rejects an integer offset:'
                . "\n\n" . '```php' . "\n" . 'foreach ($judy->toArray() as $k => $v) { unset($judy[$k]); }  // TypeError' . "\n" . '```'
                . "\n\n" . '`keys()` does not coerce — it returns every key as a string. Use it when keys need to round-trip, or cast with `(string)` when iterating `toArray()`.',
            'supported_types' => 'All types. String-keyed types require string bounds and compare them lexicographically.',
            'example' => <<<'PHP'
$judy = Judy::fromArray(Judy::INT_TO_INT, [1 => 10, 5 => 50, 10 => 100]);

$judy->toArray();          // [1 => 10, 5 => 50, 10 => 100]
$judy->toArray(5, 10);     // [5 => 50, 10 => 100]
$judy->toArray(5);         // [5 => 50, 10 => 100]  (unbounded end)
$judy->toArray(null, 5);   // [1 => 10, 5 => 50]    (unbounded start)
PHP,
        ],
        'putAll' => [
            'description' => 'Bulk-inserts all key-value pairs from the given PHP array.',
        ],
        'getAll' => [
            'description' => 'Retrieves multiple values at once. Returns an associative array mapping each requested key to its value (or `null` if absent).',
            'example' => <<<'PHP'
$judy = Judy::fromArray(Judy::INT_TO_INT, [0 => 10, 1 => 20, 2 => 30]);
$values = $judy->getAll([0, 2, 99]);
// [0 => 10, 2 => 30, 99 => null]
PHP,
        ],
        'keys' => [
            'description' => "Returns all keys as a PHP array. With `\$start` and/or `\$end`, only the inclusive `[\$start, \$end]` key range is returned; `null` leaves that side unbounded.\n\nA bounded read is a single traversal writing straight into the returned array, so prefer it to `slice(\$lo, \$hi)->keys()`, which allocates and populates a whole intermediate Judy array and then traverses that copy.",
            'supported_types' => 'All types. String-keyed types require string bounds and compare them lexicographically.',
            'example' => <<<'PHP'
$judy = new Judy(Judy::BITSET);
foreach ([1, 5, 10, 15] as $i) $judy[$i] = true;

$judy->keys(5, 10);        // [5, 10]
$judy->keys(5);            // [5, 10, 15]  (unbounded end)
$judy->keys(null, 5);      // [1, 5]       (unbounded start)
$judy->keys(10, 5);        // []           (inverted range)

// An upper bound is a bound, not a prefix filter: "blackcurrant" sorts after
// "bl", so bound a prefix sweep with the prefix's successor.
$fruit = Judy::fromArray(Judy::STRING_TO_INT, ['blackcurrant' => 1, 'cherry' => 2]);
$fruit->keys('bl', 'bl');  // []
$fruit->keys('bl', 'bm');  // ['blackcurrant']
PHP,
        ],
        'values' => [
            'description' => 'Returns all values as a PHP array. With `$start` and/or `$end`, only the values of keys in the inclusive `[$start, $end]` range are returned; `null` leaves that side unbounded.',
            'supported_types' => 'All types. String-keyed types require string bounds and compare them lexicographically.',
        ],
        'increment' => [
            'description' => 'Atomically increments the value at `$key` by `$amount`. If the key does not exist, it is created with the value `$amount`. Returns the new value.',
            'supported_types' => '`INT_TO_INT`, `STRING_TO_INT`, `STRING_TO_INT_HASH`. Throws exception for other types.',
            'example' => <<<'PHP'
$counters = new Judy(Judy::STRING_TO_INT);
$counters->increment("page_views");       // 1
$counters->increment("page_views");       // 2
$counters->increment("page_views", 10);   // 12
$counters->increment("page_views", -3);   // 9
PHP,
        ],
        'forEach' => [
            'description' => 'Calls `$callback($key, $value)` for each element. The callback receives the key as the first argument and the value as the second.',
            'supported_types' => 'All types.',
            'example' => <<<'PHP'
$judy = Judy::fromArray(Judy::INT_TO_INT, [1 => 10, 2 => 20, 3 => 30]);
$judy->forEach(function ($key, $value) {
    echo "$key => $value\n";
});
PHP,
        ],
        'filter' => [
            'description' => "Returns a new Judy array containing only elements for which `\$predicate(\$key, \$value)` returns `true`.\n\nThe value copied into the result is the one the predicate was handed. A predicate that writes or unsets `\$this[\$key]` does not change what is copied for that element.",
            'supported_types' => 'All types.',
        ],
        'map' => [
            'description' => 'Returns a new Judy array with the same keys, where each value is replaced by the return value of `$transform($key, $value)`.',
            'supported_types' => 'All types.',
        ],
        'sumValues' => [
            'description' => 'Returns the sum of all values. For BITSET, returns the population count.',
            'supported_types' => '`BITSET`, `INT_TO_INT`, `STRING_TO_INT`, `STRING_TO_INT_HASH`, `STRING_TO_INT_ADAPTIVE`. Throws exception for mixed/packed types.',
        ],
        'averageValues' => [
            'description' => 'Returns the arithmetic mean of all values. Returns `null` if the array is empty. For BITSET, always returns `1.0`.',
            'supported_types' => 'Same as `sumValues()`.',
        ],
        'equals' => [
            'description' => 'Returns `true` if both arrays have the same type, the same number of elements, and identical key-value pairs. Returns `false` for type mismatch (no exception).',
            'supported_types' => 'All types.',
        ],
        // Global functions (also looked up from this array)
        'judy_version' => [
            'description' => 'Returns the version of the PHP Judy extension.',
        ],
        'judy_type' => [
            'description' => 'Returns the Judy type constant of the given Judy array.',
        ],
        '__serialize' => [
            'example' => <<<'PHP'
$judy = Judy::fromArray(Judy::INT_TO_INT, [1 => 100, 2 => 200]);
$serialized = serialize($judy);
$restored = unserialize($serialized);
PHP,
        ],
        'jsonSerialize' => [
            'description' => "Implements `JsonSerializable`. Judy arrays can be encoded with `json_encode()`.",
            'example' => <<<'PHP'
$judy = Judy::fromArray(Judy::STRING_TO_INT, ["a" => 1, "b" => 2]);
echo json_encode($judy); // {"a":1,"b":2}
PHP,
        ],
    ],

    // ── Key/Value type table (Array Access section) ─────────────
    'key_value_table' => [
        'BITSET'                   => ['key' => '`int`',    'value' => '`bool`',  'notes' => '`true` sets the bit, `false` clears it'],
        'INT_TO_INT'               => ['key' => '`int`',    'value' => '`int`',   'notes' => 'Values coerced to integer'],
        'INT_TO_MIXED'             => ['key' => '`int`',    'value' => '`mixed`', 'notes' => 'Any PHP value'],
        'INT_TO_PACKED'            => ['key' => '`int`',    'value' => '`mixed`', 'notes' => 'Serialized on write, deserialized on read'],
        'STRING_TO_INT'            => ['key' => '`string`', 'value' => '`int`',   'notes' => 'Values coerced to integer'],
        'STRING_TO_MIXED'          => ['key' => '`string`', 'value' => '`mixed`', 'notes' => 'Any PHP value'],
        'STRING_TO_INT_HASH'       => ['key' => '`string`', 'value' => '`int`',   'notes' => 'O(1) avg lookup'],
        'STRING_TO_MIXED_HASH'     => ['key' => '`string`', 'value' => '`mixed`', 'notes' => 'O(1) avg lookup'],
        'STRING_TO_INT_ADAPTIVE'   => ['key' => '`string`', 'value' => '`int`',   'notes' => 'SSO for short keys'],
        'STRING_TO_MIXED_ADAPTIVE' => ['key' => '`string`', 'value' => '`mixed`', 'notes' => 'SSO for short keys'],
    ],

    // ── Type compatibility matrix ───────────────────────────────
    // Column order for the matrix table
    'matrix_columns' => [
        'BITSET', 'INT_TO_INT', 'INT_TO_MIXED', 'INT_TO_PACKED',
        'STR_INT', 'STR_MIXED', 'STR_INT_HASH', 'STR_MIX_HASH',
        'STR_INT_ADAPT', 'STR_MIX_ADAPT',
    ],

    // Maps the abbreviated column headers to full constant names
    'matrix_column_map' => [
        'BITSET'        => 'BITSET',
        'INT_TO_INT'    => 'INT_TO_INT',
        'INT_TO_MIXED'  => 'INT_TO_MIXED',
        'INT_TO_PACKED' => 'INT_TO_PACKED',
        'STR_INT'       => 'STRING_TO_INT',
        'STR_MIXED'     => 'STRING_TO_MIXED',
        'STR_INT_HASH'  => 'STRING_TO_INT_HASH',
        'STR_MIX_HASH'  => 'STRING_TO_MIXED_HASH',
        'STR_INT_ADAPT' => 'STRING_TO_INT_ADAPTIVE',
        'STR_MIX_ADAPT' => 'STRING_TO_MIXED_ADAPTIVE',
    ],

    'compatibility_matrix' => [
        '`memoryUsage()`' => [
            'BITSET' => 'int', 'INT_TO_INT' => 'int', 'INT_TO_MIXED' => 'int', 'INT_TO_PACKED' => 'int',
            'STR_INT' => 'approx', 'STR_MIXED' => 'approx', 'STR_INT_HASH' => 'approx', 'STR_MIX_HASH' => 'approx',
            'STR_INT_ADAPT' => 'approx', 'STR_MIX_ADAPT' => 'approx',
        ],
        '`union/intersect/diff/xor`' => [
            'BITSET' => 'yes', 'INT_TO_INT' => 'yes', 'INT_TO_MIXED' => '-', 'INT_TO_PACKED' => '-',
            'STR_INT' => 'yes', 'STR_MIXED' => '-', 'STR_INT_HASH' => 'yes', 'STR_MIX_HASH' => '-',
            'STR_INT_ADAPT' => '-', 'STR_MIX_ADAPT' => '-',
        ],
        '`populationCount()`' => [
            'BITSET' => 'yes', 'INT_TO_INT' => 'yes', 'INT_TO_MIXED' => 'yes', 'INT_TO_PACKED' => 'yes',
            'STR_INT' => '-', 'STR_MIXED' => '-', 'STR_INT_HASH' => '-', 'STR_MIX_HASH' => '-',
            'STR_INT_ADAPT' => '-', 'STR_MIX_ADAPT' => '-',
        ],
        '`sumValues()`' => [
            'BITSET' => 'yes', 'INT_TO_INT' => 'yes', 'INT_TO_MIXED' => '-', 'INT_TO_PACKED' => '-',
            'STR_INT' => 'yes', 'STR_MIXED' => '-', 'STR_INT_HASH' => 'yes', 'STR_MIX_HASH' => '-',
            'STR_INT_ADAPT' => 'yes', 'STR_MIX_ADAPT' => '-',
        ],
        '`averageValues()`' => [
            'BITSET' => 'yes', 'INT_TO_INT' => 'yes', 'INT_TO_MIXED' => '-', 'INT_TO_PACKED' => '-',
            'STR_INT' => 'yes', 'STR_MIXED' => '-', 'STR_INT_HASH' => 'yes', 'STR_MIX_HASH' => '-',
            'STR_INT_ADAPT' => 'yes', 'STR_MIX_ADAPT' => '-',
        ],
        '`increment()`' => [
            'BITSET' => '-', 'INT_TO_INT' => 'yes', 'INT_TO_MIXED' => '-', 'INT_TO_PACKED' => '-',
            'STR_INT' => 'yes', 'STR_MIXED' => '-', 'STR_INT_HASH' => 'yes', 'STR_MIX_HASH' => '-',
            'STR_INT_ADAPT' => '-', 'STR_MIX_ADAPT' => '-',
        ],
        '`byCount()`' => [
            'BITSET' => 'yes', 'INT_TO_INT' => 'yes', 'INT_TO_MIXED' => 'yes', 'INT_TO_PACKED' => 'yes',
            'STR_INT' => 'null', 'STR_MIXED' => 'null', 'STR_INT_HASH' => 'null', 'STR_MIX_HASH' => 'null',
            'STR_INT_ADAPT' => 'null', 'STR_MIX_ADAPT' => 'null',
        ],
        '`firstEmpty()` etc.' => [
            'BITSET' => 'yes', 'INT_TO_INT' => 'yes', 'INT_TO_MIXED' => 'yes', 'INT_TO_PACKED' => 'yes',
            'STR_INT' => 'null', 'STR_MIXED' => 'null', 'STR_INT_HASH' => 'null', 'STR_MIX_HASH' => 'null',
            'STR_INT_ADAPT' => 'null', 'STR_MIX_ADAPT' => 'null',
        ],
    ],

    'matrix_legend' => '**Legend**: `yes` = supported, `-` = throws exception, `null` = silently returns null, `int` = returns an exact integer value, `approx` = returns an approximate integer value (see the method entry).',
    'matrix_footer' => 'All other methods (`size`, `slice`, `deleteRange`, `forEach`, `filter`, `map`, `keys`, `values`, `equals`, `mergeWith`, `toArray`, `fromArray`, `putAll`, `getAll`) work with all 10 types, ranged forms included.',

    // ── Global functions ────────────────────────────────────────
    'global_functions' => ['judy_version', 'judy_type'],

    // ── Methods to exclude from drift detection ─────────────────
    // These are standard interface methods that don't need individual sections.
    'skip_drift_check' => ['__destruct'],
];
