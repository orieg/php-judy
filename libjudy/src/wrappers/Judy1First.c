/* php-judy build shim -- NOT an upstream Judy-1.0.5 file (see libjudy/PATCHES.md).
 * Compiles the JUDY1 variant of JudyCommon/JudyFirst.c: Judy1First/Last/... / JudyLFirst/Last/.... */
#define JUDY1 1
#include "../JudyCommon/JudyFirst.c"
