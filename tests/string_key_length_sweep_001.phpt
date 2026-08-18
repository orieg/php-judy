--TEST--
Judy string types: key-length sweep 0-24 across word boundaries (LASTWORD_BY_VALUE equivalence; #142 O4)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/*
 * Length-sweep gate for the O4 string-layer patches (#142): the vendored
 * JudySL decides "last word of the key" from the packed index word
 * (LASTWORD_BY_VALUE) instead of a strlen-derived length. The two are
 * equivalent iff it holds at EVERY length, so sweep 0..24 — covering both
 * sides of every 8-byte word boundary (7/8/9, 15/16/17, 23/24) — through
 * insert, overwrite, lookup, ordered iteration (JudySLNext/Prev), and
 * delete, for every string-keyed type. Adversarial content per length:
 * repeated ASCII, numeric strings, high bytes (0x80-0xFF), shared-prefix
 * siblings (adversarial-key lesson: realistic-only keys hid four bugs).
 */

const MAXLEN = 24;

function variants(int $len): array {
    if ($len === 0) return [""];
    $v = [
        str_repeat("a", $len),                       // dense shared prefixes
        str_repeat("z", $len),
        str_pad("9", $len, "0", STR_PAD_LEFT),       // numeric string
        str_repeat("\xff", $len),                    // high bytes
        str_repeat("\x80", $len),
    ];
    if ($len >= 2) $v[] = str_repeat("p", $len - 1) . "q"; // diverge at last byte
    return array_values(array_unique($v));
}

$types = [
    "STRING_TO_INT"            => Judy::STRING_TO_INT,
    "STRING_TO_MIXED"          => Judy::STRING_TO_MIXED,
    "STRING_TO_INT_HASH"       => Judy::STRING_TO_INT_HASH,
    "STRING_TO_MIXED_HASH"     => Judy::STRING_TO_MIXED_HASH,
    "STRING_TO_INT_ADAPTIVE"   => Judy::STRING_TO_INT_ADAPTIVE,
    "STRING_TO_MIXED_ADAPTIVE" => Judy::STRING_TO_MIXED_ADAPTIVE,
];

// Types whose primary store is JudySL (ordered walks hit JudySLNext/Prev
// directly; the others go through their own index but stay ordered too).
foreach ($types as $name => $type) {
    $j = new Judy($type);
    $expected = [];
    $val = 1;
    for ($len = 0; $len <= MAXLEN; $len++) {
        foreach (variants($len) as $k) {
            $j[$k] = $val;
            $expected[$k] = $val;
            $val++;
        }
    }
    $fail = 0;

    // insert count
    if ($j->count() !== count($expected)) { echo "$name: count after insert mismatch\n"; $fail++; }

    // overwrite every key (JudySLIns duplicate path), verify new value
    foreach ($expected as $k => $v) {
        $k = (string)$k;                 // PHP coerces numeric-string array keys to int
        $j[$k] = $v + 100000;
        $expected[$k] = $v + 100000;
    }
    if ($j->count() !== count($expected)) { echo "$name: count changed on overwrite\n"; $fail++; }
    foreach ($expected as $k => $v) {
        $k = (string)$k;
        if ($j[$k] !== $v) { echo "$name: get(" . bin2hex($k) . ") wrong after overwrite\n"; $fail++; }
        if (!isset($j[$k])) { echo "$name: isset(" . bin2hex($k) . ") false\n"; $fail++; }
    }

    // lookup misses: extensions and case-flips of stored keys must miss
    for ($len = 0; $len <= MAXLEN; $len++) {
        $probe = str_repeat("a", $len) . "!";        // stored prefix + 1
        if (isset($j[$probe])) { echo "$name: phantom hit len=" . ($len + 1) . "\n"; $fail++; }
        if ($len > 0 && isset($j[str_repeat("A", $len)])) { echo "$name: phantom hit case len=$len\n"; $fail++; }
    }

    // ordered forward walk via first/next (JudySLNext at every length)
    $sorted = array_map("strval", array_keys($expected));
    usort($sorted, "strcmp");
    $walk = [];
    for ($k = $j->first(); $k !== null; $k = $j->searchNext($k)) $walk[] = $k;
    if ($walk !== $sorted) { echo "$name: forward walk order mismatch\n"; $fail++; }

    // ordered backward walk via last/prev (JudySLPrev at every length)
    $back = [];
    for ($k = $j->last(); $k !== null; $k = $j->prev($k)) $back[] = $k;
    if ($back !== array_reverse($sorted)) { echo "$name: backward walk order mismatch\n"; $fail++; }

    // delete misses first (nothing must change), then delete every key
    unset($j[str_repeat("a", MAXLEN) . "!"]);
    if ($j->count() !== count($expected)) { echo "$name: delete-miss changed count\n"; $fail++; }
    $n = count($expected);
    foreach ($expected as $k => $v) {
        $k = (string)$k;
        unset($j[$k]);
        $n--;
        if (isset($j[$k])) { echo "$name: key survives delete len=" . strlen($k) . "\n"; $fail++; }
        if ($j->count() !== $n) { echo "$name: count wrong after delete len=" . strlen($k) . "\n"; $fail++; }
    }
    if ($j->count() !== 0) { echo "$name: not empty at end\n"; $fail++; }

    echo "$name: " . ($fail ? "FAIL ($fail)" : "OK") . "\n";
    $j->free();
}

/*
 * JudyHS-specific (hash types): engineered 32-bit hash collisions exercise
 * the JudyHSDel collision subtree: under c' = c*31 + byte, the 2-byte
 * blocks "Aa", "BB", "C#" all contribute 65*31+97 = 66*31+66 = 67*31+35
 * = 2112, so same-length keys built from these blocks share the full hash.
 * (Embedded-NUL keys are legal for JudyHS itself but rejected by the PHP
 * layer for hash types; the C-level differential fuzzer covers them.)
 */
foreach (["STRING_TO_INT_HASH" => Judy::STRING_TO_INT_HASH,
          "STRING_TO_MIXED_HASH" => Judy::STRING_TO_MIXED_HASH] as $name => $type) {
    $j = new Judy($type);
    $fail = 0;

    // colliding family, length 10 (> 8 so the hash path is used)
    $family = [str_repeat("Aa", 5), str_repeat("BB", 5), str_repeat("C#", 5),
               "AaBBC#AaBB", "BBAaAaC#C#"];
    foreach ($family as $i => $k) $j[$k] = 1000 + $i;

    // delete a colliding family member that was NOT inserted: must miss
    // and must not disturb the stored colliders (O4d leaf-compare guard)
    $absent = "C#C#BBAaAa";
    unset($j[$absent]);
    if (isset($j[$absent])) { echo "$name: absent collider present?\n"; $fail++; }
    foreach ($family as $i => $k) {
        if ($j[$k] !== 1000 + $i) { echo "$name: collider " . $k . " damaged by miss-delete\n"; $fail++; }
    }

    // delete the family one by one; survivors must stay intact
    $n = $j->count();
    foreach ($family as $i => $k) {
        unset($j[$k]);
        $n--;
        if ($j->count() !== $n) { echo "$name: collider count wrong after delete $k\n"; $fail++; }
        for ($r = $i + 1; $r < count($family); $r++) {
            if ($j[$family[$r]] !== 1000 + $r) { echo "$name: survivor {$family[$r]} damaged\n"; $fail++; }
        }
    }

    if ($j->count() !== 0) { echo "$name: not empty at end\n"; $fail++; }

    echo "$name collisions: " . ($fail ? "FAIL ($fail)" : "OK") . "\n";
    $j->free();
}
?>
--EXPECT--
STRING_TO_INT: OK
STRING_TO_MIXED: OK
STRING_TO_INT_HASH: OK
STRING_TO_MIXED_HASH: OK
STRING_TO_INT_ADAPTIVE: OK
STRING_TO_MIXED_ADAPTIVE: OK
STRING_TO_INT_HASH collisions: OK
STRING_TO_MIXED_HASH collisions: OK
