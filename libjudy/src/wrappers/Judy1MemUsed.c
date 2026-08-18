/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDY1 variant of JudyCommon/JudyMemUsed.c: Judy1MemUsed / JudyLMemUsed. */
#define JUDY1 1
#include "../JudyCommon/JudyMemUsed.c"
