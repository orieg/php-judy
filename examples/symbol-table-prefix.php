<?php
/**
 * Symbol table keyed by fully-qualified class name, queried by namespace.
 *
 * The structure every PHP tool eventually builds: FQCN -> metadata (declaring
 * file, kind, line). Point lookup by name is the obvious query. The other one,
 * which a plain hash table cannot answer without scanning, is
 *
 *     "every symbol under App\Domain\"
 *
 * IDE and LSP completion, namespace-scoped static-analysis rules, and PHPUnit's
 * --filter picking Tests\Unit\Foo\* out of a large suite are all that query.
 *
 * STRING_TO_MIXED is trie-based and orders keys lexicographically, so every
 * symbol sharing a namespace prefix is one contiguous slice of the key space.
 * A namespace query is then a bounded read of that slice and nothing else.
 *
 * The point of this file is the conversion that makes such a read correct:
 *
 *   1. a prefix is not a bound (section 1) — turning one into a pair of
 *      inclusive bounds has a carry case, an unbounded case, and exactly one
 *      possible false positive, all of which this file derives and asserts;
 *   2. once you have bounds, the read is ONE call — keys($lo, $hi) /
 *      values($lo, $hi) / toArray($lo, $hi) — not a per-element walk
 *      (sections 2 and 4).
 *
 * Contrast with examples/autocomplete-trie.php, which walks the same kind of
 * slice with first() + searchNext(). That is not an older way of doing this:
 * it is the other half of the same trade, and section 5 says when each wins.
 *
 * Run: php examples/symbol-table-prefix.php [namespace-prefix]
 *
 * Everything this script prints is a COUNT — keys visited, PHP->C crossings,
 * symbols selected. Counts do not move with machine load, which is why they
 * are the evidence here and no wall-clock number is. For measured timings see
 * BENCHMARK.md, and only trust them from an idle machine.
 */

if (!extension_loaded('judy')) {
    fwrite(STDERR, "The judy extension is not loaded.\n");
    exit(1);
}

/* ── 1. Prefix -> inclusive key range ────────────────────────────────── */

/*
 * Judy's range API takes a pair of INCLUSIVE keys, and a string upper bound is
 * a bound, not a prefix test: keys('App\Domain\', 'App\Domain\') returns
 * nothing but an exact 'App\Domain\' key, because every symbol under that
 * namespace sorts strictly after it.
 *
 * The lower bound is easy. For any key K whose prefix is P, either K === P or
 * P is a proper prefix of K and therefore sorts before it, so K >= P always.
 * P itself is the correct inclusive $start.
 *
 * The upper bound is where prefix-to-range conversions go wrong. Two shortcuts
 * circulate, and both are subtly incorrect on binary-safe keys:
 *
 *   P . "\xFF"   fails when a key actually has a 0xFF byte right after the
 *                prefix: that key sorts AFTER P."\xFF" (the bound is a proper
 *                prefix of it) and is silently dropped. Appending more 0xFF
 *                bytes only moves the cliff; it never removes it.
 *
 *   succ(P) as   right idea, wrong end. succ('bl') is 'bm', and everything
 *   a bound      under 'bl' does sort below 'bm' — but Judy's upper bound is
 *                inclusive, so a key spelled exactly 'bm' comes back too.
 *
 * The correct construction takes the second shortcut and closes its one hole.
 * Let P end with byte b. Write Q for P minus that last byte, and
 * succ(P) = Q . chr(b + 1). For any key K with P <= K <= succ(P):
 *
 *   - K must start with Q (differing earlier and lower would put K below P);
 *   - K[|Q|] is therefore >= b (from K >= P) and <= b + 1 (from K <= succ(P));
 *   - if that byte is b, K starts with Q.b = P — a genuine match;
 *   - if it is b + 1, K starts with succ(P), and K <= succ(P) forces
 *     K === succ(P).
 *
 * So the inclusive range [P, succ(P)] returns exactly the P-prefixed keys plus
 * AT MOST ONE extra key, the one spelled succ(P) itself. Drop that single key
 * and the answer is exact — for any byte string, no assumption about the key
 * alphabet.
 *
 * Two edge cases fall out of the same derivation:
 *
 *   carry        P ending in 0xFF has no in-place successor. Strip the trailing
 *                0xFF run and increment the last byte before it: succ("a\xFF")
 *                is "b", and every key under "a\xFF" does sort below "b".
 *   no successor P consisting only of 0xFF bytes (and the empty prefix, which
 *                matches everything) has no successor at all. The slice runs to
 *                the end of the array, which is what a null $end means.
 */

