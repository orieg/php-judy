/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyMallocIF.c: j__udy*Alloc/Free memory interface (per-variant). */
#define JUDYL 1
#include "../JudyCommon/JudyMallocIF.c"
