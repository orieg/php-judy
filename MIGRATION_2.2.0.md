# Migration Guide: PHP Judy 2.2.0

## Breaking Change: Judy::next() Method Renamed

In PHP Judy 2.2.0, the `Judy::next()` method has been renamed to `Judy::searchNext()` to resolve naming conflicts with the new Iterator interface implementation.

### What Changed

**Before (PHP Judy 2.1.0 and earlier):**
```php
$judy = new Judy(Judy::INT_TO_INT);
$judy[100] = 1;
$judy[200] = 2;
$judy[300] = 3;

// Find next index after 150
$next_index = $judy->next(150); // Returns 200
```

**After (PHP Judy 2.2.0):**
```php
$judy = new Judy(Judy::INT_TO_INT);
$judy[100] = 1;
$judy[200] = 2;
$judy[300] = 3;

// Find next index after 150
$next_index = $judy->searchNext(150); // Returns 200
```

### Why This Change Was Necessary

The `Judy::next()` method conflicted with the `Iterator::next()` method required by PHP's Iterator interface. To properly implement the Iterator interface (fixing GitHub issue #25), we needed to resolve this naming conflict.

### Migration Steps

1. **Search your codebase** for all occurrences of `$judy->next(`
2. **Replace** `$judy->next(` with `$judy->searchNext(`
3. **Test** your application to ensure functionality remains the same

### Example Migration

```php
// Old code
$index = 100;
while ($index !== null) {
    echo "Found index: $index\n";
    $index = $judy->next($index);
}

// New code
$index = 100;
while ($index !== null) {
    echo "Found index: $index\n";
    $index = $judy->searchNext($index);
}
```

### New Iterator Interface Features

With this change, Judy objects now properly implement the Iterator interface:

```php
$judy = new Judy(Judy::INT_TO_INT);
$judy[1] = 'one';
$judy[2] = 'two';
$judy[3] = 'three';

// Now works with foreach
foreach ($judy as $key => $value) {
    echo "$key => $value\n";
}

// Works with SPL iterators
$limited = new LimitIterator($judy, 0, 2);
foreach ($limited as $key => $value) {
    echo "$key => $value\n";
}

// Works with instanceof checks
if ($judy instanceof Iterator) {
    echo "Judy implements Iterator interface!\n";
}
```

### Backward Compatibility

- **All other Judy methods remain unchanged**
- **Functionality is identical** - only the method name changed
- **Performance characteristics are the same**
- **Memory usage is unchanged**

### Testing Your Migration

After updating your code, run your test suite to ensure everything works correctly. The Judy extension includes comprehensive tests that verify the new `searchNext()` method works exactly like the old `next()` method.

### Need Help?

If you encounter any issues during migration, please:
1. Check that all `$judy->next(` calls have been updated to `$judy->searchNext(`
2. Verify that your Judy objects are being used correctly with the new Iterator interface
3. Run the test suite to ensure compatibility
4. Open an issue on GitHub if you need additional assistance