/**
 * Inclusive [$start, $end] bounds covering every key beginning with $prefix.
 *
 * $end is null when the slice runs to the end of the key space. When $end is a
 * string it may over-reach by exactly one key — the one equal to $end — which
 * prefixRead() below trims.
 *
 * @return array{0:string,1:?string}
 */
function prefixBounds(string $prefix): array
{
    $i = strlen($prefix) - 1;
    while ($i >= 0 && $prefix[$i] === "\xFF") {   // carry past a trailing 0xFF run
        $i--;
    }
    if ($i < 0) {
        return [$prefix, null];                   // empty or all-0xFF: no successor
    }
    return [$prefix, substr($prefix, 0, $i) . chr(ord($prefix[$i]) + 1)];
}

/**
 * Every [key => value] whose key begins with $prefix, in ONE bounded read.
 *
 * toArray($start, $end) is a single C traversal writing straight into the
 * returned PHP array. It visits the slice plus the one key that ends it, and
 * crosses from PHP into C exactly once regardless of how many symbols match.
 */
function prefixRead(Judy $symbols, string $prefix): array
{
    [$lo, $hi] = prefixBounds($prefix);
    $out = $symbols->toArray($lo, $hi);
    if ($hi !== null) {
        unset($out[$hi]);                         // the single possible false positive
    }
    return $out;
}

/** Just the names — same traversal, no values materialised. */
function prefixKeys(Judy $symbols, string $prefix): array
{
    [$lo, $hi] = prefixBounds($prefix);
    $keys = $symbols->keys($lo, $hi);
    if ($hi !== null && $keys !== [] && end($keys) === $hi) {
        array_pop($keys);                         // keys() is ordered: it can only be last
    }
    return $keys;
}

/**
 * Just how many — same traversal again, nothing materialised at all.
 *
 * size($start, $end) counts the range over the same bounded walk keys() reads,
 * so this answers "how big is this namespace?" without building an array only
 * to call count() on it and throw it away. The one-key trim is the same
 * correction prefixKeys() makes, done with an isset() instead of an array_pop().
 */
function prefixCount(Judy $symbols, string $prefix): int
{
    [$lo, $hi] = prefixBounds($prefix);
    $n = $symbols->size($lo, $hi);
    if ($hi !== null && isset($symbols[$hi])) {
        $n--;                                     // the single possible false positive
    }
    return $n;
}

/*
 * Checks, so the derivation above is executable rather than a story. Written
 * as explicit comparisons rather than assert(), which zend.assertions=-1
 * compiles away — a correctness claim that can silently not run is not a
 * correctness claim.
 */

$checks = [];
$check  = static function (string $what, bool $ok) use (&$checks): void {
    $checks[$what] = $ok;
};

$edge = new Judy(Judy::STRING_TO_MIXED);
foreach (
    [
        'App\Domain\Order',                       // in
        'App\Domain\Pricing\TaxRate',             // in (nested namespace)
        'App\Domain]',                            // == succ('App\Domain\'): the false positive
        'App\DomainEvents\OrderPlaced',           // sibling namespace, NOT under App\Domain\
        "bin\xFFtail",                            // 0xFF right after a "bin\xFF" prefix
        "bin\xFF\xFF",
        'zzz',
    ] as $fqcn
) {
    $edge[$fqcn] = true;
}

$check('successor of a separator-terminated namespace', prefixBounds('App\Domain\\') === ['App\Domain\\', 'App\Domain]']);
$check('carry past a trailing 0xFF run', prefixBounds("a\xFF\xFF") === ["a\xFF\xFF", 'b']);
$check('all-0xFF prefix has no successor', prefixBounds("\xFF\xFF") === ["\xFF\xFF", null]);
$check('empty prefix is unbounded above', prefixBounds('') === ['', null]);

// The whole namespace, and nothing else: no sibling, no false positive.
$check(
    'namespace read excludes the sibling and the successor key',
    prefixKeys($edge, 'App\Domain\\') === ['App\Domain\Order', 'App\Domain\Pricing\TaxRate'],
);
// Dropping the trailing separator changes the question, and 'App\DomainEvents'
// answers it. A namespace prefix must carry its separator.
$check(
    'prefix without the separator does match the sibling namespace',
    in_array('App\DomainEvents\OrderPlaced', prefixKeys($edge, 'App\Domain'), true),
);
// Binary-safe: the naive P."\xFF" bound would drop both of these.
$check('keys with a 0xFF byte after the prefix survive', count(prefixKeys($edge, "bin\xFF")) === 2);
$check('empty prefix reads the whole table', prefixKeys($edge, '') === array_keys($edge->toArray()));
// Counting the range and reading it must agree — including on the edge case
// this whole file is about, where the successor bound is itself a stored key.
$check(
    'counting a namespace agrees with reading it',
    prefixCount($edge, 'App\Domain\\') === 2
        && prefixCount($edge, 'App\Domain') === count(prefixKeys($edge, 'App\Domain'))
        && prefixCount($edge, "bin\xFF") === 2
        && prefixCount($edge, '') === count($edge),
);

