--TEST--
Judy INT_TO_PACKED value type preservation across serialize/deserialize roundtrip
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_PACKED); } catch (Exception $e) { print "skip PACKED types not supported"; }
?>
--FILE--
<?php
$j = new Judy(Judy::INT_TO_PACKED);

// Store an object with __serialize/__unserialize
class Point {
    public function __construct(public int $x, public int $y) {}
}

$j[0] = new Point(10, 20);
$j[1] = new Point(30, 40);

$p0 = $j[0];
$p1 = $j[1];

echo "p0: x={$p0->x}, y={$p0->y}\n";
echo "p1: x={$p1->x}, y={$p1->y}\n";
echo "class: " . get_class($p0) . "\n";

// Nested array with objects
$j[2] = ["points" => [new Point(1, 2), new Point(3, 4)]];
$arr = $j[2];
echo "nested point: x={$arr['points'][0]->x}, y={$arr['points'][0]->y}\n";

echo "Done\n";
?>
--EXPECT--
p0: x=10, y=20
p1: x=30, y=40
class: Point
nested point: x=1, y=2
Done
