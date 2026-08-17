# Migrating to php-judy 2.5.0

2.5.0 changes what a **negative integer offset** means on the write path, and
makes an append that has run out of key space fail loudly. Both changes are
silent in the sense that matters for upgrades: code that hits them does not
warn today and does not warn after, it just puts your data somewhere else. Read
the detection recipe below even if you are sure you never use negative keys —
the affected range is much wider than `-1`.

There are **no renamed methods and no new required arguments** — every new
parameter is optional and every existing call keeps working positionally, which
is why this ships in a minor release. But five signatures did gain or change
optional parameters (§4), one of them renaming its parameters in a way that
matters if you call it with named arguments, and `size()` changed what it
*answers* on string-keyed arrays (§3). A behaviour change with no runtime signal
deserves more attention than a minor version number usually implies, so treat
the detection recipes below as the actual upgrade step rather than optional
reading.

## 1. A negative integer offset now stores that key

### Before (2.4.x)

```php
$j = new Judy(Judy::INT_TO_INT);
$j[1]  = 10;
$j[-1] = 20;

$j->keys();      // [1, 2]   <-- key 2 was created; key -1 was not
isset($j[-1]);   // false    <-- immediately after writing it
```

Any offset in `[PHP_INT_MIN, -1]` was discarded and the value appended at the
next free index instead. That is 2^63 keys — half of the addressable key space
— not just `-1`.

### After (2.5.0)

```php
$j = new Judy(Judy::INT_TO_INT);
$j[1]  = 10;
$j[-1] = 20;

$j->keys();      // [1, -1]
isset($j[-1]);   // true
$j[-1];          // 20
```

### Why

Integer keys in Judy are **unsigned machine words**. A PHP integer key is
reinterpreted as its unsigned bit pattern, so `-1` addresses the maximum index
and reads back as `-1`. That is lossless in both directions.

Every other part of the extension already worked this way: `offsetGet`,
`isset`, `unset`, `increment()`, `fromArray()`, `putAll()`, `__unserialize()`,
`getAll()`, `keys()`, `values()`, `toArray()`, and every range bound
(`size()`, `populationCount()`, `slice()`, `deleteRange()`). `API.md` already
documented `size(0, -1)` as meaning "everything" because `-1` is the maximum
bound. `offsetSet` was the only operation that disagreed, which meant:

```php
// 2.x — these built different arrays from the same data
$a = Judy::fromArray(Judy::INT_TO_INT, [-1 => 5]);   // key -1
$b = new Judy(Judy::INT_TO_INT); $b[-1] = 5;         // key 0

// 2.x — the ordinary copy loop was not idempotent
$copy = new Judy(Judy::INT_TO_INT);
foreach ($src as $k => $v) { $copy[$k] = $v; }
$src->equals($copy);   // false when $src held any negative key
```

2.5.0 makes `offsetSet` agree with the rest of the API.

### Ordering

This is the one user-visible consequence worth internalising. Keys sort in
**unsigned** order, so negative keys come *after* every non-negative key:

```php
$j = new Judy(Judy::INT_TO_INT);
$j[0] = 'a'; $j[5] = 'b'; $j[-1] = 'c'; $j[PHP_INT_MIN] = 'd';

$j->keys();   // [0, 5, PHP_INT_MIN, -1]
```

`foreach`, `keys()`, `first()`/`last()`, `slice()` and every range bound follow
that order. This is also why `size(0, -1)` means "everything": `-1` is the
largest key, not the smallest.

## 2. Append throws when the key space is exhausted

`$j[] = $v` means "one past the highest key". When the maximum index is already
occupied there is no key above it.

### Before (2.4.x)

The next index was computed as `(zend_long)last_idx + 1`, which wraps to `0` at
the maximum index. The append silently overwrote whatever key `0` held and
stored nothing new:

```php
$j = Judy::fromArray(Judy::INT_TO_INT, [0 => 456, -1 => 123]);
$j[] = 999;
$j[0];         // 999   <-- key 0 destroyed
$j->count();   // 2     <-- nothing was appended
```

### After (2.5.0)

```php
$j[] = 999;    // Exception: Judy: cannot append, the integer key space is
               // exhausted (the highest index is already occupied)
$j[0];         // 456   <-- intact
```

PHP's own arrays behave the same way — `$a[PHP_INT_MAX] = 1; $a[] = 2;` is an
`Error`, not a wrap.

Note that `PHP_INT_MAX` is **not** the ceiling. In unsigned order the key after
`PHP_INT_MAX` is `PHP_INT_MIN`, which is a free key, so that append still
succeeds:

```php
$j = new Judy(Judy::INT_TO_INT);
$j[PHP_INT_MAX] = 1;
$j[] = 2;
$j->keys();   // [PHP_INT_MAX, PHP_INT_MIN]
```

**Consequence to plan for:** once an array holds a key at the maximum index
(that is, `-1`), every subsequent `$j[] =` on that array throws. If you mix
`$j[-1] =` with append on the same array, write explicit keys instead of
appending.

## 3. `size($start, $end)` now counts a string key range

This is the second change with no runtime signal, and it turns a wrong answer
into a right one rather than the reverse.

### Before (2.4.x)

On a string-keyed array, `size()` accepted string bounds, **ignored them**, and
returned the whole-array count:

```php
$j = new Judy(Judy::STRING_TO_MIXED);
foreach (["App\\Domain\\A", "App\\Domain\\B", "App\\Http\\C", "Zzz\\D"] as $k) {
    $j[$k] = 1;
}

$j->size("App\\Domain\\", "App\\Domain]");   // 4  <-- bounds ignored
count($j->keys("App\\Domain\\", "App\\Domain]"));  // 2  <-- the correct count
```