echo "1. Prefix -> inclusive key range\n\n";
foreach (['App\Domain\\', 'App\Domain', "a\xFF\xFF", "\xFF\xFF", ''] as $p) {
    [$lo, $hi] = prefixBounds($p);
    printf(
        "   %-22s -> [ %-22s , %-22s ]\n",
        '"' . addcslashes($p, "\0..\37\177..\377") . '"',
        '"' . addcslashes($lo, "\0..\37\177..\377") . '"',
        $hi === null ? 'null (end of array)' : '"' . addcslashes($hi, "\0..\37\177..\377") . '"',
    );
}
echo "\n   The upper bound is inclusive, so it can over-reach by exactly one key:\n";
echo "   the one spelled like the bound itself. Trim it and the slice is exact.\n\n";

foreach ($checks as $what => $ok) {
    printf("   [%s] %s\n", $ok ? ' ok ' : 'FAIL', $what);
}
if (array_keys($checks, false, true) !== []) {
    fwrite(STDERR, "prefixBounds() is wrong on this build; the rest of this demo is meaningless.\n");
    exit(1);
}
echo "\n";

/* ── The symbol table ─────────────────────────────────────────────────── */

/**
 * A synthetic PSR-4 tree, plus enough vendor code to make "scan everything"
 * look like what it is.
 *
 * @return array<string,array{kind:string,file:string}>
 */
function symbolFixture(int $vendorPackages, int $classesPerPackage): array
{
    $tree = [
        'App\Domain\\'                   => ['Order', 'Cart', 'Customer', 'Money', 'Sku'],
        'App\Domain\Pricing\\'           => ['Discount', 'PriceList', 'TaxRate'],
        'App\DomainEvents\\'             => ['CartEmptied', 'OrderPlaced'],
        'App\Http\Controller\\'          => ['CartController', 'OrderController'],
        'App\Infrastructure\Doctrine\\'  => ['OrderRepository'],
        'Tests\Unit\Domain\\'            => ['MoneyTest', 'OrderTest', 'SkuTest'],
        'Tests\Unit\Http\\'              => ['OrderControllerTest'],
        'Tests\Feature\Checkout\\'       => ['GuestCheckoutTest', 'PayLaterTest'],
    ];

    $symbols = [];
    foreach ($tree as $ns => $names) {
        foreach ($names as $name) {
            $symbols[$ns . $name] = [
                'kind' => str_ends_with($name, 'Test') ? 'test' : 'class',
                'file' => '/app/' . str_replace('\\', '/', $ns . $name) . '.php',
            ];
        }
    }
    for ($p = 0; $p < $vendorPackages; $p++) {
        for ($c = 0; $c < $classesPerPackage; $c++) {
            $fqcn = sprintf('Vendor\Package%03d\Class%03d', $p, $c);
            $symbols[$fqcn] = [
                'kind' => 'class',
                'file' => '/vendor/' . str_replace('\\', '/', $fqcn) . '.php',
            ];
        }
    }
    return $symbols;
}

const VENDOR_PACKAGES = 400;
const VENDOR_CLASSES  = 15;

$fixture = symbolFixture(VENDOR_PACKAGES, VENDOR_CLASSES);

$symbols = Judy::fromArray(Judy::STRING_TO_MIXED, $fixture);
$hashed  = Judy::fromArray(Judy::STRING_TO_MIXED_HASH, $fixture);
$plain   = $fixture;                              // the hash-table shape, for scanning

$prefix = $argv[1] ?? 'App\Domain\\';

/* ── 2. Querying a namespace ─────────────────────────────────────────── */

echo "2. Querying a namespace\n\n";
printf("   symbol table holds %s symbols\n", number_format(count($symbols)));
printf("   point lookup App\\Domain\\Order -> %s\n\n", $symbols['App\Domain\Order']['file']);

$members = prefixRead($symbols, $prefix);
printf("   symbols under \"%s\" (%d):\n", $prefix, count($members));
foreach ($members as $fqcn => $meta) {
    printf("     %-34s %-5s %s\n", $fqcn, $meta['kind'], $meta['file']);
}

