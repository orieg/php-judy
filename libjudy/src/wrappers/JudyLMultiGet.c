/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyMultiGet.c: JudyLMultiGet (patch O5). */
#define JUDYL 1
#include "../JudyCommon/JudyMultiGet.c"
