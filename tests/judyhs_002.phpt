--TEST--
Check for JudyHS free/size methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new JudyHS();

// Init array

echo "Insert 100 index with a rand value\n";
for ($i=0; $i<100; $i++) {
        $value = rand();
        if(!$judy->ins("$i", $value))
            echo "Failed to insert index $i (value: $value)\n";
}

// Size

echo "Size: ".$judy->size()."\n";

if ($judy->del("50"))
    echo "Delete index 50\n";

echo "Size: ".$judy->size()."\n";

// Free

if (!$judy->free())
    echo "Failed to free JudyHS array\n";
else
    echo "Freeing JudyHS array\n";

echo "Size: ".$judy->size()."\n";

echo "Done\n";
?>
--EXPECT--
Insert 100 index with a rand value
Size: 100
Delete index 50
Size: 99
Freeing JudyHS array
Size: 0
Done
