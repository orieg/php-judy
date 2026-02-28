<?php
/**
 * Benchmark: All Judy Types vs Native PHP Array
 *
 * Produces a unified comparison table across all seven Judy array types plus
 * a native PHP array baseline, measuring:
 *
 *   1. Write throughput  — individual element insertion
 *   2. Read throughput   — individual element lookup
 *   3. Iteration         — foreach over full array
 *   4. Free / destroy    — unset() + gc_collect_cycles() teardown time
 *   5. Memory usage      — emalloc'd PHP heap delta (all types) +
 *                          memoryUsage() / JudyXMemUsed (where supported)
 *
 * Grouping:
 *   Integer-keyed: PHP int array, BITSET, INT_TO_INT, INT_TO_MIXED, INT_TO_PACKED
 *   String-keyed:  PHP string array, STRING_TO_INT, STRING_TO_MIXED, STRING_TO_MIXED_HASH
 *
 * Notes on memory reporting:
 *   PHP array        — memory_get_usage() delta (includes zval + bucket overhead)
 *   BITSET           — Judy1MemUsed via memoryUsage()
 *   INT_TO_INT       — JudyLMemUsed via memoryUsage()
 *   INT_TO_MIXED     — JudyLMemUsed via memoryUsage() (ecalloc'd zvals not counted)
 *   INT_TO_PACKED    — JudyLMemUsed via memoryUsage() (emalloc'd packed bufs not counted)
 *   STRING_TO_*      — memoryUsage() returns NULL (no C-level accounting macro)
 *
 * Usage:
 *   php examples/judy-bench-all-types.php [size] [iterations]
 *   php examples/judy-bench-all-types.php 100000 7
 */

ini_set('memory_limit', '2G');

// ── CLI arguments ──────────────────────────────────────────────────────────

$size       = isset($argv[1]) ? (int)$argv[1] : 500000;
$iterations = isset($argv[2]) ? (int)$argv[2] : 5;

// ── Helpers ────────────────────────────────────────────────────────────────

/**
 * Run $fn $iterations times (after one warmup) and return the median (ms).
 */
function bench_median(callable $fn, int $iterations): float {
    $fn(); // warmup
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start  = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);
    return $times[intdiv($iterations, 2)];
}

/**
 * Measure the emalloc'd PHP heap consumed while $fn() runs.
 * $fn must return the created container so we can hold a reference
 * and prevent PHP from freeing it before measurement.
 */
function measure_heap(callable $fn): int {
    gc_collect_cycles();
    $before = memory_get_usage();
    $ref = $fn();
    $after  = memory_get_usage();
    unset($ref);
    return max(0, $after - $before);
}

/**
 * Measure the time to destroy (unset) a populated container.
 * $create_fn must return the container. Median of $iterations runs.
 */
function measure_free(callable $create_fn, int $iterations): float {
    $create_fn();  // warmup
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $ref = $create_fn();
        gc_collect_cycles();
        $start = hrtime(true);
        unset($ref);
        gc_collect_cycles();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);
    return $times[intdiv($iterations, 2)];
}

function fmt_bytes(int $bytes): string {
    if ($bytes <= 0)         return '—';
    if ($bytes >= 1 << 20)   return sprintf('%.1f MB', $bytes / (1 << 20));
    if ($bytes >= 1 << 10)   return sprintf('%.1f KB', $bytes / (1 << 10));
    return $bytes . ' B';
}

function fmt_mem(?int $bytes): string {
    if ($bytes === null) return 'n/a';
    return fmt_bytes($bytes);
}

function col(string $s, int $w): string {
    return str_pad($s, $w);
}

// ── Data generators ────────────────────────────────────────────────────────

/**
 * Generate $size integer-keyed mixed values (string/int/array/bool cycling).
 */
function make_int_mixed(int $size): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        switch ($i & 3) {
            case 0: $data[$i] = "str_$i"; break;
            case 1: $data[$i] = $i * 7; break;
            case 2: $data[$i] = [$i, $i + 1]; break;
            case 3: $data[$i] = ($i & 1) === 0; break;
        }
    }
    return $data;
}

