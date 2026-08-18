/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDYL variant of JudyCommon/JudyFirst.c: Judy1First/Last/... / JudyLFirst/Last/.... */
#define JUDYL 1
#include "../JudyCommon/JudyFirst.c"
