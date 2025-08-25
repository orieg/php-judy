        PHP Judy - extension creating and accessing dynamic arrays
     ================================================================

Content
---------
 1. Introduction
 2. Directory Contents
 3. How to install
 4. Usage (Examples)
 5. Reporting Bugs
 6. Todo


1. INTRODUCTION
-----------------

php-judy is an extension by Nicolas Brousse for the Judy C library. It is compatible with PHP 8.0 and newer.
  -> http://pecl.php.net/package/Judy
  -> http://github.com/orieg/php-judy

(see Section 4 of this document for PHP examples)

A Judy array is a complex but very fast associative array data structure for
storing and looking up values using integer or string keys. Unlike normal
arrays, Judy arrays may be sparse; that is, they may have large ranges of
unassigned indices.

  -> http://en.wikipedia.org/wiki/Judy_array

The PHP extension is based on the Judy C library that implements a dynamic array.
A Judy array consumes memory only when populated yet can grow to take advantage
of all available memory.  Judy's key benefits are:  scalability, performance,
memory efficiency, and ease of use. Judy arrays are designed to grow without
tuning into the peta-element range, scaling near O(log-base-256) -- 1 more RAM
access at 256 X population.

  -> http://judy.sourceforge.net

For a detailed performance comparison with native PHP arrays, please see the [BENCHMARK.md](BENCHMARK.md) file.

2. PHP JUDY TOP DIRECTORY CONTENTS:
------------------------------------

README       This file.
LICENSE      The PHP License used by this project.
EXPERIMENTAL Note about the status of this package.

lib/         Header and source libraries used by the package.
libjudy/     Bundled libJudy.      
tests/       Unit tests.
*.c, *.h     Header and source files used to build the package.
*.php        PHP test/examples scripts.


3. HOW TO INSTALL
------------------

## A. Using PHP PIE (Recommended)

PHP PIE (PHP Extension Installer) is the easiest way to install PHP Judy on supported platforms:

```sh
# Install PHP PIE if you don't have it
curl -sSL https://pie.dev/installer | php

# Install PHP Judy using PIE
pie install judy
```

**Note**: PHP PIE automatically handles dependencies and builds the extension for your specific PHP version and platform.

## B. Using PECL

You can also install PHP Judy using PECL:

```sh
# Install the extension with pecl
pecl install judy
```

**Note**: You may need to install the Judy C library first on some systems.

## C. Linux (Manual Build)

   From the PHP Judy sources :

   ```sh
     phpize
     ./configure --with-judy[=DIR]
     make
     make test
     make install
   ```

   If you are using Ubuntu or Debian, you can install libJudy with apt :

   ```sh
     apt-get install libjudydebian1 libjudy-dev
     phpize
     ./configure --with-judy=/usr
     make
     make test
     make install
   ```

## D. Windows

   On Windows, you will need to build LibJudy yourself.

   Download the sources at 

     http://sourceforge.net/projects/judy/
	  
   Extract the sources, and open the Visual Studio command prompt and navigate to 
   the source directory. Then execute:

     build
	  
   This creates "Judy.lib", copy this into the php-sdk library folder and name it 

     libJudy.lib
	 
   Then copy the include file "judy.h" into the php-sdk includes folder. Now its 
   time to build pecl/judy, extract the pecl/judy into your build folder where 
   the build scripts will be able to pick it up, e.g.:
	
     C:\php\pecl\judy\
	 
   If your source of PHP is located in:
	
     C:\php\src\
	 
   The rest of the steps is pretty straight forward, like any other external 
   extension:
   ```sh	
     buildconf
     configure --with-judy=shared
     nmake
   ```

## E. Mac OS X

The recommended way to install `php-judy` on Mac OS X is by using `pie` or `pecl`. You will need to have the Judy C library installed first, which can be done easily with Homebrew.

### Using PHP PIE (Recommended)

```sh
# Install PHP PIE if you don't have it
curl -sSL https://pie.dev/installer | php

# Install PHP Judy using PIE
pie install judy
```

### Using PECL

   ```sh
   # First, install the Judy C library
   brew install judy

   # Then, install the extension with pecl
   pecl install judy
   ```

### Manual install

   If you prefer to compile from source, you will need to install the libJudy first. Download the sources at 

     http://sourceforge.net/projects/judy/
	  
   Extract the sources, then cd into the source directory and execute :
   ```sh
     ./configure
     make
     make install
   ```


4. USAGE (EXAMPLES)
------------------

Judy's array can be used like usual PHP arrays. The difference will be in the
type of key/values that you can use. Judy arrays are optimised for memory usage
but it force to some limitation in the PHP API.

