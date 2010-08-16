--TEST--
Check for JudySL ins/del/get methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new JudySL();

// Insert 

echo "Insert 100 index with a rand value\n";
for ($i=0; $i<100; $i++) {
        $value = rand();
        if(!$judy->ins("$i", $value))
            echo "Failed to insert index $i (value: $value)\n";
}

// Get

echo "Get 100 index\n";
for ($i=0; $i<100; $i++) {
        if($judy->get("$i") === null)
            echo "Get index $i returned null\n";
}

// Remove

echo "Remove 100 index\n";
for ($i=0; $i<100; $i++) {
        if(!$judy->del("$i"))
            echo "Failed to remove index $i\n";
}

// Get

echo "Get 100 index (should be empty)\n";
for ($i=0; $i<100; $i++) {
        if(($v = $judy->get("$i")) !== null)
            echo "Get index $i returned $v\n";
}

echo "Testing values are properly inserted and returned\n";
$judy->ins("test string index", 987);
$v = $judy->get("test string index");
if ($v != 987)
    echo "Value doesn't match to the one inserted (expected 987 got $v)\n";

echo "Done\n";
?>
--EXPECT--
Insert 100 index with a rand value
Get 100 index
Remove 100 index
Get 100 index (should be empty)
Testing values are properly inserted and returned
Done
