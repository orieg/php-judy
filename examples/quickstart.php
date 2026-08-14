<?php
/**
 * Quickstart: the essentials of PHP Judy in one file.
 *
 * Run: php examples/quickstart.php
 */

if (!extension_loaded('judy')) {
    fwrite(STDERR, "The judy extension is not loaded.\n");
    exit(1);
}

echo 'PHP Judy ', judy_version(), "\n\n";

// A Judy array behaves like a PHP array, but is sparse and ordered.
// Pick a type for your keys/values (see examples/README.md for the guide).
$judy = new Judy(Judy::INT_TO_INT);

// ArrayAccess: set, get, isset, unset
$judy[42]      = 1000;
$judy[100000]  = 2000;   // sparse: no memory used between 42 and 100000
$judy[7]       = 3000;

echo 'count: ', count($judy), "\n";          // Countable
echo 'judy[42]: ', $judy[42], "\n";
echo 'isset(judy[43]): ', var_export(isset($judy[43]), true), "\n";

// Iteration is in key order (integer-keyed and trie-based string types).
foreach ($judy as $key => $value) {
    echo "  $key => $value\n";               // 7, 42, 100000
}

// Ordered navigation — something hash tables can't do.
echo 'first key >= 40: ', $judy->first(40), "\n";     // 42
echo 'last key <= 99999: ', $judy->last(99999), "\n"; // 42

// Atomic increment: creates the key if absent, no read-modify-write.
$judy->increment(42, 8);
echo 'after increment: ', $judy[42], "\n";

// Bulk conversion runs in C, faster than a foreach loop.
$native = $judy->toArray();
$copy   = Judy::fromArray(Judy::INT_TO_INT, $native);
echo 'copies equal: ', var_export($judy->equals($copy), true), "\n";

// Memory accounting for the internal Judy structure (integer-keyed types).
echo 'memoryUsage: ', $judy->memoryUsage(), " bytes\n";

// String keys? Use STRING_TO_MIXED (sorted trie) or the *_HASH types.
$tags = new Judy(Judy::STRING_TO_MIXED);
$tags['php']  = ['color' => 'purple'];
$tags['judy'] = ['color' => 'green'];
echo "tags: ", json_encode($tags), "\n";     // JsonSerializable
