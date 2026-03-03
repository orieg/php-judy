<?php

/** @generate-function-entries */

/** Return the PHP Judy extension version string. */
function judy_version(): string {}

/**
 * Return the Judy type constant for the given array.
 *
 * @param mixed $array
 */
function judy_type($array): int {}

/**
 * @generate-class-entries
 * @strict-properties
 */
class Judy implements ArrayAccess, Countable, Iterator, JsonSerializable
{
    public const int BITSET = 1;
    public const int INT_TO_INT = 2;
    public const int INT_TO_MIXED = 3;
    public const int STRING_TO_INT = 4;
    public const int STRING_TO_MIXED = 5;
    public const int INT_TO_PACKED = 6;
    public const int STRING_TO_MIXED_HASH = 7;
    public const int STRING_TO_INT_HASH = 8;
    public const int STRING_TO_MIXED_ADAPTIVE = 9;
    public const int STRING_TO_INT_ADAPTIVE = 10;

    /* ── Constructor / Destructor ─────────────────────────────── */

    /**
     * Create a new Judy array of the specified type.
     *
     * Pass one of the Judy type constants (e.g. Judy::INT_TO_INT).
     */
    public function __construct(int $type) {}

    /** Free the Judy array and release all associated resources. */
    public function __destruct() {}

    /* ── Core methods ────────────────────────────────────────── */

    /** Return the type constant of this Judy array. */
    public function getType(): int {}

    /** Free the entire Judy array. Returns the number of bytes freed. */
    public function free(): int {}

    /**
     * Return the memory used by the internal Judy structure.
     *
     * Returns null for string-keyed types (JudySL/JudyHS do not provide
     * memory accounting).
     */
    public function memoryUsage(): ?int {}

    /**
     * Return the number of elements, optionally within a key range.
     *
     * When called with arguments, returns the count within
     * [$index_start, $index_end] (integer-keyed types only).
     */
    public function size(mixed $index_start = 0, mixed $index_end = -1): int {}

    /** Return the number of elements. Implements Countable. */
    public function count(): int {}

    /**
     * Locate the Nth index present in the array (1-based).
     *
     * Only supported for integer-keyed types. Returns null for string-keyed types.
     */
    public function byCount(mixed $nth_index): mixed {}

    /* ── Navigation ──────────────────────────────────────────── */

    /**
     * Search (inclusive) for the first index present that is equal to or
     * greater than the given index.
     */
    public function first(mixed $index = null): mixed {}

    /** Search (exclusive) for the next index present that is greater than the given index. */
    public function searchNext(mixed $index): mixed {}

    /**
     * Search (inclusive) for the last index present that is equal to or
     * less than the given index.
     */
    public function last(mixed $index = null): mixed {}

    /** Search (exclusive) for the previous index present that is less than the given index. */
    public function prev(mixed $index): mixed {}

    /**
     * Search (inclusive) for the first absent index that is equal to or
     * greater than the given index.
     *
     * Only supported for integer-keyed types. Returns null for string-keyed types.
     */
    public function firstEmpty(mixed $index = null): mixed {}

    /** Search (exclusive) for the next absent index greater than the given index. */
    public function nextEmpty(mixed $index): mixed {}

    /**
     * Search (inclusive) for the last absent index that is equal to or
     * less than the given index.
     *
     * Only supported for integer-keyed types. Returns null for string-keyed types.
     */
    public function lastEmpty(mixed $index = null): mixed {}

    /** Search (exclusive) for the previous absent index less than the given index. */
    public function prevEmpty(mixed $index): mixed {}

    /* ── Set operations ───────────────────────────────────────── */

    /**
     * Return a new Judy array containing all indices present in either array.
     *
     * For integer-valued types, values from the other array overwrite on duplicate keys.
     */
    public function union(Judy $other): Judy {}

    /**
     * Return a new Judy array containing only indices present in both arrays.
     *
     * For integer-valued types, values from this array are used.
     */
    public function intersect(Judy $other): Judy {}

    /** Return a new Judy array containing indices in this array but not in other. */
    public function diff(Judy $other): Judy {}

    /** Return a new Judy array containing indices in exactly one of the arrays (symmetric difference). */
    public function xor(Judy $other): Judy {}

