/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyPrevNextEmpty.c with -DJUDYPREV: Judy1PrevEmpty / JudyLPrevEmpty. */
#define JUDYL 1
#define JUDYPREV 1
#include "../JudyCommon/JudyPrevNextEmpty.c"
