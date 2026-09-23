--TEST--
bench-gate: tam_cpu_info() returns structured hardware and instruction metadata
--FILE--
<?php
require_once dirname(__DIR__) . '/scripts/bench-lib.php';

$info = tam_cpu_info();

// Required structure keys
$keys = ['arch', 'model', 'vendor', 'family', 'model_id', 'stepping', 'key_flags'];
foreach ($keys as $k) {
    if (!array_key_exists($k, $info)) {
        echo "FAIL: missing key $k\n";
    }
}

// arch and model must be non-empty strings
if (!is_string($info['arch']) || $info['arch'] === '') {
    echo "FAIL: invalid arch\n";
}
if (!is_string($info['model']) || $info['model'] === '') {
    echo "FAIL: invalid model\n";
}
if (!is_array($info['key_flags'])) {
    echo "FAIL: key_flags is not array\n";
}

// Static caching test: second call must return identical array
$info2 = tam_cpu_info();
if ($info !== $info2) {
    echo "FAIL: tam_cpu_info() is not idempotent\n";
}

echo "OK\n";
?>
--EXPECT--
OK
