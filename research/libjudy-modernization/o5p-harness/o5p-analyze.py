#!/usr/bin/env python3
"""O5P (partition reopen) gate analysis.

Replication unit = BUILD (randomized link order), per O1/O3/O4/O5: per
(arm, seed, cell, kernel) take the median over interleaved trials, then
percentile-bootstrap the ratio of build-median sets.

Claims (per cell):
  speedup     = pre/serial     vs post/mg256   (corrected baseline; CI-low > 1.0)
  speedup-old = pre/serialold  vs post/mg256   (old-style baseline, record comparability)
  partition   = ctl/mg256      vs post/mg256   (partition effect; unimodal cells
                                                must NOT show CI-high < 1.0 = regression)
  control     = pre/serial     vs ctl/serial   (expected null)
  serialnull  = pre/serial     vs post/serial  (additive-TU null check)
  raw         = pre/serial     vs post0/mg256  (thresholds off; crossover)
"""
import sys, csv, statistics as st, random
from collections import defaultdict

def boot_ratio(base, arm, n=20000, seed=7):
    rnd = random.Random(seed)
    out = []
    for _ in range(n):
        rb = [rnd.choice(base) for _ in base]
        ra = [rnd.choice(arm) for _ in arm]
        m = st.median(rb)
        if m > 0:
            out.append(m / st.median(ra))
    out.sort()
    return out[int(0.025 * len(out))], out[int(0.975 * len(out))]

def main(paths):
    data = defaultdict(lambda: defaultdict(list))
    for path in paths:
        with open(path) as f:
            for row in csv.reader(x for x in f if not x.startswith("#")):
                if not row or row[0] == "arm":
                    continue
                arm, seed, corpus, n, trial, kern, ns, hits = row
                data[(corpus, int(n))][(arm, seed, kern)].append(float(ns))

    for (corpus, n) in sorted(data):
        cell = data[(corpus, n)]
        def builds(arm, kern):
            out = []
            for s in "12345":
                v = cell.get((arm, s, kern))
                if v:
                    out.append(st.median(v))
            return out
        pre = builds("pre", "serial")
        preold = builds("pre", "serialold")
        if not pre:
            continue
        pm = st.median(pre)
        print(f"\n{corpus} n={n}  pre-serial={pm:.2f} ns/op (builds={len(pre)}"
              f"; old-style={st.median(preold):.2f})" if preold else
              f"\n{corpus} n={n}  pre-serial={pm:.2f} ns/op (builds={len(pre)})")
        rows = [
            ("pre",  "serial", pre,    "ctl", "serial", "control (expect null)"),
            ("pre",  "serial", pre,    "post", "serial", "post-serial null-check"),
            ("pre",  "serial", pre,    "post", "mg256", "SPEEDUP mg256 (corrected)"),
            ("pre",  "serial", pre,    "post", "mg1024", "SPEEDUP mg1024 (corrected)"),
            ("pre",  "serialold", preold, "post", "mg256", "speedup mg256 (old-style)"),
            ("ctl",  "mg256", None,   "post", "mg256", "vs-ctl partition effect"),
            ("pre",  "serial", pre,    "ctl", "mg256", "ctl (unpartitioned) mg256"),
            ("pre",  "serial", pre,    "post0", "mg256", "raw (no threshold) mg256"),
        ]
        for barm, bkern, bset, aarm, akern, label in rows:
            base = bset if bset is not None else builds(barm, bkern)
            b = builds(aarm, akern)
            if not b or not base:
                continue
            lo, hi = boot_ratio(base, b)
            point = st.median(base) / st.median(b)
            spread = (max(b) - min(b)) / st.median(b) * 100
            mark = "** CI-low > 1.0" if lo > 1.0 else (
                   "(regression, CI-high < 1.0)" if hi < 1.0 else "(null)")
            print(f"  {label:30s} {st.median(b):8.2f} ns/op  "
                  f"x{point:.4f} [{lo:.4f},{hi:.4f}] spread={spread:.1f}%  {mark}")

if __name__ == "__main__":
    main(sys.argv[1:] or ["o5p-bench-l3.csv"])