    /**
     * Merge another Judy array into this one in-place.
     *
     * Both arrays must use the same key category (both integer-keyed or
     * both string-keyed). Existing keys are overwritten.
     */
    public function mergeWith(Judy $other): void {}

    /* ── Slice ───────────────────────────────────────────────── */

    /**
     * Return a new Judy array containing entries in the [$start, $end] range (inclusive).
     *
     * For string-keyed types, comparison is lexicographic.
     */
    public function slice(mixed $start, mixed $end): Judy {}

    /* ── ArrayAccess interface ───────────────────────────────── */

    /** Check whether the given offset exists in the array. */
    public function offsetExists(mixed $offset): bool {}

    /** Return the value at the given offset. */
    public function offsetGet(mixed $offset): mixed {}

    /** Set the value at the given offset. */
    public function offsetSet(mixed $offset, mixed $value): void {}

    /** Remove the element at the given offset. */
    public function offsetUnset(mixed $offset): void {}

    /* ── Serialization ───────────────────────────────────────── */

    /** Return data suitable for json_encode(). Implements JsonSerializable. */
    public function jsonSerialize(): mixed {}

    /** Return serialization data as ['type' => int, 'data' => array]. */
    public function __serialize(): array {}

    /** Restore a Judy array from serialized data. */
    public function __unserialize(array $data): void {}

    /* ── Batch operations ────────────────────────────────────── */

    /**
     * Convert the Judy array to a native PHP array.
     *
     * Uses native C iteration internally, faster than manual foreach.
     */
    public function toArray(): array {}

    /**
     * Create a new Judy array from a PHP array.
     *
     * $type is one of the Judy type constants. $data provides the
     * key-value pairs to populate the array with.
     */
    public static function fromArray(int $type, array $data): Judy {}

    /** Bulk-insert entries from a PHP array into this Judy array. */
    public function putAll(array $data): void {}

    /**
     * Retrieve multiple values at once.
     *
     * Returns an associative array mapping each requested key to its
     * value (or null if absent).
     */
    public function getAll(array $keys): array {}

    /**
     * Atomically increment the value at the given key.
     *
     * If the key does not exist, it is created with the given amount.
     * The $amount may be negative. Returns the new value.
     */
    public function increment(mixed $key, int $amount = 1): int {}

    /* ── Iterator interface ──────────────────────────────────── */

    /** Rewind the iterator to the first element. */
    public function rewind(): void {}

    /** Check whether the current iterator position is valid. */
    public function valid(): bool {}

    /** Return the value at the current iterator position. */
    public function current(): mixed {}

    /** Return the key at the current iterator position. */
    public function key(): mixed {}

    /** Advance the iterator to the next element. */
    public function next(): void {}

    /* ── Expanded API ─────────────────────────────────────────── */

    /** Return all keys as a PHP array. */
    public function keys(): array {}

    /** Return all values as a PHP array. */
    public function values(): array {}

    /**
     * Call a callback for each element, iterating in C.
     *
     * The callback receives ($key, $value) for each element.
     */
    public function forEach(callable $callback): void {}

    /** Return a new Judy array containing only elements matching the predicate. */
    public function filter(callable $predicate): Judy {}

    /** Return a new Judy array with values transformed by the callback. */
    public function map(callable $transform): Judy {}

    /**
     * Return the sum of all values in the array.
     *
     * For BITSET, returns the population count.
     */
    public function sumValues(): int|float {}

    /**
     * Return the average of all values, or null if the array is empty.
     *
     * For BITSET, always returns 1.0.
     */
    public function averageValues(): ?float {}

    /**
     * Return the number of keys in the [$start, $end] range (inclusive).
     *
     * Only supported for integer-keyed types.
     */
    public function populationCount(mixed $start = 0, mixed $end = -1): int {}

    /**
     * Delete all keys in the [$start, $end] range (inclusive).
     *
     * Returns the number of elements deleted.
     */
    public function deleteRange(mixed $start, mixed $end): int {}

    /** Check if two Judy arrays have identical type, size, and key-value pairs. */
    public function equals(Judy $other): bool {}
}
