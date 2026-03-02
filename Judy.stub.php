<?php

/** @generate-function-entries */

function judy_version(): string {}

/** @param mixed $array */
function judy_type($array): int {}

/**
 * @generate-class-entries
 * @strict-properties
 */
class Judy implements ArrayAccess, Countable, Iterator, JsonSerializable
{
    /* ── Constructor / Destructor ─────────────────────────────── */

    public function __construct(int $type) {}

    public function __destruct() {}

    /* ── Core methods ────────────────────────────────────────── */

    public function getType(): int {}

    public function free(): int {}

    public function memoryUsage(): ?int {}

    public function size(mixed $index_start = 0, mixed $index_end = -1): int {}

    public function count(): int {}

    public function byCount(mixed $nth_index): mixed {}

    /* ── Navigation ──────────────────────────────────────────── */

    public function first(mixed $index = null): mixed {}

    public function searchNext(mixed $index): mixed {}

    public function last(mixed $index = null): mixed {}

    public function prev(mixed $index): mixed {}

    public function firstEmpty(mixed $index = null): mixed {}

    public function nextEmpty(mixed $index): mixed {}

    public function lastEmpty(mixed $index = null): mixed {}

    public function prevEmpty(mixed $index): mixed {}

    /* ── Set operations (BITSET) ─────────────────────────────── */

    public function union(Judy $other): Judy {}

    public function intersect(Judy $other): Judy {}

    public function diff(Judy $other): Judy {}

    public function xor(Judy $other): Judy {}

    /* ── Slice ───────────────────────────────────────────────── */

    public function slice(mixed $start, mixed $end): Judy {}

    /* ── ArrayAccess interface ───────────────────────────────── */

    public function offsetExists(mixed $offset): bool {}

    public function offsetGet(mixed $offset): mixed {}

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    /* ── Serialization ───────────────────────────────────────── */

    public function jsonSerialize(): mixed {}

    public function __serialize(): array {}

    public function __unserialize(array $data): void {}

    /* ── Batch operations ────────────────────────────────────── */

    public function toArray(): array {}

    public static function fromArray(int $type, array $data): Judy {}

    public function putAll(array $data): void {}

    public function getAll(array $keys): array {}

    public function increment(mixed $key, int $amount = 1): int {}

    /* ── Iterator interface ──────────────────────────────────── */

    public function rewind(): void {}

    public function valid(): bool {}

    public function current(): mixed {}

    public function key(): mixed {}

    public function next(): void {}

    /* ── Phase 2: Expanded API ───────────────────────────────── */

    public function keys(): array {}

    public function values(): array {}

    public function sumValues(): int|float {}

    public function populationCount(mixed $start = 0, mixed $end = -1): int {}

    public function deleteRange(mixed $start, mixed $end): int {}

    public function equals(Judy $other): bool {}
}
