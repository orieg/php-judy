/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDY1 variant of JudyCommon/JudyPrevNext.c with -DJUDYPREV: Judy1Prev / JudyLPrev. */
#define JUDY1 1
#define JUDYPREV 1
#include "../JudyCommon/JudyPrevNext.c"
