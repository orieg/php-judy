--TEST--
Bounds TypeErrors: string bounds on int-keyed deleteRange(), int bounds on string-keyed slice()
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* ONLY the cells that actually throw today are pinned here:
 *   - string bounds on an int-keyed deleteRange(): engine-style TypeError
 *     naming the offending argument (both $start and $end are checked),
 *   - int bounds on a string-keyed slice(): explicit C TypeError naming the
 *     array's key category (either bound being a non-string triggers it).
 *
 * Deliberately NOT pinned (they do not throw — they silently coerce, which
 * can read the wrong range): string bounds on int-keyed keys/values/toArray/
 * slice/size, and int bounds on string-keyed deleteRange (observed: coerced,
 * nothing removed). That wrong-range read (keys("a","b") with integer key 0
 * present returns [0]) is verified bug N1 and gets its FAILING expectation in
 * Step 3.8 (Batch 3) — pinning the coercion here would pin the bug.
 *
 * The throwing arrays must be left untouched (the throw precedes any work). */

function try_call(string $label, callable $fn): void {
    try {
        $fn();
        echo "$label: NO-THROW\n";
    } catch (\Throwable $e) {
        echo "$label: " . get_class($e) . " | " . $e->getMessage() . "\n";
    }
}

$i = new Judy(Judy::INT_TO_INT);
$i[10] = 1;
$i[30] = 3;
try_call('deleteRange int-keyed $start', fn() => $i->deleteRange('a', 'b'));
try_call('deleteRange int-keyed $end',   fn() => $i->deleteRange(10, 'b'));
echo 'deleteRange int-keyed intact: ' . json_encode($i->keys()) . "\n";

$s = new Judy(Judy::STRING_TO_INT);
$s['a'] = 1;
$s['c'] = 3;
try_call('slice string-keyed $start', fn() => $s->slice(1, 'c'));
try_call('slice string-keyed $end',   fn() => $s->slice('a', 5));
echo 'slice string-keyed intact: ' . json_encode($s->keys()) . "\n";

echo "done\n";
?>
--EXPECT--
deleteRange int-keyed $start: TypeError | Judy::deleteRange(): Argument #1 ($start) must be of type int, string given
deleteRange int-keyed $end: TypeError | Judy::deleteRange(): Argument #2 ($end) must be of type int, string given
deleteRange int-keyed intact: [10,30]
slice string-keyed $start: TypeError | Judy::slice() expects string arguments for string-keyed arrays
slice string-keyed $end: TypeError | Judy::slice() expects string arguments for string-keyed arrays
slice string-keyed intact: ["a","c"]
done