/**
 * Generate $size integer-keyed integer values.
 */
function make_int_int(int $size): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data[$i] = $i;
    }
    return $data;
}

/**
 * Generate $size integer-keyed bool values (for BITSET).
 */
function make_int_bool(int $size): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data[$i] = true;
    }
    return $data;
}

/**
 * Generate $size string-keyed integer values.
 */
function make_str_int(int $size): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data["key_$i"] = $i;
    }
    return $data;
}

/**
 * Generate $size string-keyed mixed values.
 */
function make_str_mixed(int $size): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        switch ($i & 3) {
            case 0: $data["key_$i"] = "str_$i"; break;
            case 1: $data["key_$i"] = $i * 7; break;
            case 2: $data["key_$i"] = [$i, $i + 1]; break;
            case 3: $data["key_$i"] = ($i & 1) === 0; break;
        }
    }
    return $data;
}

// ── Benchmark a single subject ─────────────────────────────────────────────

/**
 * @return array{write: float, read: float, iter: float, heap: int, internal: int|null}
 */
function bench_subject(
    string $label,
    callable $make_fn,
    callable $write_fn,
    callable $read_fn,
    callable $iter_fn,
    callable $mem_fn,
    int $iterations
): array {
    $data = $make_fn();
    $keys = array_keys($data);

    $write = bench_median($write_fn, $iterations);
    $read  = bench_median($read_fn,  $iterations);
    $iter  = bench_median($iter_fn,  $iterations);
    $heap  = measure_heap($write_fn);  // heap delta during write
    $internal = $mem_fn($data);        // memoryUsage()-style report (null if unavailable)

    return compact('write', 'read', 'iter', 'heap', 'internal');
}

// ── Detect available types ─────────────────────────────────────────────────

$has_packed = false;
try {
    $tmp = new Judy(Judy::INT_TO_PACKED);
    unset($tmp);
    $has_packed = true;
} catch (Exception $e) {
    // INT_TO_PACKED not supported on this platform (e.g., 32-bit or no JudyL)
}

// ── Collect all results ────────────────────────────────────────────────────

$results = [];

// ── Integer-keyed subjects ──────────────────────────────────────────────

// PHP int array — BITSET-like (bool values)
$bool_data = make_int_bool($size);
$results['PHP array (bool)'] = [
    'keys'     => 'int',
    'values'   => 'bool',
    'write'    => bench_median(function() use ($size) {
        $a = [];
        for ($i = 0; $i < $size; $i++) { $a[$i] = true; }
    }, $iterations),
    'read'     => (function() use ($size, $bool_data, $iterations) {
        $a = $bool_data;
        return bench_median(function() use ($size, $a) {
            for ($i = 0; $i < $size; $i++) { $v = $a[$i]; }
        }, $iterations);
    })(),
    'iter'     => (function() use ($size, $bool_data, $iterations) {
        $a = $bool_data;
        return bench_median(function() use ($a) {
            foreach ($a as $k => $v) {}
        }, $iterations);
    })(),
    'heap'     => measure_heap(function() use ($size) {
        $a = [];
        for ($i = 0; $i < $size; $i++) { $a[$i] = true; }
        return $a;
    }),
    'free'     => measure_free(function() use ($size) {
        $a = [];
        for ($i = 0; $i < $size; $i++) { $a[$i] = true; }
        return $a;
    }, $iterations),
    'internal' => null,
    'note'     => '',
];

