--TEST--
Judy range methods accept uniform $start/$end named arguments
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/*
 * Every range in this API is a pair of inclusive KEYS, never an offset and a
 * length, and every range method spells its bounds $start/$end. This test is
 * the guard on that second half: named arguments only stay uniform if nobody
 * reintroduces a differently-named parameter later.
 */

$j = Judy::fromArray(Judy::INT_TO_INT, [1 => 10, 5 => 50, 10 => 100, 1000 => 1000]);

echo "size:            ", $j->size(start: 0, end: 100), "\n";
echo "populationCount: ", $j->populationCount(start: 0, end: 100), "\n";
echo "slice:           ", json_encode($j->slice(start: 0, end: 100)->keys()), "\n";
echo "keys:            ", json_encode($j->keys(start: 0, end: 100)), "\n";
echo "values:          ", json_encode($j->values(start: 0, end: 100)), "\n";
echo "toArray:         ", json_encode($j->toArray(start: 0, end: 100)), "\n";

/* Skipping the first bound by name is the payoff of a uniform spelling. */
echo "keys(end:):      ", json_encode($j->keys(end: 10)), "\n";
echo "toArray(start:): ", json_encode($j->toArray(start: 10)), "\n";

$d = Judy::fromArray(Judy::INT_TO_INT, [1 => 10, 5 => 50, 10 => 100]);
echo "deleteRange:     ", $d->deleteRange(start: 0, end: 5), " deleted, left ",
    json_encode($d->keys()), "\n";

/* The bounds are keys, not offsets: this range spans positions 0..2 of the
   array but only keys 0..2 of the key space, where a single element lives. */
echo "keys(0,2):       ", json_encode($j->keys(0, 2)), "\n";
echo "byCount(2):      ", $j->byCount(2), "  <- the positional accessor\n";

/* Reflection: the parameter names are the documented contract. */
foreach (['size', 'populationCount', 'slice', 'deleteRange', 'keys', 'values', 'toArray'] as $m) {
    $names = array_map(
        static fn(ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod('Judy', $m))->getParameters()
    );
    printf("%-16s %s\n", $m . ':', implode(', ', $names));
}
?>
--EXPECT--
size:            3
populationCount: 3
slice:           [1,5,10]
keys:            [1,5,10]
values:          [10,50,100]
toArray:         {"1":10,"5":50,"10":100}
keys(end:):      [1,5,10]
toArray(start:): {"10":100,"1000":1000}
deleteRange:     2 deleted, left [10]
keys(0,2):       [1]
byCount(2):      5  <- the positional accessor
size:            start, end
populationCount: start, end
slice:           start, end
deleteRange:     start, end
keys:            start, end
values:          start, end
toArray:         start, end
