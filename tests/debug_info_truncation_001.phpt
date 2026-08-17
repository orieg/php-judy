--TEST--
get_debug_info: element preview is bounded at judy.debug_preview_size and states the true total
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--INI--
judy.debug_preview_size=4
--FILE--
<?php
// One under, exactly at, and one over the preview size — the boundary is the
// point where the truncation marker must appear.
function dump_int(int $n): void {
    $j = new Judy(Judy::INT_TO_INT);
    for ($i = 0; $i < $n; $i++) {
        $j[$i] = $i * 10;
    }
    $info = print_r($j, true);
    // memoryUsage is libJudy-dependent; drop it so the expectation is stable.
    echo preg_replace('/^\s+\[memoryUsage\].*\n/m', '', $info), "\n";
}

function dump_string(int $n): void {
    $j = new Judy(Judy::STRING_TO_INT);
    for ($i = 0; $i < $n; $i++) {
        $j["k" . $i] = $i;
    }
    $info = print_r($j, true);
    // memoryUsage is null for string-keyed types; drop the line so the
    // expectation carries no trailing whitespace.
    echo preg_replace('/^\s+\[memoryUsage\].*\n/m', '', $info), "\n";
}

dump_int(3);
dump_int(4);
dump_int(5);
dump_string(5);

// A large array must stay cheap to dump: only the preview is materialised,
// while count/lastKey still report the whole array.
$big = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < 100000; $i++) {
    $big[$i] = $i;
}
$info = print_r($big, true);
// 7 metadata rows (type, count, memoryUsage, firstKey, lastKey, preview,
// previewTruncated) plus exactly 4 preview rows — never 100000.
var_dump(substr_count($info, "=>") === 7 + 4);
var_dump(strpos($info, "showing 4 of 100000 elements (judy.debug_preview_size=4)") !== false);
var_dump(strlen($info) < 1024);
?>
--EXPECT--
Judy Object
(
    [type] => INT_TO_INT
    [count] => 3
    [firstKey] => 0
    [lastKey] => 2
    [preview] => Array
        (
            [0] => 0
            [1] => 10
            [2] => 20
        )

)

Judy Object
(
    [type] => INT_TO_INT
    [count] => 4
    [firstKey] => 0
    [lastKey] => 3
    [preview] => Array
        (
            [0] => 0
            [1] => 10
            [2] => 20
            [3] => 30
        )

)

Judy Object
(
    [type] => INT_TO_INT
    [count] => 5
    [firstKey] => 0
    [lastKey] => 4
    [preview] => Array
        (
            [0] => 0
            [1] => 10
            [2] => 20
            [3] => 30
        )

    [previewTruncated] => showing 4 of 5 elements (judy.debug_preview_size=4)
)

Judy Object
(
    [type] => STRING_TO_INT
    [count] => 5
    [firstKey] => k0
    [lastKey] => k4
    [preview] => Array
        (
            [k0] => 0
            [k1] => 1
            [k2] => 2
            [k3] => 3
        )

    [previewTruncated] => showing 4 of 5 elements (judy.debug_preview_size=4)
)

bool(true)
bool(true)
bool(true)
