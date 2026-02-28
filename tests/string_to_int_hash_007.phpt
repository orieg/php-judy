--TEST--
Check for Judy STRING_TO_INT_HASH serialization (__serialize/__unserialize) and JSON
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_INT_HASH);
$judy["alpha"] = 10;
$judy["beta"]  = 20;
$judy["gamma"] = 30;

// JSON serialization
$json = json_encode($judy);
echo "JSON: $json\n";

// __serialize / __unserialize
$serialized = serialize($judy);
$judy2 = unserialize($serialized);

echo "Unserialized type: " . $judy2->getType() . "\n";
echo "Unserialized count: " . $judy2->count() . "\n";
echo "alpha: " . $judy2["alpha"] . "\n";
echo "beta: " . $judy2["beta"] . "\n";
echo "gamma: " . $judy2["gamma"] . "\n";

// toArray
$arr = $judy->toArray();
var_dump($arr);

echo "Done\n";
?>
--EXPECT--
JSON: {"alpha":10,"beta":20,"gamma":30}
Unserialized type: 8
Unserialized count: 3
alpha: 10
beta: 20
gamma: 30
array(3) {
  ["alpha"]=>
  int(10)
  ["beta"]=>
  int(20)
  ["gamma"]=>
  int(30)
}
Done
