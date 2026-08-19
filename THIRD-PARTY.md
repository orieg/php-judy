# Third-party components

## php-judy extension code

All extension sources outside `libjudy/` are licensed under the
PHP License 3.01 — see [LICENSE](LICENSE).

## Bundled libJudy (`libjudy/`)

- License: **LGPL-2.1-or-later** — see [libjudy/COPYING](libjudy/COPYING)
- Copyright: (c) 2002 Hewlett-Packard Company; primary author Doug Baskins
- Upstream: Judy-1.0.5,
  <https://downloads.sourceforge.net/project/judy/judy/Judy-1.0.5/Judy-1.0.5.tar.gz>,
  sha256 `d2704089f85fdb6f2cd7e77be21170ced4b4375c03ef1ad4cf1075bd414a63eb`
- Modifications: documented in [libjudy/PATCHES.md](libjudy/PATCHES.md),
  each available as a git diff against the pristine import commit, and
  each modified file carries a §2(b) change notice at its top. That
  ledger is the authoritative list; php-judy *additions* (the build
  shims under `src/wrappers/`, the pre-generated size-class tables) are
  marked as such and carry provenance headers instead.
- Build: these sources are compiled into the extension by default
  (`config.m4` / `config.w32`)

Users are free to modify the files under `libjudy/` and recompile the
extension under the terms of LGPL-2.1; the complete corresponding
source and build definitions ship in this repository and package.

Building with `--with-judy=DIR` links against a system libJudy instead
and compiles nothing under `libjudy/`; that mode remains supported, and
pure dynamic linking is available through it.

Note for downstream **binary** packagers: distributing binaries that
contain the bundled library carries the obligations of LGPL-2.1 §6.
The complete corresponding source of the bundled library ships in this
package under `libjudy/`.
