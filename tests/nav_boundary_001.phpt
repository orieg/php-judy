--TEST--
Navigation boundaries: searchNext() exclusivity on int-keyed, first()/searchNext() empty-string bounds
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/* Pins, all verified against the current binary:
 * 1. searchNext() is EXCLUSIVE on int-keyed types — passing a PRESENT key
 *    returns the next key, never the same one; first() is INCLUSIVE (>=).
 * 2. Not-found everywhere (past-the-end key, empty array) returns NULL —
 *    not false — from both first() and searchNext().
 * 3. first("") / searchNext("") on a string-keyed array: "" is simply the
 *    smallest key — first() is inclusive of it, searchNext() is exclusive,
 *    and an absent "" behaves like any other absent bound.
 *
 * NOT pinned (recorded as a follow-up issue; no gate decides it in-sprint):
 * the last("") -> "b" vs prev("") -> NULL divergence on the same array —
 * this test deliberately touches neither last() nor prev() with "".
 * Also NOT pinned: string bounds on int-keyed navigation (silent coercion —
 * bug N1, Step 3.8). */

$i = new Judy(Judy::INT_TO_INT);
$i[10] = 1;
$i[20] = 2;
$i[30] = 3;
printf("int keys [10,20,30]: first(10)=%s first(15)=%s first(99)=%s searchNext(10)=%s searchNext(15)=%s searchNext(30)=%s searchNext(99)=%s\n",
    json_encode($i->first(10)), json_encode($i->first(15)),
    json_encode($i->first(99)), json_encode($i->searchNext(10)),
    json_encode($i->searchNext(15)), json_encode($i->searchNext(30)),
    json_encode($i->searchNext(99)));

$empty = new Judy(Judy::INT_TO_INT);
printf("int empty: first(0)=%s searchNext(0)=%s\n",
    json_encode($empty->first(0)), json_encode($empty->searchNext(0)));

$t = new Judy(Judy::STRING_TO_INT);
$t['a'] = 1;
$t['b'] = 2;
printf('str keys ["a","b"], "" absent: first("")=%s searchNext("")=%s' . "\n",
    json_encode($t->first('')), json_encode($t->searchNext('')));

$t[''] = 9;
printf('str keys ["","a","b"], "" present: first("")=%s searchNext("")=%s' . "\n",
    json_encode($t->first('')), json_encode($t->searchNext('')));

$te = new Judy(Judy::STRING_TO_INT);
printf('str empty: first("")=%s searchNext("")=%s' . "\n",
    json_encode($te->first('')), json_encode($te->searchNext('')));

echo "done\n";
?>
--EXPECT--
int keys [10,20,30]: first(10)=10 first(15)=20 first(99)=null searchNext(10)=20 searchNext(15)=20 searchNext(30)=null searchNext(99)=null
int empty: first(0)=null searchNext(0)=null
str keys ["a","b"], "" absent: first("")="a" searchNext("")="a"
str keys ["","a","b"], "" present: first("")="" searchNext("")="a"
str empty: first("")=null searchNext("")=null
done