There is currently 5 type of PHP Judy Arrays :
 - BITSET (using Judy1)
 - INT_TO_INT (using JudyL)
 - INT_TO_MIXED (using JudyL)
 - STRING_TO_INT (using JudySL)
 - STRING_TO_MIXED (using JudySL)

You can use foreach() and the PHP array notation on all PHP Judy arrays.

  A. BITSET

  Bitset implementation is quite basic for now. It allow you to set a bunch of index
  setting the value to false will be the same than using unset().

  ```php
    $bitset = new Judy(Judy::BITSET);
    $bitset[124] = true;
 
    print $bitset[124]; // will print 1
 
    $bitset[124] = false; // is the same as unset($bitset[124])
  ```

  B. INT_TO_INT

  This type let you create an array with key and value of integer, and integer only.

  ```php
    $int2int = new Judy(Judy::INT_TO_INT);
    $int2int[125] = 17;

    print $int2int[125]; // will print 17
  ```

  C. INT_TO_MIXED

  This type let you create an array with key as integer and value of any type, including
  other judy array or any object.

  ```php
    $int2mixed = new Judy(Judy::INT_TO_MIXED);
    $int2mixed[1] = "one";
    $int2mixed[2] = array('a', 'b', 'c');
    $int2mixed[3] = new Judy(Judy::BITSET);
  ```

  D. STRING_TO_INT

  This type let you create an array with key as string (currently limited to 65536 char.)
  and an integer as the value.

  ```php
    $string2int = new Judy(Judy::STRING_TO_INT);
    $string2int["one"] = 1;
    $string2int["two"] = 2;

    print $string2int["one"]; // will print 1
  ```

  E. STRING_TO_MIXED

  This type let you create an array with key as string and values of any type, including
  other judy array or any objects.

  ```php
    $string2mixed = new Judy(Judy::STRING_TO_MIXED);
    $string2mixed["string"] = "hello world!";
    $string2mixed["array"] = array('a', 'b', 'c');
    $string2mixed["integer"] = 632;
    $string2mixed["bitset"] = new Judy(Judy::BITSET);
  ```


5. REPORTING BUGS
------------------

If you encounter a bug, please submit it via the bug tracker on Git Hub:

  https://github.com/orieg/php-judy/issues


6. TODO
--------

 * Implements comparator and cast handler
 * Add bitset comparator (cf. Judy1Op sample)


7. DEVELOPMENT AND RELEASE PROCESS
---------------------------------

This section outlines the process for preparing and publishing a new release of the `php-judy` extension to PECL.

### Step 1: Update Version Numbers

Before creating a new release, the version number must be updated in three key places:

1.  **`php_judy.h`**: Update the `PHP_JUDY_VERSION` macro.
2.  **`tests/001.phpt`**: Update the expected version number in the `--EXPECTF--` section.
3.  **`package.xml`**: Update the `<release>` and `<api>` tags in the `<version>` block at the top of the file.

### Step 2: Update `package.xml`

This is the manifest for the PECL package and must be carefully updated.

1.  **Date:** Update the `<date>` tag to the current release date.
2.  **PHP Dependency:** Ensure the minimum required PHP version in `<dependencies><required><php><min>` is correct. For version 2.0.0 and newer, this should be `8.0.0`.
3.  **File List (`<contents>`):** Manually add any new files to the `<contents>` section. This includes new source files, tests, documentation (`BENCHMARK.md`), or tooling (`Dockerfile`, `Dockerfile.validate`).
4.  **Changelog:** Add a new `<release>` block to the *top* of the `<changelog>` section. This should contain the new version number, date, and a `<notes>` section with a bulleted list of the changes in this release. The top-level `<notes>` section of the `package.xml` should be identical to the notes in this new changelog entry.

### Step 3: Build the PECL Package

The `.tgz` package for PECL is built using the Docker container to ensure a consistent environment.

   ```sh
   # Build the PECL .tgz package
   docker run -v $(pwd):/usr/src/php-judy -w /usr/src/php-judy --rm php-judy-test pecl package
   ```
   This command will generate a versioned `.tgz` file (e.g., `Judy-2.0.0.tgz`) in the project root.

### Step 4: Validate the Package

Before publishing, validate that the generated package can be installed in a clean environment. This is done using the `Dockerfile.validate` file.

   ```sh
   # Build the validation Docker image
   docker build -f Dockerfile.validate -t judy-validation-test .
   ```
   A successful build of this image confirms that the package is valid and installable.

### Step 5: Publish

After validating the package, the final step is to upload the generated `.tgz` file to the PECL website through the maintainer interface.
