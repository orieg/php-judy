--TEST--
Judy unset(): STRING_TO_MIXED_HASH and both *_ADAPTIVE branches keep their probe-then-delete ordering
--SKIPIF--
<?php
if (!extension_loaded("judy")) print "skip";
?>
--FILE--
<?php
/* Guard for the unset branches that were deliberately NOT simplified: the
 * _MIXED variants must still fetch the slot before deleting it, because the
 * stored zval has to be captured for its destructor, and the delete-before-
 * free ordering is a destructor-re-entrancy guard. The _ADAPTIVE types must
 * still route short keys through the packed JudyL and long keys through
 * JudyHS. */

class Tracer
{
    public function __construct(public string $name) {}
    public function __destruct() { echo "    dtor ", $this->name, "\n"; }
}

function state(Judy $j): string
{
    $keys = $j->keys();
    $walk = [];
    foreach ($j as $k => $v) {
        $walk[] = $k . "=" . (is_object($v) ? "obj:" . $v->name : var_export($v, true));
    }
    return sprintf("count=%d keys=[%s] walk=[%s]",
        count($j), implode(",", $keys), implode(",", $walk));
}

/* ---- STRING_TO_MIXED_HASH: the value destructor must run on unset ---- */
echo "STRING_TO_MIXED_HASH\n";
$m = new Judy(Judy::STRING_TO_MIXED_HASH);
$m["alpha"] = new Tracer("alpha");
$m["beta"]  = new Tracer("beta");
$m["gamma"] = "plain";
echo "  ", state($m), "\n";
echo "  unset absent:\n";
unset($m["delta"]);
echo "  ", state($m), "\n";
echo "  unset present (destructor expected):\n";
unset($m["beta"]);
echo "  ", state($m), "\n";
echo "  unset same key again:\n";
unset($m["beta"]);
echo "  ", state($m), "\n";
echo "  drain:\n";
unset($m["alpha"], $m["gamma"]);
echo "  ", state($m), " mem=", $m->memoryUsage(), "\n";

/* A destructor that re-enters and unsets the same key must not double-free:
 * this is what the probe-then-delete ordering in that branch protects. */
echo "  re-entrant destructor:\n";
class Reenter
{
    public static ?Judy $target = null;
    public function __construct(public string $key) {}
    public function __destruct()
    {
        echo "    dtor re-enters for ", $this->key, "\n";
        if (self::$target !== null) {
            unset(self::$target[$this->key]);
        }
    }
}
$r = new Judy(Judy::STRING_TO_MIXED_HASH);
Reenter::$target = $r;
$r["loop"] = new Reenter("loop");
unset($r["loop"]);
Reenter::$target = null;
echo "  ", state($r), "\n";

/* ---- STRING_TO_INT_ADAPTIVE: short (packed) and long (JudyHS) keys ---- */
echo "STRING_TO_INT_ADAPTIVE\n";
foreach ([false, true] as $opt) {
    $a = new Judy(Judy::STRING_TO_INT_ADAPTIVE, optimizeIteration: $opt);
    echo "  optimizeIteration=", var_export($opt, true),
         " honoured=", var_export($a->isIterationOptimized(), true), "\n";
    $a["s"]                  = 1;   /* short: packed JudyL path */
    $a["short7"]             = 2;   /* still short */
    $a["longkey_eight"]      = 3;   /* long: JudyHS path */
    $a[str_repeat("z", 64)]  = 4;
    echo "    ", state($a), "\n";
    unset($a["absent"], $a["absentlongkey_xxxxxx"]);
    echo "    absent: ", state($a), "\n";
    unset($a["short7"], $a["longkey_eight"]);
    echo "    mixed unset: ", state($a), "\n";
    unset($a["s"], $a[str_repeat("z", 64)]);
    echo "    drained: ", state($a), " mem=", $a->memoryUsage(), "\n";
}

/* ---- STRING_TO_MIXED_ADAPTIVE: destructors on both paths ---- */
echo "STRING_TO_MIXED_ADAPTIVE\n";
$ma = new Judy(Judy::STRING_TO_MIXED_ADAPTIVE);
$ma["s"]             = new Tracer("short");
$ma["longkey_eight"] = new Tracer("long");
echo "  ", state($ma), "\n";
echo "  unset absent:\n";
unset($ma["nope"], $ma["nope_long_key_here"]);
echo "  ", state($ma), "\n";
echo "  unset short (destructor expected):\n";
unset($ma["s"]);
echo "  unset long (destructor expected):\n";
unset($ma["longkey_eight"]);
echo "  ", state($ma), " mem=", $ma->memoryUsage(), "\n";

echo "Done\n";
?>
--EXPECT--
STRING_TO_MIXED_HASH
  count=3 keys=[alpha,beta,gamma] walk=[alpha=obj:alpha,beta=obj:beta,gamma='plain']
  unset absent:
  count=3 keys=[alpha,beta,gamma] walk=[alpha=obj:alpha,beta=obj:beta,gamma='plain']
  unset present (destructor expected):
    dtor beta
  count=2 keys=[alpha,gamma] walk=[alpha=obj:alpha,gamma='plain']
  unset same key again:
  count=2 keys=[alpha,gamma] walk=[alpha=obj:alpha,gamma='plain']
  drain:
    dtor alpha
  count=0 keys=[] walk=[] mem=0
  re-entrant destructor:
    dtor re-enters for loop
  count=0 keys=[] walk=[]
STRING_TO_INT_ADAPTIVE
  optimizeIteration=false honoured=false
    count=4 keys=[longkey_eight,s,short7,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz] walk=[longkey_eight=3,s=1,short7=2,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz=4]
    absent: count=4 keys=[longkey_eight,s,short7,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz] walk=[longkey_eight=3,s=1,short7=2,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz=4]
    mixed unset: count=2 keys=[s,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz] walk=[s=1,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz=4]
    drained: count=0 keys=[] walk=[] mem=0
  optimizeIteration=true honoured=true
    count=4 keys=[longkey_eight,s,short7,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz] walk=[longkey_eight=3,s=1,short7=2,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz=4]
    absent: count=4 keys=[longkey_eight,s,short7,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz] walk=[longkey_eight=3,s=1,short7=2,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz=4]
    mixed unset: count=2 keys=[s,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz] walk=[s=1,zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz=4]
    drained: count=0 keys=[] walk=[] mem=0
STRING_TO_MIXED_ADAPTIVE
  count=2 keys=[longkey_eight,s] walk=[longkey_eight=obj:long,s=obj:short]
  unset absent:
  count=2 keys=[longkey_eight,s] walk=[longkey_eight=obj:long,s=obj:short]
  unset short (destructor expected):
    dtor short
  unset long (destructor expected):
    dtor long
  count=0 keys=[] walk=[] mem=0
Done