### After (2.5.0)

```php
$j->size("App\\Domain\\", "App\\Domain]");   // 2
```

`size($lo, $hi)` now counts the same inclusive range `keys()`/`values()`/
`toArray()` read, sharing their bound parsing and traversal — so counting and
reading agree by construction. It costs the range, not the array, and unlike
`count($j->keys($lo, $hi))` it materialises nothing.

**What to check:** any call that passed string bounds to `size()` was getting
the whole-array count. If you compensated for that — by not passing bounds, or
by using `count(keys(...))` instead — the compensation is now unnecessary, and
if you *relied* on the whole-array count coming back you must now call `size()`
with no arguments to get it.

`populationCount()` is deliberately unchanged: it answers from libJudy's O(1)
population cache, which the JudySL/JudyHS string stores do not have, so it stays
integer-keyed-only and still throws on string-keyed arrays. Ranged counting on
string keys is `size()`; `populationCount()` is the integer-only cache read.

Integer-keyed behaviour is unchanged. `size(0, -1)` still means "everything",
because bounds are unsigned machine words and `-1` is the maximum key — the same
ordering rule as §1.

## 4. New and changed signatures

All additive: no new required arguments, and every existing positional call
still compiles and means what it did.

| Method | 2.4.x | 2.5.0 |
| --- | --- | --- |
| `__construct` | `(int $type)` | `(int $type, bool $optimizeIteration = false)` |
| `keys` | `()` | `(mixed $start = null, mixed $end = null)` |
| `values` | `()` | `(mixed $start = null, mixed $end = null)` |
| `toArray` | `()` | `(mixed $start = null, mixed $end = null)` |
| `size` | `(mixed $index_start = 0, mixed $index_end = -1)` | `(mixed $start = null, mixed $end = null)` |

**The one real break is `size()` with named arguments.** Its parameters were
renamed from `$index_start`/`$index_end` to `$start`/`$end` so that all seven
range methods spell their bounds the same way. Positional calls are unaffected;
named ones are not:

```php
$j->size(index_start: 0, index_end: 10);   // 2.4.x — Error in 2.5.0
$j->size(start: 0, end: 10);               // 2.5.0
```

Grep for it directly — this is the only mechanical break in the release:

```
grep -rn 'index_start:\|index_end:' --include='*.php' .
```

`size()`'s defaults also moved from `(0, -1)` to `(null, null)`, so that
"unbounded" is expressible on string-keyed types, which have no sentinel key
meaning "the maximum". For integer-keyed arrays the two spellings are
equivalent — `null` and `-1` both mean "to the end of the key space".

`optimizeIteration` is opt-in, off by default, and honoured only by
`STRING_TO_INT_HASH` and `STRING_TO_INT_ADAPTIVE`; every other type accepts it
and ignores it. Call `isIterationOptimized()` to see what actually took effect.

## 5. Related fixes

- `$j[] =` no longer loses a value when a negative-offset write left the append
  watermark stale. In 2.x, `$j[] = 10; $j[-1] = 20; $j[] = 30;` produced
  `[10, 30]` — the `20` was gone, on every integer-keyed type.
- `map()` and `filter()` now preserve negative keys. In 2.x they relocated
  them, so a key-preserving transform silently moved entries.

## Migration steps

1. **Find writes whose index can be negative.** Grep for array-subscript writes
   on a `Judy` where the index is computed rather than a literal:

   ```
   grep -rn '\$[A-Za-z_][A-Za-z0-9_]*\[[^]]*\][[:space:]]*=' --include='*.php' .
   ```

   Look for indices from subtraction (`$i - $n`), `strpos()` results, delta
   encodings, IDs that use negatives as sentinels, and anything derived from a
   hash. **Hash-derived keys are the big one**: roughly half of all 64-bit hash
   values have the high bit set, so on 2.x about half of them were silently
   stored under the wrong key.

2. **Decide what those writes meant.** In almost every case the 2.x behaviour
   was already wrong for you — the value went to a key you could not read it
   back from. In that case 2.5.0 simply fixes it and no code change is needed.

3. **If you deliberately used `$j[-1] = $v` to append, change it to `$j[] = $v`.**
   That spelling is shorter, has always been the documented way to append, and
   is unaffected by this release.

4. **If you mix negative keys and append on the same array**, replace the
   appends with explicit keys (see the consequence noted in §2).

5. **Check any persisted data.** Arrays written by 2.x through `offsetSet` with
   negative offsets have their values under the appended keys, not the intended
   ones. `serialize()`/`fromArray()` payloads are unaffected — those paths
   always stored the intended key.

6. **Grep for `size()` called with named arguments** (§4). This is the only
   mechanical break in the release:

   ```
   grep -rn 'index_start:\|index_end:' --include='*.php' .
   ```

7. **Find `size()` calls that pass string bounds** (§3). They were silently
   returning the whole-array count and now return the range count:

   ```
   grep -rn '->size(' --include='*.php' .
   ```

   Any that compensated with `count($j->keys($lo, $hi))` can now call
   `$j->size($lo, $hi)` directly, which counts the same range without building
   the array.

## Verifying the upgrade

```php
$j = new Judy(Judy::INT_TO_INT);
$j[-1] = 20;
var_dump(isset($j[-1]), $j[-1], $j->keys());
// 2.5.0: bool(true) int(20) [-1]
// 2.x:   bool(false) null   [0]

$s = new Judy(Judy::STRING_TO_INT);
$s['a'] = 1; $s['b'] = 2; $s['c'] = 3;
var_dump($s->size('a', 'b'));
// 2.5.0: int(2)   <-- counts the range
// 2.x:   int(3)   <-- bounds ignored, whole-array count
```
