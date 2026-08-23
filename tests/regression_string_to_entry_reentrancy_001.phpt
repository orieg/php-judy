--TEST--
Regression: Judy STRING_TO_ENTRY destructor reentrancy and UAF safety
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
class EvilObject {
    public function __construct(public Judy $judy, public string $key) {}
    public function __destruct() {
        // Reentrantly unset or access key during destruction
        unset($this->judy[$this->key]);
    }
}

class PruneEvilObject {
    public function __construct(public Judy $judy) {}
    public function __destruct() {
        // Reentrantly access/prune the cache
        $this->judy->pruneExpired();
    }
}

// 1. ArrayAccess overwrite reentrancy
$j = new Judy(Judy::STRING_TO_ENTRY);
$j["k"] = new EvilObject($j, "k");
$j["k"] = 42;
var_dump(isset($j["k"]));

// 2. Judy::set() overwrite reentrancy
$j2 = new Judy(Judy::STRING_TO_ENTRY);
$j2->set("token", new EvilObject($j2, "token"), 300);
$j2->set("token", "new_token_payload", 300);
var_dump(isset($j2["token"]));

// 3. Judy::pruneExpired() reentrancy
$j3 = new Judy(Judy::STRING_TO_ENTRY);
$j3->set("expired_item", new PruneEvilObject($j3), 1);
sleep(2);
$evicted = $j3->pruneExpired();
var_dump($evicted >= 1);
var_dump(count($j3));

echo "OK\n";
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
int(0)
OK
