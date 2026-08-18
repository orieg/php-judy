/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyMemUsed.c: Judy1MemUsed / JudyLMemUsed. */
#define JUDYL 1
#include "../JudyCommon/JudyMemUsed.c"
