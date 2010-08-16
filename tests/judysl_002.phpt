--TEST--
Check for JudySL free/size methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new JudySL();

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
    echo "Failed to free JudySL array\n";
else
    echo "Freeing JudySL array\n";

echo "Size: ".$judy->size()."\n";

echo "Done\n";
?>
--EXPECT--
Insert 100 index with a rand value
Size: 100
Delete index 50
Size: 99
Freeing JudySL array
Size: 0
Done
