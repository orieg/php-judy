--TEST--
judy.debug_preview_size: default, runtime ini_set, 0 disables the preview, negative clamps to 0
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
// No --INI-- section here on purpose: this pins the compiled-in default.
var_dump(ini_get("judy.debug_preview_size"));

$j = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < 40; $i++) {
    $j[$i] = $i;
}

function preview_size(Judy $j): int {
    $info = print_r($j, true);
    preg_match('/\[preview\] => Array\s*\((.*?)\n\s*\)/s', $info, $m);
    return preg_match_all('/=>/', $m[1] ?? '');
}

function truncation_note(Judy $j): string {
    $info = print_r($j, true);
    return preg_match('/\[previewTruncated\] => (.*)/', $info, $m) ? trim($m[1]) : "(none)";
}

// Default: 16 elements previewed out of 40.
var_dump(preview_size($j));
var_dump(truncation_note($j));

// PHP_INI_ALL: changeable at runtime, so a debugger session can widen it.
ini_set("judy.debug_preview_size", "3");
var_dump(preview_size($j));
var_dump(truncation_note($j));

// Wider than the array: no truncation marker at all.
ini_set("judy.debug_preview_size", "100");
var_dump(preview_size($j));
var_dump(truncation_note($j));

// 0 means metadata only — the preview array stays empty and the marker still
// reports the true total, so the dump cannot be misread as "empty array".
ini_set("judy.debug_preview_size", "0");
var_dump(preview_size($j));
var_dump(truncation_note($j));

// Negative values clamp to 0 rather than looping forever / dumping everything.
ini_set("judy.debug_preview_size", "-5");
var_dump(preview_size($j));
var_dump(truncation_note($j));

// The setting must not affect the real data accessors.
ini_set("judy.debug_preview_size", "2");
var_dump(count($j), count($j->toArray()), count($j->keys()));
?>
--EXPECT--
string(2) "16"
int(16)
string(54) "showing 16 of 40 elements (judy.debug_preview_size=16)"
int(3)
string(52) "showing 3 of 40 elements (judy.debug_preview_size=3)"
int(40)
string(6) "(none)"
int(0)
string(52) "showing 0 of 40 elements (judy.debug_preview_size=0)"
int(0)
string(52) "showing 0 of 40 elements (judy.debug_preview_size=0)"
int(40)
int(40)
int(40)
