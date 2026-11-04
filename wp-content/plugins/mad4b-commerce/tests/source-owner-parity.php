<?php
/** Read-only source owner check. Execute as: php tests/source-owner-parity.php */
declare(strict_types=1);
$root=dirname(__DIR__);
$fail=[];
$main=file_get_contents($root.'/mad4b-commerce.php');
$display=file_get_contents($root.'/modules/compat/tour-rate-native-display-tags.php');
$projection=file_get_contents($root.'/modules/compat/local-presentation-fluent-projection-v1.php');
$rate=file_get_contents($root.'/modules/commerce/tour-rates-dynamic-function.php');
foreach (['tour-rate-structured-tags.inc.php','tour-rate-package-disclosure.inc.php','tour-rate-original-price-svg.inc.php','tour-rate-occupancy-label.inc.php'] as $file) {
 $relative='modules/compat/assets/'.$file;
 if(!is_readable($root.'/'.$relative))$fail[]='missing:'.$relative;
 if(substr_count($display,"__DIR__.'/assets/".$file."'")!==1)$fail[]='load-not-single:'.$file;
 if(str_contains($display,"WPMU_PLUGIN_DIR.'/assets/".$file."'"))$fail[]='old-owner-reference:'.$file;
}
if(!is_readable($root.'/assets/tour-rate-centered-popover.js'))$fail[]='missing:popover';
if(!str_contains($display,"plugins_url('assets/tour-rate-centered-popover.js'"))$fail[]='popover-route';
if(substr_count($main,"require_once __DIR__ . '/modules/compat/local-presentation-fluent-projection-v1.php';")!==1)$fail[]='form-projection-loader';
if(!str_contains($projection,'MAD4B_Rate_Naming_And_Note_V1::name'))$fail[]='translated-choice-source';
if(!str_contains($rate,'tk_trdf_format_date'))$fail[]='rate-label-date-source';
if(!str_contains(file_get_contents($root.'/modules/compat/assets/tour-rate-package-disclosure.inc.php'),'wpml_object_id'))$fail[]='localized-term-source';
echo 'MAD4B_COMMERCE_SOURCE_OWNER_CHECK '.($fail?'FAIL':'PASS')."\n";
foreach($fail as $err)echo 'FAIL '.$err."\n";
exit($fail?1:0);
