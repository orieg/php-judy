# Patches applied to the bundled libJudy

The sources under `libjudy/` were imported pristine from upstream
Judy-1.0.5 (see [README.md](README.md) for provenance). Every
modification since that import follows one discipline:

- **one patch = one commit**, diffable against the pristine import
  commit (`git log --follow libjudy/` finds it; the import commit
  touches only `libjudy/` and says "pristine" in its subject);
- **one entry in the table below** per patch, stating what changed,
  which files, why, when, and the tracking issue;
- **a per-file change notice** at the top of every modified file
  (a short comment: date + summary of the change), satisfying
  LGPL-2.1 §2(b)'s requirement that modified files carry prominent
  notices of the change and its date.

No reformatting, no drive-by cleanups: a diff against the pristine
import must show only deliberate, documented changes.

## Patches

| Patch | Files | Why | Date | Issue |
| ----- | ----- | --- | ---- | ----- |

_None yet — the tree is pristine Judy-1.0.5 (Stage 0 of the vendoring
plan, tracked in [#142](https://github.com/orieg/php-judy/issues/142))._
