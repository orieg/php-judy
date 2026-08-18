/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyFreeArray.c: Judy1FreeArray / JudyLFreeArray. */
#define JUDYL 1
#include "../JudyCommon/JudyFreeArray.c"
