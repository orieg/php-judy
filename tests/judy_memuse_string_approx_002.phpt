--TEST--
Judy memoryUsage() accounting survives clone, serialize, and every bulk mutation
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::STRING_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
/* The reported total must be a pure function of the live contents: an array
   reached by any route has to agree with one built from scratch holding the
   same keys and values. That is what pins the accounting to the operations
   below, without hard-coding the per-entry model. */
function rebuilt(Judy $j): int
{
    return Judy::fromArray($j->getType(), $j->toArray())->memoryUsage();
}

function check(string $what, Judy $j): void
{
    printf("%-22s %s\n", $what, $j->memoryUsage() === rebuilt($j) ? "agrees" : "DIFFERS");
}

$types = [
    'STRING_TO_INT'            => false,
    'STRING_TO_MIXED'          => true,
    'STRING_TO_INT_HASH'       => false,
    'STRING_TO_MIXED_HASH'     => true,
    'STRING_TO_INT_ADAPTIVE'   => false,
    'STRING_TO_MIXED_ADAPTIVE' => true,
];

foreach ($types as $name => $mixed) {
    $type = constant("Judy::$name");
    echo "== $name\n";

    $j = new Judy($type);
    /* Both key shapes: ADAPTIVE packs keys shorter than 8 bytes into the index
       word and copies longer ones into JudyHS, so they are accounted apart. */
    for ($i = 0; $i < 30; $i++) { $j[sprintf("s%03d", $i)] = $mixed ? "v$i" : $i; }
    for ($i = 0; $i < 30; $i++) { $j["long_padded_key_$i"] = $mixed ? "v$i" : $i; }
    check("populated", $j);

    check("clone", clone $j);
    check("unserialize", unserialize(serialize($j)));
    check("slice", $j->slice("s000", "s015"));
    check("filter", $j->filter(fn($v, $k) => true));
    check("map", $j->map(fn($v, $k) => $v));

    $d = clone $j;
    $d->deleteRange("s005", "s020");
    $d->deleteRange("long_padded_key_1", "long_padded_key_2");
    check("deleteRange", $d);

    $other = new Judy($type);
    $other["s001"] = $mixed ? "overwritten" : 42;
    $other["brand_new_long_key"] = $mixed ? "fresh" : 43;
    $m = clone $j;
    $m->mergeWith($other);
    check("mergeWith", $m);

    $p = Judy::fromArray($type, ["from_array_key" => $mixed ? "a" : 1]);
    $p->putAll(["put_all_key" => $mixed ? "b" : 2, "from_array_key" => $mixed ? "c" : 3]);
    check("fromArray+putAll", $p);

    if (!$mixed) {
        check("union", $j->union($other));
        check("intersect", $j->intersect($other));
        check("diff", $j->diff($other));
        check("xor", $j->xor($other));

        /* increment() covers STRING_TO_INT and STRING_TO_INT_HASH only. */
        if ($name !== 'STRING_TO_INT_ADAPTIVE') {
            $inc = clone $j;
            $inc->increment("s001");
            $inc->increment("newly_created_by_increment");
            check("increment", $inc);
        }
    }

    /* Serialize/clone must reproduce the number itself, not merely a consistent one. */
    printf("%-22s %s\n", "clone equals source",
        (clone $j)->memoryUsage() === $j->memoryUsage() ? "yes" : "no");
    printf("%-22s %s\n", "unserialize equals",
        unserialize(serialize($j))->memoryUsage() === $j->memoryUsage() ? "yes" : "no");
}
?>
--EXPECT--
== STRING_TO_INT
populated              agrees
clone                  agrees
unserialize            agrees
slice                  agrees
filter                 agrees
map                    agrees
deleteRange            agrees
mergeWith              agrees
fromArray+putAll       agrees
union                  agrees
intersect              agrees
diff                   agrees
xor                    agrees
increment              agrees
clone equals source    yes
unserialize equals     yes
== STRING_TO_MIXED
populated              agrees
clone                  agrees
unserialize            agrees
slice                  agrees
filter                 agrees
map                    agrees
deleteRange            agrees
mergeWith              agrees
fromArray+putAll       agrees
clone equals source    yes
unserialize equals     yes
== STRING_TO_INT_HASH
populated              agrees
clone                  agrees
unserialize            agrees
slice                  agrees
filter                 agrees
map                    agrees
deleteRange            agrees
mergeWith              agrees
fromArray+putAll       agrees
union                  agrees
intersect              agrees
diff                   agrees
xor                    agrees
increment              agrees
clone equals source    yes
unserialize equals     yes
== STRING_TO_MIXED_HASH
populated              agrees
clone                  agrees
unserialize            agrees
slice                  agrees
filter                 agrees
map                    agrees
deleteRange            agrees
mergeWith              agrees
fromArray+putAll       agrees
clone equals source    yes
unserialize equals     yes
== STRING_TO_INT_ADAPTIVE
populated              agrees
clone                  agrees
unserialize            agrees
slice                  agrees
filter                 agrees
map                    agrees
deleteRange            agrees
mergeWith              agrees
fromArray+putAll       agrees
union                  agrees
intersect              agrees
diff                   agrees
xor                    agrees
clone equals source    yes
unserialize equals     yes
== STRING_TO_MIXED_ADAPTIVE
populated              agrees
clone                  agrees
unserialize            agrees
slice                  agrees
filter                 agrees
map                    agrees
deleteRange            agrees
mergeWith              agrees
fromArray+putAll       agrees
clone equals source    yes
unserialize equals     yes
