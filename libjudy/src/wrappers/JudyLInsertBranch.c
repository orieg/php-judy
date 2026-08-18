/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyInsertBranch.c: j__udyInsertBranch (per-variant). */
#define JUDYL 1
#include "../JudyCommon/JudyInsertBranch.c"
