/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDY1 variant of JudyCommon/JudyCreateBranch.c: j__udyCreateBranch* (per-variant). */
#define JUDY1 1
#include "../JudyCommon/JudyCreateBranch.c"
