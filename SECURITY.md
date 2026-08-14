# Security Policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 2.4.x   | ✅        |
| < 2.4   | ❌        |

## Reporting a vulnerability

PHP Judy is a C extension that processes user-supplied keys and values, so
memory-safety issues (out-of-bounds access, use-after-free, uninitialized
reads) are treated as security bugs.

**Please do not open a public issue for suspected vulnerabilities.**

Instead, report privately via
[GitHub private vulnerability reporting](https://github.com/orieg/php-judy/security/advisories/new).

Please include:

- The extension version (`php -r 'echo judy_version();'`) and PHP version
- Platform and how the extension was installed (PIE, PECL, source)
- A minimal reproduction script
- Any crash output, valgrind report, or sanitizer trace if available

You will receive an acknowledgment, and a fix or mitigation plan will be
coordinated with you before public disclosure.
