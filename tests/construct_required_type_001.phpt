--TEST--
Judy::__construct() requires $type, and stays arginfo/zpp-consistent on a debug build
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Judy_arginfo.h declares one required argument for __construct(). The
   implementation used to run zpp only when ZEND_NUM_ARGS() > 0, so `new Judy()`
   returned without ever parsing: a PHP debug build aborted the call outright
   ("Arginfo / zpp mismatch during call of Judy::__construct()"), and a release
   build handed back a silently UNINITIALIZED object. $type has always been
   required by the documented signature, so it is enforced instead. */

// No arguments at all.
try {
    new Judy();
} catch (ArgumentCountError $e) {
    echo "no args: ", get_class($e), "\n";
}

// Explicit re-construction with no arguments is rejected the same way, and the
// argument check runs before the already-instantiated check.
$j = new Judy(Judy::INT_TO_INT);
try {
    $j->__construct();
} catch (ArgumentCountError $e) {
    echo "re-construct, no args: ", get_class($e), "\n";
}

// A wrong-typed argument still reaches zpp and reports a TypeError.
try {
    new Judy("not an int");
} catch (TypeError $e) {
    echo "bad type: ", get_class($e), "\n";
}

// The valid one- and two-argument forms are unaffected.
$a = new Judy(Judy::BITSET);
var_dump($a->getType() === Judy::BITSET);

$b = new Judy(Judy::STRING_TO_INT_HASH, true);
var_dump($b->getType() === Judy::STRING_TO_INT_HASH);
var_dump($b->isIterationOptimized());

// The object survives a real write, i.e. it is genuinely initialized.
$b["k"] = 7;
var_dump(count($b), $b["k"]);
?>
--EXPECT--
no args: ArgumentCountError
re-construct, no args: ArgumentCountError
bad type: TypeError
bool(true)
bool(true)
bool(true)
int(1)
int(7)
