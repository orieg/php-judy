--TEST--
Regression: deleteRange() survives a stored value's destructor re-entering the same array
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// deleteRange() used to run a stored value's destructor BEFORE removing its
// entry from the Judy store. A userland destructor can re-enter and unset (or
// overwrite) the same key, which then found the entry still present and freed
// the same zval a second time. It also walked with the shared
// intern->key_scratch cursor, which a re-entrant destructor can move.
// Every branch must now delete first, free second, and walk a private cursor.

class UnsetKey {
    public $j; public $k;
    function __construct($j, $k) { $this->j = $j; $this->k = $k; }
    function __destruct() { unset($this->j[$this->k]); }
}

class Reinsert {
    public $j; public $k;
    function __construct($j, $k) { $this->j = $j; $this->k = $k; }
    function __destruct() { $this->j[$this->k] = "reborn"; }
}

class Iterate {
    public $j;
    function __construct($j) { $this->j = $j; }
    function __destruct() { foreach ($this->j as $k => $v) { /* walk */ } }
}

class SeekCursor {
    public $j; public $k;
    function __construct($j, $k) { $this->j = $j; $this->k = $k; }
    // Moves the shared key_scratch cursor to the far end of the range.
    function __destruct() { $this->j->first($this->k); }
}

function run($label, $type, $mk, $lo, $hi, $string_keyed) {
    echo "== $label ==\n";

    // 1. Destructor unsets the very key deleteRange is deleting (double free).
    $j = new Judy($type);
    $j[$mk(5)] = new UnsetKey($j, $mk(5));
    $n = $j->deleteRange($lo, $hi);
    echo "  self:     deleted=$n count=" . count($j) . "\n";
    unset($j);

    // 2. Destructor unsets a different key still ahead in the range.
    $j = new Judy($type);
    $j[$mk(1)] = "a";
    $j[$mk(5)] = new UnsetKey($j, $mk(7));
    $j[$mk(7)] = "c";
    $j[$mk(9)] = "d";
    $n = $j->deleteRange($lo, $hi);
    echo "  other:    deleted=$n count=" . count($j) . "\n";
    unset($j);

    // 3. Destructor re-inserts the same key: must not free the new value and
    //    must not hand the same key back to the walk forever.
    $j = new Judy($type);
    $j[$mk(1)] = "a";
    $j[$mk(5)] = new Reinsert($j, $mk(5));
    $n = $j->deleteRange($lo, $hi);
    echo "  reinsert: deleted=$n count=" . count($j)
        . " val=" . var_export($j[$mk(5)], true) . "\n";
    unset($j);

    // 4. Destructor iterates the same array.
    $j = new Judy($type);
    $it = new Iterate($j);
    $j[$mk(1)] = $it;
    unset($it);
    foreach ([2, 3, 4, 5] as $i) { $j[$mk($i)] = "v$i"; }
    $n = $j->deleteRange($lo, $hi);
    echo "  iterate:  deleted=$n count=" . count($j) . "\n";
    unset($j);

    // 5. Destructor seeks the shared key cursor past the rest of the range.
    //    Only string-keyed types use intern->key_scratch.
    if ($string_keyed) {
        $j = new Judy($type);
        $j[$mk(1)] = new SeekCursor($j, $mk(9));
        foreach ([2, 3, 4, 5, 6, 7, 8, 9] as $i) { $j[$mk($i)] = "v$i"; }
        $n = $j->deleteRange($lo, $hi);
        echo "  cursor:   deleted=$n count=" . count($j) . "\n";
        foreach ($j as $k => $v) { echo "    leftover: "; var_dump($v); }
        unset($j);
    }
}

$long = str_repeat("k", 40);

run("INT_TO_MIXED",       Judy::INT_TO_MIXED,             fn($n) => $n,        0, 10, false);
run("STRING_TO_MIXED",    Judy::STRING_TO_MIXED,          fn($n) => "k$n",     "k0", "k9", true);
run("STRING_TO_MIXED_HASH", Judy::STRING_TO_MIXED_HASH,   fn($n) => "k$n",     "k0", "k9", true);
run("ADAPTIVE (sso)",     Judy::STRING_TO_MIXED_ADAPTIVE, fn($n) => "k$n",     "k0", "k9", true);
run("ADAPTIVE (long)",    Judy::STRING_TO_MIXED_ADAPTIVE, fn($n) => "$long$n", "{$long}0", "{$long}9", true);

// The non-MIXED branches were reordered too; check they still delete cleanly.
$p = new Judy(Judy::INT_TO_PACKED);
for ($i = 0; $i < 10; $i++) { $p[$i] = [$i, "v$i"]; }
echo "PACKED deleted=" . $p->deleteRange(2, 7) . " count=" . count($p) . "\n";

$a = new Judy(Judy::STRING_TO_INT_ADAPTIVE);
foreach (["a", "b", "c", "d"] as $i => $s) { $a[$s] = $i; $a[$long . $s] = $i; }
echo "ADAPTIVE_INT deleted=" . $a->deleteRange("a", "c") . " count=" . count($a) . "\n";

$h = new Judy(Judy::STRING_TO_INT_HASH);
foreach (["a", "b", "c", "d"] as $i => $s) { $h[$s] = $i; }
echo "HASH_INT deleted=" . $h->deleteRange("a", "c") . " count=" . count($h) . "\n";

echo "done\n";
?>
--EXPECT--
== INT_TO_MIXED ==
  self:     deleted=1 count=0
  other:    deleted=3 count=0
  reinsert: deleted=2 count=1 val='reborn'
  iterate:  deleted=5 count=0
== STRING_TO_MIXED ==
  self:     deleted=1 count=0
  other:    deleted=3 count=0
  reinsert: deleted=2 count=1 val='reborn'
  iterate:  deleted=5 count=0
  cursor:   deleted=9 count=0
== STRING_TO_MIXED_HASH ==
  self:     deleted=1 count=0
  other:    deleted=3 count=0
  reinsert: deleted=2 count=1 val='reborn'
  iterate:  deleted=5 count=0
  cursor:   deleted=9 count=0
== ADAPTIVE (sso) ==
  self:     deleted=1 count=0
  other:    deleted=3 count=0
  reinsert: deleted=2 count=1 val='reborn'
  iterate:  deleted=5 count=0
  cursor:   deleted=9 count=0
== ADAPTIVE (long) ==
  self:     deleted=1 count=0
  other:    deleted=3 count=0
  reinsert: deleted=2 count=1 val='reborn'
  iterate:  deleted=5 count=0
  cursor:   deleted=9 count=0
PACKED deleted=6 count=4
ADAPTIVE_INT deleted=3 count=5
HASH_INT deleted=3 count=1
done