// BITSET
$results['BITSET'] = [
    'keys'   => 'int',
    'values' => 'bool',
    'write'  => bench_median(function() use ($size) {
        $j = new Judy(Judy::BITSET);
        for ($i = 0; $i < $size; $i++) { $j[$i] = true; }
    }, $iterations),
    'read'   => (function() use ($size, $iterations) {
        $j = new Judy(Judy::BITSET);
        for ($i = 0; $i < $size; $i++) { $j[$i] = true; }
        return bench_median(function() use ($size, $j) {
            for ($i = 0; $i < $size; $i++) { $v = $j[$i]; }
        }, $iterations);
    })(),
    'iter'   => (function() use ($size, $iterations) {
        $j = new Judy(Judy::BITSET);
        for ($i = 0; $i < $size; $i++) { $j[$i] = true; }
        return bench_median(function() use ($j) {
            foreach ($j as $k => $v) {}
        }, $iterations);
    })(),
    'heap'   => measure_heap(function() use ($size) {
        $j = new Judy(Judy::BITSET);
        for ($i = 0; $i < $size; $i++) { $j[$i] = true; }
        return $j;
    }),
    'free'   => measure_free(function() use ($size) {
        $j = new Judy(Judy::BITSET);
        for ($i = 0; $i < $size; $i++) { $j[$i] = true; }
        return $j;
    }, $iterations),
    'internal' => (function() use ($size) {
        $j = new Judy(Judy::BITSET);
        for ($i = 0; $i < $size; $i++) { $j[$i] = true; }
        return $j->memoryUsage();
    })(),
    'note'   => 'Judy1MemUsed',
];

// PHP int array — integer values
$int_data = make_int_int($size);
$results['PHP array (int)'] = [
    'keys'   => 'int',
    'values' => 'int',
    'write'  => bench_median(function() use ($size) {
        $a = [];
        for ($i = 0; $i < $size; $i++) { $a[$i] = $i; }
    }, $iterations),
    'read'   => (function() use ($size, $int_data, $iterations) {
        $a = $int_data;
        return bench_median(function() use ($size, $a) {
            for ($i = 0; $i < $size; $i++) { $v = $a[$i]; }
        }, $iterations);
    })(),
    'iter'   => (function() use ($int_data, $iterations) {
        $a = $int_data;
        return bench_median(function() use ($a) {
            foreach ($a as $k => $v) {}
        }, $iterations);
    })(),
    'heap'   => measure_heap(function() use ($size) {
        $a = [];
        for ($i = 0; $i < $size; $i++) { $a[$i] = $i; }
        return $a;
    }),
    'free'   => measure_free(function() use ($size) {
        $a = [];
        for ($i = 0; $i < $size; $i++) { $a[$i] = $i; }
        return $a;
    }, $iterations),
    'internal' => null,
    'note'   => '',
];

// INT_TO_INT
$results['INT_TO_INT'] = [
    'keys'   => 'int',
    'values' => 'int',
    'write'  => bench_median(function() use ($size) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) { $j[$i] = $i; }
    }, $iterations),
    'read'   => (function() use ($size, $iterations) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) { $j[$i] = $i; }
        return bench_median(function() use ($size, $j) {
            for ($i = 0; $i < $size; $i++) { $v = $j[$i]; }
        }, $iterations);
    })(),
    'iter'   => (function() use ($size, $iterations) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) { $j[$i] = $i; }
        return bench_median(function() use ($j) {
            foreach ($j as $k => $v) {}
        }, $iterations);
    })(),
    'heap'   => measure_heap(function() use ($size) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) { $j[$i] = $i; }
        return $j;
    }),
    'free'   => measure_free(function() use ($size) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) { $j[$i] = $i; }
        return $j;
    }, $iterations),
    'internal' => (function() use ($size) {
        $j = new Judy(Judy::INT_TO_INT);
        for ($i = 0; $i < $size; $i++) { $j[$i] = $i; }
        return $j->memoryUsage();
    })(),
    'note'   => 'JudyLMemUsed',
];

// PHP int array — mixed values
$mixed_data = make_int_mixed($size);
$keys_arr   = array_keys($mixed_data);
$results['PHP array (mixed)'] = [
    'keys'   => 'int',
    'values' => 'mixed',
    'write'  => bench_median(function() use ($mixed_data) {
        $a = [];
        foreach ($mixed_data as $k => $v) { $a[$k] = $v; }
    }, $iterations),
    'read'   => (function() use ($mixed_data, $keys_arr, $iterations) {
        $a = $mixed_data;
        return bench_median(function() use ($a, $keys_arr) {
            foreach ($keys_arr as $k) { $v = $a[$k]; }
        }, $iterations);
    })(),
    'iter'   => (function() use ($mixed_data, $iterations) {
        $a = $mixed_data;
        return bench_median(function() use ($a) {
            foreach ($a as $k => $v) {}
        }, $iterations);
    })(),
    'heap'   => measure_heap(function() use ($mixed_data) {
        $a = [];
        foreach ($mixed_data as $k => $v) { $a[$k] = $v; }
        return $a;
    }),
    'free'   => measure_free(function() use ($mixed_data) {
        $a = [];
        foreach ($mixed_data as $k => $v) { $a[$k] = $v; }
        return $a;
    }, $iterations),
    'internal' => null,
    'note'   => '',
];

