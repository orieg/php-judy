--TEST--
Judy::STRING_TO_ENTRY Iterator, navigation and toArray()
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_ENTRY);
$j->set("charlie", 300);
$j->set("alice", 100);
$j->set("bob", 200);

// foreach
foreach ($j as $k => $v) {
    echo "$k => $v
";
}

// navigation
var_dump($j->first());
var_dump($j->searchNext("alice"));
var_dump($j->last());
var_dump($j->prev("charlie"));

// toArray
var_dump($j->toArray());
var_dump($j->keys());
var_dump($j->values());
?>
--EXPECT--
alice => 100
bob => 200
charlie => 300
string(5) "alice"
string(3) "bob"
string(7) "charlie"
string(3) "bob"
array(3) {
  ["alice"]=>
  int(100)
  ["bob"]=>
  int(200)
  ["charlie"]=>
  int(300)
}
array(3) {
  [0]=>
  string(5) "alice"
  [1]=>
  string(3) "bob"
  [2]=>
  string(7) "charlie"
}
array(3) {
  [0]=>
  int(100)
  [1]=>
  int(200)
  [2]=>
  int(300)
}
