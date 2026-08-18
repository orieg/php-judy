/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyPrevNext.c with -DJUDYNEXT: Judy1Next / JudyLNext. */
#define JUDYL 1
#define JUDYNEXT 1
#include "../JudyCommon/JudyPrevNext.c"