// keys(), values() and toArray() all take the same bounds. Reach for the one
// whose output you actually want rather than materialising more and discarding.
[$lo, $hi] = prefixBounds($prefix);
printf("\n   keys(\$lo, \$hi)    -> %d names, no values materialised\n", count(prefixKeys($symbols, $prefix)));
printf("   values(\$lo, \$hi)  -> %d metadata records, no names\n", count($symbols->values($lo, $hi)));
printf("   toArray(\$lo, \$hi) -> %d pairs\n", count($members));
printf("   size(\$lo, \$hi)    -> %d, and nothing materialised at all\n\n", prefixCount($symbols, $prefix));

// Completion usually wants direct children, not the whole subtree. That is the
// same bounded read plus a filter at VM speed — still one crossing.
$direct = [];
foreach (prefixKeys($symbols, $prefix) as $fqcn) {
    $tail = substr($fqcn, strlen($prefix));
    $direct[strstr($tail, '\\', true) ?: $tail] = str_contains($tail, '\\');
}
$rendered = [];
foreach ($direct as $name => $isNamespace) {
    $rendered[] = $isNamespace ? $name . '\\' : $name;
}
printf("   direct children of \"%s\": %s\n\n", $prefix, implode(', ', $rendered));

/* ── 3. Keys visited: ordered slice vs hash-table scan ───────────────── */

/** The prefix walk, instrumented. Returns [names, keysVisited, crossings]. */
function prefixWalk(Judy $symbols, string $prefix): array
{
    $names     = [];
    $visited   = 0;
    $crossings = 0;

    $key = $symbols->first($prefix);              // seek straight to the slice
    $crossings++;
    while ($key !== null && str_starts_with($key, $prefix)) {
        $visited++;
        $names[] = $key;
        $key = $symbols->searchNext($key);        // one crossing per step, including
        $crossings++;                             // the one that ends the walk
    }

    return [$names, $visited + 1, $crossings];    // +1 for the key that ended the walk
}

/** The same question of a hash table: every key must be tested. */
function prefixScan(array $symbols, string $prefix): array
{
    $names   = [];
    $visited = 0;
    foreach ($symbols as $fqcn => $_) {
        $visited++;
        if (str_starts_with($fqcn, $prefix)) {
            $names[] = $fqcn;
        }
    }
    sort($names);
    return [$names, $visited];
}

[$walkNames, $walkVisited, $walkCrossings] = prefixWalk($symbols, $prefix);
[$scanNames, $scanVisited]                 = prefixScan($plain, $prefix);
$bulkNames                                 = prefixKeys($symbols, $prefix);

echo "3. Keys visited\n\n";
printf("   query: every symbol under \"%s\" of %s in the table\n\n", $prefix, number_format(count($symbols)));
printf("   ordered trie (bounded read)   matched=%-4d keys visited=%d\n", count($bulkNames), $walkVisited);
printf("   hash table   (full scan)      matched=%-4d keys visited=%d\n", count($scanNames), $scanVisited);
printf(
    "\n   The trie touched %.2f%% of the table; the hash table touched 100%%.\n",
    $walkVisited / count($symbols) * 100,
);
echo "   That ratio is the point: the trie's cost follows the slice being read,\n";
echo "   the hash table's follows the size of the table. Add a symbol in an\n";
echo "   unrelated namespace and only the second number moves.\n\n";

// Judy's own *_HASH types are NOT the scanning shape above: they keep a sorted
// key index, so the identical bounded read works and returns the same answer.
// What differs is cost, not capability — prefix work over the hash types scales
// with the whole store rather than with the slice. BENCHMARK.md measures that
// gap as a complexity-class difference across a 100x sweep; this script does
// not time anything, so read it there.
$hashedNames = prefixKeys($hashed, $prefix);
printf(
    "   STRING_TO_MIXED_HASH answers the same bounded read identically: %s\n",
    $hashedNames === $bulkNames ? 'yes' : 'NO - the types disagree',
);
echo "   (capability is not the difference between the trie and hash types —\n";
echo "    cost is. See BENCHMARK.md before choosing on performance grounds.)\n\n";

/* ── 4. Crossings: walk vs bounded bulk read ─────────────────────────── */

