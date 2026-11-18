<?php
/** Minimal native CSS exclusion regression; no WordPress data mutation. */
define('ABSPATH','/tmp/');
function add_action(...$args){}
function add_filter(...$args){}
require __DIR__.'/source-427-under-test.php';
$cls='MAD4B_Public_Archive_CSS_Source_V1';
$rp=(new ReflectionClass($cls))->getProperty('admitted');
$checks=0;
$assert=function($ok,$name)use(&$checks) {
    if (!$ok)throw new RuntimeException('FAIL '.$name);
    ++$checks;echo "PASS $name
";
};
$input=['original-excluded-url'];
$rp->setValue(false);
$assert($cls::exclude_volatile($input)===$input,'unadmitted public or user context unchanged');
$assert($cls::exclude_volatile('unchanged-string')==='unchanged-string','nonarray input left intact when unadmitted');
$rp->setValue(true);
$assert($cls::exclude_volatile($input)===['original-excluded-url','elementor-dcss-'],'volatile JetEngine CSS isolated for valid category only');
$assert($input===['original-excluded-url'],'original immutable exclusion list unchanged');
$assert($cls::exclude_volatile($cls::exclude_volatile($input))===['original-excluded-url','elementor-dcss-'],'idempotent native CSS exclusion');
$assert($cls::exclude_volatile(null)===['elementor-dcss-'],'null exclusion turns into valid LiteSpeed array');
echo "TOTAL $checks/6
";
