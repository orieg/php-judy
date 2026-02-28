--TEST--
Judy batch operations with 1000+ elements
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Build large array
$data = [];
for ($i = 0; $i < 2000; $i++) {
    $data[$i] = $i * 10;
}

// fromArray with 2000 elements
$j = Judy::fromArray(Judy::INT_TO_INT, $data);
echo "fromArray count: " . $j->count() . "\n";
echo "Spot check [0]: " . $j[0] . "\n";
echo "Spot check [999]: " . $j[999] . "\n";
echo "Spot check [1999]: " . $j[1999] . "\n";

// toArray roundtrip
$arr = $j->toArray();
echo "toArray count: " . count($arr) . "\n";
echo "toArray[500]: " . $arr[500] . "\n";

// putAll with 1000 new elements
$more = [];
for ($i = 2000; $i < 3000; $i++) {
    $more[$i] = $i * 10;
}
$j->putAll($more);
echo "After putAll count: " . $j->count() . "\n";
echo "Spot check [2500]: " . $j[2500] . "\n";

// getAll with 100 keys
$keys = range(0, 99);
$result = $j->getAll($keys);
echo "getAll 100 keys count: " . count($result) . "\n";
echo "getAll[50]: " . $result[50] . "\n";

// STRING_TO_INT large batch
$sdata = [];
for ($i = 0; $i < 1000; $i++) {
    $sdata["key_" . str_pad($i, 4, "0", STR_PAD_LEFT)] = $i;
}
$j = Judy::fromArray(Judy::STRING_TO_INT, $sdata);
echo "STRING_TO_INT fromArray count: " . $j->count() . "\n";
echo "Spot check [key_0500]: " . $j["key_0500"] . "\n";

$arr = $j->toArray();
echo "STRING_TO_INT toArray count: " . count($arr) . "\n";
?>
--EXPECT--
fromArray count: 2000
Spot check [0]: 0
Spot check [999]: 9990
Spot check [1999]: 19990
toArray count: 2000
toArray[500]: 5000
After putAll count: 3000
Spot check [2500]: 25000
getAll 100 keys count: 100
getAll[50]: 500
STRING_TO_INT fromArray count: 1000
Spot check [key_0500]: 500
STRING_TO_INT toArray count: 1000