// INT_TO_MIXED
$results['INT_TO_MIXED'] = [
    'keys'   => 'int',
    'values' => 'mixed',
    'write'  => bench_median(function() use ($mixed_data) {
        $j = new Judy(Judy::INT_TO_MIXED);
        foreach ($mixed_data as $k => $v) { $j[$k] = $v; }
    }, $iterations),
    'read'   => (function() use ($mixed_data, $keys_arr, $iterations) {
        $j = Judy::fromArray(Judy::INT_TO_MIXED, $mixed_data);
        return bench_median(function() use ($j, $keys_arr) {
            foreach ($keys_arr as $k) { $v = $j[$k]; }
        }, $iterations);
    })(),
    'iter'   => (function() use ($mixed_data, $iterations) {
        $j = Judy::fromArray(Judy::INT_TO_MIXED, $mixed_data);
        return bench_median(function() use ($j) {
            foreach ($j as $k => $v) {}
        }, $iterations);
    })(),
    'heap'   => measure_heap(function() use ($mixed_data) {
        $j = new Judy(Judy::INT_TO_MIXED);
        foreach ($mixed_data as $k => $v) { $j[$k] = $v; }
        return $j;
    }),
    'free'   => measure_free(function() use ($mixed_data) {
        $j = new Judy(Judy::INT_TO_MIXED);
        foreach ($mixed_data as $k => $v) { $j[$k] = $v; }
        return $j;
    }, $iterations),
    'internal' => (function() use ($mixed_data) {
        $j = Judy::fromArray(Judy::INT_TO_MIXED, $mixed_data);
        return $j->memoryUsage();
    })(),
    'note'   => 'JudyLMemUsed (excl. zvals)',
];

// INT_TO_PACKED
if ($has_packed) {
    $results['INT_TO_PACKED'] = [
        'keys'   => 'int',
        'values' => 'mixed',
        'write'  => bench_median(function() use ($mixed_data) {
            $j = new Judy(Judy::INT_TO_PACKED);
            foreach ($mixed_data as $k => $v) { $j[$k] = $v; }
        }, $iterations),
        'read'   => (function() use ($mixed_data, $keys_arr, $iterations) {
            $j = Judy::fromArray(Judy::INT_TO_PACKED, $mixed_data);
            return bench_median(function() use ($j, $keys_arr) {
                foreach ($keys_arr as $k) { $v = $j[$k]; }
            }, $iterations);
        })(),
        'iter'   => (function() use ($mixed_data, $iterations) {
            $j = Judy::fromArray(Judy::INT_TO_PACKED, $mixed_data);
            return bench_median(function() use ($j) {
                foreach ($j as $k => $v) {}
            }, $iterations);
        })(),
        'heap'   => measure_heap(function() use ($mixed_data) {
            $j = new Judy(Judy::INT_TO_PACKED);
            foreach ($mixed_data as $k => $v) { $j[$k] = $v; }
            return $j;
        }),
        'free'   => measure_free(function() use ($mixed_data) {
            $j = new Judy(Judy::INT_TO_PACKED);
            foreach ($mixed_data as $k => $v) { $j[$k] = $v; }
            return $j;
        }, $iterations),
        'internal' => (function() use ($mixed_data) {
            $j = Judy::fromArray(Judy::INT_TO_PACKED, $mixed_data);
            return $j->memoryUsage();
        })(),
        'note'   => 'JudyLMemUsed (excl. bufs)',
    ];
} else {
    $results['INT_TO_PACKED'] = [
        'keys' => 'int', 'values' => 'mixed',
        'write' => null, 'read' => null, 'iter' => null,
        'heap' => null, 'free' => null, 'internal' => null,
        'note' => 'not supported on this platform',
    ];
}

