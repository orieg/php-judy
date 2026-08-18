/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyDel.c: Judy1Unset / JudyLDel. */
#define JUDYL 1
#include "../JudyCommon/JudyDel.c"
