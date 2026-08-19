#!/usr/bin/env python3
"""Baseline-stability guard for the O5P gate CSVs.

WHY (2026-08-19): the O5-reopen out-of-cache sweep was corrupted by a
concurrent php-judy benchmark campaign on the same host. Both campaigns
passed the project's loadavg < N/2 hygiene check (24 cores, loadavg peaked
at 2.87) and corrupted each other anyway, because two memory-bound
benchmarks contend for LLC and memory bandwidth regardless of core
pinning. The corruption was caught by LUCK -- a partial read of a cell
disagreed with the full read.

This makes that check mechanical. The `pre` arm is an untouched baseline:
its per-trial medians must be stable within a cell. A spread beyond
--tol (default 15%) means the machine changed under the benchmark, and
NO ratio computed from those trials is interpretable.

usage: o5p-stability.py [--tol 0.15] <csv> [csv...]
exit 1 if any cell fails, so a driver can gate on it.
"""
import sys, csv, statistics as st
from collections import defaultdict

tol = 0.15
args = sys.argv[1:]
if args and args[0] == "--tol":
    tol = float(args[1]); args = args[2:]

# (corpus,n,arm,kernel) -> {trial: [ns]}
data = defaultdict(lambda: defaultdict(list))
loadavg = defaultdict(dict)
for path in args:
    for line in open(path):
        if line.startswith("#"):
            if "loadavg_" in line:
                k, _, v = line.strip("# \n").partition("=")
                loadavg[path][k] = v
            continue
        row = next(csv.reader([line]))
        if not row or row[0] == "arm":
            continue
        arm, seed, corpus, n, trial, kern, ns, hits = row
        data[(corpus, int(n), arm, kern)][int(trial)].append(float(ns))

bad = 0
print(f"{'cell':38s} {'arm/kernel':16s} {'trials':>7s} {'min':>9s} {'max':>9s} {'spread':>8s}  verdict")
for (corpus, n, arm, kern) in sorted(data):
    if arm != "pre" or kern not in ("serial", "serialold"):
        continue           # the untouched baseline is the canary.
    per_trial = {t: st.median(v) for t, v in data[(corpus, n, arm, kern)].items()}
    if len(per_trial) < 2:
        continue
    lo, hi = min(per_trial.values()), max(per_trial.values())
    spread = (hi - lo) / lo
    ok = spread <= tol
    if not ok:
        bad += 1
        drift = " ".join(f"t{t}={v:.1f}" for t, v in sorted(per_trial.items()))
        verdict = f"CONTAMINATED ({drift})"
    else:
        verdict = "stable"
    print(f"{corpus+' n='+str(n):38s} {arm+'/'+kern:16s} {len(per_trial):7d} "
          f"{lo:9.2f} {hi:9.2f} {spread*100:7.1f}%  {verdict}")

for path, la in loadavg.items():
    vals = [float(v) for v in la.values()]
    if vals and (max(vals) - min(vals)) > 0.5:
        print(f"\nNOTE {path}: loadavg moved {min(vals)}..{max(vals)} during the run.")

if bad:
    print(f"\n{bad} cell(s) FAILED the baseline-stability guard at tol={tol:.0%}. "
          f"Ratios from those trials are not interpretable -- re-run on an idle host.")
    sys.exit(1)
print(f"\nAll baseline cells stable within {tol:.0%}.")