// ── String-keyed subjects ───────────────────────────────────────────────

$str_int_data   = make_str_int($size);
$str_mixed_data = make_str_mixed($size);
$str_keys       = array_keys($str_int_data);
$str_mix_keys   = array_keys($str_mixed_data);

// PHP string array — int values
$results['PHP array (str→int)'] = [
    'keys'   => 'string',
    'values' => 'int',
    'write'  => bench_median(function() use ($str_int_data) {
        $a = [];
        foreach ($str_int_data as $k => $v) { $a[$k] = $v; }
    }, $iterations),
    'read'   => (function() use ($str_int_data, $str_keys, $iterations) {
        $a = $str_int_data;
        return bench_median(function() use ($a, $str_keys) {
            foreach ($str_keys as $k) { $v = $a[$k]; }
        }, $iterations);
    })(),
    'iter'   => (function() use ($str_int_data, $iterations) {
        $a = $str_int_data;
        return bench_median(function() use ($a) {
            foreach ($a as $k => $v) {}
        }, $iterations);
    })(),
    'heap'   => measure_heap(function() use ($str_int_data) {
        $a = [];
        foreach ($str_int_data as $k => $v) { $a[$k] = $v; }
        return $a;
    }),
    'free'   => measure_free(function() use ($str_int_data) {
        $a = [];
        foreach ($str_int_data as $k => $v) { $a[$k] = $v; }
        return $a;
    }, $iterations),
    'internal' => null,
    'note'   => '',
];

// STRING_TO_INT
$results['STRING_TO_INT'] = [
    'keys'   => 'string',
    'values' => 'int',
    'write'  => bench_median(function() use ($str_int_data) {
        $j = new Judy(Judy::STRING_TO_INT);
        foreach ($str_int_data as $k => $v) { $j[$k] = $v; }
    }, $iterations),
    'read'   => (function() use ($str_int_data, $str_keys, $iterations) {
        $j = Judy::fromArray(Judy::STRING_TO_INT, $str_int_data);
        return bench_median(function() use ($j, $str_keys) {
            foreach ($str_keys as $k) { $v = $j[$k]; }
        }, $iterations);
    })(),
    'iter'   => (function() use ($str_int_data, $iterations) {
        $j = Judy::fromArray(Judy::STRING_TO_INT, $str_int_data);
        return bench_median(function() use ($j) {
            foreach ($j as $k => $v) {}
        }, $iterations);
    })(),
    'heap'   => measure_heap(function() use ($str_int_data) {
        $j = new Judy(Judy::STRING_TO_INT);
        foreach ($str_int_data as $k => $v) { $j[$k] = $v; }
        return $j;
    }),
    'free'   => measure_free(function() use ($str_int_data) {
        $j = new Judy(Judy::STRING_TO_INT);
        foreach ($str_int_data as $k => $v) { $j[$k] = $v; }
        return $j;
    }, $iterations),
    'internal' => null,  // JudySL has no C-level memory accounting
    'note'   => 'memoryUsage()=null (JudySL)',
];

// PHP string array — mixed values
$results['PHP array (str→mixed)'] = [
    'keys'   => 'string',
    'values' => 'mixed',
    'write'  => bench_median(function() use ($str_mixed_data) {
        $a = [];
        foreach ($str_mixed_data as $k => $v) { $a[$k] = $v; }
    }, $iterations),
    'read'   => (function() use ($str_mixed_data, $str_mix_keys, $iterations) {
        $a = $str_mixed_data;
        return bench_median(function() use ($a, $str_mix_keys) {
            foreach ($str_mix_keys as $k) { $v = $a[$k]; }
        }, $iterations);
    })(),
    'iter'   => (function() use ($str_mixed_data, $iterations) {
        $a = $str_mixed_data;
        return bench_median(function() use ($a) {
            foreach ($a as $k => $v) {}
        }, $iterations);
    })(),
    'heap'   => measure_heap(function() use ($str_mixed_data) {
        $a = [];
        foreach ($str_mixed_data as $k => $v) { $a[$k] = $v; }
        return $a;
    }),
    'free'   => measure_free(function() use ($str_mixed_data) {
        $a = [];
        foreach ($str_mixed_data as $k => $v) { $a[$k] = $v; }
        return $a;
    }, $iterations),
    'internal' => null,
    'note'   => '',
];

