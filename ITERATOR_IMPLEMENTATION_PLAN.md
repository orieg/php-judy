# PHP-Judy Iterator Interface Implementation Plan

## Overview

This plan addresses [GitHub issue #25](https://github.com/orieg/php-judy/issues/25) to properly implement Iterator interface support in PHP8 for the php-judy extension. The goal is to ensure that `$judy instanceof Iterator` returns `true` and that Judy objects work correctly with SPL iterators like `LimitIterator`.

## Current State Analysis

### What's Already Implemented
- Iterator interface declared in `zend_class_implements()` call
- `get_iterator` function pointer set in class entry
- Complete iterator implementation in `judy_iterator.c`
- Iterator methods (`rewind`, `valid`, `current`, `key`) implemented
- Basic test case exists in `tests/iterator_interface_001.phpt`

### The Problem
Despite having the implementation, the Iterator interface is not being properly recognized by PHP's reflection system, causing issues with type hints and SPL iterators.

## Implementation Plan

### Phase 1: Environment Setup and Current State Validation

#### Step 1.1: Verify Current Docker Environment
```bash
# Build the Docker image
docker build -t php-judy-test .

# Test basic extension loading
docker run --rm php-judy-test php -m | grep judy

# Expected output: judy
```

#### Step 1.2: Validate Current Iterator Implementation
```bash
# Run existing iterator test
docker run --rm -v $(pwd):/app php-judy-test bash -c "cd /app && php run-tests.php tests/iterator_interface_001.phpt"

# Check current interface implementation
docker run --rm php-judy-test php -r "
\$judy = new Judy(Judy::INT_TO_INT);
var_dump(\$judy instanceof Iterator);
var_dump(\$judy instanceof Traversable);
var_dump(\$judy instanceof ArrayAccess);
var_dump(\$judy instanceof Countable);
"
```

**Validation Criteria:**
- Extension loads successfully
- Iterator test passes
- `instanceof Iterator` should return `true` (currently fails)

### Phase 2: PHP8 Compatibility Audit

#### Step 2.1: Review PHP8 API Changes
**Key PHP8 Changes in `zend_object_iterator_funcs`:**

1. **Function Signature Changes:**
   - `get_iterator` now receives `zend_class_entry *ce` as first argument
   - Iterator functions must handle PHP8's stricter type system
   - Memory management patterns have evolved

2. **Structure Evolution:**
   - `zend_object_iterator_funcs` structure has been updated
   - Iterator state management requires more careful handling
   - Error handling patterns have changed

**Reference:** PHP documentation on extending PHP for version 8.0+

#### Step 2.2: Update Interface Registration
**File: `php_judy.c` (around line 525)**

Current code:
```c
zend_class_implements(judy_ce, 3, zend_ce_arrayaccess, zend_ce_countable, zend_ce_iterator);
```

**Implementation Notes:**
- This line registers the Iterator interface with the Judy class
- The order of interfaces matters for proper reflection
- Must be called before setting the `get_iterator` function pointer

**Validation Steps:**
```bash
# After each change, rebuild and test
docker run --rm -v $(pwd):/app php-judy-test bash -c "
cd /app && 
phpize && 
./configure --with-judy=/usr && 
make && 
make install &&
php -r '\$judy = new Judy(Judy::INT_TO_INT); var_dump(\$judy instanceof Iterator);'
"
```

### Phase 3: Iterator Implementation Fixes

#### Step 3.1: Review Iterator Function Implementation
**File: `judy_iterator.c`**

**Critical Implementation Details:**

1. **`judy_get_iterator()` Function:**
   - Must properly populate the `zend_object_iterator` struct
   - Set `data` field to the Judy object's `zend_object`
   - Set `funcs` pointer to static `zend_iterator_funcs` structure
   - **CRITICAL**: Ensure `move_forward` function pointer is explicitly set to `judy_iterator_move_forward()`
   - This is what foreach loops use to advance iteration
   - Ensure proper reference counting for the Judy object

2. **`zend_iterator_funcs` Structure:**
   - Must be static and properly populated with `judy_iterator_*` functions
   - **CRITICAL**: Map `judy_iterator_move_forward()` to the `move_forward` function pointer
   - This mapping is what foreach loops use internally to advance iteration
   - Ensure all function pointers are correctly assigned in `judy_get_iterator()`

3. **Key Functions to Review:**
   - `judy_get_iterator()` - Iterator creation (most critical)
   - `judy_iterator_valid()` - Iterator state validation
   - `judy_iterator_current_data()` - Current value retrieval
   - `judy_iterator_current_key()` - Current key retrieval
   - `judy_iterator_move_forward()` - Iterator advancement (maps to next())
   - `judy_iterator_rewind()` - Iterator reset

#### Step 3.3: Separate Judy's next() from Iterator's next()
**CRITICAL: Naming Conflict Resolution**

The existing `PHP_METHOD(judy, next)` is a Judy-specific search function that takes an index parameter, not the Iterator interface's zero-argument `next()` method. This creates a naming conflict that prevents proper Iterator interface implementation.

**Required Changes:**

1. **Rename Existing Judy::next() Method:**
   ```c
   /* Judy search method - renamed from next() to avoid Iterator interface conflicts
    * 
    * This method provides Judy's original search functionality to find the next
    * index greater than the given value. It was renamed to searchNext() to
    * resolve naming conflicts with the Iterator interface's next() method.
    * 
    * Original functionality: Search (exclusive) for the next index present
    * that is greater than the passed Index.
    */
   // Change from:
   PHP_METHOD(judy, next)
   
   // To:
   PHP_METHOD(judy, searchNext)
   ```

2. **Update Method Registration:**
   ```c
   // Change from:
   PHP_ME(judy, next, arginfo_judy_next, ZEND_ACC_PUBLIC)
   
   // To:
   PHP_ME(judy, searchNext, arginfo_judy_search_next, ZEND_ACC_PUBLIC)
   ```

3. **Rename Argument Info:**
   ```c
   // Change from:
   ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_next, 0, 0, 1)
       ZEND_ARG_INFO(0, index)
   ZEND_END_ARG_INFO()
   
   // To:
   ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_search_next, 0, 0, 1)
       ZEND_ARG_INFO(0, index)
   ZEND_END_ARG_INFO()
   ```

4. **Implement Iterator::next() Method:**
   ```c
   /* Iterator interface next() method - Fixes GitHub issue #25
    * 
    * This method was separated from the original Judy::next() search function
    * to resolve naming conflicts with the Iterator interface. The original
    * search functionality has been moved to searchNext() method.
    * 
    * This zero-argument method is required by the Iterator interface and
    * is called by foreach loops to advance the iterator position.
    */
   PHP_METHOD(judy, next)
   {
       JUDY_METHOD_GET_OBJECT
       
       // Call the internal iterator move_forward function
       // This is what foreach loops use internally to advance iteration
       if (intern->iterator) {
           judy_iterator_move_forward(intern->iterator);
       }
   }
   ```

5. **Add Iterator next() Argument Info:**
   ```c
   /* Iterator interface next() method signature */
   ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_next, 0, 0, IS_VOID, 0)
   ZEND_END_ARG_INFO()
   ```

6. **Register Iterator next() Method:**
   ```c
   /* Iterator interface methods - Fixes GitHub issue #25 */
   PHP_ME(judy, next, arginfo_judy_next, ZEND_ACC_PUBLIC)
   ```

**Validation Steps:**
```bash
# Test that searchNext() works (renamed method)
docker run --rm -v $(pwd):/app php-judy-test bash -c "
cd /app && 
phpize && 
./configure --with-judy=/usr && 
make && 
make install &&
php -r '
\$judy = new Judy(Judy::INT_TO_INT);
\$judy[1] = 100;
\$judy[5] = 500;
\$judy[10] = 1000;

// Test renamed search method
\$next = \$judy->searchNext(1);
var_dump(\$next); // Should return 5

// Test Iterator next() method
\$judy->rewind();
var_dump(\$judy->current()); // Should return 100
\$judy->next();
var_dump(\$judy->current()); // Should return 500
'
"
```

**Validation Steps:**
```bash
# Test iterator functionality
docker run --rm -v $(pwd):/app php-judy-test bash -c "
cd /app && 
phpize && 
./configure --with-judy=/usr && 
make && 
make install &&
php -r '
\$judy = new Judy(Judy::INT_TO_INT);
\$judy[1] = 100;
\$judy[5] = 500;
\$judy[10] = 1000;

foreach (\$judy as \$key => \$value) {
    echo \"Key: \$key, Value: \$value\n\";
}

echo \"Testing SPL Iterator...\n\";
try {
    \$it = new LimitIterator(\$judy, 0, 2);
    foreach (\$it as \$key => \$value) {
        echo \"SPL Key: \$key, Value: \$value\n\";
    }
} catch (Exception \$e) {
    echo \"SPL Error: \" . \$e->getMessage() . \"\n\";
}
'
"
```

#### Step 3.2: Fix Iterator State Management
**Issues to Address:**
- [ ] Proper iterator state initialization
- [ ] Memory leak prevention
- [ ] Error handling for edge cases
- [ ] PHP8-specific iterator requirements

**Validation Steps:**
```bash
# Test memory management
docker run --rm -v $(pwd):/app php-judy-test bash -c "
cd /app && 
phpize && 
./configure --with-judy=/usr && 
make && 
make install &&
php -r '
\$judy = new Judy(Judy::INT_TO_INT);
for (\$i = 0; \$i < 1000; \$i++) {
    \$judy[\$i] = \$i * 2;
}

// Test multiple iterations
for (\$j = 0; \$j < 10; \$j++) {
    \$count = 0;
    foreach (\$judy as \$key => \$value) {
        \$count++;
    }
    echo \"Iteration \$j: \$count items\n\";
}
'
"
```

### Phase 4: PHP8-Specific Enhancements

#### Step 4.1: Add Return Type Declarations
**File: `php_judy.c`**

Update method signatures with proper return types and add code comments referencing GitHub issue #25:

```c
/* Iterator interface methods - Fixes GitHub issue #25 */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_rewind, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_valid, 0, 0, IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_current, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_key, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

/* Iterator interface next() method signature */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_next, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()
```

**Validation Steps:**
```bash
# Test reflection
docker run --rm -v $(pwd):/app php-judy-test bash -c "
cd /app && 
phpize && 
./configure --with-judy=/usr && 
make && 
make install &&
php -r '
\$reflection = new ReflectionClass(Judy::class);
\$methods = \$reflection->getMethods(ReflectionMethod::IS_PUBLIC);
foreach (\$methods as \$method) {
    if (in_array(\$method->getName(), [\"rewind\", \"valid\", \"current\", \"key\"])) {
        echo \$method->getName() . \": \" . \$method->getReturnType() . \"\n\";
    }
}
'
"
```

#### Step 4.2: Update Method Registration
**File: `php_judy.c`**

Update method registration with proper argument info and add comments:

```c
/* Judy search methods (renamed from original next/prev methods) */
PHP_ME(judy, searchNext, arginfo_judy_search_next, ZEND_ACC_PUBLIC)

/* Iterator interface methods - Fixes GitHub issue #25 */
PHP_ME(judy, rewind, arginfo_judy_rewind, ZEND_ACC_PUBLIC)
PHP_ME(judy, valid, arginfo_judy_valid, ZEND_ACC_PUBLIC)
PHP_ME(judy, current, arginfo_judy_current, ZEND_ACC_PUBLIC)
PHP_ME(judy, key, arginfo_judy_key, ZEND_ACC_PUBLIC)
PHP_ME(judy, next, arginfo_judy_next, ZEND_ACC_PUBLIC)
```

### Phase 5: Comprehensive Testing

#### Step 5.1: Create Enhanced Test Suite
**File: `tests/iterator_interface_002.phpt`**

```php
--TEST--
Judy Iterator Interface - Comprehensive Test
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$judy = new Judy(Judy::INT_TO_INT);

// Test basic interface implementation
var_dump($judy instanceof Iterator);
var_dump($judy instanceof Traversable);
var_dump($judy instanceof ArrayAccess);
var_dump($judy instanceof Countable);

// Test with data
$judy[1] = 100;
$judy[5] = 500;
$judy[10] = 1000;

// Test foreach
$count = 0;
foreach ($judy as $key => $value) {
    $count++;
    echo "Key: $key, Value: $value\n";
}
var_dump($count);

// Test SPL iterators
try {
    $it = new LimitIterator($judy, 0, 2);
    $spl_count = 0;
    foreach ($it as $key => $value) {
        $spl_count++;
        echo "SPL Key: $key, Value: $value\n";
    }
    var_dump($spl_count);
} catch (Exception $e) {
    echo "SPL Error: " . $e->getMessage() . "\n";
}

// Test iterator methods directly
$judy->rewind();
var_dump($judy->valid());
var_dump($judy->key());
var_dump($judy->current());

// Test Iterator next() method
$judy->next();
var_dump($judy->key());
var_dump($judy->current());

// Test renamed searchNext() method
$next_index = $judy->searchNext(1);
var_dump($next_index); // Should return 5
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
Key: 1, Value: 100
Key: 5, Value: 500
Key: 10, Value: 1000
int(3)
SPL Key: 1, Value: 100
SPL Key: 5, Value: 500
int(2)
bool(true)
int(1)
int(100)
int(5)
int(500)
int(5)
```

#### Step 5.2: Test All Judy Types
**File: `tests/iterator_all_types.phpt`**

```php
--TEST--
Judy Iterator - All Types Test
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$types = [
    Judy::BITSET,
    Judy::INT_TO_INT,
    Judy::INT_TO_MIXED,
    Judy::STRING_TO_INT,
    Judy::STRING_TO_MIXED
];

foreach ($types as $type) {
    echo "Testing type: $type\n";
    $judy = new Judy($type);
    
    // Add some test data
    if ($type == Judy::BITSET) {
        $judy[1] = true;
        $judy[5] = true;
        $judy[10] = true;
    } elseif ($type == Judy::INT_TO_INT || $type == Judy::INT_TO_MIXED) {
        $judy[1] = 100;
        $judy[5] = 500;
        $judy[10] = 1000;
    } else {
        $judy["a"] = 100;
        $judy["b"] = 500;
        $judy["c"] = 1000;
    }
    
    // Test iterator
    $count = 0;
    foreach ($judy as $key => $value) {
        $count++;
    }
    echo "Items: $count\n";
    
    // Test instanceof
    var_dump($judy instanceof Iterator);
    echo "\n";
}
?>
--EXPECT--
Testing type: 1
Items: 3
bool(true)

Testing type: 2
Items: 3
bool(true)

Testing type: 3
Items: 3
bool(true)

Testing type: 4
Items: 3
bool(true)

Testing type: 5
Items: 3
bool(true)
```

#### Step 5.3: Performance Testing
**File: `tests/iterator_performance.phpt`**

```php
--TEST--
Judy Iterator - Performance Test
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$judy = new Judy(Judy::INT_TO_INT);

// Add 10,000 items
for ($i = 0; $i < 10000; $i++) {
    $judy[$i] = $i * 2;
}

// Test iteration performance
$start = microtime(true);
$count = 0;
foreach ($judy as $key => $value) {
    $count++;
}
$end = microtime(true);

echo "Iterated $count items in " . ($end - $start) . " seconds\n";
var_dump($count == 10000);
?>
--EXPECT--
Iterated 10000 items in [time] seconds
bool(true)
```

### Phase 6: Validation and Documentation

#### Step 6.1: Final Validation Suite
```bash
# Complete test suite
docker run --rm -v $(pwd):/app php-judy-test bash -c "
cd /app && 
phpize && 
./configure --with-judy=/usr && 
make && 
make install &&
php run-tests.php tests/iterator_*.phpt
"

# Test with different PHP versions
docker run --rm -v $(pwd):/app php:8.2-cli bash -c "
apt-get update && apt-get install -y build-essential libjudy-dev &&
cd /app && 
phpize && 
./configure --with-judy=/usr && 
make && 
make install &&
php -r '\$judy = new Judy(Judy::INT_TO_INT); var_dump(\$judy instanceof Iterator);'
"

docker run --rm -v $(pwd):/app php:8.3-cli bash -c "
apt-get update && apt-get install -y build-essential libjudy-dev &&
cd /app && 
phpize && 
./configure --with-judy=/usr && 
make && 
make install &&
php -r '\$judy = new Judy(Judy::INT_TO_INT); var_dump(\$judy instanceof Iterator);'
"
```

#### Step 6.2: Update Documentation
**Files to Update:**
- `README.md` - Add Iterator interface documentation
- `package.xml` - Update version and changelog
- PHP manual documentation (if applicable)

**Documentation Content:**
```markdown
## Iterator Interface Support

The Judy class implements the Iterator interface, allowing you to use Judy objects in foreach loops and with SPL iterators:

```php
$judy = new Judy(Judy::INT_TO_INT);
$judy[1] = 100;
$judy[5] = 500;

// Foreach iteration
foreach ($judy as $key => $value) {
    echo "$key => $value\n";
}

// SPL iterators
$it = new LimitIterator($judy, 0, 2);
foreach ($it as $key => $value) {
    echo "$key => $value\n";
}
```

### Iterator Methods

- `rewind()` - Reset iterator to first element
- `valid()` - Check if current position is valid
- `current()` - Get current value
- `key()` - Get current key
```

## Success Criteria

### Primary Goals
- [ ] `$judy instanceof Iterator` returns `true`
- [ ] Works with SPL iterators (LimitIterator, FilterIterator, etc.)
- [ ] All existing functionality remains intact
- [ ] No performance regression

### Secondary Goals
- [ ] Proper return type declarations
- [ ] Comprehensive test coverage
- [ ] Updated documentation
- [ ] PHP8.1, 8.2, and 8.3 compatibility

### Validation Checklist
- [ ] Extension builds successfully
- [ ] All tests pass
- [ ] Iterator interface detection works
- [ ] SPL iterators work correctly
- [ ] Performance is acceptable
- [ ] Documentation is updated

## Rollback Plan

If issues arise during implementation:
1. Keep working state in git branches
2. Use `git revert` to undo problematic commits
3. Test each change incrementally
4. Maintain backup of working state

## Implementation Notes

### Critical Implementation Points

1. **Naming Conflict Resolution:**
   - **CRITICAL**: The existing `Judy::next()` method is a search function, not the Iterator interface's `next()` method
   - Must rename existing `next()` to `searchNext()` to avoid conflicts
   - Implement new zero-argument `next()` method for Iterator interface
   - This is the primary reason why Iterator interface detection fails
   - **Documentation**: Add comprehensive comments explaining the separation and reasoning

2. **Internal Iterator Function Mapping:**
   - **CRITICAL**: The `move_forward` function pointer in `zend_iterator_funcs` must be explicitly set to `judy_iterator_move_forward()`
   - This mapping is what foreach loops use internally to advance iteration
   - Double-check this assignment in `judy_get_iterator()` function
   - This is the most critical part of the internal iterator implementation

3. **Iterator Function Mapping:**
   - The `judy_iterator_move_forward()` function maps to the internal `next()` operation
   - This is the key function that enables proper iteration behavior
   - Must be correctly assigned in the `zend_iterator_funcs` structure

4. **Memory Management:**
   - Ensure proper reference counting in `judy_get_iterator()`
   - Handle iterator state cleanup in destructor functions
   - Prevent memory leaks during iteration

5. **PHP8 Compatibility:**
   - All function signatures must match PHP8 requirements
   - Use proper type declarations and argument info
   - Handle PHP8's stricter error checking

### Breaking Changes

**Note:** This implementation introduces a breaking change by renaming `Judy::next()` to `Judy::searchNext()`. Existing code using `$judy->next($index)` will need to be updated to `$judy->searchNext($index)`.

**Migration Guide:**
```php
// Old code (will break):
$next_index = $judy->next(1);

// New code:
$next_index = $judy->searchNext(1);

// Iterator interface usage (new):
$judy->rewind();
while ($judy->valid()) {
    echo $judy->key() . " => " . $judy->current() . "\n";
    $judy->next(); // Iterator interface method
}
```
