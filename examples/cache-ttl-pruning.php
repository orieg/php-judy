<?php
/**
 * Demo: Cache Workload with Native In-C TTL Pruning
 *
 * Demonstrates Judy::STRING_TO_ENTRY for in-memory cache storage with native
 * expiration timestamps (TTL), user-defined flags, and in-C single-pass
 * batch eviction via pruneExpired().
 *
 * Run:
 *   php examples/cache-ttl-pruning.php
 */

if (!extension_loaded('judy')) {
    echo "The judy extension is not loaded.\n";
    exit(1);
}

echo "=== Judy::STRING_TO_ENTRY Cache & TTL Demo ===\n\n";

$cache = new Judy(Judy::STRING_TO_ENTRY);

// 1. Setting items with TTL and metadata flags
echo "1. Storing cache items with TTLs and flags...\n";

// Active user session (TTL: 3600 seconds = 1 hour, flags: 0x01 = authenticated)
$cache->set("session:usr_1001", [
    "user_id" => 1001,
    "username" => "alice",
    "role" => "admin"
], ttl: 3600, flags: 0x01);

// Short-lived rate-limit token (TTL: 2 seconds)
$cache->set("ratelimit:ip_192.168.1.50", 5, ttl: 2, flags: 0x02);

// Long-lived static config (TTL: 0 = never expires)
$cache->set("config:site_name", "Acme Platform", ttl: 0);

echo "   Stored 3 cache entries.\n\n";

// 2. Reading values and extracting expiration/flags
echo "2. Reading cache entries...\n";

$expiry = 0;
$flags = 0;
$session = $cache->get("session:usr_1001", $expiry, $flags);
echo "   session:usr_1001 => username: " . $session['username'] . " (expires at $expiry, flags: 0x" . dechex($flags) . ")\n";

$entry = $cache->getEntry("session:usr_1001");
echo "   Full entry inspection: value=" . json_encode($entry['value']) . ", is_expired=" . ($entry['is_expired'] ? 'true' : 'false') . "\n\n";

// 3. ArrayAccess support
echo "3. ArrayAccess read & write:\n";
$cache["session:usr_1002"] = ["user_id" => 1002, "username" => "bob"];
echo "   isset('session:usr_1002'): " . (isset($cache["session:usr_1002"]) ? 'true' : 'false') . "\n";
echo "   Count before pruning: " . count($cache) . "\n\n";

// 4. Expiration and native in-C pruneExpired()
echo "4. Expiring an entry for real, then running native in-C pruneExpired()...\n";

// Give the rate-limit token a fresh 1-second TTL. get() consults the real
// clock, so we let it genuinely lapse rather than simulate. Gate-1 canonical
// ruling: reads *and traversals* hide an expired entry (get() => null,
// keys()/foreach skip it), while count()/size() are raw counters that still
// include it until pruneExpired() runs.
$cache->set("ratelimit:ip_192.168.1.50", 5, ttl: 1, flags: 0x02);
sleep(2);

// Reads hide the expired entry (Gate-1 canonical ruling):
$rl = $cache->get("ratelimit:ip_192.168.1.50");
echo "   get('ratelimit:ip_192.168.1.50') => " . ($rl === null ? "NULL (expired)" : "Value: $rl") . "\n";

// Traversals hide it too: keys() no longer lists the expired key...
echo "   keys() before pruneExpired(): " . json_encode($cache->keys()) . " (expired key hidden)\n";

// ...but count()/size() are raw counters: they still count the expired entry
// until pruning — count($j) === count($j->keys()) holds only after
// pruneExpired() (also the Gate-1 ruling):
echo "   count() before pruneExpired(): " . count($cache) . " (expired entry still counted)\n";

// Native prune in C (evicts all items where expires_at <= now; a $now argument
// is accepted for testing)
$evicted = $cache->pruneExpired();
echo "   pruneExpired() evicted $evicted expired item(s) in a single trie pass.\n";
echo "   Count after pruning: " . count($cache) . "\n\n";

echo "5. Remaining active cache keys:\n";
foreach ($cache->keys() as $key) {
    echo "   - $key\n";
}
echo "\nDemo complete.\n";