// STRING_TO_MIXED
$results['STRING_TO_MIXED'] = [
    'keys'   => 'string',
    'values' => 'mixed',
    'write'  => bench_median(function() use ($str_mixed_data) {
        $j = new Judy(Judy::STRING_TO_MIXED);
        foreach ($str_mixed_data as $k => $v) { $j[$k] = $v; }
    }, $iterations),
    'read'   => (function() use ($str_mixed_data, $str_mix_keys, $iterations) {
        $j = Judy::fromArray(Judy::STRING_TO_MIXED, $str_mixed_data);
        return bench_median(function() use ($j, $str_mix_keys) {
            foreach ($str_mix_keys as $k) { $v = $j[$k]; }
        }, $iterations);
    })(),
    'iter'   => (function() use ($str_mixed_data, $iterations) {
        $j = Judy::fromArray(Judy::STRING_TO_MIXED, $str_mixed_data);
        return bench_median(function() use ($j) {
            foreach ($j as $k => $v) {}
        }, $iterations);
    })(),
    'heap'   => measure_heap(function() use ($str_mixed_data) {
        $j = new Judy(Judy::STRING_TO_MIXED);
        foreach ($str_mixed_data as $k => $v) { $j[$k] = $v; }
        return $j;
    }),
    'free'   => measure_free(function() use ($str_mixed_data) {
        $j = new Judy(Judy::STRING_TO_MIXED);
        foreach ($str_mixed_data as $k => $v) { $j[$k] = $v; }
        return $j;
    }, $iterations),
    'internal' => null,  // JudySL has no C-level memory accounting
    'note'   => 'memoryUsage()=null (JudySL)',
];

// STRING_TO_MIXED_HASH
$results['STRING_TO_MIXED_HASH'] = [
    'keys'   => 'string',
    'values' => 'mixed',
    'write'  => bench_median(function() use ($str_mixed_data) {
        $j = new Judy(Judy::STRING_TO_MIXED_HASH);
        foreach ($str_mixed_data as $k => $v) { $j[$k] = $v; }
    }, $iterations),
    'read'   => (function() use ($str_mixed_data, $str_mix_keys, $iterations) {
        $j = Judy::fromArray(Judy::STRING_TO_MIXED_HASH, $str_mixed_data);
        return bench_median(function() use ($j, $str_mix_keys) {
            foreach ($str_mix_keys as $k) { $v = $j[$k]; }
        }, $iterations);
    })(),
    'iter'   => (function() use ($str_mixed_data, $iterations) {
        $j = Judy::fromArray(Judy::STRING_TO_MIXED_HASH, $str_mixed_data);
        return bench_median(function() use ($j) {
            foreach ($j as $k => $v) {}
        }, $iterations);
    })(),
    'heap'   => measure_heap(function() use ($str_mixed_data) {
        $j = new Judy(Judy::STRING_TO_MIXED_HASH);
        foreach ($str_mixed_data as $k => $v) { $j[$k] = $v; }
        return $j;
    }),
    'free'   => measure_free(function() use ($str_mixed_data) {
        $j = new Judy(Judy::STRING_TO_MIXED_HASH);
        foreach ($str_mixed_data as $k => $v) { $j[$k] = $v; }
        return $j;
    }, $iterations),
    'internal' => null,  // JudyHS has no C-level memory accounting
    'note'   => 'memoryUsage()=null (JudyHS)',
];

// ── Output ─────────────────────────────────────────────────────────────────

$div  = str_repeat('━', 92);
$dash = str_repeat('─', 92);

