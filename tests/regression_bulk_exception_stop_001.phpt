--TEST--
Regression: putAll stops on the first throwing key instead of plowing on
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// judy_populate_from_array kept writing after a key threw (embedded NUL /
// oversize), leaving an exception pending across further inserts. It must stop
// at the first error.
$j = new Judy(Judy::STRING_TO_INT);
$oversize = str_repeat('x', 70000);   // >= PHP_JUDY_MAX_LENGTH: throws
try {
    $j->putAll(["ok1" => 1, $oversize => 2, "ok2" => 3]);
    echo "no exception (unexpected)\n";
} catch (\Throwable $e) {
    echo "caught: " . $e->getMessage() . "\n";
}
// "ok1" landed before the bad key; iteration stopped at the throw so "ok2"
// (after it) did not. Exactly one clean exception, no leaked pending state.
echo "has ok1: " . (isset($j["ok1"]) ? "yes" : "no") . "\n";
echo "count: " . $j->count() . "\n";
// A subsequent operation must run cleanly (no lingering exception).
$j["after"] = 9;
echo "after-insert ok: " . $j["after"] . "\n";
?>
--EXPECTF--
caught: %s
has ok1: yes
count: 1
after-insert ok: 9
