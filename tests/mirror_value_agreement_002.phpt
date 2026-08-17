--TEST--
Judy *_HASH / *_ADAPTIVE: the mirrored payload survives mutation during iteration, destructors and failed writes
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// Companion to mirror_value_agreement_001.phpt, which pins traversal against
// point lookup after each *completed* write. This one covers the cases where
// the two stores are touched while a walk is already in flight, or where a
// write does not complete at all:
//
//   1. mutate an already-visited key mid-iteration
//   2. mutate a not-yet-visited key mid-iteration
//   3. delete mid-iteration
//   4. increment() mid-iteration
//   5. a destructor that iterates, run from inside an iteration
//   6. slice(), filter() and map() results, which build their stores directly
//      rather than through the shared write path
//   7. a write that throws, which must leave both stores exactly as they were
//
// STRING_TO_INT is carried along as the control: it has one store and cannot
// diverge, so any line where it differs from the two mirrored types is a
// property of the mirror rather than of the operation.
$types = [
    'STRING_TO_INT'          => Judy::STRING_TO_INT,
    'STRING_TO_INT_HASH'     => Judy::STRING_TO_INT_HASH,
    'STRING_TO_INT_ADAPTIVE' => Judy::STRING_TO_INT_ADAPTIVE,
];

// Keys straddling the 8-byte SSO boundary: ADAPTIVE stores the short ones in a
// JudyL and the long ones in a JudyHS, and only the long ones are mirrored.
function seed(Judy $j): array {
    $model = [];
    for ($i = 0; $i < 6; $i++) {
        foreach ([sprintf('s%d', $i), sprintf('long_key_%02d', $i)] as $k) {
            $j[$k] = $i;
            $model[$k] = $i;
        }
    }
    ksort($model, SORT_STRING);
    return $model;
}

/**
 * Every ordered read surface must agree with point lookup, key for key.
 *
 * The truth is built from keys() plus a point lookup each: keys() never reads
 * a value, and point lookup deliberately still reads the value store, so the
 * pair is independent of the mirror. Comparing the surfaces against each other
 * instead would pass with a uniformly stale mirror — they would all be reading
 * the same wrong word.
 */
function agrees(Judy $j): string {
    $problems = [];
    $truth = [];
    foreach ($j->keys() as $k) {
        $truth[$k] = $j[$k];
    }

    $seen = [];
    foreach ($j as $k => $v) { $seen[$k] = $v; }
    if ($seen !== $truth)          { $problems[] = "foreach"; }
    if ($j->toArray() !== $truth)  { $problems[] = "toArray"; }
    if ($j->values() !== array_values($truth)) { $problems[] = "values"; }
    $cb = [];
    $j->forEach(function ($v, $k) use (&$cb) { $cb[$k] = $v; });
    if ($cb !== $truth)            { $problems[] = "forEach"; }
    if (count($truth) !== $j->count()) { $problems[] = "count"; }
    // sumValues()/averageValues() are ordered walks too, and nothing else in
    // the suite exercises them on the key_index-backed types.
    if ($truth && $j->sumValues() !== array_sum($truth)) { $problems[] = "sumValues"; }
    if ($truth && abs($j->averageValues() - array_sum($truth) / count($truth)) > 1e-9) {
        $problems[] = "averageValues";
    }
    return $problems ? "DIVERGED: " . implode(",", $problems) : "ok";
}

// A destructor that walks the array it is being freed from inside.
class Walker {
    public function __construct(private Judy $j, private array &$out) {}
    public function __destruct() {
        foreach ($this->j as $k => $v) { $this->out[$k] = $v; }
    }
}

