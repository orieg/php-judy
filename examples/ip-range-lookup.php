<?php
/**
 * IP-range lookup with ordered integer keys.
 *
 * Store each range keyed by its start address, then resolve an IP with a
 * single last() call: the greatest range-start <= IP. This "floor lookup"
 * pattern needs ordered keys — a hash table can't do it without scanning.
 * The same shape works for tariff tables, time buckets, ID shards, etc.
 *
 * Run: php examples/ip-range-lookup.php [ip]
 */

if (!extension_loaded('judy')) {
    fwrite(STDERR, "The judy extension is not loaded.\n");
    exit(1);
}

$ranges = new Judy(Judy::INT_TO_MIXED);

// [cidr, label] — start address is the key, [end, label] is the value.
foreach (
    [
        ['10.0.0.0/8',     'private-net'],
        ['127.0.0.0/8',    'loopback'],
        ['172.16.0.0/12',  'private-net'],
        ['192.168.0.0/16', 'private-net'],
        ['203.0.113.0/24', 'documentation'],
    ] as [$cidr, $label]
) {
    [$net, $bits] = explode('/', $cidr);
    $start = ip2long($net);
    $end   = $start + (1 << (32 - (int)$bits)) - 1;
    $ranges[$start] = [$end, $label];
}

function lookup(Judy $ranges, string $ip): ?string
{
    $addr = ip2long($ip);
    if ($addr === false) {
        return null;
    }
    $start = $ranges->last($addr);          // greatest range-start <= addr
    if ($start === null) {
        return null;
    }
    [$end, $label] = $ranges[$start];
    return $addr <= $end ? $label : null;   // inside the range?
}

foreach ([$argv[1] ?? '192.168.1.50', '8.8.8.8', '203.0.113.99', '127.0.0.1'] as $ip) {
    printf("%-15s -> %s\n", $ip, lookup($ranges, $ip) ?? 'no match');
}