echo "$div\n";
echo "  Judy All-Types Benchmark — " . number_format($size) . " elements, $iterations iterations (median)\n";
echo "$div\n";
echo "  PHP " . phpversion() . " | Judy ext " . judy_version() . "\n";
echo "  INT_TO_PACKED: " . ($has_packed ? "supported" : "NOT supported on this platform") . "\n";
echo "$div\n\n";

// ── Section 1: Integer-keyed types ──────────────────────────────────────

echo "┌─ Integer-keyed types (" . number_format($size) . " elements) ──────────────────────────────┐\n\n";

$int_types = ['PHP array (bool)', 'BITSET', 'PHP array (int)', 'INT_TO_INT',
              'PHP array (mixed)', 'INT_TO_MIXED', 'INT_TO_PACKED'];

// Grouped display: bool group, int group, mixed group
$int_groups = [
    'bool values (BITSET use-case)'    => ['PHP array (bool)', 'BITSET'],
    'integer values'                   => ['PHP array (int)',  'INT_TO_INT'],
    'mixed values (str/int/array/bool)'=> ['PHP array (mixed)','INT_TO_MIXED','INT_TO_PACKED'],
];

foreach ($int_groups as $group_label => $names) {
    $w = [24, 10, 10, 10, 10, 14, 20];
    printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s  %{$w[4]}s  %{$w[5]}s  %-{$w[6]}s\n",
        "[$group_label]", 'Write', 'Read', 'Foreach', 'Free', 'Heap delta', 'Internal mem');
    printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s  %{$w[4]}s  %{$w[5]}s  %-{$w[6]}s\n",
        str_repeat('─', $w[0]),
        str_repeat('─', $w[1]),
        str_repeat('─', $w[2]),
        str_repeat('─', $w[3]),
        str_repeat('─', $w[4]),
        str_repeat('─', $w[5]),
        str_repeat('─', $w[6]));

    foreach ($names as $name) {
        $r = $results[$name];
        $write = $r['write'] !== null ? sprintf('%.2f ms', $r['write']) : '—';
        $read  = $r['read']  !== null ? sprintf('%.2f ms', $r['read'])  : '—';
        $iter  = $r['iter']  !== null ? sprintf('%.2f ms', $r['iter'])  : '—';
        $free  = $r['free']  !== null ? sprintf('%.2f ms', $r['free'])  : '—';
        $heap  = $r['heap']  !== null ? fmt_bytes($r['heap'])           : '—';
        $mem   = fmt_mem($r['internal']);
        $note  = $r['note'] !== '' ? " ({$r['note']})" : '';

        printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s  %{$w[4]}s  %{$w[5]}s  %-{$w[6]}s\n",
            $name, $write, $read, $iter, $free, $heap, $mem . $note);
    }
    echo "\n";
}

// ── Section 2: String-keyed types ──────────────────────────────────────

echo "┌─ String-keyed types (" . number_format($size) . " elements) ───────────────────────────────┐\n\n";

$str_groups = [
    'integer values'                    => ['PHP array (str→int)',   'STRING_TO_INT'],
    'mixed values (str/int/array/bool)' => ['PHP array (str→mixed)', 'STRING_TO_MIXED', 'STRING_TO_MIXED_HASH'],
];

foreach ($str_groups as $group_label => $names) {
    $w = [26, 10, 10, 10, 10, 14, 20];
    printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s  %{$w[4]}s  %{$w[5]}s  %-{$w[6]}s\n",
        "[$group_label]", 'Write', 'Read', 'Foreach', 'Free', 'Heap delta', 'Internal mem');
    printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s  %{$w[4]}s  %{$w[5]}s  %-{$w[6]}s\n",
        str_repeat('─', $w[0]),
        str_repeat('─', $w[1]),
        str_repeat('─', $w[2]),
        str_repeat('─', $w[3]),
        str_repeat('─', $w[4]),
        str_repeat('─', $w[5]),
        str_repeat('─', $w[6]));

    foreach ($names as $name) {
        $r = $results[$name];
        $write = $r['write'] !== null ? sprintf('%.2f ms', $r['write']) : '—';
        $read  = $r['read']  !== null ? sprintf('%.2f ms', $r['read'])  : '—';
        $iter  = $r['iter']  !== null ? sprintf('%.2f ms', $r['iter'])  : '—';
        $free  = $r['free']  !== null ? sprintf('%.2f ms', $r['free'])  : '—';
        $heap  = $r['heap']  !== null ? fmt_bytes($r['heap'])           : '—';
        $mem   = fmt_mem($r['internal']);
        $note  = $r['note'] !== '' ? " ({$r['note']})" : '';

        printf("  %-{$w[0]}s  %{$w[1]}s  %{$w[2]}s  %{$w[3]}s  %{$w[4]}s  %{$w[5]}s  %-{$w[6]}s\n",
            $name, $write, $read, $iter, $free, $heap, $mem . $note);
    }
    echo "\n";
}

