--TEST--
Judy memoryUsage() reports approximate bytes for every string-keyed type
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::STRING_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
$types = [
    'STRING_TO_INT'            => false,
    'STRING_TO_MIXED'          => true,
    'STRING_TO_INT_HASH'       => false,
    'STRING_TO_MIXED_HASH'     => true,
    'STRING_TO_INT_ADAPTIVE'   => false,
    'STRING_TO_MIXED_ADAPTIVE' => true,
];

foreach ($types as $name => $mixed) {
    $j = new Judy(constant("Judy::$name"));
    $out = [];

    $out[] = "new is int: " . (is_int($j->memoryUsage()) ? "yes" : "no");
    $out[] = "new is zero: " . ($j->memoryUsage() === 0 ? "yes" : "no");

    // Monotonic growth across inserts.
    $growing = true;
    $prev = $j->memoryUsage();
    for ($i = 0; $i < 50; $i++) {
        $j["key_with_padding_$i"] = $mixed ? "value_$i" : $i;
        $now = $j->memoryUsage();
        if ($now <= $prev) { $growing = false; }
        $prev = $now;
    }
    $out[] = "grows on insert: " . ($growing ? "yes" : "no");

    // An overwrite changes no key and allocates no extra slot.
    $before = $j->memoryUsage();
    $j["key_with_padding_0"] = $mixed ? "replacement" : 999;
    $out[] = "flat on overwrite: " . ($j->memoryUsage() === $before ? "yes" : "no");

    // A delete gives the bytes back; a no-op delete does not move it.
    $before = $j->memoryUsage();
    unset($j["key_with_padding_0"]);
    $out[] = "shrinks on delete: " . ($j->memoryUsage() < $before ? "yes" : "no");
    $after = $j->memoryUsage();
    unset($j["never_inserted"]);
    $out[] = "flat on absent delete: " . ($j->memoryUsage() === $after ? "yes" : "no");

    // Longer keys must cost more than shorter ones.
    $before = $j->memoryUsage();
    $j["s"] = $mixed ? "x" : 1;
    $short = $j->memoryUsage() - $before;
    $before = $j->memoryUsage();
    $j[str_repeat("l", 64)] = $mixed ? "x" : 1;
    $long = $j->memoryUsage() - $before;
    $out[] = "longer key costs more: " . ($long > $short ? "yes" : "no");

    // Emptying by hand returns to exactly zero.
    foreach ($j->keys() as $k) { unset($j[$k]); }
    $out[] = "zero after unsetting all: " . ($j->memoryUsage() === 0 ? "yes" : "no");

    // So does free().
    $j["back_again"] = $mixed ? "v" : 1;
    $j->free();
    $out[] = "zero after free(): " . ($j->memoryUsage() === 0 ? "yes" : "no");

    echo "== $name\n", implode("\n", $out), "\n";
}
?>
--EXPECT--
== STRING_TO_INT
new is int: yes
new is zero: yes
grows on insert: yes
flat on overwrite: yes
shrinks on delete: yes
flat on absent delete: yes
longer key costs more: yes
zero after unsetting all: yes
zero after free(): yes
== STRING_TO_MIXED
new is int: yes
new is zero: yes
grows on insert: yes
flat on overwrite: yes
shrinks on delete: yes
flat on absent delete: yes
longer key costs more: yes
zero after unsetting all: yes
zero after free(): yes
== STRING_TO_INT_HASH
new is int: yes
new is zero: yes
grows on insert: yes
flat on overwrite: yes
shrinks on delete: yes
flat on absent delete: yes
longer key costs more: yes
zero after unsetting all: yes
zero after free(): yes
== STRING_TO_MIXED_HASH
new is int: yes
new is zero: yes
grows on insert: yes
flat on overwrite: yes
shrinks on delete: yes
flat on absent delete: yes
longer key costs more: yes
zero after unsetting all: yes
zero after free(): yes
== STRING_TO_INT_ADAPTIVE
new is int: yes
new is zero: yes
grows on insert: yes
flat on overwrite: yes
shrinks on delete: yes
flat on absent delete: yes
longer key costs more: yes
zero after unsetting all: yes
zero after free(): yes
== STRING_TO_MIXED_ADAPTIVE
new is int: yes
new is zero: yes
grows on insert: yes
flat on overwrite: yes
shrinks on delete: yes
flat on absent delete: yes
longer key costs more: yes
zero after unsetting all: yes
zero after free(): yes
