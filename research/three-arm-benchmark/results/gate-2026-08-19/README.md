# Cross-platform gate — first recorded runs (2026-08-19)

Raw output from the four gate runs per platform that
[`baselines/arm-ratios.json`](../../../../baselines/arm-ratios.json) was derived
from, kept because a prior round of this work lost a favourable measurement to
an unpersisted run.

- `r1..r12-gate-<platform>-<repeat>.json` — 4 runs per platform spanning **2
  distinct GitHub runner instances** (2 workflow runs x 2 repeats). `r1-r6` are
  the first workflow run, `r7-r12` the second. Cross-runner span is the point:
  deriving a floor from repeats inside one job understates the variance the gate
  actually faces by three to five times, and doing exactly that on the first
  attempt is why the floors in this baseline are per-cell rather than per-axis.
- `<platform>-arm-s-manifest.json` — the arm-S verification record for that
  platform. All three read `UNPATCHED`: 21 of 28 upstream `.c`/`.h` files
  byte-identical to the pristine Judy-1.0.5 import commit, the other 7 carrying
  P5 (LLP64) and nothing else, no post-`f366fdb` patched file leaked in, and no
  `__POPCNT__` / `__builtin_bswap64` fingerprint present.
- `<platform>-census-{S,C}.json` — the instruction-level cross-check. x86-64
  fingerprints O1 as `popcnt` and O3 as `bswap`; arm64 as `cnt` and `rev`. The
  O1 count reads exactly 0 in arm S on both architectures.

Regenerate with `.github/workflows/bench-gate.yml` (weekly, or
`workflow_dispatch` with `repeats`), or locally per
[BENCHMARK.md](../../../../BENCHMARK.md#reproducing-the-gate-on-any-platform).
