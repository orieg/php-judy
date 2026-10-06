--TEST--
Judy::increment() oversized-key message reports the true key length
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* CHARACTERIZATION TEST — documented Rule-3 exception (see plan Step 1.5):
 *
 * Pre-fix, increment() called zend_string_release(skey) and then read
 * ZSTR_LEN(skey) to build the exception message (use-after-free). This test
 * CANNOT fail on standard builds: the Zend allocator's free-list header
 * occupies bytes 0-15 of the freed block while ZSTR_LEN sits at offset 16,
 * and nothing allocates between the release and the read, so the message
 * reads the original length anyway. It is shipped to pin the message
 * contract (true length, exact wording); the UAF itself is detected by
 * source review at Gate 1 — valgrind is not available on this machine
 * (Step 3.7 SKIP is recorded), and no ASan-instrumented build of this
 * extension exists in this environment.
 *
 * The fix hoists `size_t key_len = ZSTR_LEN(skey);` above the release so
 * the message no longer touches freed memory. */
$j = new Judy(Judy::STRING_TO_INT);

$big = new class {
    public function __toString(): string {
        return str_repeat("x", 70000);
    }
};

try {
    $j->increment($big);
    echo "no throw\n";
} catch (\Throwable $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

// Nothing was stored, and the array still works afterwards.
var_dump(count($j));
var_dump($j->increment("ok"));
var_dump($j->increment("ok", -5));

echo "Done\n";
?>
--EXPECT--
Caught: Judy string key length (70000) exceeds maximum of 65535 bytes
int(0)
int(1)
int(-4)
Done
