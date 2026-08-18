--TEST--
Judy ArrayAccess offsetSet()/offsetUnset() return nothing, as their void declaration requires
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
try { new Judy(Judy::INT_TO_MIXED); } catch (Exception $e) { print "skip MIXED types not supported"; }
?>
--FILE--
<?php
/*
Invariant: Judy implements ArrayAccess, so offsetSet() and offsetUnset() are
declared `void` and must write nothing to return_value.

Both used to RETURN_TRUE/RETURN_FALSE on the helper's SUCCESS/FAILURE under an
IS_VOID arginfo. On a release build the declared type is not enforced, so the
bool leaked to callers; on an --enable-debug build the same call trips
zend_verify_internal_return_type(). The bool was not merely undeclared but
wrong: judy_object_unset_dimension_helper() reports SUCCESS for a no-op delete
of an absent key and FAILURE only when neither backing array is allocated yet,
so offsetUnset() read as false on a fresh Judy and true on a populated one --
internal allocation state, not whether anything was unset.

Nothing is lost by dropping it: every failure the helpers can report has
already thrown (bad key type, NUL in key, over-long key, keyless append on a
string-keyed array) or is an allocation failure userland cannot act on. The
$j[$k] / unset($j[$k]) object handlers have always discarded it the same way.
*/

$types = [
    'BITSET'                   => [Judy::BITSET,                   0,     true],
    'INT_TO_INT'               => [Judy::INT_TO_INT,               1,     42],
    'INT_TO_MIXED'             => [Judy::INT_TO_MIXED,             1,     'v'],
    'INT_TO_PACKED'            => [Judy::INT_TO_PACKED,            1,     1.5],
    'STRING_TO_INT'            => [Judy::STRING_TO_INT,            'k',   42],
    'STRING_TO_MIXED'          => [Judy::STRING_TO_MIXED,          'k',   'v'],
    'STRING_TO_INT_HASH'       => [Judy::STRING_TO_INT_HASH,       'k',   42],
    'STRING_TO_MIXED_HASH'     => [Judy::STRING_TO_MIXED_HASH,     'k',   'v'],
    'STRING_TO_INT_ADAPTIVE'   => [Judy::STRING_TO_INT_ADAPTIVE,   'k',   42],
    'STRING_TO_MIXED_ADAPTIVE' => [Judy::STRING_TO_MIXED_ADAPTIVE, 'k',   'v'],
];

// 1. The declared signature is the interface's, not merely "some void".
echo "== signature ==\n";
foreach (['offsetSet', 'offsetUnset'] as $m) {
    $judy = (string) (new ReflectionMethod('Judy', $m))->getReturnType();
    // ArrayAccess declares its return types tentatively, so the interface side
    // is only visible through getTentativeReturnType().
    $r = new ReflectionMethod('ArrayAccess', $m);
    $iface = (string) ($r->getTentativeReturnType() ?? $r->getReturnType());
    var_dump($judy === 'void', $judy === $iface);
}

// 2. Both return null, and keep returning null once a value is actually
//    returned by the helper's every branch: unallocated array, absent key,
//    present key, overwrite.
foreach ($types as $name => [$type, $key, $value]) {
    echo "== $name ==\n";

    // Fresh instance: neither backing array is allocated. This is the case
    // that used to evaluate to false while every later one evaluated to true.
    $j = new Judy($type);
    var_dump($j->offsetUnset($key) === null);

    // Insert, overwrite, delete absent, delete present.
    var_dump(
        $j->offsetSet($key, $value) === null,
        $j->offsetSet($key, $value) === null,
        $j->offsetUnset($key) === null,
        $j->offsetUnset($key) === null
    );

    // 3. Discarding the return value did not discard the side effect.
    $j = new Judy($type);
    $j->offsetSet($key, $value);
    var_dump(isset($j[$key]), $j->count() === 1, $j[$key] === $value);
    $j->offsetUnset($key);
    var_dump(isset($j[$key]), $j->count() === 0);

    // 4. The object handlers reach the same state as the explicit calls.
    $viaHandler = new Judy($type);
    $viaHandler[$key] = $value;
    $viaMethod = new Judy($type);
    $viaMethod->offsetSet($key, $value);
    var_dump($viaHandler->equals($viaMethod));
    unset($viaHandler[$key]);
    $viaMethod->offsetUnset($key);
    var_dump($viaHandler->equals($viaMethod));
}

// 5. The other half of the quartet keeps its own declared types: offsetExists
//    is bool and offsetGet is mixed, so neither had this mismatch.
echo "== quartet ==\n";
$j = new Judy(Judy::STRING_TO_INT);
$j['a'] = 1;
var_dump($j->offsetExists('a'), $j->offsetExists('b'), $j->offsetGet('a'));

// 6. Failures still surface as exceptions rather than as a return value.
echo "== errors ==\n";
try { $j->offsetSet(123, 1); } catch (Throwable $e) { echo get_class($e), "\n"; }
try { $j->offsetUnset(123); } catch (Throwable $e) { echo get_class($e), "\n"; }
try { $j->offsetSet("a\0b", 1); } catch (Throwable $e) { echo get_class($e), "\n"; }
try { $j->offsetUnset("a\0b"); } catch (Throwable $e) { echo get_class($e), "\n"; }
// The insert that threw must not have landed.
var_dump($j->count() === 1);
?>
--EXPECT--
== signature ==
bool(true)
bool(true)
bool(true)
bool(true)
== BITSET ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
== INT_TO_INT ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
== INT_TO_MIXED ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
== INT_TO_PACKED ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
== STRING_TO_INT ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
== STRING_TO_MIXED ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
== STRING_TO_INT_HASH ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
== STRING_TO_MIXED_HASH ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
== STRING_TO_INT_ADAPTIVE ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
== STRING_TO_MIXED_ADAPTIVE ==
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
== quartet ==
bool(true)
bool(false)
int(1)
== errors ==
TypeError
TypeError
Exception
Exception
bool(true)
