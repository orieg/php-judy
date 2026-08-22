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
echo "4. Simulating time passage and running native in-C pruneExpired()...\n";

// Simulate 5 seconds later
$futureTime = time() + 5;
echo "   Current simulated timestamp: $futureTime\n";

// The rate-limit entry had TTL=2, so at +5s it has expired:
$rl = $cache->get("ratelimit:ip_192.168.1.50");
echo "   get('ratelimit:ip_192.168.1.50') => " . ($rl === null ? "NULL (expired)" : "Value: $rl") . "\n";

// Native prune in C (evicts all items where expires_at <= $futureTime)
$evicted = $cache->pruneExpired($futureTime);
echo "   pruneExpired() evicted $evicted expired item(s) in a single trie pass.\n";
echo "   Count after pruning: " . count($cache) . "\n\n";

echo "5. Remaining active cache keys:\n";
foreach ($cache->keys() as $key) {
    echo "   - $key\n";
}
echo "\nDemo complete.\n";