// ── Section 3: Summary comparison table ────────────────────────────────

echo "$div\n";
echo "  SUMMARY — All types, " . number_format($size) . " elements (median of $iterations iterations)\n";
echo "$div\n";

$w = [22, 8, 7, 10, 10, 10, 10, 14];
printf("  %-{$w[0]}s  %-{$w[1]}s  %-{$w[2]}s  %{$w[3]}s  %{$w[4]}s  %{$w[5]}s  %{$w[6]}s  %{$w[7]}s\n",
    'Type', 'Keys', 'Values', 'Write(ms)', 'Read(ms)', 'Iter(ms)', 'Free(ms)', 'Heap delta');
printf("  %s\n", str_repeat('─', array_sum($w) + count($w) * 2));

$ordered = [
    'PHP array (bool)', 'BITSET',
    'PHP array (int)',  'INT_TO_INT',
    'PHP array (mixed)','INT_TO_MIXED', 'INT_TO_PACKED',
    'PHP array (str→int)',   'STRING_TO_INT',
    'PHP array (str→mixed)', 'STRING_TO_MIXED', 'STRING_TO_MIXED_HASH',
];

$prev_keys = null;
foreach ($ordered as $name) {
    $r = $results[$name];
    if ($prev_keys !== null && $r['keys'] !== $prev_keys) {
        printf("  %s\n", str_repeat('·', array_sum($w) + count($w) * 2));
    }
    $prev_keys = $r['keys'];

    $write = $r['write'] !== null ? sprintf('%.2f', $r['write']) : '—';
    $read  = $r['read']  !== null ? sprintf('%.2f', $r['read'])  : '—';
    $iter  = $r['iter']  !== null ? sprintf('%.2f', $r['iter'])  : '—';
    $free  = $r['free']  !== null ? sprintf('%.2f', $r['free'])  : '—';
    $heap  = $r['heap']  !== null ? fmt_bytes($r['heap'])        : '—';

    printf("  %-{$w[0]}s  %-{$w[1]}s  %-{$w[2]}s  %{$w[3]}s  %{$w[4]}s  %{$w[5]}s  %{$w[6]}s  %{$w[7]}s\n",
        $name, $r['keys'], $r['values'], $write, $read, $iter, $free, $heap);
}

echo "\n";
echo "  Notes:\n";
echo "  • Write/Read/Iter: median of $iterations iterations via hrtime(true)\n";
echo "  • Free: median time to unset() + gc_collect_cycles() a populated container\n";
echo "  • Heap delta: memory_get_usage() before/after populate (emalloc'd PHP heap)\n";
echo "  • Internal mem: Judy C-level accounting (JLMU/J1MU) via memoryUsage()\n";
echo "    – BITSET/INT_TO_INT/INT_TO_MIXED/INT_TO_PACKED support this\n";
echo "    – STRING_TO_INT/STRING_TO_MIXED: JudySL has no accounting macro → n/a\n";
echo "    – STRING_TO_MIXED_HASH: JudyHS has no accounting macro → n/a\n";
echo "    – INT_TO_MIXED: counts JudyL nodes only; ecalloc'd zvals not included\n";
echo "    – INT_TO_PACKED: counts JudyL nodes only; emalloc'd packed bufs not included\n";
echo "$div\n";
echo "  Benchmark complete — " . date('Y-m-d H:i:s') . "\n";
echo "$div\n";
