# PHP Judy API Reference

Complete API reference for the PHP Judy extension. For installation instructions and an introduction, see [README.md](README.md). For performance benchmarks, see [BENCHMARK.md](BENCHMARK.md).

## Table of Contents

1. [Type Constants](#type-constants)
2. [Constructor](#constructor)
3. [Core Methods](#core-methods)
4. [Array Access](#array-access)
5. [Navigation](#navigation)
6. [Set Operations](#set-operations)
7. [Range Operations](#range-operations)
8. [Batch Operations](#batch-operations)
9. [Functional Methods](#functional-methods)
10. [Aggregation](#aggregation)
11. [Comparison](#comparison)
12. [Serialization](#serialization)
13. [Iterator Interface](#iterator-interface)
14. [Global Functions](#global-functions)
15. [Type Compatibility Matrix](#type-compatibility-matrix)

---

## Type Constants

PHP Judy provides 10 array types, each optimized for different key/value combinations and access patterns.

### Integer-Keyed Types

| Constant              | Value | Description                                       | Backing Structure      |
| --------------------- | ----- | ------------------------------------------------- | ---------------------- |
| `Judy::BITSET`        | 1     | 1-bit per index, stores boolean presence          | Judy1                  |
| `Judy::INT_TO_INT`    | 2     | Integer keys, integer values                      | JudyL                  |
| `Judy::INT_TO_MIXED`  | 3     | Integer keys, mixed PHP values                    | JudyL + heap zvals     |
| `Judy::INT_TO_PACKED` | 6     | Integer keys, serialized values stored outside GC | JudyL + packed buffers |

### String-Keyed Types (Trie-Based)

| Constant                | Value | Description                            | Backing Structure   |
| ----------------------- | ----- | -------------------------------------- | ------------------- |
| `Judy::STRING_TO_INT`   | 4     | String keys (sorted), integer values   | JudySL              |
| `Judy::STRING_TO_MIXED` | 5     | String keys (sorted), mixed PHP values | JudySL + heap zvals |

### String-Keyed Types (Hash-Based)

| Constant                     | Value | Description                                 | Backing Structure         |
| ---------------------------- | ----- | ------------------------------------------- | ------------------------- |
| `Judy::STRING_TO_INT_HASH`   | 8     | String keys (hash lookup), integer values   | JudyHS + JudySL key index |
| `Judy::STRING_TO_MIXED_HASH` | 7     | String keys (hash lookup), mixed PHP values | JudyHS + JudySL key index |

Hash types use JudyHS for O(1) average-case lookups with a parallel JudySL index for sorted iteration.

### String-Keyed Types (Adaptive / SSO)

| Constant                         | Value | Description                            | Backing Structure                       |
| -------------------------------- | ----- | -------------------------------------- | --------------------------------------- |
| `Judy::STRING_TO_INT_ADAPTIVE`   | 10    | Adaptive string keys, integer values   | JudyL (SSO) + JudyHS + JudySL key index |
| `Judy::STRING_TO_MIXED_ADAPTIVE` | 9     | Adaptive string keys, mixed PHP values | JudyL (SSO) + JudyHS + JudySL key index |

Adaptive types use Short-String Optimization (SSO): keys of 7 bytes or fewer are packed into a 64-bit integer and stored in a JudyL array, avoiding hashing overhead. Longer keys fall back to JudyHS. Both maintain a JudySL key index for sorted iteration.

---

## Constructor

### __construct()

```php
public function __construct(int $type)
```

Creates a new Judy array of the specified type.

```php
$judy = new Judy(Judy::INT_TO_INT);
```

---

## Core Methods

### getType()

```php
public function getType(): int
```

Returns the type constant of the Judy array.

### free()

```php
public function free(): int
```

Frees all memory used by the Judy array and resets the element count. Returns the number of bytes freed (or 0 for string-keyed types).

### memoryUsage()

```php
public function memoryUsage(): ?int
```

Returns the number of bytes used by the internal Judy structure. Returns `null` for string-keyed types (JudySL and JudyHS do not provide memory accounting).

| Supported Types                                 | Returns       |
| ----------------------------------------------- | ------------- |
| BITSET, INT_TO_INT, INT_TO_MIXED, INT_TO_PACKED | `int` (bytes) |
| All string-keyed types                          | `null`        |

### size()

```php
public function size(mixed $index_start = 0, mixed $index_end = -1): int
```

Returns the number of elements. When called with arguments, returns the population count within the given range (integer-keyed types only).

### count()

```php
public function count(): int
```

Returns the number of elements. Implements PHP's `Countable` interface.

```php
$judy = new Judy(Judy::INT_TO_INT);
$judy[0] = 100;
$judy[1] = 200;
echo count($judy); // 2
```

---

## Array Access

Judy arrays implement PHP's `ArrayAccess` interface, so they can be used with standard array syntax.

```php
$judy = new Judy(Judy::INT_TO_INT);

// Write
$judy[0] = 42;

// Read
echo $judy[0]; // 42

// Check existence
if (isset($judy[0])) { /* ... */ }

// Delete
unset($judy[0]);
```

### Key/Value Types per Array Type

| Type                     | Key      | Value   | Notes                                     |
| ------------------------ | -------- | ------- | ----------------------------------------- |
| BITSET                   | `int`    | `bool`  | `true` sets the bit, `false` clears it    |
| INT_TO_INT               | `int`    | `int`   | Values coerced to integer                 |
| INT_TO_MIXED             | `int`    | `mixed` | Any PHP value                             |
| INT_TO_PACKED            | `int`    | `mixed` | Serialized on write, deserialized on read |
| STRING_TO_INT            | `string` | `int`   | Values coerced to integer                 |
| STRING_TO_MIXED          | `string` | `mixed` | Any PHP value                             |
| STRING_TO_INT_HASH       | `string` | `int`   | O(1) avg lookup                           |
| STRING_TO_MIXED_HASH     | `string` | `mixed` | O(1) avg lookup                           |
| STRING_TO_INT_ADAPTIVE   | `string` | `int`   | SSO for short keys                        |
| STRING_TO_MIXED_ADAPTIVE | `string` | `mixed` | SSO for short keys                        |

---

## Navigation

Methods for traversing the sorted key space. For string-keyed types, keys are sorted lexicographically.

### first()

```php
public function first(mixed $index = null): mixed
```

Returns the first index in the array. If `$index` is provided, returns the first index equal to or greater than `$index`.

### searchNext()

```php
public function searchNext(mixed $index): mixed
```

Returns the next index strictly greater than `$index`.

### last()

```php
public function last(mixed $index = null): mixed
```

Returns the last index in the array. If `$index` is provided, returns the last index equal to or less than `$index`.

### prev()

```php
public function prev(mixed $index): mixed
```

Returns the previous index strictly less than `$index`.

### firstEmpty() / nextEmpty() / lastEmpty() / prevEmpty()

```php
public function firstEmpty(mixed $index = null): mixed
public function nextEmpty(mixed $index): mixed
public function lastEmpty(mixed $index = null): mixed
public function prevEmpty(mixed $index): mixed
```

Find empty (unset) indices. Only supported for integer-keyed types (BITSET, INT_TO_INT, INT_TO_MIXED, INT_TO_PACKED). Returns `null` for string-keyed types.

### byCount()

```php
public function byCount(mixed $nth_index): mixed
```

Returns the index of the Nth element (1-based). Only supported for integer-keyed types. Returns `null` for string-keyed types.

```php
$judy = new Judy(Judy::INT_TO_INT);
$judy[10] = 100;
$judy[20] = 200;
$judy[30] = 300;
echo $judy->byCount(2); // 20 (second element)
```

---

## Set Operations

Set operations create a new Judy array from two arrays of the same type.

**Supported types**: `BITSET`, `INT_TO_INT`, `STRING_TO_INT`, `STRING_TO_INT_HASH`

Both operands must be the same type. Throws an exception for unsupported or mismatched types.

### union()

```php
public function union(Judy $other): Judy
```

Returns a new Judy array containing all elements from both arrays. For integer-valued types, values from `$other` overwrite values in `$this` for duplicate keys.

### intersect()

```php
public function intersect(Judy $other): Judy
```

Returns a new Judy array containing only elements present in both arrays.

### diff()

```php
public function diff(Judy $other): Judy
```

Returns a new Judy array containing elements in `$this` that are not in `$other`.

### xor()

```php
public function xor(Judy $other): Judy
```

Returns a new Judy array containing elements in either array but not both (symmetric difference).

### mergeWith()

```php
public function mergeWith(Judy $other): void
```

Merges elements from `$other` into `$this` in-place. Both arrays must use the same key category (both integer-keyed or both string-keyed). Does not require the same type.

**Supported types**: All types. Throws exception only if mixing integer-keyed with string-keyed arrays.

```php
$a = new Judy(Judy::INT_TO_INT);
$a[0] = 1; $a[1] = 2;

$b = new Judy(Judy::INT_TO_INT);
$b[1] = 20; $b[2] = 30;

$a->mergeWith($b);
// $a now contains: [0 => 1, 1 => 20, 2 => 30]
```

---

## Range Operations

### slice()

```php
public function slice(mixed $start, mixed $end): Judy
```

Returns a new Judy array containing elements with keys in the range `[$start, $end]` (inclusive). For string-keyed types, `$start` and `$end` must be strings and comparison is lexicographic.

**Supported types**: All types.

```php
$judy = new Judy(Judy::INT_TO_INT);
for ($i = 0; $i < 100; $i++) $judy[$i] = $i * 10;

$slice = $judy->slice(10, 20);
// $slice contains keys 10..20
```

### deleteRange()

```php
public function deleteRange(mixed $start, mixed $end): int
```

Deletes all elements with keys in the range `[$start, $end]` (inclusive). Returns the number of elements deleted.

**Supported types**: All types.

### populationCount()

```php
public function populationCount(mixed $start = 0, mixed $end = -1): int
```

Returns the number of elements with keys in the range `[$start, $end]`. Uses Judy's internal population cache for O(1) counting.

**Supported types**: Integer-keyed types only (BITSET, INT_TO_INT, INT_TO_MIXED, INT_TO_PACKED). Throws exception for string-keyed types.

---

## Batch Operations

### fromArray()

```php
public static function fromArray(int $type, array $data): Judy
```

Creates a new Judy array from a PHP array.

```php
$judy = Judy::fromArray(Judy::INT_TO_INT, [0 => 100, 5 => 200, 10 => 300]);
```

### toArray()

```php
public function toArray(): array
```

Converts the Judy array to a PHP array. Uses native C iteration internally, 2-3x faster than manual `foreach`.

### putAll()

```php
public function putAll(array $data): void
```

Bulk-inserts all key-value pairs from the given PHP array.

### getAll()

```php
public function getAll(array $keys): array
```

Retrieves multiple values at once. Returns an associative array mapping each requested key to its value (or `null` if absent).

```php
$judy = Judy::fromArray(Judy::INT_TO_INT, [0 => 10, 1 => 20, 2 => 30]);
$values = $judy->getAll([0, 2, 99]);
// [0 => 10, 2 => 30, 99 => null]
```

### keys()

```php
public function keys(): array
```

Returns all keys as a PHP array.

### values()

```php
public function values(): array
```

Returns all values as a PHP array.

### increment()

```php
public function increment(mixed $key, int $amount = 1): int
```

Atomically increments the value at `$key` by `$amount`. If the key does not exist, it is created with the value `$amount`. Returns the new value.

**Supported types**: `INT_TO_INT`, `STRING_TO_INT`, `STRING_TO_INT_HASH`. Throws exception for other types.

```php
$counters = new Judy(Judy::STRING_TO_INT);
$counters->increment("page_views");       // 1
$counters->increment("page_views");       // 2
$counters->increment("page_views", 10);   // 12
$counters->increment("page_views", -3);   // 9
```

---

## Functional Methods

These methods iterate in C, bypassing the PHP Iterator protocol overhead.

### forEach()

```php
public function forEach(callable $callback): void
```

Calls `$callback($key, $value)` for each element. The callback receives the key as the first argument and the value as the second.

**Supported types**: All types.

```php
$judy = Judy::fromArray(Judy::INT_TO_INT, [1 => 10, 2 => 20, 3 => 30]);
$judy->forEach(function ($key, $value) {
    echo "$key => $value\n";
});
```

### filter()

```php
public function filter(callable $predicate): Judy
```

Returns a new Judy array containing only elements for which `$predicate($key, $value)` returns `true`.

**Supported types**: All types.

### map()

```php
public function map(callable $transform): Judy
```

Returns a new Judy array with the same keys, where each value is replaced by the return value of `$transform($key, $value)`.

**Supported types**: All types.

---

## Aggregation

### sumValues()

```php
public function sumValues(): int|float
```

Returns the sum of all values. For BITSET, returns the population count.

**Supported types**: `BITSET`, `INT_TO_INT`, `STRING_TO_INT`, `STRING_TO_INT_HASH`, `STRING_TO_INT_ADAPTIVE`. Throws exception for mixed/packed types.

### averageValues()

```php
public function averageValues(): ?float
```

Returns the arithmetic mean of all values. Returns `null` if the array is empty. For BITSET, always returns `1.0`.

**Supported types**: Same as `sumValues()`.

---

## Comparison

### equals()

```php
public function equals(Judy $other): bool
```

Returns `true` if both arrays have the same type, the same number of elements, and identical key-value pairs. Returns `false` for type mismatch (no exception).

**Supported types**: All types.

---

## Serialization

### __serialize() / __unserialize()

```php
public function __serialize(): array
public function __unserialize(array $data): void
```

PHP native serialization support. Judy arrays can be serialized with `serialize()` and restored with `unserialize()`.

```php
$judy = Judy::fromArray(Judy::INT_TO_INT, [1 => 100, 2 => 200]);
$serialized = serialize($judy);
$restored = unserialize($serialized);
```

### jsonSerialize()

```php
public function jsonSerialize(): mixed
```

Implements `JsonSerializable`. Judy arrays can be encoded with `json_encode()`.

```php
$judy = Judy::fromArray(Judy::STRING_TO_INT, ["a" => 1, "b" => 2]);
echo json_encode($judy); // {"a":1,"b":2}
```

---

## Iterator Interface

Judy arrays implement PHP's `Iterator` interface for use in `foreach` loops.

```php
$judy = Judy::fromArray(Judy::INT_TO_INT, [1 => 10, 5 => 50, 10 => 100]);

foreach ($judy as $key => $value) {
    echo "$key => $value\n";
}
```

Keys are iterated in sorted order (ascending integer order for integer-keyed types, lexicographic for string-keyed types).

Individual iterator methods: `rewind()`, `valid()`, `current()`, `key()`, `next()`.

---

## Global Functions

### judy_version()

```php
function judy_version(): string
```

Returns the version of the PHP Judy extension.

### judy_type()

```php
function judy_type(mixed $array): int
```

Returns the Judy type constant of the given Judy array.

---

## Type Compatibility Matrix

Summary of which methods are available for each type. Methods not listed here work with all 10 types.

| Method                     | BITSET | INT_TO_INT | INT_TO_MIXED | INT_TO_PACKED | STR_INT | STR_MIXED | STR_INT_HASH | STR_MIX_HASH | STR_INT_ADAPT | STR_MIX_ADAPT |
| -------------------------- | ------ | ---------- | ------------ | ------------- | ------- | --------- | ------------ | ------------ | ------------- | ------------- |
| `memoryUsage()`            | int    | int        | int          | int           | null    | null      | null         | null         | null          | null          |
| `union/intersect/diff/xor` | yes    | yes        | -            | -             | yes     | -         | yes          | -            | -             | -             |
| `populationCount()`        | yes    | yes        | yes          | yes           | -       | -         | -            | -            | -             | -             |
| `sumValues()`              | yes    | yes        | -            | -             | yes     | -         | yes          | -            | yes           | -             |
| `averageValues()`          | yes    | yes        | -            | -             | yes     | -         | yes          | -            | yes           | -             |
| `increment()`              | -      | yes        | -            | -             | yes     | -         | yes          | -            | -             | -             |
| `byCount()`                | yes    | yes        | yes          | yes           | null    | null      | null         | null         | null          | null          |
| `firstEmpty()` etc.        | yes    | yes        | yes          | yes           | null    | null      | null         | null         | null          | null          |

**Legend**: `yes` = supported, `-` = throws exception, `null` = silently returns null, `int` = returns integer value.

All other methods (`slice`, `deleteRange`, `forEach`, `filter`, `map`, `keys`, `values`, `equals`, `mergeWith`, `toArray`, `fromArray`, `putAll`, `getAll`) work with all 10 types.
