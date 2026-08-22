--TEST--
Judy::STRING_TO_ENTRY TTL expiration and pruneExpired()
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_ENTRY);
$now = 1700000000;

// Set keys with explicit expiration timestamps simulated via set / prune
// k1: expires at $now + 10
// k2: expires at $now + 100
// k3: never expires
$j->set("k1", "val1", ttl: 10);
$j->set("k2", "val2", ttl: 100);
$j->set("k3", "val3", ttl: 0);

$exp1 = $j->getExpiry("k1");
$exp2 = $j->getExpiry("k2");
$exp3 = $j->getExpiry("k3");

var_dump($exp1 > 0);
var_dump($exp2 > $exp1);
var_dump($exp3 === 0);

var_dump(count($j));

// Prune at time before any expiry
$pruned = $j->pruneExpired($exp1 - 5);
var_dump($pruned);
var_dump(count($j));

// Prune at time when k1 has expired
$pruned = $j->pruneExpired($exp1 + 1);
var_dump($pruned);
var_dump(count($j));
var_dump($j->get("k1"));
var_dump(isset($j["k1"]));
var_dump($j->get("k2"));
var_dump($j->get("k3"));

// Prune everything up to exp2
$pruned = $j->pruneExpired($exp2 + 1);
var_dump($pruned);
var_dump(count($j));
var_dump($j->get("k2"));
var_dump($j->get("k3"));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(3)
int(0)
int(3)
int(1)
int(2)
NULL
bool(false)
string(4) "val2"
string(4) "val3"
int(1)
int(1)
NULL
string(4) "val3"
