--TEST--
Judy clone STRING_TO_INT independence
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_INT);
$j["apple"] = 10;
$j["banana"] = 20;
$j["cherry"] = 30;

$clone = clone $j;

// Verify clone has same data
echo "Clone count: " . $clone->count() . "\n";
echo "Clone[apple]: " . $clone["apple"] . "\n";
echo "Clone[banana]: " . $clone["banana"] . "\n";
echo "Clone[cherry]: " . $clone["cherry"] . "\n";

// Modify original, verify clone unchanged
$j["apple"] = 999;
unset($j["banana"]);
$j["date"] = 40;

echo "After modify - original count: " . $j->count() . "\n";
echo "After modify - clone count: " . $clone->count() . "\n";
echo "Original[apple]: " . $j["apple"] . "\n";
echo "Clone[apple]: " . $clone["apple"] . "\n";
echo "Original[banana] exists: " . (isset($j["banana"]) ? "yes" : "no") . "\n";
echo "Clone[banana]: " . $clone["banana"] . "\n";
echo "Clone[date] exists: " . (isset($clone["date"]) ? "yes" : "no") . "\n";
?>
--EXPECT--
Clone count: 3
Clone[apple]: 10
Clone[banana]: 20
Clone[cherry]: 30
After modify - original count: 3
After modify - clone count: 3
Original[apple]: 999
Clone[apple]: 10
Original[banana] exists: no
Clone[banana]: 20
Clone[date] exists: no
