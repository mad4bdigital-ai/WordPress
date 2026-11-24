<?php
declare(strict_types=1);
define('ABSPATH', '/');
$GLOBALS['registered_filters']=[];
function add_filter($hook,$fn,$priority=10,$argc=1):void {
  $GLOBALS['registered_filters'][$hook][]=['callback'=>$fn,'priority'=>$priority,'argc'=>$argc];
}
function is_admin():bool { return false; }
function wp_doing_ajax():bool { return false; }
function is_preview():bool { return false; }
$_SERVER['REQUEST_METHOD']='GET';
require __DIR__.'/../../wp-content/mu-plugins/447-mad4b-navigation-native-ssr-guard.php';
function check(bool $ok,string $label):void {
 if (!$ok) {fwrite(STDERR,"FAIL $label\n");exit(1);}
 echo "PASS $label\n";
}
$nav=['_css_classes'=>'foo navigation-editor-grid bar','lisitng_id'=>67125];
$other=['_css_classes'=>'related-tour-grid','lisitng_id'=>15188];
$admission=$GLOBALS['registered_filters']['mad4b/company_adaptive/listing_eligible'][0]['callback'];
$forced=$GLOBALS['registered_filters']['jet-engine/listing/grid/is_lazy_load'][0]['callback'];
check(mad4b_nav_grid_requires_native_ssr($nav),'Recognize semantic navigation grid');
check(!mad4b_nav_grid_requires_native_ssr($other),'Do not match regular listing');
check($admission(true,$nav,67125)===false,'Prevent global adaptive mutation of navigation grid');
check($admission(true,$other,15188)===true,'Preserve adaptive eligibility of other listings');
check($forced(true,$nav)===false,'Force server-side navigation output');
check($forced(true,$other)===true,'Preserve existing lazy flags for other listings');
$_SERVER['REQUEST_METHOD']='POST';
check($admission(true,$nav,67125)===true,'No effect on non-GET requests');
check($forced(true,$nav)===true,'No effect on POST/AJAX request sequence');
echo "TESTS 8/8 PASS\n";
