--TEST--
get_debug_info: dynamic properties stay visible and never shadow the synthetic entries
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--INI--
judy.debug_preview_size=16
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
$j = new Judy(Judy::INT_TO_INT);
$j[1] = 10;
$j->label = "cache index";
// A property named like a synthetic entry must not hide the real value.
$j->type = "impostor";

$info = print_r($j, true);
echo preg_replace('/^\s+\[memoryUsage\].*\n/m', '', $info);
?>
--EXPECT--
Judy Object
(
    [label] => cache index
    [type] => INT_TO_INT
    [count] => 1
    [firstKey] => 1
    [lastKey] => 1
    [preview] => Array
        (
            [1] => 10
        )

)
