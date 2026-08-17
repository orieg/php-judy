# Step-0 feasibility spike: shared-memory Judy arena (issue #83)

Canonical record for the five Step-0 gates. Feasibility investigation only —
nothing here is a committed feature, and none of this code ships (the spike is
deliberately absent from `package.xml` and touches no extension source).

**Overall verdict: `PROCEED_WITH_AMENDMENT` — but the amendments are large
enough that the honest framing is "this is not a feature, it is a subsystem".**
All five gates are individually survivable on Linux. The conjunction is what
hurts. See [§8](#8-recommendation).

---

## 1. How to reproduce

```sh
cd research/shm-arena
make            # builds gate1..gate5
make run        # runs all five
make asan       # gate 1 under ASan + UBSan
```

Linux validation (this spike was developed on macOS; Linux is the real target):

```sh
docker run --rm -v "$PWD":/spike -w /spike debian:bookworm-slim bash -c '
  apt-get update -qq && apt-get install -y -qq gcc make libjudy-dev valgrind
  mkdir -p /build && cp shm_arena.* gate*.c Makefile /build/ && cd /build
  make && make run'
```

| File | Purpose |
| --- | --- |
| `shm_arena.{h,c}` | MAP_SHARED arena + `JudyMalloc`/`JudyFree` override + instrumentation |
| `gate1_correctness.c` | allocator override correctness (Judy1/JudyL/JudySL) |
| `gate2_fork.c` | address stability across fork, MAP_FIXED, ASLR |
| `gate3_lock.c` | pshared/robust lock availability, cost, holder death |
| `gate4_profile.c` | allocation histogram, fragmentation, bump vs freelist |
| `gate5_crash.c` | writer-death corruption rate, arena leak, rebuild cost |

## 2. Environment and benchmark hygiene (read before trusting any timing)

- **macOS**: Darwin 25.4.0, Apple M1, 8 cores, 16 GB. libJudy 1.0.5 (Homebrew).
- **Linux**: Debian bookworm, glibc 2.36, aarch64, in Docker. **The Docker VM
  reports only 4 CPUs**, so the Linux 8-process contention column is
  oversubscribed and its absolute values are not comparable to macOS.
- **Load was NOT clean.** Load average ranged 3.3–5.9 on an 8-core machine
  during the timing runs; the standing threshold is cores/2 = 4.0. Chrome and
  WebKit helpers were consuming 25–88% CPU throughout. **Every timing number
  below is CONTAMINATED and is reported as an upper bound.**
- Mitigation used: each configuration is run 15x (uncontended) or 5x
  (contended) and the **minimum** is reported as primary. Interference can only
  add time, never remove it, so the min is the robust estimator under
  contamination. The median was visibly corrupted — in the first Gate 3 run the
  median showed `rwlock rdlock` (73.7 ns) as *faster* than no lock at all
  (99.0 ns), which is impossible; the min-based ordering was coherent. Spread
  (min/median/max) is reported so the noise is visible.
- **Structural findings (symbol interposition, capability probes, corruption
  counts, allocation histograms) are NOT timing-sensitive and are unaffected.**
  Judy's allocation counts were byte-identical on macOS and Linux, which is a
  useful cross-check that the workload is deterministic.

## 3. Premises verified before building anything

Both premises in the issue were checked empirically rather than assumed. One of
them is materially wrong on macOS.

### 3.1 `JudyMalloc`/`JudyFree` are interposable — platform-dependent

`nm -gU` confirms `_JudyMalloc`/`_JudyFree` are exported (`T`) in both the
Homebrew dylib and static archive, and every internal caller
(`JudyLMallocIF.o`, `Judy1MallocIF.o`, `JudySL.o`, `JudyHS.o`) references them
as undefined (`U`). But exported != interposable:

| Link target | Override honored? | Evidence (measured) |
| --- | --- | --- |
| Linux, stock shared `libJudy.so` | **YES** | 57,711 `JudyMalloc` calls captured |
| Linux, static `libJudy.a` | **YES** | 57,711 calls captured |
| macOS, static `libJudy.a` | **YES** | 57,711 calls captured |
| macOS, `libJudy.dylib` | **NO** | **0 calls** — override silently ignored |
| macOS, `libJudy.dylib` + `-Wl,-flat_namespace` | **NO** | **0 calls** |

**This is a material finding, not a footnote.** macOS's two-level namespace
binds libJudy's internal calls to its own `JudyMalloc` at build time.
`-flat_namespace` does not rescue it, and `DYLD_INSERT_LIBRARIES` is restricted
under SIP. php-judy's `config.m4` uses `PHP_ADD_LIBRARY_WITH_PATH(Judy, ...)`
-> `-lJudy`, which resolves to the **dylib** on macOS. So a shm-arena build on
macOS would require linking `libJudy.a` explicitly or vendoring/rebuilding
libJudy — a build-system change, not a code change.

The silent-failure mode is the dangerous part: the override compiles and links
cleanly and simply never fires. Any implementation must assert at MINIT that
its allocator is actually being called, or macOS builds will quietly use the
process heap while believing they are in shared memory.

### 3.2 Alignment contract — 8 bytes, low 3 bits clear

Determined from Judy-1.0.5 source (tarball sha256
`d2704089f85fdb6f2cd7e77be21170ced4b4375c03ef1ad4cf1075bd414a63eb`,
byte-identical to the Homebrew formula's pin), not guessed:

- `src/JudyCommon/JudyMallocIF.c:140-147` — `MALLOCBITS_VALUE 0x3`,
  `MALLOCBITS_MASK 0x7`, and `assert(((Word_t)(Addr) & MALLOCBITS_MASK) == 0)`
  before OR-ing the tag bits in.
- `src/JudyCommon/JudyPrivate.h:365-368` — the low bits are reserved so Judy
  objects "come from different `malloc()` namespaces".

So **every returned address must have its low 3 bits clear**. Note `MALLOCBITS`
is only active in DEBUG builds, so a misaligned arena would corrupt trees
*silently* in a release libJudy. The arena enforces alignment unconditionally
and aborts on violation (`shm_arena.c`). Stock `JudyMalloc` is a bare `malloc()`
returning byte counts of `Words * sizeof(Word_t)` — no cache-line alignment is
required (the 128-byte constant in `JudyPrivate.h` is a performance target for
node *sizing*, not an allocator constraint).

---

## 4. Gate-by-gate results

### Gate 1 — `JudyMalloc` override with a shm arena -> **PASS_with_caveats**

Caveat is entirely §3.1 (macOS dylib cannot be overridden).

Workload: 200k-key JudyL (dense and sparse), 200k-key Judy1, 50k-string JudySL,
each with insert -> ordered forward/reverse iterate -> delete half -> verify ->
re-insert -> verify -> free-array.

| Check | Result (measured) |
| --- | --- |
| `JudyMalloc` / `JudyFree` calls | 535,191 / 535,191 — **identical on macOS and Linux** |
| Overlapping live blocks | **0** (word-granular shadow map over the whole arena) |
| Double / unknown frees | **0** |
| **Alloc/free size mismatches** | **0** |
| Live bytes after all `*FreeArray` | **0** (no leak) |
| Ordered iteration vs reference model | exact match, forward and reverse |
| ASan + UBSan | clean |
| valgrind memcheck (Linux) | **0 errors, 0 bytes definitely/indirectly/possibly lost** |

**The load-bearing result is "alloc/free size mismatches = 0".** Judy always
frees a block with exactly the word count it allocated it with. That invariant
is what makes Gate 4's exact-size-class freelist safe; without it the freelist
design collapses. It is verified, not assumed.

### Gate 2 — address stability under fork -> **PASS_with_caveats**

| Test | macOS | Linux |
| --- | --- | --- |
| 2a. 8 children see identical arena base | YES | YES |
| 2a. Children fully traverse the pre-fork tree | OK | OK |
| 2b. Child's insert visible to parent (MAP_SHARED write-through) | YES | YES |
| 2c. `MAP_FIXED` at a reserved address | EXACT | EXACT |
| 2c. Child inherits fixed mapping at same address | YES | YES |

**Caveat — ASLR across master restart (measured).** The arena base differs on
every independent run of the same binary:

- macOS: `0x1058d8000`, `0x105794000`, `0x105180000`, `0x107868000`,
  `0x1089e8000`, `0x1072c8000` (6/6 distinct)
- Linux: `0xffff78410000`, `0xffff7d160000`, `0xffff783f0000`,
  `0xffff7cb80000`, `0xffffb2f40000` (5/5 distinct)

**Implication:** absolute pointers are valid only within one master's fork tree.
A graceful restart / `reload` starts a new master at a new address, so a
surviving segment is unreadable — the cache is necessarily **cold on every
reload**, unless `MAP_FIXED` pins a hardcoded address.

`MAP_FIXED` works here, but as a production strategy it is a liability:
`MAP_FIXED` **silently unmaps whatever already occupies the range**, so a
hardcoded address that collides with ASLR-placed libraries, the heap, or a JIT
region corrupts the process. `MAP_FIXED_NOREPLACE` (Linux 4.17+) fails safely
instead and would be mandatory; macOS has no equivalent. Also note non-fork
worker models (Windows, some Octane drivers) are unsupported regardless — the
issue already scoped that out, and this spike confirms it is unavoidable rather
than an implementation shortcut.

### Gate 3 — process-shared robust rwlock -> **macOS: FAIL / Linux: PASS_with_caveats**

**3a. Capability probe (measured, structural):**

| Primitive | macOS | Linux (glibc) |
| --- | --- | --- |
| `pthread_rwlockattr_setpshared` | OK | OK |
| `pthread_mutexattr_setpshared` | OK | OK |
| `pthread_mutexattr_setrobust` | **ABSENT** | **OK** |
| robust *rwlock* | **does not exist in POSIX on any platform** | same |

Two traps worth recording:

1. **Robust rwlocks do not exist anywhere.** POSIX defines robustness only for
   mutexes. A design that wants both reader parallelism *and* death recovery
   cannot get them from one primitive.
2. **Detecting robust support by feature macro is wrong.** glibc declares
   `PTHREAD_MUTEX_ROBUST` as an **enum constant, not a macro**, so
   `#if defined(PTHREAD_MUTEX_ROBUST)` is FALSE on Linux even though robust
   mutexes are fully supported. This spike hit that bug and initially recorded a
   false "Linux has no robust mutexes" result. Detect by C library (`__GLIBC__`)
   or a configure-time link test.

**3b. Uncontended per-op cost** — min of 15 reps, ns per JudyL lookup.
CONTAMINATED (see §2); Linux ran in a quieter container and is the more
trustworthy column.

| Mode | macOS min | macOS lock cost | Linux min | Linux lock cost |
| --- | --- | --- | --- | --- |
| no lock (unsafe baseline) | 34.98 ns | — | 45.40 ns | — |
| pshared rwlock rdlock | 39.26 ns | +4.28 ns | 104.47 ns | **+59.07 ns** |
| pshared mutex lock | 50.97 ns | +15.99 ns | 106.73 ns | +61.33 ns |
| seqlock optimistic read | 30.80 ns | -4.18 ns (noise) | 46.59 ns | **+1.19 ns** |

On Linux a pshared rwlock **more than doubles** the cost of a Judy read.

**3c. N-process contention, read-dominated (99% read / 1% write)** — best-of-5
aggregate ns/op. Lower is better. macOS, 8 real cores:

| Mode | 1p | 2p | 4p | 8p |
| --- | --- | --- | --- | --- |
| no lock (unsafe baseline) | 54.5 | 21.2 | 11.1 | **9.3** |
| pshared rwlock rdlock | 59.9 | 135.7 | 227.4 | **373.5** |
| pshared mutex lock | 67.3 | 143.7 | 468.4 | 332.4 |
| seqlock optimistic read | 91.1 | 25.6 | 14.7 | **14.7** |

**The rwlock is the wrong primitive for a read-dominated cache.** It *degrades*
6.2x from 1->8 processes while the unlocked baseline *improves* 5.9x (real
parallelism). At 8 processes the rwlock is **25x slower than the seqlock**
(373.5 vs 14.7 ns). Cause is not subtle: `rdlock` performs an atomic
read-modify-write on a shared reader-count cache line, so every reader
invalidates every other reader's cache line. Linux shows the same shape
(79.0 -> 358.0 ns for rwlock; 37.2 -> 14.8 ns for seqlock).

**3d. Killing a lock holder mid-critical-section (measured):**

- **macOS**: survivor's `pthread_mutex_lock()` **hung forever** (killed by a 3 s
  alarm); `trylock` returned EBUSY. **One worker dying inside a write critical
  section permanently deadlocks the entire pool.** There is no recovery API.
- **Linux**: survivor got `EOWNERDEAD`, and `pthread_mutex_consistent()`
  succeeded — **the lock recovers**.

**The critical caveat on the Linux PASS: `EOWNERDEAD` recovers the *lock*, not
the *data*.** `pthread_mutex_consistent()` is a promise the application makes
that the protected state is sane. After a writer dies inside `JudyLIns`, it is
not — see Gate 5. Robust mutexes convert a deadlock into a *corrupt but
running* system, which is an improvement only if there is a real recovery plan.

**A seqlock is NOT a drop-in fix.** The 3b/3c seqlock numbers measure lock
*overhead* honestly, but the benchmark's writers only mutate a value in place —
they never restructure the tree. A seqlock cannot protect a pointer-chasing
structure whose nodes are being freed and reused: an optimistic reader can
dereference a node the writer has already freed and the arena has already
recycled, and the version re-check happens *after* the fault. Gate 5's reader
crashes are exactly this failure class. Using seqlock reads therefore requires
**epoch-based / RCU-style deferred reclamation** so freed nodes are not reused
while any reader may still hold a pointer — which in turn constrains the Gate 4
freelist (frees must be quarantined, not immediately reusable).

**Portable fallback**, if robust mutexes are unavailable (macOS): `flock`/`fcntl`
advisory locks are released by the kernel on process death on both platforms.
Cost is a syscall per operation rather than a userspace atomic — roughly two
orders of magnitude worse than the numbers above, which is untenable on a cache
read path. A lease/heartbeat scheme avoids the syscall but adds a liveness
timeout during which the cache is stalled or bypassed.

### Gate 4 — arena management and fragmentation -> **PASS** (freelist) / **FAIL** (bump-only)

This is the gate the design most clearly survives, and the results are
**byte-identical on macOS and Linux** (Judy's allocation behavior is
deterministic).

**4a. Real allocation profile at 1M keys (measured):**

| | dense/sequential | sparse/random |
| --- | --- | --- |
| `JudyMalloc` calls | 160,170 | 395,226 |
| `JudyFree` calls | 140,619 | 329,433 |
| **churn ratio (free/alloc)** | **0.878** | **0.834** |
| arena footprint | 8.0 MB | 25.6 MB |
| live bytes | 7.9 MB | 16.8 MB |
| distinct size classes | **12** | **12** |
| largest allocation | 512 words (4096 B) | 512 words (4096 B) |

Two structurally important facts:

- **Only 12 distinct size classes exist, capped at 4096 B** (= `jbu_t`, a
  256-way uncompressed branch). This is a small, bounded, static set — exactly
  the shape that makes exact-size-class freelists cheap and removes any need for
  splitting, coalescing, or best-fit search.
- **Churn is ~85% even during pure insertion.** Judy constantly frees and
  reallocates nodes as leaves grow and branches get promoted. Delete-heavy TTL
  traffic is not the only source of churn — *insert-only* workloads already
  free ~0.85 blocks per allocation. Any "we mostly just append" reasoning is
  wrong.

**4b/4c. Bump-only vs size-class freelist under TTL churn (measured):**

Build 500k keys, then 20 rounds x (evict 25k + insert 25k), live population flat:

| Arena mode | allocs | reused | footprint | live | waste |
| --- | --- | --- | --- | --- | --- |
| size-class freelist | 656,321 | 546,453 | 11.8 MB | 9.0 MB | **1.31x** |
| bump-only (no reuse) | 656,321 | 0 | 67.4 MB | 9.0 MB | **7.45x** |

Sustained churn, live population constant at 4.1 MB:

| Arena mode | 10 rounds | 40 rounds | 160 rounds |
| --- | --- | --- | --- |
| size-class freelist | 5.2 MB | 5.2 MB | **5.2 MB (flat)** |
| bump-only | 12.1 MB | 21.4 MB | **58.5 MB (linear growth)** |

**A bump-only arena is fatal, quantified: it grows ~0.31 MB per churn round
without bound while the live set stays flat.** For a TTL cache that is an
unbounded leak terminating in segment exhaustion. The size-class freelist
reaches a **flat steady state** and is the only viable design.

**Derived fragmentation bound (projected, from the measured histogram):** with
exact-size-class freelists and no splitting or coalescing, the arena's
high-water mark converges to `sum_c peak_live_blocks[c] * size[c]` — the sum of
per-class peaks. Measured steady-state waste is **1.27–1.31x live**, consistent
with that bound. **The residual risk is class stranding**: blocks freed in class
A can only ever be reused by class A, so a workload whose *shape* shifts (e.g.
key-length distribution changes, shifting Judy from one node type to another)
strands the old class's peak permanently. The bound is a sum of peaks, not a
peak of sums. For a cache with a stable key distribution this is fine; it should
be monitored rather than assumed, and a segment-level reset is the escape hatch.

### Gate 5 — crash consistency during writer death -> **FAIL as an unmitigated design; tractable only with an explicit recovery strategy**

This gate was measured rather than argued, because the central claim ("a writer
dying mid-`JudyLIns` corrupts the shared tree") is testable.

**5a. Arena leak per killed writer (measured, 300k inserts):**

| Allocations in one `JudyLIns` | Share |
| --- | --- |
| 0 | 42.17% |
| 1 | 57.70% |
| 2 | 0.05% |
| >=7 | 0.09% |

Worst observed single insert: **17 allocations, 4184 bytes**. A writer killed
mid-insert strands up to that much in the arena — allocated, not yet linked into
the tree, therefore unreachable and unfreeable. **There is no process teardown
to reclaim a shared arena**, so this leak is permanent for the segment's
lifetime and accumulates across every writer death.

**5b. Writer SIGKILLed at a random point mid-insert; a separate reader process
then traverses the shared tree. 40 trials per platform:**

| Outcome | macOS (run 1) | macOS (run 2) | Linux |
| --- | --- | --- | --- |
| traversable | 37 | 35 | 31 |
| inconsistent (count/order wrong) | 2 | 2 | 3 |
| **reader process crashed/hung** | 1 | 3 | **6** |
| traversable but lost/partial update | 0 | 5 | 6 |

Corruption rate (inconsistent + crashed), with Wilson 95% CIs:

- macOS: 3/40 = 7.5%, CI **[2.6%, 19.9%]**
- Linux: 9/40 = 22.5%, CI **[12.3%, 37.5%]**
- Pooled (12/80): 15.0%, CI **[8.8%, 24.4%]**

**The CI lower bound is well clear of zero on both platforms: writer death
corrupts the shared tree, this is established rather than suspected.** And the
worst outcome is not silent wrongness — on Linux **6/40 (15%, CI [7.1%, 29.1%])
of writer deaths caused an *unrelated reader process to crash*.** In FPM terms:
one worker hitting a fatal error inside a write takes healthy workers down with
it, and they will keep dying on every subsequent read until the segment is
replaced. This is a cascading-failure mode, not a degraded-cache mode.

Note that "traversable" is a weak pass. It only means a forward iteration
completed in order; it does not prove the tree is structurally sound, and the
"lost/partial update" column shows entries silently missing even then.

**5c. Recovery cost — discard the segment and rebuild (measured, contaminated,
upper bound):**

| Keys | min | median | max |
| --- | --- | --- | --- |
| 100,000 | 0.005 s | 0.006 s | 0.007 s |
| 1,000,000 | 0.156 s | 0.182 s | 0.208 s |

**This is the number that makes Gate 5 survivable.** A cache is reconstructible
by definition, and a full 1M-key rebuild costs ~0.16 s. "Poison the segment and
start empty" is a legitimate recovery strategy here in a way it never would be
for a database.

**Options, with costs:**

| Strategy | Cost | Verdict |
| --- | --- | --- |
| Generation counter + rebuild on corruption | cannot *detect* structural corruption cheaply; a reader discovers it by faulting | **insufficient alone** — detection is the hard part, not rebuild |
| Write-ahead journal | every mutation writes twice; replay on recovery; must journal allocator state too | disproportionate for a cache |
| **Single-writer-process design** | all mutations serialized through one owner; readers never observe a partial write from a dead peer; writer death is contained | **strongest**, but changes the architecture — no longer "an extension calls JudyLIns" |
| Copy-on-write double-buffer | 2x memory; publish via one atomic root swap; readers always see a complete tree | clean, viable for read-mostly; write amplification is severe for per-key TTL updates |
| **Accept corruption + poison + nuke the segment** | one flag check per operation; **~0.16 s to refill 1M keys**; every writer death flushes the whole cache | **most practical** given 5c |

**The design would have to adopt segment poisoning + rebuild, combined with
robust-mutex `EOWNERDEAD` detection (Linux) to know a death occurred at all.**
Concretely: a writer sets an "in-flight mutation" flag in the shared header
before touching the tree and clears it after; any process that acquires the lock
with `EOWNERDEAD` while that flag is set marks the entire segment poisoned;
every worker checks the poison flag and rebuilds/bypasses. This bounds the
damage but means **any writer death flushes the entire cache** — an availability
characteristic that must be stated up front, because PHP fatal errors, OOM
kills, and `request_terminate_timeout` kills are routine in FPM, not
exceptional.

Even this does not recover the 5a arena leak; poisoning discards the whole
segment, which is the only thing that reclaims it.

---

## 5. Untested / out of scope

Reported as untested, not as passing:

- **Real PHP/FPM integration.** Everything here is standalone C. Whether
  ZTS/NTS PHP, opcache, or the FPM master's own `fork` timing changes the
  picture is unmeasured. In particular the arena must be created at MINIT
  *before* the master forks, which was not exercised.
- **x86-64.** All Linux testing was aarch64 (Docker on M1). Memory-ordering
  behavior around the seqlock differs materially on x86-64 (TSO) vs aarch64
  (weak) — aarch64 is the *stricter* test for correctness, but the *timing*
  will differ.
- **8-process contention on Linux.** The Docker VM had only 4 CPUs, so the 8p
  Linux column is oversubscribed.
- **Epoch/RCU reclamation.** Identified as required for safe seqlock reads
  (Gate 3); not prototyped. Its interaction with the Gate 4 freelist
  (quarantined frees inflating the fragmentation bound) is **underived**.
- **Contended write-heavy workloads.** Benchmarks used 1% writes. TTL-heavy
  caches may write far more.
- **Class stranding under distribution shift** (Gate 4) — bound derived, not
  measured.
- **`MAP_FIXED_NOREPLACE`** and hugepage interaction — not tested.
- **Musl libc** (Alpine) — robust-mutex support not verified.

## 6. What would kill this feature

Ranked by how likely they are to be fatal:

1. **The cascading reader crash (Gate 5b).** 15% of writer deaths on Linux
   killed an unrelated reader. A cache whose failure mode is "takes down healthy
   workers" is worse than no cache. This is only acceptable with segment
   poisoning, and poisoning means a full cache flush on every writer death.
2. **macOS is a dead end for the locking story (Gate 3d).** No robust mutexes;
   a killed holder wedges the pool permanently. Combined with §3.1 (dylib
   override impossible), macOS would be a non-functional or badly degraded
   development platform for a Linux-only production feature. That is a real
   maintenance and contributor-experience cost for this repo.
3. **The read path cannot use the obvious primitive (Gate 3b/3c).** A pshared
   rwlock costs +59 ns/read and is 25x slower than the alternative at 8
   processes. The alternative (seqlock) needs epoch reclamation, which is a
   substantial subsystem in its own right and constrains the allocator.
4. **Cold cache on every reload (Gate 2).** ASLR moves the mapping on every
   master restart. `MAP_FIXED` at a hardcoded address is the only fix and
   carries its own corruption risk.

Not on this list, notably: **the allocator itself.** Gates 1 and 4 came out
clean and the arena design is sound and bounded. The problems are all in
concurrency and failure semantics.

## 7. Gate summary

| Gate | Verdict | Single most important measured number |
| --- | --- | --- |
| 1. `JudyMalloc` override | **PASS_with_caveats** | 535,191 allocs, **0** size mismatches / overlaps / leaks; but **0 calls captured** against macOS dylib |
| 2. Address stability | **PASS_with_caveats** | 8/8 children identical base; **6/6 runs differ** across restart (ASLR) |
| 3. Robust pshared lock | **Linux PASS_with_caveats / macOS FAIL** | rwlock **373.5 ns** vs seqlock **14.7 ns** at 8p; macOS holder death = **permanent deadlock** |
| 4. Arena management | **PASS** (freelist) / **FAIL** (bump) | freelist **5.2 MB flat** vs bump **58.5 MB and growing**, same 4.1 MB live |
| 5. Crash consistency | **FAIL unmitigated** | corruption **15%** pooled, CI **[8.8%, 24.4%]**; **15%** of Linux kills crashed a reader; rebuild **0.156 s / 1M keys** |

## 8. Recommendation

**`PROCEED_WITH_AMENDMENT`**, conditional on accepting all of the following. If
any is unacceptable, the correct answer is REJECT.

1. **Linux-only feature.** macOS gets a compile-time-disabled stub. Requires
   `__GLIBC__` robust mutexes (verify musl separately). macOS developers cannot
   test this code path — accept that, or vendor libJudy to at least restore the
   allocator override for local testing.
2. **Size-class freelist arena, never bump-only.** Non-negotiable per Gate 4.
3. **No pshared rwlock on the read path.** Either accept +59 ns/read and 25x
   contention degradation, or build epoch-based reclamation to make optimistic
   reads safe. **Prototype the epoch reclaimer before committing** — it is the
   largest unquantified risk remaining.
4. **Segment poisoning + rebuild-on-writer-death**, with the availability
   characteristic stated in the docs: *any* worker dying mid-write flushes the
   entire cache. Gate 5c shows this costs ~0.16 s per 1M keys, which is
   acceptable; the surprise factor for users is not, so it must be documented,
   not discovered.
5. **Assert at MINIT that the arena allocator is actually installed**, because
   the macOS failure mode is silent (§3.1).
6. **Cold cache on reload** is documented as expected behavior, or
   `MAP_FIXED_NOREPLACE` is adopted with a collision-failure path.

**Strongly consider the single-writer amendment instead.** Routing all mutations
through one owner process collapses Gates 3 and 5 simultaneously: no
multi-writer contention, no partial writes from a dead peer, no robust-mutex
dependency, and the read path can be a genuine seqlock because the single writer
can drive epoch reclamation itself. It costs an IPC hop per write. Given that
Gates 3 and 5 are where all the risk lives, and Gates 1 and 4 (the parts unique
to Judy) are clean, this is the variant most likely to actually ship.

**The blunt version:** the Judy-specific parts of this idea work. The allocator
hook is real, the arena is bounded and well-behaved, and ordered prefix
invalidation remains a genuine advantage APCu cannot match. What the spike
found is that the hard part was never Judy — it is that putting a
pointer-rich mutable structure in shared memory across processes that can be
`kill -9`'d at any instant requires a concurrency and failure-recovery
subsystem (robust locking + epoch reclamation + poison/rebuild) that is larger
than the feature itself. Scope it as that subsystem, or scope it down to a
single writer.

**Per the issue's own framing, API design, judy-cache integration, and APCu
benchmarks remain out of scope** — gates 3 and 5 did not pass cleanly enough to
open them.

---

*All verdicts are from this spike's own measurements on one machine, under
acknowledged load contamination for timing figures. No independent review has
been performed.*
