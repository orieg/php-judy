--TEST--
Check for Judy BITSET free/count/by_count methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::BITSET);

// Init array

echo "Set 100 index\n";
for ($i=0; $i<100; $i++) {
        if(!$judy->set($i))
            echo "Failed to set index $i\n";
}

// Count

echo "Half count: ".$judy->count(0, 49)."\n";
echo "Count: ".$judy->count()."\n";

if ($judy->unset(50))
    echo "Unset index 50\n";

echo "First half count: ".$judy->count(0, 49)."\n";
echo "Second half count: ".$judy->count(50, 100)."\n";
echo "Count: ".$judy->count()."\n";

// By count

if (($index = $judy->by_count(50)) !== null)
    echo "By count (50th): $index\n";
else
    echo "By count (50th set index) failed\n";

if (($index = $judy->by_count(51)) !== null)
    echo "By count (51th): $index\n";
else
    echo "By count (51th set index) failed\n";

// Free

if (!$judy->free())
    echo "Failed to free Judy array\n";
else
    echo "Freeing Judy array\n";

echo "Count: ".$judy->count()."\n";

echo "Done\n";
?>
--EXPECT--
Set 100 index
Half count: 50
Count: 100
Unset index 50
First half count: 50
Second half count: 49
Count: 99
By count (50th): 49
By count (51th): 51
Freeing Judy array
Count: 0
Done
