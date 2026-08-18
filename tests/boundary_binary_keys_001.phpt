--TEST--
Judy boundary: high-byte string keys round-trip and sort by unsigned byte; embedded NUL is rejected on all six string-keyed types
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$types = [
    'STRING_TO_INT'            => Judy::STRING_TO_INT,
    'STRING_TO_MIXED'          => Judy::STRING_TO_MIXED,
    'STRING_TO_INT_HASH'       => Judy::STRING_TO_INT_HASH,
    'STRING_TO_MIXED_HASH'     => Judy::STRING_TO_MIXED_HASH,
    'STRING_TO_INT_ADAPTIVE'   => Judy::STRING_TO_INT_ADAPTIVE,
    'STRING_TO_MIXED_ADAPTIVE' => Judy::STRING_TO_MIXED_ADAPTIVE,
];

/* Part 1 — high bytes are legal key bytes and order as unsigned.
   examples/symbol-table-prefix.php does 0xFF carry arithmetic on prefixes and
   depends on this; the guard added for issue #117 must not touch it. */
$keys = ["a", "a\xFF", "a\xFF\x7A", "b", "\x80", "\xFE", "\xFF", "m\x80n", "m\xFEn", "m\xFFn"];
$expected = $keys;
sort($expected, SORT_STRING); // PHP compares byte-wise unsigned, same as Judy

echo "== high-byte keys ==\n";
foreach ($types as $name => $type) {
    $j = new Judy($type);
    foreach ($keys as $i => $k) {
        $j[$k] = $i;
    }

    $roundtrip = 'ok';
    foreach ($keys as $i => $k) {
        if (!isset($j[$k]) || $j[$k] !== $i) {
            $roundtrip = 'FAILED at ' . bin2hex($k);
            break;
        }
    }

    $order = ($j->keys() === $expected)
        ? 'ok'
        : 'FAILED ' . implode(',', array_map('bin2hex', $j->keys()));

    printf("%-24s count=%d roundtrip=%s order=%s first=%s last=%s\n",
        $name, $j->count(), $roundtrip, $order,
        bin2hex($j->first()), bin2hex($j->last()));
}

/* Part 2 — a key with an embedded NUL is rejected everywhere, on every type.
   JudySL indexes NUL-terminated C strings, so such a key would be truncated:
   "ab\0cd" and "ab" would collide and one value would be lost silently. */
$nul = "ab\x00cd";

$ops = [
    'offsetSet'      => function (Judy $j) use ($nul) { $j[$nul] = 1; },
    'offsetGet'      => function (Judy $j) use ($nul) { return $j[$nul]; },
    'offsetExists'   => function (Judy $j) use ($nul) { return isset($j[$nul]); },
    'offsetUnset'    => function (Judy $j) use ($nul) { unset($j[$nul]); },
    'putAll'         => function (Judy $j) use ($nul) { $j->putAll([$nul => 1]); },
    'getAll'         => function (Judy $j) use ($nul) { return $j->getAll([$nul]); },
    'slice'          => function (Judy $j) use ($nul) { return $j->slice($nul, "zz"); },
    'deleteRange'    => function (Judy $j) use ($nul) { return $j->deleteRange($nul, "zz"); },
    'keys(range)'    => function (Judy $j) use ($nul) { return $j->keys($nul, "zz"); },
    'values(range)'  => function (Judy $j) use ($nul) { return $j->values($nul, "zz"); },
    'toArray(range)' => function (Judy $j) use ($nul) { return $j->toArray($nul, "zz"); },
    'size(range)'    => function (Judy $j) use ($nul) { return $j->size($nul, "zz"); },
    'first'          => function (Judy $j) use ($nul) { return $j->first($nul); },
    'last'           => function (Judy $j) use ($nul) { return $j->last($nul); },
    'searchNext'     => function (Judy $j) use ($nul) { return $j->searchNext($nul); },
    'prev'           => function (Judy $j) use ($nul) { return $j->prev($nul); },
];

echo "\n== embedded NUL rejected ==\n";
foreach ($types as $name => $type) {
    $bad = [];
    foreach ($ops as $op => $fn) {
        $j = new Judy($type);
        $j["ab"] = 2;
        try {
            $fn($j);
            $bad[] = $op;
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'must not contain embedded null bytes') === false) {
                $bad[] = $op . '(wrong message: ' . $e->getMessage() . ')';
            }
        }
        // The rejected operation must not have disturbed the array.
        if ($j->count() !== 1 || $j["ab"] !== 2) {
            $bad[] = $op . '(mutated)';
        }
    }
    printf("%-24s %s\n", $name, $bad === [] ? 'all rejected' : 'NOT REJECTED: ' . implode(', ', $bad));
}

