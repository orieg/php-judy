--TEST--
Judy::STRING_TO_ENTRY basic CRUD, TTL metadata and flags
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$j = new Judy(Judy::STRING_TO_ENTRY);
var_dump($j->getType() === Judy::STRING_TO_ENTRY);

// Basic set without TTL / flags
$j->set("user:1", ["id" => 1, "name" => "Alice"]);
var_dump($j->get("user:1"));
var_dump($j->getExpiry("user:1"));
var_dump($j->getFlags("user:1"));

// Set with TTL and flags
$j->set("session:abc", "data_payload", ttl: 3600, flags: 42);
$expiry = null;
$flags = null;
$val = $j->get("session:abc", $expiry, $flags);
var_dump($val);
var_dump($expiry > time());
var_dump($flags);
var_dump($j->getExpiry("session:abc") === $expiry);
var_dump($j->getFlags("session:abc") === 42);

// getEntry
$entry = $j->getEntry("session:abc");
var_dump($entry["value"]);
var_dump($entry["expires_at"] === $expiry);
var_dump($entry["flags"] === 42);
var_dump($entry["is_expired"] === false);

// Non-existent key
var_dump($j->get("non_existent"));
var_dump($j->getEntry("non_existent"));
var_dump($j->getExpiry("non_existent"));
var_dump($j->getFlags("non_existent"));
?>
--EXPECT--
bool(true)
array(2) {
  ["id"]=>
  int(1)
  ["name"]=>
  string(5) "Alice"
}
int(0)
int(0)
string(12) "data_payload"
bool(true)
int(42)
bool(true)
bool(true)
string(12) "data_payload"
bool(true)
bool(true)
bool(true)
NULL
NULL
NULL
NULL
