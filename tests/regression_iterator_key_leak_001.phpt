--TEST--
Judy regression: completed manual Iterator walks must not leak the string key
--SKIPIF--
<?php if (!extension_loaded('judy')) die('skip judy extension not available'); ?>
--FILE--
<?php
/* next()/rewind() blanked iterator_key with a bare ZVAL_UNDEF on the
 * end-of-walk and empty-array paths. On the string-keyed types that zval holds
 * a refcounted zend_string, so each completed walk leaked one string (32 bytes
 * measured). The leak is Zend-MM allocated, which is why memory_get_usage()
 * can see it here. */

$types = [
    'STRING_TO_INT'            => Judy::STRING_TO_INT,
    'STRING_TO_MIXED'          => Judy::STRING_TO_MIXED,
    'STRING_TO_INT_HASH'       => Judy::STRING_TO_INT_HASH,
    'STRING_TO_MIXED_HASH'     => Judy::STRING_TO_MIXED_HASH,
    'STRING_TO_INT_ADAPTIVE'   => Judy::STRING_TO_INT_ADAPTIVE,
    'STRING_TO_MIXED_ADAPTIVE' => Judy::STRING_TO_MIXED_ADAPTIVE,
];

function walk(Judy $j): int
{
    $seen = 0;
    $j->rewind();
    while ($j->valid()) {
        $j->key();
        $j->current();
        $j->next();
        $seen++;
    }
    return $seen;
}

foreach ($types as $name => $type) {
    $j = new Judy($type);
    foreach (['alpha', 'beta', 'gamma'] as $i => $k) {
        $j[$k] = ($type === Judy::STRING_TO_INT
            || $type === Judy::STRING_TO_INT_HASH
            || $type === Judy::STRING_TO_INT_ADAPTIVE) ? $i : "v$i";
    }

    /* Settle allocator state before measuring. */
    for ($i = 0; $i < 100; $i++) {
        walk($j);
    }

    $before = memory_get_usage();
    for ($i = 0; $i < 500; $i++) {
        walk($j);
    }
    $growth = memory_get_usage() - $before;

    printf("%-24s walked=%d growth=%d\n", $name, walk($j), $growth);
}

/* rewind() on an array emptied after a partial walk: the stale string key must
 * be released rather than blanked. */
$j = new Judy(Judy::STRING_TO_MIXED);
$j['alpha'] = 1;
$j['beta'] = 2;
for ($i = 0; $i < 100; $i++) {
    $j->rewind();
    $j->key();
    unset($j['alpha'], $j['beta']);
    $j->rewind();
    $j['alpha'] = 1;
    $j['beta'] = 2;
}
$before = memory_get_usage();
for ($i = 0; $i < 500; $i++) {
    $j->rewind();
    $j->key();
    unset($j['alpha'], $j['beta']);
    $j->rewind();
    $j['alpha'] = 1;
    $j['beta'] = 2;
}
printf("rewind-on-emptied growth=%d\n", memory_get_usage() - $before);
?>
--EXPECT--
STRING_TO_INT            walked=3 growth=0
STRING_TO_MIXED          walked=3 growth=0
STRING_TO_INT_HASH       walked=3 growth=0
STRING_TO_MIXED_HASH     walked=3 growth=0
STRING_TO_INT_ADAPTIVE   walked=3 growth=0
STRING_TO_MIXED_ADAPTIVE walked=3 growth=0
rewind-on-emptied growth=0
