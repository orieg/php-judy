<?php
/**
 * In-memory counters for long-running workers.
 *
 * In a queue worker or Swoole/RoadRunner/FrankenPHP/Octane process, state
 * survives across jobs/requests. Judy's atomic increment() makes it a
 * compact metrics accumulator: no read-modify-write, keys created on
 * first touch, and less memory than a PHP array when the key space gets
 * large: the INT_TO_INT per-user counters below measure 2.0-2.5x smaller for
 * dense IDs and 3.1-5.3x for sparse ones (peak RSS; see BENCHMARK.md). The
 * JudyHS-backed STRING_TO_*_HASH types are not part of that measurement.
 *
 * Run: php examples/worker-counters.php
 */

if (!extension_loaded('judy')) {
    fwrite(STDERR, "The judy extension is not loaded.\n");
    exit(1);
}

// String-keyed counters: metric name -> count.
$counters = new Judy(Judy::STRING_TO_INT_HASH);

// Integer-keyed counters: user id -> requests served.
$perUser = new Judy(Judy::INT_TO_INT);

// Simulate handling a stream of jobs.
mt_srand(7);
for ($i = 0; $i < 100_000; $i++) {
    $status = [200, 200, 200, 200, 404, 500][mt_rand(0, 5)];
    $counters->increment("http_$status");          // create-or-increment
    $counters->increment('jobs_total');
    $perUser->increment(mt_rand(1, 20_000));
}
$counters->increment('bytes_sent', 1_048_576);     // arbitrary amounts work too

// Periodic flush: snapshot in C speed, then reset.
echo "metrics snapshot:\n";
foreach ($counters->toArray() as $metric => $value) {
    printf("  %-12s %d\n", $metric, $value);
}

printf("distinct users: %d\n", count($perUser));
printf("busiest-user requests: %d\n", max($perUser->values()));
printf("judy memory (per-user counters): %d bytes\n", $perUser->memoryUsage());

// Range analytics on integer keys, in C:
printf("users 1-1000 served: %d requests\n", $perUser->slice(1, 1000)->sumValues());
