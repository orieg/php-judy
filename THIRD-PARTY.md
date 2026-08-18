# Third-party components

## php-judy extension code

All extension sources outside `libjudy/` are licensed under the
PHP License 3.01 — see [LICENSE](LICENSE).

## Bundled libJudy (`libjudy/`)

- License: **LGPL-2.1-or-later** — see [libjudy/COPYING](libjudy/COPYING)
- Copyright: Doug Baskins / Hewlett-Packard Company
- Upstream: Judy-1.0.5,
  <https://downloads.sourceforge.net/project/judy/judy/Judy-1.0.5/Judy-1.0.5.tar.gz>,
  sha256 `d2704089f85fdb6f2cd7e77be21170ced4b4375c03ef1ad4cf1075bd414a63eb`
- Modifications: documented in [libjudy/PATCHES.md](libjudy/PATCHES.md),
  each available as a git diff against the pristine import commit
  (currently none — the tree is pristine)

Building with `--with-judy=DIR` links against a system libJudy instead
of the bundled copy; pure dynamic linking remains available.

Note for downstream **binary** packagers: distributing binaries that
contain the bundled library carries the obligations of LGPL-2.1 §6.
The complete corresponding source of the bundled library ships in this
package under `libjudy/`.
