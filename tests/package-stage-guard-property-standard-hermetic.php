<?php
define('ABSPATH','/');$done=0;
$types=[201=>'property-rates',202=>'property-rates'];
$meta=[201=>['prop_related_unit_id'=>'0','mad4b_rate_scope_v1'=>'property'],
 202=>['prop_related_unit_id'=>'101','mad4b_rate_scope_v1'=>'unit']];
function get_post_type($id){return $GLOBALS['types'][$id]??'';}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function absint($value){return abs((int)$value);}
require __DIR__.'/../wp-content/mu-plugins/shared-product-core/pricing/package-stage-rate-guard-v1.php';
$cls='MAD4B_Package_Stage_Rate_Guard_V1';
function ok($yes,$label){$GLOBALS['done']++;if(!$yes)throw new RuntimeException('FAIL '.$label);}
$r=$cls::costRateScope(201,['unit_id'=>0],[]);
ok($r['valid']&&$r['scope']==='property_rate','native Property Standard with no Unit accepted');
ok($r['requires_native_unit_allocation']===false,'no mandatory Unit allocation');
ok($r['requires_independent_party_and_receipt_approval']===true,'commercial approval stays independent');
$r=$cls::costRateScope(201,['unit_id'=>101],[]);
ok(!$r['valid'],'Property-wide receipt cannot misrepresent Unit identity');
$r=$cls::costRateScope(202,['unit_id'=>101],[101]);
ok($r['valid']&&$r['scope']==='unit_rate','explicit Unit Rate remains eligible');
$r=$cls::costRateScope(202,['unit_id'=>101],[]);
ok(!$r['valid'],'Unit Rate cannot bypass Unit selection');
$source=file_get_contents(__DIR__.'/../wp-content/mu-plugins/shared-product-core/pricing/package-stage-rate-guard-v1.php');
ok(!str_contains($source,"$".'flag(\'stage_units_not_selected\')'),'unselected Unit not hard-blocked');
ok(str_contains($source,'stage_property_standard_party_cost_receipt_recheck_required'),
 'Property Standard still needs independent signed supplier/occupancy proof');
ok(str_contains($source,'cruise_rate_stage_native_provider_not_connected'),
 'Cruise commercial provider not silently enabled');
ok(str_contains($source,'sleeper_train_rate_stage_native_provider_not_connected'),
 'Sleeper commercial provider not silently enabled');
echo 'PASS '.$done.'/'.$done.' native Stage Guard optional Unit + commercial blocks'.PHP_EOL;
