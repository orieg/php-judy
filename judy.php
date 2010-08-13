<?php
$br = (php_sapi_name() == "cli")? "":"<br>";

if(!extension_loaded('judy')) {
	dl('judy.' . PHP_SHLIB_SUFFIX);
}

judy_version();
echo "$br\n";

$module = 'judy';
$functions = get_extension_funcs($module);
echo "Functions available in the $module extension:$br\n";
foreach($functions as $func) {
    echo "\t$func()$br\n";
}
echo "$br\n";
$constants = get_defined_constants(true);
echo "Constants available in $module extension:$br\n";
foreach ($constants[$module] as $const => $v) {
    echo "\t$const => $v$br\n";
}
echo "$br\n";
$class_methods = get_class_methods('Judy');
echo "Judy class methods:$br\n";
foreach ($class_methods as $method_name) {
    echo "\tJudy::$method_name$br\n";
}
?>
