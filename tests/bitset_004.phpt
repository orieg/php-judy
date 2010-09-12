--TEST--
Check for Judy BITSET first_empty/next_empty/last_empty/prev_empty methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::BITSET);

// Init array

echo "Insert 500 index\n";
for ($i=100; $i<600; $i++) {
        if(!$judy->set($i))
            echo "Failed to set index $i\n";
}

$unset = array(150, 232, 346, 427, 589);
foreach($unset as $i) {
    echo "Unset index $i\n";
    if(!$judy->unset($i))
        echo "Failed to delete index $i\n";
}

// First Empty

$firstIndexDefault = $judy->first_empty();
echo "First empty index set: $firstIndexDefault\n";

$firstIndex50 = $judy->first_empty(50);
echo "First empty index set from index 50: $firstIndex50\n";

$firstIndex500 = $judy->first_empty(500);
echo "First empty index set from index 500: $firstIndex500\n";

// Last Empty

$lastIndexDefault = $judy->last_empty();
echo "Last empty index set: $lastIndexDefault\n";

$lastIndex1000 = $judy->last_empty(1000);
echo "Last empty index set from index 1000: $lastIndex1000\n";

$lastIndex500 = $judy->last_empty(500);
echo "Last empty index set from index 500: $lastIndex500\n";

// Next Empty

echo "Testing next_empty()\n";
$index = $firstIndexDefault;
while ($index < $lastIndex500) {
    $parent_index = $index;
    $index = $judy->next_empty($parent_index);
    if (empty($index) || $index < $firstIndexDefault) {
        echo "Failed to get next index from parent index ($parent_index)\n";
        break;
    }
}

// Prev Empty

echo "Testing prev_empty()\n";
$index = 600;
while ($index > $firstIndexDefault) {
    $parent_index = $index;
    $index = $judy->prev_empty($parent_index);
    if ($index < 0) {
        echo "Failed to get previous index from parent index ($parent_index)\n";
        break;
    }
}

echo "Done\n";
?>
--EXPECT--
Insert 500 index
Unset index 150
Unset index 232
Unset index 346
Unset index 427
Unset index 589
First empty index set: 0
First empty index set from index 50: 50
First empty index set from index 500: 589
Last empty index set: -1
Last empty index set from index 1000: 1000
Last empty index set from index 500: 427
Testing next_empty()
Testing prev_empty()
Done
