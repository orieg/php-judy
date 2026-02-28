--TEST--
Check for Judy STRING_TO_INT_HASH batch operations (fromArray, putAll, getAll) and increment
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
// fromArray
$judy = Judy::fromArray(Judy::STRING_TO_INT_HASH, [
    "x" => 10,
    "y" => 20,
    "z" => 30,
]);
echo "fromArray count: " . $judy->count() . "\n";
echo "x: " . $judy["x"] . "\n";
echo "y: " . $judy["y"] . "\n";
echo "z: " . $judy["z"] . "\n";

// putAll
$judy->putAll(["a" => 1, "b" => 2]);
echo "After putAll count: " . $judy->count() . "\n";
echo "a: " . $judy["a"] . "\n";
echo "b: " . $judy["b"] . "\n";

// getAll
$results = $judy->getAll(["x", "a", "missing"]);
var_dump($results);

// increment
$judy2 = new Judy(Judy::STRING_TO_INT_HASH);
$val = $judy2->increment("counter");
echo "First increment: $val\n";
$val = $judy2->increment("counter");
echo "Second increment: $val\n";
$val = $judy2->increment("counter", 5);
echo "Increment by 5: $val\n";
$val = $judy2->increment("counter", -3);
echo "Decrement by 3: $val\n";
echo "Count after increments: " . $judy2->count() . "\n";

echo "Done\n";
?>
--EXPECT--
fromArray count: 3
x: 10
y: 20
z: 30
After putAll count: 5
a: 1
b: 2
array(3) {
  ["x"]=>
  int(10)
  ["a"]=>
  int(1)
  ["missing"]=>
  NULL
}
First increment: 1
Second increment: 2
Increment by 5: 7
Decrement by 3: 4
Count after increments: 1
Done
