# Bundled libJudy

This directory contains a subset of the upstream **Judy-1.0.5** sources,
bundled so php-judy can build without a system libJudy. License:
LGPL-2.1-or-later — see [COPYING](COPYING) (upstream's file, verbatim)
and the root [THIRD-PARTY.md](../THIRD-PARTY.md).

## Provenance

- Upstream: <https://downloads.sourceforge.net/project/judy/judy/Judy-1.0.5/Judy-1.0.5.tar.gz>
- Version: Judy-1.0.5
- Tarball sha256: `d2704089f85fdb6f2cd7e77be21170ced4b4375c03ef1ad4cf1075bd414a63eb`
- Imported: 2026-08-18, byte-identical to the tarball, preserving the
  upstream relative layout under `libjudy/`

## What was imported

- `COPYING` — upstream LGPL-2.1 license text
- `src/Judy.h` — public API header
- `src/JudyCommon/` — the complete shared engine: every `*.c` and `*.h`
  in the directory, including `JudyPrintJP.c` (a debug/trace helper
  `#include`d by JudyGet/JudyIns/JudyDel/JudyPrevNextEmpty under trace
  ifdefs, so it is not dead code)
- `src/Judy1/Judy1.h`, `src/JudyL/JudyL.h` — per-variant internal headers
- `src/JudySL/JudySL.c`, `src/JudyHS/JudyHS.c` — string-keyed layers

## What was deliberately excluded

- Upstream autotools/build files (`configure`, `Makefile.am/.in`,
  `aclocal.m4`, `bootstrap`, libtool helpers) — php-judy wires these
  sources into its own build system instead
- `doc/`, `test/`, `tool/`, `src/apps/`, per-directory `README`s,
  `build.bat`, `sh_build`, `Judy.h.check.c`
- `src/JudyHS/JudyHS.h` — a standalone compatibility header for
  pre-JudyHS `Judy.h`; nothing includes it (the `#include` in
  `JudyHS.c` is commented out upstream)
- Upstream's build-time-generated Judy1/JudyL wrapper sources and
  size-class tables — php-judy ships its own clearly-marked equivalents
  instead: the build shims under `src/wrappers/` and the pre-generated
  `src/Judy1/Judy1Tables.c` / `src/JudyL/JudyLTables.c` (see the
  provenance headers in those files)

## Modifications

Every change to these sources is documented in [PATCHES.md](PATCHES.md)
and available as a git diff against the pristine import commit.
Currently: none — the tree is pristine.

## Build integration

These sources are **not compiled yet**. Stage 1 of the vendoring plan
([#142](https://github.com/orieg/php-judy/issues/142)) wires them into
`config.m4`/`config.w32`, compiling them at `-O2 -fno-lto` only — never
`-O3`/LTO (see [#131](https://github.com/orieg/php-judy/issues/131);
`-O2` is load-bearing for correctness).
