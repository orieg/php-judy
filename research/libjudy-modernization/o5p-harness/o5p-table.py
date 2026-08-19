#!/usr/bin/env python3
"""Render the O5P gate cells as a markdown table (for the PR / #142 record).

Columns: corpus, n, pre-serial ns/op (corrected), post mg ratio [CI]
(corrected baseline), post mg ratio (old-style baseline), post-vs-ctl
partition effect [CI], ctl-vs-pre (archived impl) [CI], verdict.
Verdict rules: WIN = corrected CI-low > 1.0; REG = CI-high < 1.0; null
otherwise. The no-regression gate is the post-vs-ctl column.
"""
import sys, csv, statistics as st, random
from collections import defaultdict

args = sys.argv[1:]
EXCL = set()
if args and args[0] == "--exclude-trials":
    EXCL = {int(x) for x in args[1].split(",")}; args = args[2:]
KERN = args[0] if args else "mg4096"
FILES = args[1:]

def boot(base, arm, n=20000, seed=7):
    rnd = random.Random(seed); out=[]
    for _ in range(n):
        rb=[rnd.choice(base) for _ in base]; ra=[rnd.choice(arm) for _ in arm]
        m=st.median(rb)
        if m>0: out.append(m/st.median(ra))
    out.sort(); return out[int(.025*len(out))], out[int(.975*len(out))]

def mark(lo,hi):
    return "WIN" if lo>1.0 else ("REG" if hi<1.0 else "null")

data=defaultdict(lambda: defaultdict(list))
for path in FILES:
    with open(path) as f:
        for row in csv.reader(x for x in f if not x.startswith("#")):
            if not row or row[0]=="arm": continue
            arm,seed,corpus,n,trial,kern,ns,hits=row
            if int(trial) in EXCL: continue
            data[(corpus,int(n))][(arm,seed,kern)].append(float(ns))

print(f"| corpus | n | pre serial (ns/op) | post {KERN} vs pre (corrected) | vs pre (old-style) | post vs ctl (partition effect) | ctl vs pre (archived) | verdict |")
print("| --- | --- | --- | --- | --- | --- | --- | --- |")
for (c,n) in sorted(data):
    cell=data[(c,n)]
    B=lambda a,k:[st.median(cell[(a,s,k)]) for s in "12345" if cell.get((a,s,k))]
    pre=B("pre","serial"); preold=B("pre","serialold")
    post=B("post",KERN);  ctl=B("ctl",KERN)
    if not (pre and post): continue
    lo,hi=boot(pre,post); pt=st.median(pre)/st.median(post)
    if preold:
        lo2,hi2=boot(preold,post); pt2=st.median(preold)/st.median(post)
        old=f"x{pt2:.2f} [{lo2:.2f},{hi2:.2f}]"
    else: old="—"
    if ctl:
        lo3,hi3=boot(ctl,post); pt3=st.median(ctl)/st.median(post)
        pc=f"x{pt3:.3f} [{lo3:.3f},{hi3:.3f}] {mark(lo3,hi3)}"
        lo4,hi4=boot(pre,ctl); pt4=st.median(pre)/st.median(ctl)
        cv=f"x{pt4:.2f} [{lo4:.2f},{hi4:.2f}] {mark(lo4,hi4)}"
    else: pc=cv="—"
    print(f"| `{c}` | {n:,} | {st.median(pre):.2f} | **x{pt:.3f} [{lo:.3f},{hi:.3f}]** | {old} | {pc} | {cv} | {mark(lo,hi)} |")
