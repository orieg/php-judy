<?php

ini_set("memory_limit", "128M");

function __convert($size)
{
    $unit=array('b','kb','mb','gb','tb','pb');
    return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
}

$count = array(100, 500, 1000, 5000, 10000, 50000, 100000, 500000);

foreach($count as $v) {
    echo "## Count: $v\n";
    echo "-- ARRAY \n";
    echo "Mem usage: ". __convert(memory_get_usage()) . "\n";
    echo "Mem real: ". __convert(memory_get_usage(true)) . "\n";

    $s=microtime(true);
    $array = array();
    for ($i=0; $i<$v; $i++)
        $array[$i] = rand();

	/* Del some index */
	unset($array[101]);
	$a = isset($array[100]) ? $array[100] : null;
	$b = isset($array[102]) ? $array[102] : null;
	var_dump($a);
	var_dump($b);

    $e=microtime(true);
    echo "Count: ".count($array)."\n";
    echo "Elapsed time: ".($e - $s)." sec.\n";
    echo "Mem usage: ". __convert(memory_get_usage()) . "\n";
    echo "Mem real: ". __convert(memory_get_usage(true)) . "\n";

    unset($array);

    echo "\n-- Judy INT_TO_INT \n";
    echo "Mem usage: ". __convert(memory_get_usage()) . "\n";
    echo "Mem real: ". __convert(memory_get_usage(true)) . "\n";

    $s=microtime(true);
    $judy = new Judy(Judy::INT_TO_INT);
    for ($i=0; $i<$v; $i++)
        $judy[$i] = rand();

	/* Del some index */
	unset($judy[101]);
	$a = isset($judy[100]) ? $judy[100] : null;
	$b = isset($judy[102]) ? $judy[102] : null;
	var_dump($a);
	var_dump($b);

    echo "Count: ".count($judy)."\n";
    echo "MU: ".__convert($judy->memoryUsage())."\n";
    $e=microtime(true);
    echo "Elapsed time: ".($e - $s)." sec.\n";
    echo "Mem usage: ". __convert(memory_get_usage()) . "\n";
    echo "Mem real: ". __convert(memory_get_usage(true)) . "\n";
    echo "\n";

    unset($judy);
}
