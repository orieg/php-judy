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
- `r13..r16-gate-windows-x64-1.json` — the four runs the **windows-x64** entry
  was derived from, added later the same day. All four are commit
  `3ce4885`, one run per workflow dispatch, because the Windows job does not
  honour `BENCH_REPEATS`: it drives `bench-gate.php` directly rather than
  through `run-gate.sh`, so one dispatch produces exactly one run JSON. Four
  dispatches were therefore issued in parallel on four refs all pointing at
  `3ce4885` — the workflow's concurrency group is keyed on `github.ref`, so
  same-ref dispatches cancel each other, and same-commit different-ref ones do
  not. Each dispatch got its own ephemeral runner VM.

  **`derived_from.distinct_hosts` reads 1 for this platform, and that
  understates it.** The field counts distinct `uname` strings, and GitHub's
  Windows Server 2025 image reports the same host name
  (`runnervmk2qs2`) from every one of the four VMs, where the Linux images
  vary it. The runs really are four separate runner instances. Nothing was
  edited to compensate: the recorded value is what the tool measured, and the
  consequence — the derivation stays in the conservative
  `axis_floor_is_lower_bound` regime — is the same regime all three POSIX
  platforms are in anyway.
- `<platform>-arm-s-manifest.json` — the arm-S verification record for that
  platform. All four read `UNPATCHED`: 21 of 28 upstream `.c`/`.h` files
  byte-identical to the pristine Judy-1.0.5 import commit, the other 7 carrying
  P5 (LLP64) and nothing else, no post-`f366fdb` patched file leaked in, and no
  `__POPCNT__` / `__builtin_bswap64` fingerprint present.
- `<platform>-census-{S,C}.json` — the instruction-level cross-check. x86-64
  fingerprints O1 as `popcnt` and O3 as `bswap`; arm64 as `cnt` and `rev`. The
  O1 count reads exactly 0 in arm S on both architectures. There is no
  `windows-x64` census: that job builds through `php-windows-builder` rather
  than `build-arms.sh`, which is where the census is taken. The arm-S manifest
  is produced there and is identical byte-for-byte to the POSIX platforms',
  the reconstruction being deterministic; the byte-difference check between the
  two DLLs in the workflow is what stands in for the census.

Regenerate with `.github/workflows/bench-gate.yml` (weekly, or
`workflow_dispatch` with `repeats`), or locally per
[BENCHMARK.md](../../../../BENCHMARK.md#reproducing-the-gate-on-any-platform).