foreach ($types as $name => $type) {
    echo "== $name\n";

    // 1 + 2. write to a visited and an unvisited key while walking
    $j = new Judy($type);
    $model = seed($j);
    $order = array_keys($model);
    $first = $order[0];
    $last = $order[count($order) - 1];
    foreach ($j as $k => $v) {
        if ($k === $order[1]) {
            $j[$first] = 900;   // already visited
            $j[$last] = 901;    // not yet visited
        }
    }
    $model[$first] = 900;
    $model[$last] = 901;
    echo "  mutate during iteration: ", agrees($j), "\n";
    echo "  values after mutation: ", ($j->toArray() === $model ? "ok" : "WRONG"), "\n";

    // 3. delete while walking
    $j = new Judy($type);
    $model = seed($j);
    $victim = array_keys($model)[3];
    foreach ($j as $k => $v) {
        if ($k === array_keys($model)[1]) {
            unset($j[$victim]);
        }
    }
    unset($model[$victim]);
    echo "  delete during iteration: ", agrees($j), "\n";
    echo "  values after delete: ", ($j->toArray() === $model ? "ok" : "WRONG"), "\n";

    // 4. increment() while walking (INT_ADAPTIVE does not support increment)
    $j = new Judy($type);
    $model = seed($j);
    if ($type !== Judy::STRING_TO_INT_ADAPTIVE) {
        foreach ($j as $k => $v) {
            $j->increment($k, 10);
            $model[$k] = $v + 10;
        }
        echo "  increment during iteration: ", agrees($j), "\n";
        echo "  values after increment: ", ($j->toArray() === $model ? "ok" : "WRONG"), "\n";
    } else {
        echo "  increment during iteration: n/a\n";
        echo "  values after increment: n/a\n";
    }

    // 5. a destructor that iterates, released from inside an iteration
    $j = new Judy($type);
    $model = seed($j);
    $fromDtor = [];
    $w = new Walker($j, $fromDtor);
    foreach ($j as $k => $v) {
        if ($k === array_keys($model)[2]) {
            $w = null;   // __destruct() walks $j re-entrantly
        }
    }
    echo "  iterate inside a destructor: ",
        ($fromDtor === $model ? "ok" : "WRONG"), "\n";
    echo "  after destructor walk: ", agrees($j), "\n";

    // 6. derived arrays build their stores directly rather than through the
    //    shared write path, so they get their own mirror
    $j = new Judy($type);
    $model = seed($j);
    $sliced = $j->slice('long_key_00', 'long_key_zz');
    echo "  slice: ", agrees($sliced), "\n";
    $filtered = $j->filter(fn($v, $k) => $v >= 3);
    echo "  filter: ", agrees($filtered), "\n";
    $mapped = $j->map(fn($v, $k) => $v * 2);
    echo "  map: ", agrees($mapped), "\n";
    echo "  map values: ",
        ($mapped->toArray() === array_map(fn($v) => $v * 2, $model) ? "ok" : "WRONG"), "\n";
    $cloned = clone $j;
    echo "  clone: ", agrees($cloned), "\n";

    // 7. a write that throws must leave both stores untouched. A true
    //    allocation failure (PJERR) cannot be provoked from userland — libJudy
    //    allocates with malloc, outside PHP's memory_limit — so the reachable
    //    rollback canary is the precondition failure, which returns before any
    //    store is touched and must therefore change nothing.
    $before = $j->toArray();
    // The embedded-NUL precondition exists only for the key_index-backed
    // types, where a NUL would truncate the JudySL key and desynchronise the
    // two stores. Plain JudySL has one store and simply truncates, so the
    // control is exercised with the oversize key alone.
    if ($type !== Judy::STRING_TO_INT) {
        try {
            $j["with\0nul"] = 1;
        } catch (Throwable $e) {
        }
    }
    try {
        $j[str_repeat('z', 70000)] = 1;
    } catch (Throwable $e) {
    }
    echo "  after rejected writes: ", agrees($j), "\n";
    echo "  unchanged by rejected writes: ",
        ($j->toArray() === $before ? "ok" : "WRONG"), "\n";
}
?>
--EXPECT--
== STRING_TO_INT
  mutate during iteration: ok
  values after mutation: ok
  delete during iteration: ok
  values after delete: ok
  increment during iteration: ok
  values after increment: ok
  iterate inside a destructor: ok
  after destructor walk: ok
  slice: ok
  filter: ok
  map: ok
  map values: ok
  clone: ok
  after rejected writes: ok
  unchanged by rejected writes: ok
== STRING_TO_INT_HASH
  mutate during iteration: ok
  values after mutation: ok
  delete during iteration: ok
  values after delete: ok
  increment during iteration: ok
  values after increment: ok
  iterate inside a destructor: ok
  after destructor walk: ok
  slice: ok
  filter: ok
  map: ok
  map values: ok
  clone: ok
  after rejected writes: ok
  unchanged by rejected writes: ok
== STRING_TO_INT_ADAPTIVE
  mutate during iteration: ok
  values after mutation: ok
  delete during iteration: ok
  values after delete: ok
  increment during iteration: n/a
  values after increment: n/a
  iterate inside a destructor: ok
  after destructor walk: ok
  slice: ok
  filter: ok
  map: ok
  map values: ok
  clone: ok
  after rejected writes: ok
  unchanged by rejected writes: ok
