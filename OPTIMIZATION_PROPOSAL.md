# PHP Judy Extension — Optimization & Enhancement Proposal (SOTA Edition)

**Version**: 2.5.0
**Date**: March 2026
**Status**: Proposal / SOTA Research

---

## Table of Contents

1. [Current Architecture Assessment](#1-current-architecture-assessment)
2. [CPU/Speed Optimization Opportunities](#2-cpuspeed-optimization-opportunities)
3. [Memory Optimization Opportunities](#3-memory-optimization-opportunities)
4. [New Features to Expand Library Value](#4-new-features-to-expand-library-value)
5. [Code Quality & Correctness Issues](#5-code-quality--correctness-issues)
6. [SOTA: Aggressive "Adaptive" Storage Strategy (The Array Killer)](#6-sota-aggressive-adaptive-storage-strategy-the-array-killer)
7. [Benchmark Strategy for SOTA Approach](#7-benchmark-strategy-for-sota-approach)
8. [Optimization Priority Matrix](#8-optimization-priority-matrix)
9. [Summary](#9-summary)

---

## 1. Current Architecture Assessment

The extension is well-structured with 4 C source files (`php_judy.c`, `judy_handlers.c`, `judy_arrayaccess.c`, `judy_iterator.c`), 8 Judy array types, and a clean PHP API surface. The codebase already includes several good optimizations:

- **Cached type-flag booleans** (`is_integer_keyed`, `is_mixed_value`, etc.) to avoid repeated type comparisons
- **`JUDYERROR_NOTEST`** to skip `JError_t` stack allocations
- **`INT_TO_PACKED` tagged-union storage** avoiding serialize/unserialize for scalar values
- **Optimized `judy_populate_from_array()`** with type-specialized tight loops for integer-keyed types
- **Efficient `judy_iterator`** with pre-allocated `key_scratch` buffer for string key reuse
- **Production compiler flags** (`-O3 -fomit-frame-pointer -fno-common`) in `config.m4`

---

## 2. CPU/Speed Optimization Opportunities

### 2A. Eliminate Redundant JLG Before JLI in Hot Paths ⭐ HIGH IMPACT

**The single most impactful optimization.** In multiple hot paths, a `JLG` (lookup) is followed by `JLI` (insert-or-get) to track whether a key is new. But `JLI` already returns a pointer to a *zeroed* slot for new keys, so newness can be detected by checking `*PValue == 0` after `JLI`, eliminating one full tree traversal per write.

**Current code** (`php_judy.c`, INT_TO_INT write, ~line 510):

```c
// Two traversals per write
PWord_t PExisting;
JLG(PExisting, intern->array, index);  // traversal #1
JLI(PValue, intern->array, index);     // traversal #2
JUDY_LVAL_WRITE(PValue, zval_get_long(value));
if (PExisting == NULL) intern->counter++;
```

**Proposed:**

```c
// Single traversal per write
JLI(PValue, intern->array, index);     // traversal #1 only
zend_long was_new = (*(Word_t *)PValue == 0);  // JLI zeros new slots
JUDY_LVAL_WRITE(PValue, zval_get_long(value));
if (was_new) intern->counter++;
```

**Affected locations:**

| File         | Function                               | Line(s)    | Type                      |
| ------------ | -------------------------------------- | ---------- | ------------------------- |
| `php_judy.c` | `judy_object_write_dimension_helper()` | ~510-517   | INT_TO_INT                |
| `php_judy.c` | `judy_object_write_dimension_helper()` | ~561-568   | STRING_TO_INT (JSLG+JSLI) |
| `php_judy.c` | `judy_populate_from_array()`           | ~2852-2858 | TYPE_INT_TO_INT           |
| `php_judy.c` | `judy_populate_from_array()`           | ~2867-2869 | TYPE_INT_TO_MIXED         |
| `php_judy.c` | `judy_populate_from_array()`           | ~2892-2893 | TYPE_INT_TO_PACKED        |
| `php_judy.c` | `increment()`                          | ~3077-3083 | INT_TO_INT                |

**For MIXED types**, `JUDY_MVAL_READ(PValue) == NULL` after `JLI` indicates a new slot.
**For STRING_TO_INT types**, `JSLI` also zeros new slots.

**Expected improvement**: ~30-40% speedup on bulk insert workloads by cutting Judy tree traversals in half.

> **⚠️ Correctness Note on value 0 ambiguity for INT_TO_INT and STRING_TO_INT**: The proposed optimization checks if `*PValue == 0` after a `JLI` or `JSLI` call to detect a new key, as Judy zeros new slots. However, this is ambiguous for `INT_TO_INT` and `STRING_TO_INT` types, where `0` is a valid user-stored value. If an existing key already has the value `0`, the check will incorrectly identify it as a new key and increment the counter on overwrite.
>
> **Recommended Solution**: Keep the `JLG`/`JSLG` lookup for `INT_TO_INT` and `STRING_TO_INT` types. The optimization can be safely applied to `MIXED`/`PACKED` types where a `NULL` pointer is an unambiguous "new slot" indicator. This captures most of the benefit with zero correctness risk.

---

### 2B. Use `__builtin_expect` for Branch Prediction Hints — MEDIUM IMPACT

Error paths (`PValue == PJERR`, `PValue == NULL` for missing keys, empty arrays) are cold paths. Hinting the compiler improves branch prediction on modern CPUs:

```c
// Add to php_judy.h
#if defined(__GNUC__) || defined(__clang__)
#define JUDY_LIKELY(x)   __builtin_expect(!!(x), 1)
#define JUDY_UNLIKELY(x) __builtin_expect(!!(x), 0)
#else
#define JUDY_LIKELY(x)   (x)
#define JUDY_UNLIKELY(x) (x)
#endif
```

**Apply in**: `read_dimension_helper`, `write_dimension_helper`, `has_dimension_helper`, iterator `move_forward`, `getAll()`.

---

### 2C. Type Vtable / Function Pointers — MEDIUM IMPACT

Many methods re-dispatch on `intern->type` every call via switch/if-chains. Store function pointers (a vtable) per-instance at construction time:

```c
typedef struct _judy_type_ops {
    zval *(*read)(judy_object *intern, zval *offset, zval *rv);
    int   (*write)(judy_object *intern, zval *offset, zval *value);
    int   (*unset)(judy_object *intern, zval *offset);
    void  (*iter_rewind)(judy_object *intern, judy_iterator *it);
    void  (*iter_next)(judy_object *intern, judy_iterator *it);
} judy_type_ops;

// In constructor:
intern->ops = &int_to_int_ops;
```

---

### 2D. Iterator String Key Allocation for Hash Types — MEDIUM IMPACT

In `judy_iterator_move_forward()` for `STRING_TO_INT` / `STRING_TO_MIXED`, there is already a smart optimization that reuses the key `zend_string` when it fits (`judy_iterator.c`, lines 202-210). However, for `STRING_TO_MIXED_HASH` and `STRING_TO_INT_HASH` iteration (line 243-244), a new `ZVAL_STRING` is always allocated per step.

**Current** (`judy_iterator.c`, ~line 243):

```c
// Hash-keyed path: always allocates a new zend_string per step
zval_ptr_dtor(&it->key);
ZVAL_STRING(&it->key, (char *)key);
```

**Proposed** — apply the same reuse pattern already used for non-hash string types:

```c
size_t new_len = strlen((char *)key);
if (Z_TYPE(it->key) == IS_STRING && !ZSTR_IS_INTERNED(Z_STR(it->key))
    && GC_REFCOUNT(Z_STR(it->key)) == 1
    && new_len <= ZSTR_LEN(Z_STR(it->key))) {
    memcpy(ZSTR_VAL(Z_STR(it->key)), key, new_len + 1);
    ZSTR_LEN(Z_STR(it->key)) = new_len;
} else {
    zval_ptr_dtor(&it->key);
    ZVAL_STRINGL(&it->key, (char *)key, new_len);
}
```

**Expected improvement**: Eliminates one `emalloc`/`efree` per iteration step for hash-keyed string types.

---

### 2E. Hash-Keyed Iterator: Store Value Pointer in key_index — LOW-MEDIUM IMPACT

For `STRING_TO_MIXED_HASH` iteration, every `move_forward` does two lookups:
1. `JSLN` on `key_index` to get next key (ordered traversal)
2. `JHSG` on `array` to get the value (hash lookup by key)

The `key_index` (JudySL) value slots are currently unused — they just serve as markers for key ordering. We can cache the JudyHS value pointer there to skip the second lookup:

```c
// During insert (write_dimension_helper), store the HValue pointer in key_index:
JHSI(HValue, intern->array, key, key_len);
JSLI(KValue, intern->key_index, (uint8_t *)key);
*KValue = (Word_t)HValue;  // cache the JudyHS slot pointer

// During iteration (move_forward), read directly — no JHSG needed:
JSLN(KValue, object->key_index, key);
if (KValue != NULL && KValue != PJERR) {
    Pvoid_t *HValue = (Pvoid_t *)(Word_t)*KValue;  // cached pointer
    // ... read value from HValue directly ...
}
```

> **⚠️ Caveat**: JudyHS may reallocate internal storage on subsequent `JHSI` calls, potentially invalidating cached pointers. This optimization is only safe if the array is not modified during iteration (the common `foreach` case). An alternative is to re-validate the pointer by comparing the key, falling back to `JHSG` on mismatch.

---

### 2F. Zval Slab/Freelist for INT_TO_MIXED — MEDIUM IMPACT

Every `INT_TO_MIXED` and `STRING_TO_MIXED` write allocates a `zval` on the heap via `emalloc(sizeof(zval))`. Since `sizeof(zval) == 16` on 64-bit, a simple per-object freelist avoids per-allocation overhead:

```c
// Add to judy_object struct in php_judy.h:
zval *zval_freelist;  // singly-linked freelist of recycled zval containers

// On delete (unset_dimension) — return to freelist instead of efree:
zval *old = JUDY_MVAL_READ(PValue);
zval_ptr_dtor(old);                       // destroy the zval content
*(void**)old = intern->zval_freelist;     // push container onto freelist
intern->zval_freelist = old;

// On insert (write_dimension) — pop from freelist before emalloc:
zval *new_value;
if (intern->zval_freelist) {
    new_value = intern->zval_freelist;
    intern->zval_freelist = *(void**)new_value;  // pop from freelist
} else {
    new_value = emalloc(sizeof(zval));
}
ZVAL_COPY(new_value, value);

// On object destruction (free_storage) — drain the freelist:
while (intern->zval_freelist) {
    zval *next = *(void**)intern->zval_freelist;
    efree(intern->zval_freelist);
    intern->zval_freelist = next;
}
```

**Expected improvement**: Significant reduction in allocator pressure for workloads that churn values (frequent overwrites, delete+reinsert patterns).

---

### 2G. Compiler Flags Enhancement — LOW IMPACT

Enable `-flto` (Link-Time Optimization) and `-funroll-loops` to allow cross-translation-unit inlining and faster iteration.

---

## 3. Memory Optimization Opportunities

### 3A. `judy_object` Struct Layout Optimization — LOW IMPACT

Current struct (`php_judy.h`, line 181) has suboptimal field ordering that causes padding waste:

```c
// Current layout — note the padding gaps after lone zend_bool fields
typedef struct _judy_object {
    zend_long       type;                // 8 bytes
    Pvoid_t         array;               // 8 bytes
    zend_long       counter;             // 8 bytes
    Word_t          next_empty;          // 8 bytes
    zend_bool       next_empty_is_valid; // 1 byte + 7 padding ❌
    zval            iterator_key;        // 16 bytes
    zval            iterator_data;       // 16 bytes
    zend_bool       iterator_initialized;// 1 byte
    zend_bool       is_integer_keyed;    // 1 byte
    zend_bool       is_string_keyed;     // 1 byte
    zend_bool       is_mixed_value;      // 1 byte
    zend_bool       is_packed_value;     // 1 byte
    zend_bool       is_hash_keyed;       // 1 byte + 2 padding
    Pvoid_t         key_index;           // 8 bytes
    zend_object     std;                 // must be last (XtOffsetOf)
} judy_object;
```

**Proposed** — group all bools together, put hottest fields first on the same cache line:

```c
typedef struct _judy_object {
    Pvoid_t         array;               // 8  — hottest field, first cache line
    Pvoid_t         key_index;           // 8
    zend_long       counter;             // 8
    Word_t          next_empty;          // 8
    zend_long       type;                // 8
    zval            iterator_key;        // 16
    zval            iterator_data;       // 16
    // Pack all bools together (7 bytes + 1 byte padding)
    zend_bool       next_empty_is_valid;
    zend_bool       iterator_initialized;
    zend_bool       is_integer_keyed;
    zend_bool       is_string_keyed;
    zend_bool       is_mixed_value;
    zend_bool       is_packed_value;
    zend_bool       is_hash_keyed;
    zend_object     std;                 // must be last (XtOffsetOf)
} judy_object;
```

**Benefit**: Saves ~8-16 bytes per instance from reduced padding; puts `array`, `key_index`, `counter`, and `type` on the same 64-byte cache line for hot-path access.

---

## 4. New Features to Expand Library Value

### 4A. `mergeWith()` — In-Place Merge ⭐ HIGH VALUE

Currently, `union()` creates a new array. A mutating `mergeWith()` avoids the allocation:
```php
public function mergeWith(Judy $other): void {}
```
Valuable for accumulation patterns (e.g., merging counters from multiple workers).

---

### 4B. C-Level `forEach()` / `filter()` / `map()` ⭐ HIGH VALUE

`forEach()` implemented in C bypasses the entire PHP Iterator protocol. Benchmarks show the Iterator protocol is **11.2x slower** than native `getAll()`. A C-level forEach calling a PHP callback per element would be 2-3x faster than `foreach` by eliminating `valid()` → `current()` → `key()` → `next()` round-trips.
```php
public function forEach(callable $callback): void {}
public function filter(callable $predicate): Judy {}
public function map(callable $transform): Judy {}
```

---

### 4C. `populationCount(start, end)` — Expose J1C/JLC Range Counting — MEDIUM VALUE

`size()` already wraps `J1C`/`JLC`, but a dedicated `populationCount` exposes Judy's killer feature: answering "how many bits/keys are set between X and Y" in **O(1) time** via the internal population cache. No PHP array can replicate this.
```php
public function populationCount(int $start = 0, int $end = -1): int {}
```

---

### 4D. Native `keys()` and `values()` — MEDIUM VALUE

Like `toArray()`, these use native C iteration and would be **2-3x faster** than `array_keys($judy->toArray())`.
```php
public function keys(): array {}
public function values(): array {}
```

---

### 4E. Bulk `removeAll()` / `deleteRange()` — MEDIUM VALUE

`deleteRange` for `BITSET`/`INT_TO_INT` would be extremely powerful — iterate and delete in a single C pass. Currently, users must `foreach` + `unset`, which is slow due to per-element PHP dispatch.
```php
public function removeAll(array $keys): int {}
public function deleteRange(int $start, int $end): int {}
```

---

### 4F. Set Operations for STRING_TO_INT Types — MEDIUM VALUE

Extending `union`, `intersect`, etc., to `STRING_TO_INT` types adds significant value for text-processing workloads (vocabulary intersection, tag comparison).

---

### 4G. `sumValues()` / `averageValues()` — Aggregation — MEDIUM VALUE

A C-level sum over all values avoids iteration overhead entirely. Natural for counters and analytics use cases.
```php
public function sumValues(): int|float {}
```

---

### 4H. `equals()` / `isSubsetOf()` — Comparison — MEDIUM VALUE

These can short-circuit early (return false on first mismatch), much faster than materializing both arrays for comparison.
```php
public function equals(Judy $other): bool {}
```

---

## 5. Code Quality & Correctness Issues

### 5A. Stack-Allocated 64KB Buffer Risk

Every method handling string keys allocates `uint8_t key[64KB]` on the stack. This is a major risk for **PHP Fibers (coroutines)** or deep recursion, where stack space is limited.
**Proposed**: Use the `key_scratch` pattern (heap-allocated, reused scratch buffer) or dynamic allocation for keys > 4KB.

### 5B. Duplicate Type Initialization Code

The type flag initialization block is duplicated in 5+ locations: `__construct`, `__unserialize`, `judy_create_result`, `judy_create_bitset_result`, and `fromArray`.
**Proposed**: A single `judy_init_type_flags(intern, jtype)` helper to prevent future inconsistencies.

### 5C. Missing Parameter Guards

Methods like `getType()`, `free()`, `count()`, etc., lack `ZEND_PARSE_PARAMETERS_NONE()` guards, silently accepting extra arguments.

---

## 6. SOTA: Aggressive "Adaptive" Storage Strategy (The Array Killer)

To truly compete with and exceed PHP arrays, we propose a new `Judy\Adaptive` type (or `Judy\Auto`) that uses a multi-tier storage model. Judy is an excellent "Big O" data structure, but for small sets, the overhead of tree traversal and node management can be slower than a simple linear array.

### 6A. Tiered Storage Model (The "Evolutionary" Array)

An `Adaptive` object starts small and evolves as data is added:

1.  **Tier 0: Inline Storage (0-8 elements)**
    *   **Mechanism**: Keys and Values are stored directly in the `judy_object` struct (8 `Word_t` for keys, 8 `Word_t` for values).
    *   **Cost**: Zero heap allocations beyond the object itself.
    *   **Speed**: O(N) linear scan. For N=8, this fits in a single cache line and is faster than any tree or hash lookup.
2.  **Tier 1: Linear Sorted Array (9-128 elements)**
    *   **Mechanism**: A single `emalloc` block containing a sorted list of KV pairs.
    *   **Speed**: O(log N) binary search. Perfect cache locality.
3.  **Tier 2: Dense/Packed Path (Adaptive Optimization)**
    *   **Mechanism**: If keys are detected to be dense (e.g., >70% density in a 0..1024 range), switch to a standard C array for O(1) access.
4.  **Tier 3: Full Judy Tree (129+ elements or Sparse)**
    *   **Mechanism**: Promote to `JudyL` or `JudyHS`.
    *   **Benefit**: Judy's O(log base 256) performance takes over for large datasets.

#### Architectural Considerations

**Struct changes**: The `judy_object` struct would gain a `tier` field and a union for tier-specific storage:

```c
typedef struct _judy_adaptive_object {
    uint8_t tier;           // 0, 1, 2, or 3
    uint8_t count;          // element count (for tier 0/1)
    union {
        struct {            // Tier 0: inline
            Word_t keys[8];
            Word_t values[8];
        } inline_store;
        struct {            // Tier 1: sorted array
            Word_t *kv_pairs;   // emalloc'd, sorted by key
            uint16_t capacity;
        } sorted_store;
        struct {            // Tier 2: dense C array
            Word_t *values;     // emalloc'd, indexed by (key - base)
            Word_t base;
            Word_t range;
        } dense_store;
        Pvoid_t judy_array; // Tier 3: full JudyL/JudyHS
    } storage;
    zend_object std;        // must be last
} judy_adaptive_object;
```

**Promotion/demotion**: Promotion happens on insert when the current tier's capacity is exceeded. Demotion (e.g., Tier 2 → Tier 1 after many deletes reduce density below threshold) should use **hysteresis** — demote at a lower threshold (e.g., 30% density) than the promotion threshold (70%) to avoid thrashing between tiers on boundary workloads.

**Relationship to existing types**: This would be a **new 9th type** (`TYPE_ADAPTIVE`), not a replacement for existing types. Users who know their data is large and sparse can still use `INT_TO_INT` directly for zero-overhead Judy access. The `Adaptive` type targets the general-purpose "I don't know my data size" use case.

**Density detection for Tier 2 promotion**: On every insert into Tier 1, track `min_key` and `max_key` alongside `count`. Density = `count / (max_key - min_key + 1)`. When `count > 64` and density exceeds 70%, promote to Tier 2. This check is O(1) per insert — no scanning required. On delete from Tier 2, recompute density; demote to Tier 1 when it drops below 30% (hysteresis).

**String key scoping**: The `Adaptive` type as described here is **integer-key only**. String keys have fundamentally different tier characteristics (no density concept, no sorted integer binary search). A future `Judy\AdaptiveString` could use a similar tiered model: Tier 0 inline with `zend_string*` pointers, Tier 1 sorted array with `strcmp`-based binary search, Tier 2 JudySL, Tier 3 JudyHS. But that's a separate design exercise — the integer `Adaptive` type should be proven first.

**Iterator complexity**: Each tier needs its own iteration logic. The vtable approach from 2C becomes essential here — `intern->ops` would point to the correct tier's iteration functions, updated on promotion/demotion.

### 6B. Short-String Optimization (SSO) for JudyHS

Hashing is expensive. For string keys ≤ 7 bytes:
- Pack characters directly into a 64-bit `Word_t`.
- Store in a `JudyL` (integer) tree instead of `JudyHS` (hash).
- **Result**: Eliminates hashing overhead and hash collisions for short tags/keys.

#### Byte-Packing Scheme

The encoding must produce `Word_t` values whose **natural integer sort order matches lexicographic string order**, so that `JudyL` iteration (`JLF`/`JLN`) yields keys in the same order as `JudySL`/`strcmp`.

```c
/* Pack a string of len <= 7 into a Word_t (big-endian, NUL-padded right).
 * Byte 0 (MSB) = length (1-7), Bytes 1-7 = chars left-aligned, NUL-padded.
 * Length in MSB ensures no collision between "a" (len=1) and "a\0\0..." (len=5).
 * Big-endian layout ensures strcmp-compatible sort order on JudyL integer keys. */
static inline Word_t judy_sso_pack(const char *key, size_t len) {
    Word_t packed = 0;
    ((unsigned char *)&packed)[0] = (unsigned char)len;  // MSB = length
    memcpy(((unsigned char *)&packed) + 1, key, len);    // chars after length
    // Remaining bytes are already 0 from initialization
#if __BYTE_ORDER__ == __ORDER_LITTLE_ENDIAN__
    packed = __builtin_bswap64(packed);  // Convert to big-endian for sort order
#endif
    return packed;
}

static inline size_t judy_sso_unpack(Word_t packed, char *buf) {
#if __BYTE_ORDER__ == __ORDER_LITTLE_ENDIAN__
    packed = __builtin_bswap64(packed);
#endif
    size_t len = ((unsigned char *)&packed)[0];
    memcpy(buf, ((unsigned char *)&packed) + 1, len);
    buf[len] = '\0';
    return len;
}
```

#### Boundary Handling (Long Keys)

When a key exceeds 7 bytes, it cannot be SSO-packed and must go into the `JudyHS` hash table. This creates a **dual-storage** situation:

- **SSO keys (≤ 7 bytes)**: Stored in a `JudyL` array via `judy_sso_pack()`.
- **Long keys (> 7 bytes)**: Stored in `JudyHS` with a parallel `JudySL` key index (as the existing `STRING_TO_INT_HASH` type already does).
- **Iteration**: Must merge both sources. Iterate `JudyL` (SSO keys) first (they sort lexicographically due to big-endian packing), then iterate `JudySL` key index for long keys. Since short strings sort before long strings with the same prefix (due to the length byte in MSB), the merge produces correct lexicographic order only within each group — a full sorted merge would require interleaving, which adds complexity. **Simpler alternative**: iterate all keys via the `JudySL` key index (store SSO keys there too for iteration, but use `JudyL` for fast *lookup* only). This trades some insert overhead for simple iteration.

#### Interaction with Existing Types

SSO would be applied **transparently inside** the `STRING_TO_INT_HASH` and `STRING_TO_MIXED_HASH` types, not as a separate type. On insert:

1. If `key_len <= 7`: pack into `Word_t`, use `JLI` on the SSO `JudyL`.
2. If `key_len > 7`: use `JHSI` on the `JudyHS` hash table.
3. In both cases, insert into the `JudySL` key index for iteration.

On lookup, check `key_len` to decide which backing store to query. This is a single branch — no performance regression for long keys, significant speedup for short keys.

### 6C. Vectorized (SIMD) Bitset Operations

Leverage AVX2/AVX-512 for `union`, `intersect`, and `diff` on `Bitset` types. While Judy manages sparsity, the dense blocks within Judy can be processed using SIMD instructions for 4-8x speedup on set logic.

> **⚠️ Architectural challenge**: Judy's internal bitmap leaves are **opaque** — the `Judy1` API does not expose direct access to its internal 256-bit bitmap nodes. Two approaches:
>
> 1. **External dense-range approach** (recommended): Use `J1F`/`J1N` to identify dense ranges, export them into a temporary aligned buffer, apply SIMD operations on the buffers, then re-insert results. This works with the existing Judy library but adds copy overhead.
> 2. **Patched Judy library**: Fork/patch libJudy to expose internal bitmap pointers via a new API (e.g., `Judy1BitmapGet()`). This gives zero-copy SIMD access but creates a maintenance burden on a custom Judy fork.
>
> Approach 1 is recommended for initial implementation. The copy overhead is amortized over the SIMD speedup for sufficiently dense regions (>64 bits per 256-bit block).

### 6D. "Zero-Copy" Serialization

The current `__serialize()` implementation builds a full PHP array (via `judy_build_data_array`), which means every key-value pair becomes a `zval` — expensive for large arrays. A binary format can bypass this overhead.

#### PHP API Constraint

PHP's `__serialize()` magic method **must return a PHP array** and `__unserialize()` **receives a PHP array**. There is no way to return raw binary from these methods. Two approaches:

1. **Binary blob inside `__serialize` array** (recommended): Return `['type' => int, 'format' => 'binary_v1', 'data' => <PHP string containing binary payload>]`. The `data` value is a single `zend_string` built in C without creating per-element zvals. On `__unserialize`, detect `format == 'binary_v1'` and parse the binary blob directly.
2. **Custom `serialize`/`unserialize` handlers**: Register `zend_class_entry->serialize` and `->unserialize` function pointers for full control over the byte stream. This bypasses `__serialize` entirely but is the older PHP serialization interface and is less portable across PHP versions.

Approach 1 is recommended — it stays within the modern `__serialize`/`__unserialize` API while still achieving the binary fast path.

#### Binary Wire Format (v1)

```
Header (fixed 16 bytes):
  bytes 0-3:   Magic "JDY1" (0x4A445931)
  byte  4:     Format version (1)
  byte  5:     Judy type (TYPE_INT_TO_INT, TYPE_STRING_TO_INT, etc.)
  bytes 6-7:   Flags (reserved, set to 0)
  bytes 8-15:  Element count (uint64_t, little-endian)

Body (variable, depends on type):

  For INT_TO_INT / INT_TO_MIXED / INT_TO_PACKED:
    Repeated N times:
      bytes 0-7:  Key   (uint64_t LE)
      bytes 8-15: Value (uint64_t LE for INT types)
    For MIXED/PACKED values, the value field is replaced by:
      byte  0:    Tag (packed_tag enum: 0=long, 1=double, 2=true, 3=false, 4=null, 5=string, 255=serialized)
      bytes 1-8:  Inline value (long/double) OR length (uint32_t LE) followed by that many raw bytes

  For STRING_TO_INT / STRING_TO_INT_HASH:
    Repeated N times:
      bytes 0-3:  Key length (uint32_t LE)
      bytes 4-N:  Key bytes (NOT NUL-terminated)
      next 8:     Value (int64_t LE)

  For STRING_TO_MIXED / STRING_TO_MIXED_HASH:
    Repeated N times:
      bytes 0-3:  Key length (uint32_t LE)
      bytes 4-N:  Key bytes
      next 1:     Value tag
      next varies: Value payload (same as MIXED/PACKED encoding above)

  For BITSET:
    Sorted list of set bit indices:
      Repeated N times:
        bytes 0-7: Index (uint64_t LE)
```

#### C Implementation Sketch

```c
/* In __serialize: build binary blob as a single zend_string */
static zend_string *judy_serialize_binary(judy_object *intern) {
    size_t est_size = 16 + intern->counter * 16; /* header + worst-case per-element */
    smart_str buf = {0};
    smart_str_alloc(&buf, est_size, 0);

    /* Write header */
    smart_str_appendl(&buf, "JDY1", 4);
    uint8_t meta[4] = {1, (uint8_t)intern->type, 0, 0};
    smart_str_appendl(&buf, (char *)meta, 4);
    uint64_t count = (uint64_t)intern->counter;
    smart_str_appendl(&buf, (char *)&count, 8);  /* LE on LE platforms */

    /* Write body — iterate Judy and append raw key/value bytes */
    /* ... (type-specific iteration, writing raw bytes) ... */

    return smart_str_extract(&buf);
}
```

#### Backward Compatibility and Versioning

- **Detection**: `__unserialize` checks for the `format` key. If absent (old-format data with `type` + `data` array), fall back to the current array-based restore path. This ensures **full backward compatibility** — arrays serialized with older versions of the extension can still be unserialized.
- **Version byte**: The format version byte (currently `1`) allows future format changes without breaking existing serialized data. Bump to `2` if the body layout changes.
- **Endianness**: The format uses **little-endian** throughout (matching x86/ARM64). If big-endian support is ever needed, add a flag bit in the header and byte-swap on read.

---

## 7. Benchmark Strategy for SOTA Approach

To validate these aggressive optimizations, we will use a multi-dimensional benchmark:

| Metric                | Scenario                          | Goal vs. PHP Array |
| --------------------- | --------------------------------- | ------------------ |
| **Latency (P99)**     | Single random lookup (N=10 to 1M) | < 0.8x (Faster)    |
| **Write Throughput**  | Bulk insert 1M elements           | < 1.0x (On-par)    |
| **Memory Efficiency** | Sparse array (keys 1, 1M, 2M)     | < 0.1x (10x Save)  |
| **Iteration Speed**   | `forEach()` over 100k elements    | < 0.5x (2x Faster) |
| **Small-Set Speed**   | Operations on N=5 elements        | < 0.5x (2x Faster) |
| **Short-String Opt**  | 4-char string keys lookup         | < 0.7x (Faster)    |

**Proposed Benchmark Suite**: `examples/benchmark_sota_vs_php.php`

---

## 8. Optimization Priority Matrix

| #      | Optimization                             | Impact                               | Effort | Risk   |
| ------ | ---------------------------------------- | ------------------------------------ | ------ | ------ |
| **6A** | **Adaptive Multi-Tier Storage**          | 🔴 Critical (SOTA Performance)        | High   | High   |
| **2A** | Eliminate JLG+JLI double traversal       | 🔴 High (30-40% on writes)            | Low    | Low    |
| **4B** | C-level forEach/filter/map               | 🔴 High (bypass iterator)             | Medium | Low    |
| **6B** | **Short-String Optimization (SSO)**      | 🟡 High (String lookup speed)         | Medium | Medium |
| **2C** | Type vtable/function pointers            | 🟡 Medium (5-10% on dispatch)         | Medium | Medium |
| **2D** | Iterator string key reuse for hash types | 🟡 Medium (per-iter alloc saved)      | Low    | Low    |
| **2F** | Zval slab allocator                      | 🟡 Medium (reduces malloc pressure)   | Medium | Medium |
| **6C** | **SIMD Bitset Ops**                      | 🟡 Medium (Scientific/Data use cases) | High   | Medium |
| **2B** | `__builtin_expect` hints                 | 🟡 Medium (2-5% overall)              | Low    | None   |
| **4A** | `mergeWith()`                            | 🟡 Medium (API value)                 | Low    | Low    |
| **4D** | `keys()` / `values()`                    | 🟡 Medium (API value)                 | Low    | Low    |
| **4E** | `deleteRange()`                          | 🟡 Medium (API value)                 | Low    | Low    |
| **4G** | `sumValues()`                            | 🟡 Medium (API value)                 | Low    | Low    |
| **4C** | `populationCount(start, end)`            | 🟡 Medium (API value)                 | Low    | Low    |
| **4F** | Set ops for STRING_TO_INT                | 🟡 Medium (API value)                 | Medium | Low    |
| **4H** | `equals()` / `isSubsetOf()`              | 🟡 Medium (API value)                 | Low    | Low    |
| **2G** | `-flto -funroll-loops`                   | 🟢 Low (1-3%)                         | Low    | Low    |
| **3A** | Struct packing                           | 🟢 Low (cache line)                   | Low    | Low    |
| **2E** | Hash iterator cached pointer             | 🟢 Low-Med                            | Medium | Medium |
| **5B** | Refactor type init                       | 🟢 Low (code quality)                 | Low    | None   |
| **5C** | Add parameter guards                     | 🟢 Low (correctness)                  | Low    | None   |

---

## 9. Summary

The extension is already well-optimized. The highest-ROI improvements focus on moving from a "Pure Judy" model to an **Adaptive Hybrid Storage** model while optimizing the underlying Judy traversals.

### Recommended Implementation Roadmap

1.  **Phase 1 — Quick Wins & Correctness**:
    *   Implement **2A** (JLG+JLI elimination) for `MIXED`/`PACKED` types.
    *   Apply **2B** (branch hints), **2G** (compiler flags), and **3A** (struct packing).
    *   Refactor **5B/5C** (code quality) and fix **5A** (64KB buffer risk).
2.  **Phase 2 — High-Value API Expansion**:
    *   Implement **4D** (`keys`/`values`), **4G** (`sumValues`), and **4C** (`populationCount`).
    *   Add **4E** (`deleteRange`) and **4H** (`equals`).
3.  **Phase 3 — Advanced Performance**:
    *   Implement **4B** (`forEach`/`filter`/`map`) in C to bypass the PHP iterator protocol.
    *   Add **4A** (`mergeWith`) and **4F** (string set ops).
    *   Implement **6B** (Short-String Optimization).
4.  **Phase 4 — SOTA Transformation**:
    *   Architect **6A** (Adaptive Multi-Tier Storage).
    *   Implement **2C** (vtable), **2D/2E** (iterator optimizations), and **2F** (zval freelist).
    *   Develop **6C** (SIMD Bitset Ops).

By combining low-level Judy optimizations with higher-level adaptive storage patterns, the PHP Judy extension can transcend its role as a "niche sparse array library" and become a viable, high-performance alternative to standard PHP arrays for almost all use cases.
