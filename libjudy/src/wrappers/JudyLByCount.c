/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyByCount.c with -DNOSMARTJBB -DNOSMARTJBU -DNOSMARTJLB: Judy1ByCount / JudyLByCount. */
#define JUDYL 1
#define NOSMARTJBB 1
#define NOSMARTJBU 1
#define NOSMARTJLB 1
#include "../JudyCommon/JudyByCount.c"
