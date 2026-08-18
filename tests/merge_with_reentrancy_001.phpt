--TEST--
Judy mergeWith() - a MIXED destructor that re-enters mid-merge cannot leave a stale slot
--DESCRIPTION--
Issue #121: the merge loop now holds a live value-slot pointer from its own
cursor. Writing into the destination releases whatever value that key held,
which for a MIXED type runs a user destructor, which can mutate — or free
outright — either array. The value is therefore materialised into a zval BEFORE
the write, and neither the cursor slot nor the resolved value slot is
dereferenced across it; JLN/JSLN re-descends from the key instead. These cases
exercise both, on the integer and on all three string layouts.
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
class Harness { public static $src = null; public static $dst = null; public static array $log = []; }

function report(string $label, Judy $j): void {
    $out = [];
    foreach ($j->keys() as $k) {
        $v = $j[$k];
        $out[] = (is_int($k) ? $k : "'$k'") . '=>' . (is_object($v) ? get_class($v) : var_export($v, true));
    }
    echo "$label count=" . count($j) . " {" . implode(', ', $out) . "}\n";
}

/* The destructor deletes a not-yet-visited source key, appends a source key
   that sorts after the cursor, and writes into the destination. */
class Mutator {
    public function __construct(public string $tag, public $del, public $add) {}
    public function __destruct() {
        $s = Harness::$src; $d = Harness::$dst;
        if ($s !== null) { unset($s[$this->del]); $s[$this->add] = 'added-by-dtor'; }
        if ($d !== null) { $d[$this->add] = 'added-by-dtor'; }
        Harness::$log[] = "dtor({$this->tag})";
    }
}

foreach ([
    ['INT_TO_MIXED', Judy::INT_TO_MIXED, [1, 2, 3, 4, 5], 3, 4, 99],
    ['STRING_TO_MIXED', Judy::STRING_TO_MIXED, ['aa','bb','cc','dd','ee'], 'cc', 'dd', 'zzz'],
    ['STRING_TO_MIXED_HASH', Judy::STRING_TO_MIXED_HASH, ['aa','bb','cc','dd','ee'], 'cc', 'dd', 'zzz'],
    ['STRING_TO_MIXED_ADAPTIVE', Judy::STRING_TO_MIXED_ADAPTIVE, ['aa','bb','cc','dd_long_key','ee'], 'cc', 'dd_long_key', 'zzz_long_key'],
] as [$name, $type, $keys, $hot, $del, $add]) {
    echo "=== destructor mutates both arrays, $name ===\n";
    Harness::$log = [];
    $src = new Judy($type);
    foreach ($keys as $k) { $src[$k] = "src-$k"; }
    $dst = new Judy($type);
    $dst[$hot] = new Mutator((string)$hot, $del, $add);
    Harness::$src = $src; Harness::$dst = $dst;
    $dst->mergeWith($src);
    Harness::$src = null; Harness::$dst = null;
    echo "log: " . implode(',', Harness::$log) . "\n";
    report('src', $src);
    report('dst', $dst);
    unset($src, $dst);
}

/* The harshest case: the destructor frees the whole source store while the
   merge loop is standing on it. The value for the key being written was
   materialised before the destructor ran, so it must still land. */
class Nuke {
    public function __destruct() {
        if (Harness::$src !== null) { Harness::$src->free(); Harness::$log[] = 'freed-source'; }
    }
}
foreach ([
    ['INT_TO_MIXED', Judy::INT_TO_MIXED, [1,2,3,4,5], 3],
    ['STRING_TO_MIXED', Judy::STRING_TO_MIXED, ['aa','bb','cc','dd','ee'], 'cc'],
    ['STRING_TO_MIXED_HASH', Judy::STRING_TO_MIXED_HASH, ['aa','bb','cc','dd','ee'], 'cc'],
    ['STRING_TO_MIXED_ADAPTIVE', Judy::STRING_TO_MIXED_ADAPTIVE, ['aa','bb','cc_long_key','dd','ee'], 'cc_long_key'],
] as [$name, $type, $keys, $hot]) {
    echo "=== destructor frees the source mid-merge, $name ===\n";
    Harness::$log = [];
    $src = new Judy($type);
    foreach ($keys as $k) { $src[$k] = "src-$k"; }
    $dst = new Judy($type);
    $dst[$hot] = new Nuke();
    Harness::$src = $src; Harness::$dst = $dst;
    $dst->mergeWith($src);
    Harness::$src = null; Harness::$dst = null;
    echo "log: " . implode(',', Harness::$log) . "\n";
    report('src', $src);
    report('dst', $dst);
    unset($src, $dst);
}

echo "Done.\n";
?>
--EXPECT--
=== destructor mutates both arrays, INT_TO_MIXED ===
log: dtor(3)
src count=5 {1=>'src-1', 2=>'src-2', 3=>'src-3', 5=>'src-5', 99=>'added-by-dtor'}
dst count=5 {1=>'src-1', 2=>'src-2', 3=>'src-3', 5=>'src-5', 99=>'added-by-dtor'}
=== destructor mutates both arrays, STRING_TO_MIXED ===
log: dtor(cc)
src count=5 {'aa'=>'src-aa', 'bb'=>'src-bb', 'cc'=>'src-cc', 'ee'=>'src-ee', 'zzz'=>'added-by-dtor'}
dst count=5 {'aa'=>'src-aa', 'bb'=>'src-bb', 'cc'=>'src-cc', 'ee'=>'src-ee', 'zzz'=>'added-by-dtor'}
=== destructor mutates both arrays, STRING_TO_MIXED_HASH ===
log: dtor(cc)
src count=5 {'aa'=>'src-aa', 'bb'=>'src-bb', 'cc'=>'src-cc', 'ee'=>'src-ee', 'zzz'=>'added-by-dtor'}
dst count=5 {'aa'=>'src-aa', 'bb'=>'src-bb', 'cc'=>'src-cc', 'ee'=>'src-ee', 'zzz'=>'added-by-dtor'}
=== destructor mutates both arrays, STRING_TO_MIXED_ADAPTIVE ===
log: dtor(cc)
src count=5 {'aa'=>'src-aa', 'bb'=>'src-bb', 'cc'=>'src-cc', 'ee'=>'src-ee', 'zzz_long_key'=>'added-by-dtor'}
dst count=5 {'aa'=>'src-aa', 'bb'=>'src-bb', 'cc'=>'src-cc', 'ee'=>'src-ee', 'zzz_long_key'=>'added-by-dtor'}
=== destructor frees the source mid-merge, INT_TO_MIXED ===
log: freed-source
src count=0 {}
dst count=3 {1=>'src-1', 2=>'src-2', 3=>'src-3'}
=== destructor frees the source mid-merge, STRING_TO_MIXED ===
log: freed-source
src count=0 {}
dst count=3 {'aa'=>'src-aa', 'bb'=>'src-bb', 'cc'=>'src-cc'}
=== destructor frees the source mid-merge, STRING_TO_MIXED_HASH ===
log: freed-source
src count=0 {}
dst count=3 {'aa'=>'src-aa', 'bb'=>'src-bb', 'cc'=>'src-cc'}
=== destructor frees the source mid-merge, STRING_TO_MIXED_ADAPTIVE ===
log: freed-source
src count=0 {}
dst count=3 {'aa'=>'src-aa', 'bb'=>'src-bb', 'cc_long_key'=>'src-cc_long_key'}
Done.
