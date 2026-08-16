<?php
/**
 * Sliding-window rate limiting with ordered timestamp keys.
 *
 * Bucket hits by millisecond and let the key order do the expiry work:
 * deleteRange() drops everything older than the window in one call, touching
 * only the expired keys. A hash table has to scan every entry to find which
 * ones aged out, so its eviction cost grows with the whole keyset rather than
 * with the number of expired buckets.
 *
 * The same evict-then-measure shape works for sliding-window metrics in a
 * long-running worker (Swoole/RoadRunner/FrankenPHP): p95 buffers, rolling
 * error counts, per-tenant quotas.
 *
 * Run: php examples/sliding-window-rate-limit.php
 */

if (!extension_loaded('judy')) {
    fwrite(STDERR, "The judy extension is not loaded.\n");
    exit(1);
}

final class SlidingWindow
{
    /** Millisecond timestamp => hits in that millisecond. */
    private Judy $hits;

    public function __construct(
        private readonly int $limit,
        private readonly int $windowMs,
    ) {
        $this->hits = new Judy(Judy::INT_TO_INT);
    }

    /**
     * Record a hit at $nowMs and report whether the caller is still under quota.
     */
    public function allow(int $nowMs): bool
    {
        // Evict the tail of the window. Only expired buckets are visited.
        $cutoff = $nowMs - $this->windowMs;
        if ($cutoff > 0) {
            $this->hits->deleteRange(0, $cutoff);
        }

        // Same-millisecond hits collapse into one bucket via atomic increment.
        $this->hits->increment($nowMs);

        return $this->count() <= $this->limit;
    }

    /** Hits currently inside the window (everything left after eviction). */
    public function count(): int
    {
        return (int) $this->hits->sumValues();
    }

    /** Distinct millisecond buckets retained — the real memory footprint. */
    public function buckets(): int
    {
        return count($this->hits);
    }

    /** When the oldest retained hit falls out of the window. */
    public function resetsAtMs(): ?int
    {
        $oldest = $this->hits->first();

        return $oldest === null ? null : $oldest + $this->windowMs;
    }
}

// 5 requests per second, arriving 150ms apart: the 6th trips the limit, and
// the window slides open again once the earliest hit ages out.
$window = new SlidingWindow(limit: 5, windowMs: 1000);

$t0 = 1_700_000_000_000;
foreach (range(0, 9) as $i) {
    $now = $t0 + $i * 150;
    $ok  = $window->allow($now);

    printf(
        "t=%4dms  %-7s  in-window=%d  buckets=%d\n",
        $now - $t0,
        $ok ? 'allow' : 'BLOCK',
        $window->count(),
        $window->buckets(),
    );
}

$resets = $window->resetsAtMs();
printf("\nwindow resets at t=%dms\n", $resets === null ? 0 : $resets - $t0);

// Eviction cost tracks the expired slice, not the retained set: jumping far
// past the window drops every bucket in a single ranged delete.
$far = $t0 + 60_000;
$window->allow($far);
printf("after a %ds idle gap: in-window=%d, buckets=%d\n", 60, $window->count(), $window->buckets());
