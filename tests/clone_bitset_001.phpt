--TEST--
Test Judy BITSET clone correctness
--FILE--
<?php
$judy = new Judy(Judy::BITSET);
$judy[10] = true;
$judy[20] = true;
$judy[30] = true;
$judy[100] = true;
$judy[200] = true;

$clone = clone $judy;

// Verify all original keys exist in clone
$keys = [];
for ($clone->rewind(); $clone->valid(); $clone->next()) {
    $keys[] = $clone->key();
}

echo "Original count: " . $judy->count() . "\n";
echo "Clone count: " . $clone->count() . "\n";
echo "Clone keys: " . implode(",", $keys) . "\n";

// Verify independence - modifying clone doesn't affect original
$clone[500] = true;
echo "Original after clone modification: " . $judy->count() . "\n";
echo "Clone after modification: " . $clone->count() . "\n";
?>
--EXPECT--
Original count: 5
Clone count: 5
Clone keys: 10,20,30,100,200
Original after clone modification: 5
Clone after modification: 6
