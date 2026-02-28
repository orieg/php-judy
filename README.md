# PHP Judy

**PHP Judy** - Extension for creating and accessing dynamic arrays

[![PECL](https://img.shields.io/badge/PECL-Judy-blue.svg)](https://pecl.php.net/package/Judy)
[![Packagist](https://img.shields.io/badge/Packagist-orieg/judy-orange.svg)](https://packagist.org/packages/orieg/judy)
[![PHP Version](https://img.shields.io/badge/PHP-8.0+-green.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-PHP-blue.svg)](LICENSE)

## Table of Contents

1. [Introduction](#introduction)
2. [Directory Contents](#directory-contents)
3. [Installation](#installation)
4. [Usage Examples](#usage-examples)
5. [Reporting Bugs](#reporting-bugs)
6. [Todo](#todo)

## Introduction

**php-judy** is an extension by Nicolas Brousse for the Judy C library. It is compatible with PHP 8.0 and newer.

- **PECL Package**: [http://pecl.php.net/package/Judy](http://pecl.php.net/package/Judy)
- **Packagist Package**: [https://packagist.org/packages/orieg/judy](https://packagist.org/packages/orieg/judy)
- **GitHub Repository**: [http://github.com/orieg/php-judy](http://github.com/orieg/php-judy)

A Judy array is a complex but very fast associative array data structure for storing and looking up values using integer or string keys. Unlike normal arrays, Judy arrays may be sparse; that is, they may have large ranges of unassigned indices.

- **Wikipedia**: [http://en.wikipedia.org/wiki/Judy_array](http://en.wikipedia.org/wiki/Judy_array)

The PHP extension is based on the Judy C library that implements a dynamic array. A Judy array consumes memory only when populated yet can grow to take advantage of all available memory. Judy's key benefits are: scalability, performance, memory efficiency, and ease of use. Judy arrays are designed to grow without tuning into the peta-element range, scaling near O(log-base-256) -- 1 more RAM access at 256 X population.

- **Judy C Library**: [http://judy.sourceforge.net](http://judy.sourceforge.net)

For a detailed performance comparison with native PHP arrays, please see the [BENCHMARK.md](BENCHMARK.md) file.

## Directory Contents

```
README.md          This file
LICENSE           The PHP License used by this project
EXPERIMENTAL      Note about the status of this package
BENCHMARK.md      Performance benchmarks and analysis
MIGRATION_2.2.0.md Migration guide for version 2.2.0

lib/              Header and source libraries used by the package
libjudy/          Bundled libJudy
tests/            Unit tests
examples/         PHP test/example scripts
*.c, *.h          Header and source files used to build the package
*.php             PHP test/examples scripts
```

## Installation

### A. Using PHP PIE (Recommended)

PHP PIE (PHP Extension Installer) is the easiest way to install PHP Judy on supported platforms:

```sh
# Install PHP PIE if you don't have it
curl -sSL https://pie.dev/installer | php

# Install PHP Judy using PIE
pie install judy
```

**Note**: PHP PIE automatically handles dependencies and builds the extension for your specific PHP version and platform.

### B. Using PECL

You can also install PHP Judy using PECL:

```sh
# Install the extension with pecl
pecl install judy
```

**Note**: You may need to install the Judy C library first on some systems.

### C. Linux (Manual Build)

From the PHP Judy sources:

```sh
phpize
./configure --with-judy[=DIR]
make
make test
make install
```

If you are using Ubuntu or Debian, you can install libJudy with apt:

```sh
apt-get install libjudydebian1 libjudy-dev
phpize
./configure --with-judy=/usr
make
make test
make install
```

### D. Windows

On Windows, you will need to build LibJudy yourself.

Download the sources at [http://sourceforge.net/projects/judy/](http://sourceforge.net/projects/judy/)

Extract the sources, and open the Visual Studio command prompt and navigate to the source directory. Then execute:

```
build
```

This creates "Judy.lib", copy this into the php-sdk library folder and name it `libJudy.lib`

Then copy the include file "judy.h" into the php-sdk includes folder. Now it's time to build pecl/judy, extract the pecl/judy into your build folder where the build scripts will be able to pick it up, e.g.:

```
C:\php\pecl\judy\
```

If your source of PHP is located in:

```
C:\php\src\
```

The rest of the steps is pretty straightforward, like any other external extension:

```sh
buildconf
configure --with-judy=shared
nmake
```

### E. Mac OS X

The recommended way to install `php-judy` on Mac OS X is by using `pie` or `pecl`. You will need to have the Judy C library installed first, which can be done easily with Homebrew.

#### Using PHP PIE (Recommended)

```sh
# Install PHP PIE if you don't have it
curl -sSL https://pie.dev/installer | php

# Install PHP Judy using PIE
pie install judy
```

#### Using PECL

```sh
# First, install the Judy C library
brew install judy

# Then, install the extension with pecl
pecl install judy
```

#### Manual Install

If you prefer to compile from source, you will need to install the libJudy first. Download the sources at [http://sourceforge.net/projects/judy/](http://sourceforge.net/projects/judy/)

Extract the sources, then cd into the source directory and execute:

```sh
./configure
make
make install
```

## Usage Examples

Judy arrays can be used like usual PHP arrays. The difference will be in the type of key/values that you can use. Judy arrays are optimized for memory usage but it forces some limitations in the PHP API.

There are currently 5 types of PHP Judy Arrays:

### 1. Judy::BITSET

A Judy array with only 1 bit per index. It can be used to store boolean values.

```php
$judy = new Judy(Judy::BITSET);
$judy[100] = true;
$judy[200] = true;
$judy[300] = false;

if ($judy[100]) {
    echo "Index 100 is set\n";
}
```

### 2. Judy::INT_TO_INT

A Judy array with integer keys and integer values.

```php
$judy = new Judy(Judy::INT_TO_INT);
$judy[1] = 100;
$judy[2] = 200;
$judy[3] = 300;

echo $judy[2]; // Outputs: 200
```

### 3. Judy::INT_TO_MIXED

A Judy array with integer keys and mixed values (strings, integers, etc.).

```php
$judy = new Judy(Judy::INT_TO_MIXED);
$judy[1] = "Hello";
$judy[2] = 42;
$judy[3] = [1, 2, 3];

echo $judy[1]; // Outputs: Hello
```

### 4. Judy::STRING_TO_INT

A Judy array with string keys and integer values.

```php
$judy = new Judy(Judy::STRING_TO_INT);
$judy["apple"] = 1;
$judy["banana"] = 2;
$judy["cherry"] = 3;

echo $judy["banana"]; // Outputs: 2
```

### 5. Judy::STRING_TO_MIXED

A Judy array with string keys and mixed values.

```php
$judy = new Judy(Judy::STRING_TO_MIXED);
$judy["name"] = "John Doe";
$judy["age"] = 30;
$judy["scores"] = [85, 92, 78];

echo $judy["name"]; // Outputs: John Doe
```

### Iterator Interface (PHP 8+)

Judy arrays implement the PHP Iterator interface, allowing you to use them in foreach loops:

```php
$judy = new Judy(Judy::INT_TO_MIXED);
$judy[1] = "First";
$judy[5] = "Fifth";
$judy[10] = "Tenth";

// Iterate through all elements
foreach ($judy as $key => $value) {
    echo "Key: $key, Value: $value\n";
}

// Manual iteration
$judy->rewind();
while ($judy->valid()) {
    $key = $judy->key();
    $value = $judy->current();
    echo "Key: $key, Value: $value\n";
    $judy->next();
}
```

### Performance Considerations

- **Memory Efficiency**: Judy arrays use 2-4x less memory than PHP arrays
- **Sequential Access**: Excellent performance for ordered iteration
- **Range Queries**: Native support for range operations
- **Random Access**: Slower than PHP arrays (O(log n) vs O(1))

For detailed performance analysis, see [BENCHMARK.md](BENCHMARK.md).

## Reporting Bugs

Please report bugs and issues on the GitHub repository:

[https://github.com/orieg/php-judy/issues](https://github.com/orieg/php-judy/issues)

## Todo

- [ ] Additional performance optimizations
- [ ] More comprehensive test coverage
- [ ] Additional documentation and examples
- [ ] Integration with more PHP frameworks
- [x] **Performance**: Implement `zend_object_handlers` (read/write_dimension) to bypass ArrayAccess overhead.
- [x] **Performance**: Upgrade to Fast ZPP (Zend Parameter Parsing) macros.
- [x] **Performance**: Optimize `get_iterator` to use native C iterators.
- [x] **Core Features**: Implement `slice($start, $end)` for efficient range queries.
- [x] **Set Operations**: Add native methods for Union, Intersection, and Difference (especially for BITSET).
- [x] **Serialization**: Implement `__serialize`/`__unserialize` for optimized binary persistence.
- [x] **Interoperability**: Implement `JsonSerializable` interface.
- [ ] **Testing**: More comprehensive test coverage.
- [ ] **Documentation**: Document memory usage patterns vs standard arrays.

## License

This project is licensed under the PHP License - see the [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

- **Documentation**: [BENCHMARK.md](BENCHMARK.md) for performance analysis
- **Migration Guide**: [MIGRATION_2.2.0.md](MIGRATION_2.2.0.md) for version 2.2.0 changes
- **Examples**: Check the `examples/` directory for usage examples
