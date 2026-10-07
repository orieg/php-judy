--TEST--
judy.string.maxlength INI now enforces a tighter string-key cap on writes (Step 4.1)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Step 4.1: judy.string.maxlength was registered (default "65536") and never
 * read — ini_set() succeeded and did nothing while the real cap was the
 * compile-time PHP_JUDY_MAX_LENGTH. Default ruling: honor it as a TIGHTER
 * reject boundary on the write entry points (offsetSet $j[$k]=$v, set(), and
 * increment() — the three the cap comment names as sharing one check); it can
 * never loosen the compile-time ceiling. Boundary semantics match the old
 * compile check: len >= cap is rejected, cap - 1 is the longest accepted key,
 * and 0/negative values clamp to 1 (full lockdown: only the legal empty key
 * survives). putAll()/fromArray() stay on the compile cap (plan scope). */

function t(string $label, callable $fn): void {
    try {
        $fn();
        echo "$label: NO-THROW\n";
    } catch (\Throwable $e) {
        echo "$label: " . get_class($e) . " | " . $e->getMessage() . "\n";
    }
}

$k31    = str_repeat('k', 31);
$k32    = str_repeat('k', 32);
$k100   = str_repeat('k', 100);
$k65535 = str_repeat('k', 65535);

/* --- Default cap (65536) = compile ceiling; 65535 is the longest key. --- */
t('default 65535-byte offsetSet', function () use ($k65535) {
    $j = new Judy(Judy::STRING_TO_INT);
    $j[$k65535] = 1;
    if (count($j) !== 1) throw new Exception('count wrong');
    if ($j[$k65535] !== 1) throw new Exception('value wrong');
});
t('default 65536-byte offsetSet', function () use ($k65535) {
    $j = new Judy(Judy::STRING_TO_INT);
    $j[$k65535 . 'x'] = 1;
});

/* --- Tightened cap: 32. --- */
ini_set('judy.string.maxlength', 32);
t('cap32 offsetSet 100-byte', function () use ($k100) {
    $j = new Judy(Judy::STRING_TO_INT);
    $j[$k100] = 1;
});
t('cap32 offsetSet 31-byte', function () use ($k31) {
    $j = new Judy(Judy::STRING_TO_INT);
    $j[$k31] = 1;
    if ($j[$k31] !== 1) throw new Exception('value wrong');
});
t('cap32 offsetSet empty key', function () {
    $j = new Judy(Judy::STRING_TO_INT);
    $j[''] = 1;
    if ($j[''] !== 1) throw new Exception('empty key lost');
});
t('cap32 offsetSet 32-byte', function () use ($k32) {
    $j = new Judy(Judy::STRING_TO_INT);
    $j[$k32] = 1;
});
t('cap32 set() 100-byte (ENTRY)', function () use ($k100) {
    $j = new Judy(Judy::STRING_TO_ENTRY);
    $j->set($k100, 'v');
});
t('cap32 set() 32-byte (ENTRY)', function () use ($k32) {
    $j = new Judy(Judy::STRING_TO_ENTRY);
    $j->set($k32, 'v');
});
t('cap32 increment 100-byte', function () use ($k100) {
    $j = new Judy(Judy::STRING_TO_INT_HASH);
    $j->increment($k100);
});
t('cap32 increment 31-byte', function () use ($k31) {
    $j = new Judy(Judy::STRING_TO_INT_HASH);
    $j->increment($k31);
    if ($j[$k31] !== 1) throw new Exception('increment lost');
});
t('cap32 increment 32-byte', function () use ($k32) {
    $j = new Judy(Judy::STRING_TO_INT_HASH);
    $j->increment($k32);
});

/* --- 0/negative clamp to 1: only the empty key survives. --- */
ini_set('judy.string.maxlength', 0);
t('cap0 empty key', function () {
    $j = new Judy(Judy::STRING_TO_INT);
    $j[''] = 1;
});
t('cap0 1-byte key', function () {
    $j = new Judy(Judy::STRING_TO_INT);
    $j['a'] = 1;
});

/* --- Restore; a value LOOSER than the compile ceiling never loosens. --- */
ini_set('judy.string.maxlength', 65536);
t('restored 65535-byte offsetSet', function () use ($k65535) {
    $j = new Judy(Judy::STRING_TO_INT);
    $j[$k65535] = 1;
});
ini_set('judy.string.maxlength', 999999);
t('huge ini still 65536 ceiling', function () use ($k65535) {
    $j = new Judy(Judy::STRING_TO_INT);
    $j[$k65535 . 'x'] = 1;
});

echo "done\n";
?>
--EXPECT--
default 65535-byte offsetSet: NO-THROW
default 65536-byte offsetSet: Exception | Judy string key length (65536) exceeds maximum of 65535 bytes
cap32 offsetSet 100-byte: Exception | Judy string key length (100) exceeds maximum of 31 bytes
cap32 offsetSet 31-byte: NO-THROW
cap32 offsetSet empty key: NO-THROW
cap32 offsetSet 32-byte: Exception | Judy string key length (32) exceeds maximum of 31 bytes
cap32 set() 100-byte (ENTRY): Exception | Judy string key length (100) exceeds maximum of 31 bytes
cap32 set() 32-byte (ENTRY): Exception | Judy string key length (32) exceeds maximum of 31 bytes
cap32 increment 100-byte: Exception | Judy string key length (100) exceeds maximum of 31 bytes
cap32 increment 31-byte: NO-THROW
cap32 increment 32-byte: Exception | Judy string key length (32) exceeds maximum of 31 bytes
cap0 empty key: NO-THROW
cap0 1-byte key: Exception | Judy string key length (1) exceeds maximum of 0 bytes
restored 65535-byte offsetSet: NO-THROW
huge ini still 65536 ceiling: Exception | Judy string key length (65536) exceeds maximum of 65535 bytes
done