echo "4. PHP->C crossings for the same slice\n\n";
printf("   first() + searchNext() walk   crossings=%d  (1 + one per matched symbol)\n", $walkCrossings);
echo   "   keys(\$lo, \$hi)                crossings=1  (one bounded traversal)\n\n";
echo "   Both make the same number of KEY visits inside libJudy — the walk and\n";
echo "   the bounded read traverse the same slice. What the bulk form removes is\n";
echo "   the per-element method dispatch: keys()/values()/toArray() write straight\n";
echo "   into the destination PHP array in one pass. Prefer them over\n";
echo "   slice(\$lo, \$hi)->keys() too, which copies the range into a freshly\n";
echo "   allocated Judy and then traverses that copy a second time.\n\n";
echo "   The walk counted above collects names only. Wanting the metadata too\n";
echo "   adds a \$symbols[\$key] fetch per element and doubles its crossings,\n";
echo "   while toArray(\$lo, \$hi) still costs one.\n\n";

$agree = $bulkNames === $walkNames && $bulkNames === $scanNames && $bulkNames === $hashedNames;
printf(
    "   bounded read, walk, hash scan and STRING_TO_MIXED_HASH all select the\n"
    . "   identical symbol set: %s\n\n",
    $agree ? 'yes' : 'NO - the shapes disagree',
);

/* ── 5. When the walk is still the right tool ────────────────────────── */

echo "5. When to walk instead\n\n";
echo "   A bounded read materialises the whole slice. That is what you want for\n";
echo "   a static-analysis rule scoped to a namespace, or for PHPUnit --filter\n";
echo "   selecting Tests\\Unit\\Foo\\* — the consumer needs every match anyway.\n\n";
echo "   Completion does not. An editor showing ten rows wants the first ten\n";
echo "   matches and should stop there, and a walk can stop; a bounded read\n";
echo "   cannot. examples/autocomplete-trie.php is that shape, and it is not an\n";
echo "   older way of writing this one:\n\n";
echo "     whole slice, count unbounded   ->  keys/values/toArray(\$lo, \$hi)\n";
echo "     first N matches, N small       ->  first() + searchNext(), break early\n";
echo "     just \"how many\" / \"any at all\" ->  bounded read and count it; the\n";
echo "                                        empty array IS the \"none\" answer\n\n";

$top = [];
foreach (prefixWalk($symbols, 'Vendor\Package00')[0] as $fqcn) {
    $top[] = $fqcn;
    if (count($top) === 3) {
        break;                                    // an LSP dropdown, not a full read
    }
}
printf("   first 3 completions under Vendor\\Package00: %s\n\n", implode(', ', $top));

echo "Notes:\n";
echo "  - a namespace prefix must include its trailing separator. \"App\\Domain\"\n";
echo "    is a legitimate string prefix and it matches App\\DomainEvents\\* too;\n";
echo "    only \"App\\Domain\\\" is the namespace. The bounds are correct either\n";
echo "    way — the question changes, not the answer.\n";
echo "  - Judy string keys are binary-safe, so a prefix-to-range helper has to\n";
echo "    be as well. Appending \"\\xFF\" is not: it silently drops any key with a\n";
echo "    0xFF byte immediately after the prefix. Increment-with-carry plus the\n";
echo "    one-key trim has no such hole, and costs one array_pop.\n";
echo "  - the trim is not paranoia about class names (no PHP identifier is\n";
echo "    spelled \"App\\Domain]\"). It is what makes the helper reusable for keys\n";
echo "    you did not choose — cache keys, file paths, serialised tuples.\n";
echo "  - size(\$lo, \$hi) counts the range on string-keyed types too, over the\n";
echo "    same bounded walk keys() reads — so a namespace population costs the\n";
echo "    range and materialises nothing. Prefer it to count(keys(\$lo, \$hi)),\n";
echo "    which builds an array only to measure it. The one-key trim applies\n";
echo "    here as well, as an isset() rather than an array_pop().\n";
echo "    populationCount() still throws on these types: it answers from\n";
echo "    libJudy's population cache, which the string-keyed stores lack.\n";
echo "  - deleting a namespace is the same range, one call: deleteRange(\$lo,\n";
echo "    \$hi) — but the bound is inclusive there too, so it removes the one\n";
echo "    key spelled like \$hi as well. A read can trim that key afterwards; a\n";
echo "    delete cannot, so delete the exact-successor key's range separately\n";
echo "    (or re-insert it) when the keyspace can contain it.\n";
echo "    examples/prefix-invalidation.php writes the equivalent walk out\n";
echo "    longhand so that keys-visited stays countable.\n";
echo "  - every number printed above is a count, not a timing, so a loaded\n";
echo "    machine cannot change it. Nothing here is a performance claim; for\n";
echo "    measured cost see BENCHMARK.md and re-run it when idle.\n";