// fromArray() is static, so it gets its own pass.
echo "\n== embedded NUL rejected by fromArray() ==\n";
foreach ($types as $name => $type) {
    try {
        Judy::fromArray($type, [$nul => 1]);
        printf("%-24s NOT REJECTED\n", $name);
    } catch (Exception $e) {
        printf("%-24s %s\n", $name,
            strpos($e->getMessage(), 'must not contain embedded null bytes') !== false
                ? 'rejected' : 'wrong message: ' . $e->getMessage());
    }
}

// increment() is only defined for the two INT trie/hash types.
echo "\n== embedded NUL rejected by increment() ==\n";
foreach (['STRING_TO_INT' => Judy::STRING_TO_INT, 'STRING_TO_INT_HASH' => Judy::STRING_TO_INT_HASH] as $name => $type) {
    $j = new Judy($type);
    try {
        $j->increment($nul, 1);
        printf("%-24s NOT REJECTED\n", $name);
    } catch (Exception $e) {
        printf("%-24s %s\n", $name,
            strpos($e->getMessage(), 'must not contain embedded null bytes') !== false
                ? 'rejected' : 'wrong message: ' . $e->getMessage());
    }
    printf("%-24s count after rejection: %d\n", $name, $j->count());
}

/* Part 3 — the exact collision from issue #117 can no longer happen silently. */
echo "\n== issue #117 collision ==\n";
foreach ($types as $name => $type) {
    $j = new Judy($type);
    $threw = 'no';
    try {
        $j["ab\x00cd"] = 1;
    } catch (Exception $e) {
        $threw = 'yes';
    }
    $j["ab"] = 2;
    $reread = 'threw';
    try {
        $reread = var_export($j["ab\x00cd"], true);
    } catch (Exception $e) {
        // expected: the truncating read is refused rather than answered wrongly
    }
    printf("%-24s write threw=%s count=%d keys=%s reread=%s\n", $name, $threw, $j->count(),
        implode(',', array_map('bin2hex', $j->keys())), $reread);
}

// A NUL is only rejected as a KEY. Values are unaffected.
echo "\n== NUL in a value is still fine ==\n";
$j = new Judy(Judy::STRING_TO_MIXED);
$j["packed"] = "a\x00b";
var_dump(bin2hex($j["packed"]));
?>
--EXPECT--
== high-byte keys ==
STRING_TO_INT            count=10 roundtrip=ok order=ok first=61 last=ff
STRING_TO_MIXED          count=10 roundtrip=ok order=ok first=61 last=ff
STRING_TO_INT_HASH       count=10 roundtrip=ok order=ok first=61 last=ff
STRING_TO_MIXED_HASH     count=10 roundtrip=ok order=ok first=61 last=ff
STRING_TO_INT_ADAPTIVE   count=10 roundtrip=ok order=ok first=61 last=ff
STRING_TO_MIXED_ADAPTIVE count=10 roundtrip=ok order=ok first=61 last=ff

== embedded NUL rejected ==
STRING_TO_INT            all rejected
STRING_TO_MIXED          all rejected
STRING_TO_INT_HASH       all rejected
STRING_TO_MIXED_HASH     all rejected
STRING_TO_INT_ADAPTIVE   all rejected
STRING_TO_MIXED_ADAPTIVE all rejected

== embedded NUL rejected by fromArray() ==
STRING_TO_INT            rejected
STRING_TO_MIXED          rejected
STRING_TO_INT_HASH       rejected
STRING_TO_MIXED_HASH     rejected
STRING_TO_INT_ADAPTIVE   rejected
STRING_TO_MIXED_ADAPTIVE rejected

== embedded NUL rejected by increment() ==
STRING_TO_INT            rejected
STRING_TO_INT            count after rejection: 0
STRING_TO_INT_HASH       rejected
STRING_TO_INT_HASH       count after rejection: 0

== issue #117 collision ==
STRING_TO_INT            write threw=yes count=1 keys=6162 reread=threw
STRING_TO_MIXED          write threw=yes count=1 keys=6162 reread=threw
STRING_TO_INT_HASH       write threw=yes count=1 keys=6162 reread=threw
STRING_TO_MIXED_HASH     write threw=yes count=1 keys=6162 reread=threw
STRING_TO_INT_ADAPTIVE   write threw=yes count=1 keys=6162 reread=threw
STRING_TO_MIXED_ADAPTIVE write threw=yes count=1 keys=6162 reread=threw

== NUL in a value is still fine ==
string(6) "610062"
