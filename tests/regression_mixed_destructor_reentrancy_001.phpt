--TEST--
Regression: *_TO_MIXED overwrite/unset survive a destructor that mutates the same array
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// The overwrite and unset paths held a raw Judy slot pointer across
// zval_ptr_dtor(old), which runs a user __destruct that can re-enter and
// restructure the tree — a use-after-free write/double-free. These exercises
// must complete with correct state and no crash.

function run($type, $mk) {
    $j = new Judy($type);
    // A value whose destructor mutates the SAME Judy array.
    $evil = new class($j) {
        public $j;
        function __construct($j) { $this->j = $j; }
        function __destruct() {
            // Re-enter and mutate the same array to force tree restructuring
            // while the C code may still hold a slot pointer.
            $k = $GLOBALS['neighbor'];
            $this->j[$k] = 1;
            unset($this->j[$k]);
        }
    };
    $ka = $mk(1); $kb = $mk(2);
    $GLOBALS['neighbor'] = $kb;
    $j[$ka] = $evil;
    unset($evil);            // drop our local ref; slot holds the only ref now
    $j[$kb] = 7;             // populate neighbor
    $j[$ka] = "overwrite";   // <-- dtor of evil fires mid-overwrite
    echo "overwrite ok: " . ($j[$ka] === "overwrite" ? "yes" : "no") . "\n";

    // Now the unset path
    $evil2 = new class($j) {
        public $j;
        function __construct($j) { $this->j = $j; }
        function __destruct() {
            $k = $GLOBALS['neighbor2'];
            $this->j[$k] = 2;
            unset($this->j[$k]);
        }
    };
    $kc = $mk(3); $kd = $mk(4);
    $GLOBALS['neighbor2'] = $kd;
    $j[$kc] = $evil2;
    unset($evil2);
    $j[$kd] = 8;
    unset($j[$kc]);          // <-- dtor of evil2 fires mid-unset
    echo "unset ok: " . (isset($j[$kc]) ? "no" : "yes") . "\n";
}

run(Judy::INT_TO_MIXED,               fn($n) => $n);
run(Judy::STRING_TO_MIXED,            fn($n) => "k$n");
run(Judy::STRING_TO_MIXED_HASH,       fn($n) => "k$n");
run(Judy::STRING_TO_MIXED_ADAPTIVE,   fn($n) => "k$n");
run(Judy::STRING_TO_MIXED_ADAPTIVE,   fn($n) => str_repeat("k$n", 20));
echo "done\n";
?>
--EXPECT--
overwrite ok: yes
unset ok: yes
overwrite ok: yes
unset ok: yes
overwrite ok: yes
unset ok: yes
overwrite ok: yes
unset ok: yes
overwrite ok: yes
unset ok: yes
done
