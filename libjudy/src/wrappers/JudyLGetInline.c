/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyGet.c with -DJUDYGETINLINE: j__udy1Test / j__udyLGet (internal inline copy). */
#define JUDYL 1
#define JUDYGETINLINE 1
#include "../JudyCommon/JudyGet.c"
