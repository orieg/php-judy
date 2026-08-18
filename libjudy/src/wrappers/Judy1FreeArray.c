/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDY1 variant of JudyCommon/JudyFreeArray.c: Judy1FreeArray / JudyLFreeArray. */
#define JUDY1 1
#include "../JudyCommon/JudyFreeArray.c"
