--TEST--
Judy::BITSET immediate-leaf cascade integrity (detects a miscompiled libJudy, issue #131)
--DESCRIPTION--
libJudy 1.0.5 declares the Judy1 immediate index array as 8 bytes
(JudyPrivateBranch.h, uint8_t j_pi_1Index[sizeof(Word_t)]) while every Judy1
immediate type needs 15 (Judy1.h, cJ1_IMMED1_MAXPOP1). JudyCascade.c copies up
to 15 bytes into it when a 2-byte leaf splays into per-sub-expanse immediates.
A compiler that exploits the out-of-bounds write truncates the copy, and the
keys past the eighth in each 9..15-member sub-expanse are silently lost: J1S
reports success, J1T then denies the key, iteration skips it, and the J1C
population cache over-reports. gcc does exploit it at -O3; clang does not.
Note the "iteration 8 invokes undefined behavior
[-Waggressive-loop-optimizations]" warning gcc emits is not the discriminator
-- gcc 15 emits it at -O2 as well, where the generated code is still correct
-- so only runtime behaviour distinguishes a usable library from a broken one,
which is why this needs to be a test rather than a build-log check.

This test drives that exact transition and asserts that count(), iteration and
isset() all agree. It fails against a libJudy built with gcc -O3 against stock
sources. It is a detection test for the system library, not a test of this
extension: a failure here means the installed libJudy is miscompiled and must
be rebuilt at -O2 (or with the index-array widening patch that Debian, Ubuntu
and Fedora carry). See https://github.com/orieg/php-judy/issues/131.
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php

/**
 * Assert that the three independent views of the population agree:
 * count() reads libJudy's population cache, iteration walks the tree, and
 * isset() does a point lookup per key. The miscompile makes the first
 * over-report while the other two under-report, so only comparing all three
 * against the keys we actually inserted catches it.
 */
function check(Judy $j, array $expected, string $label): void
{
    $count = count($j);

    $walked = [];
    foreach ($j as $k => $v) {
        $walked[$k] = true;
    }

    $hits = 0;
    foreach ($expected as $k => $_) {
        if (isset($j[$k])) {
            $hits++;
        }
    }

    $missing = count(array_diff_key($expected, $walked));
    $extra   = count(array_diff_key($walked, $expected));

    printf(
        "%s: inserted=%d count=%d walked=%d isset=%d missing=%d extra=%d\n",
        $label,
        count($expected),
        $count,
        count($walked),
        $hits,
        $missing,
        $extra
    );
}

// Phase 1 -- the cascade itself.
//
// A Judy1 2-byte leaf holds at most 128 keys; the 129th insertion splays it
// into one immediate per high byte. 15 keys per high byte is the maximum
// population of a 1-byte immediate, i.e. the 15-byte copy into the 8-byte
// field. Nine groups of 15 is 135 keys: just past the leaf maximum, and the
// smallest shape that reaches the transition.
$j = new Judy(Judy::BITSET);
$expected = [];
for ($h = 0; $h < 9; $h++) {
    for ($l = 0; $l < 15; $l++) {
        $key = ($h << 8) | $l;
        $j[$key] = true;
        $expected[$key] = true;
    }
}
check($j, $expected, 'cascade');

// Phase 2 -- more sub-expanses, so the splay runs repeatedly.
for ($h = 9; $h < 48; $h++) {
    for ($l = 0; $l < 15; $l++) {
        $key = ($h << 8) | $l;
        $j[$key] = true;
        $expected[$key] = true;
    }
}
check($j, $expected, 'wide');

// Phase 3 -- delete back below the immediate maximum, then re-insert.
// Deletion walks the decascade path, and re-filling re-runs the copy.
for ($h = 0; $h < 48; $h += 2) {
    for ($l = 8; $l < 15; $l++) {
        $key = ($h << 8) | $l;
        unset($j[$key]);
        unset($expected[$key]);
    }
}
check($j, $expected, 'after-unset');

for ($h = 0; $h < 48; $h += 2) {
    for ($l = 8; $l < 15; $l++) {
        $key = ($h << 8) | $l;
        $j[$key] = true;
        $expected[$key] = true;
    }
}
check($j, $expected, 'refilled');

// Phase 4 -- the same transition one level deeper in the tree, so the leaf
// being splayed sits under a branch rather than directly under the root.
$deep = new Judy(Judy::BITSET);
$deepExpected = [];
for ($b = 1; $b <= 3; $b++) {
    for ($h = 0; $h < 12; $h++) {
        for ($l = 0; $l < 15; $l++) {
            $key = ($b << 20) | ($h << 8) | $l;
            $deep[$key] = true;
            $deepExpected[$key] = true;
        }
    }
}
check($deep, $deepExpected, 'deep');

echo "Done\n";
?>
--EXPECT--
cascade: inserted=135 count=135 walked=135 isset=135 missing=0 extra=0
wide: inserted=720 count=720 walked=720 isset=720 missing=0 extra=0
after-unset: inserted=552 count=552 walked=552 isset=552 missing=0 extra=0
refilled: inserted=720 count=720 walked=720 isset=720 missing=0 extra=0
deep: inserted=540 count=540 walked=540 isset=540 missing=0 extra=0
Done
