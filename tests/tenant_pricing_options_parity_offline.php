<?php
/** Fail-closed tenant gate and exact JetEngine native field injection. */
define('ABSPATH','/');
$GLOBALS['allowed']=true;
$GLOBALS['hooks']=[];
function add_action($name,$callback,$priority=10){$GLOBALS['hooks'][$name]=[$callback,$priority];}
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['hooks'][$name]=[$callback,$priority,$args];}
class MAD4B_Tenant_Service_Identity_Boundary_V1 {
 public static function eligible():bool{return $GLOBALS['allowed'];}
}
function require_that($value,$name){if(!$value)throw new RuntimeException($name);}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-tenant-pricing-options-parity-v1.php';
$c=MAD4B_Tenant_Pricing_Options_Parity_V1::class;
$page=(object)['slug'=>'etg-dynamic-pricing-controls'];
$field=['element'=>'control','name'=>'booking_enabled','type'=>'switcher'];
$original=['pricing_engine_enabled'=>['name'=>'pricing_engine_enabled'],'booking_enabled'=>$field,
   'quote_ttl_minutes'=>['name'=>'quote_ttl_minutes']];
$updated=$c::fields($original,$page);
require_that(count($updated)===4,'one native field added');
require_that(array_keys($updated)===['pricing_engine_enabled','booking_enabled',
    'booking_fulfillment_mode','quote_ttl_minutes'],'native field position');
require_that(array_keys($updated['booking_fulfillment_mode']['options'])
  ===['inquiry_only','request_to_book','instant_booking'],'authoritative allowed modes');
require_that($c::fields($updated,$page)===$updated,'idempotent field registration');
require_that($c::fields($original,(object)['slug'=>'another-page'])===$original,'other settings ignored');
$GLOBALS['allowed']=false;
require_that($c::fields($original,$page)===$original,'unenrolled site denied');
require_that(($GLOBALS['hooks']['plugins_loaded'][1]??null)===5,'provider deferred load');
echo json_encode(['status'=>'PASS','assertions'=>7,'pricing_values_written'=>false]).PHP_EOL;
