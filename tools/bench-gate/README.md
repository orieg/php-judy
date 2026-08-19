# bench-gate — cross-platform performance regression gate

CI-invoked tooling for the recurring gate described in
[BENCHMARK.md](../../BENCHMARK.md#keeping-it-honest-over-time-the-recurring-cross-platform-gate).
Lives here rather than under `research/` because CI runs it and contributors
re-run it; `research/` keeps the records these produce.

| file | what it does |
| --- | --- |
| `build-arms.sh` | builds arms **S** and **C** from one tree with one toolchain, N independently linked copies each, and writes the arm-S verification manifest beside them |
| `run-gate.sh` | runs `scripts/bench-gate.php` once or several times on one platform, takes `tools/bench-lock.sh` off CI, runs `tools/bench-stability.py` over the CSV the gate emits, and derives the noise floor when there is more than one run |

The PHP that does the measuring lives in `scripts/`, which is this repo's
documented home for PHP-level helpers and ships as `role="doc"`:
`bench-gate.php` (the driver), `bench-arm-s.php` (arm-S reconstruction and
verification), `bench-lib.php` (hygiene and statistics shared with
`bench-threearm.php`), `bench-gate-report.php` (the Markdown summary).

Nothing here is shipped in the PECL tarball.

## Quick start

```sh
./tools/bench-gate/build-arms.sh /tmp/arms 2
php scripts/bench-gate.php \
  --arm C=/tmp/arms/judy-C-1.so --arm C=/tmp/arms/judy-C-2.so \
  --arm S=/tmp/arms/judy-S-1.so --arm S=/tmp/arms/judy-S-2.so \
  --baseline baselines/arm-ratios.json --gate --out gate.json
```

Two copies of each arm, not one: they are rotated across rounds, and C1-vs-C2
is the rebuild control that measures how far a cell can move on this machine
today for no reason at all.

## Relationship to the other benchmark tooling

- `scripts/bench-compare.php` — release-over-release, absolute ms, one platform.
  Advisory in `ci.yml`; never gates a merge.
- `scripts/bench-threearm.php` — the deep one-off study on a dedicated host.
  Produces claim-grade absolute numbers.
- **this** — recurring, every supported platform, ratios only. Gates.

All three share `scripts/bench-lib.php`, so the hygiene machinery exists once.
