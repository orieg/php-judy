--TEST--
Check for Judy STRING_TO_MIXED free/size methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::STRING_TO_MIXED);

// Init array

echo "Insert 100 index with a rand value\n";
for ($i=0; $i<100; $i++) {
        $value = rand();
        $judy["$i"] = "$value";
        if(!$judy["$i"])
            echo "Failed to insert index $i (value: $value)\n";
}

// Size

echo "Size Method: ".$judy->size()."\n";
echo "Count Method: ".$judy->count()."\n";
echo "Count Function: ".count($judy)."\n";

unset($judy["50"]);
if (!$judy["50"])
    echo "Delete index 50\n";

echo "Size Method: ".$judy->size()."\n";
echo "Count Method: ".$judy->count()."\n";
echo "Count Function: ".count($judy)."\n";

// Free

if (!$judy->free())
    echo "Failed to free Judy array\n";
else
    echo "Freeing Judy array\n";

echo "Size: ".$judy->size()."\n";
echo "Count: ".$judy->count()."\n";

echo "Done\n";
?>
--EXPECT--
Insert 100 index with a rand value
Size Method: 100
Count Method: 100
Count Function: 100
Delete index 50
Size Method: 99
Count Method: 99
Count Function: 99
Freeing Judy array
Size: 0
Count: 0
Done
