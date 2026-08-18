/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyMemActive.c: Judy1MemActive / JudyLMemActive. */
#define JUDYL 1
#include "../JudyCommon/JudyMemActive.c"
