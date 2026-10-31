<?php
/** No Wordpress runtime or database mutations; simulate authorized REV65 contract. */
define('ABSPATH','/isolated/');
class WP_Error {
    private string $code;
    public function __construct($code,$msg=''){ $this->code=$code; }
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
$GLOBALS['staff']=true;
$GLOBALS['native_calls']=0;
$GLOBALS['enabled']=true;
function current_user_can($cap){return $cap==='manage_woocommerce' && $GLOBALS['staff'];}
function shared_pricing_orchestrator_enabled(){return $GLOBALS['enabled'];}
function shared_pricing_domain_providers(){return ['tour'=>['callback'=>'shared_pricing_provider_tour','source_type'=>'tour-rates'],
    'accommodation'=>['callback'=>'shared_pricing_provider_accommodation','source_type'=>'property-rates'],
    'transportation'=>['callback'=>'shared_pricing_provider_transportation','source_type'=>'transport-rates'],
    'visa'=>['callback'=>'shared_pricing_provider_visa','source_type'=>'travel-visa']];}
function shared_pricing_provider_tour(){return [];}
function shared_pricing_provider_accommodation(){return [];}
function shared_pricing_provider_transportation(){return [];}
function shared_pricing_provider_visa(){return [];}
function shared_pricing_orchestrate($request){
    $GLOBALS['native_calls']++;
    return ['status'=>'unified_preview_only','checkout_ready'=>false,
        'quote_authorized'=>false,'inventory_reserved'=>false,'orders_created'=>false,
        'cross_product_eligibility'=>'not_certified','total'=>2230.0,'currency'=>'USD',
        'receipt_sha256'=>str_repeat('a',64),'component_count'=>3,
        'components'=>[
          ['domain'=>'tour','source_type'=>'tour-rates','amount'=>1840,'currency'=>'USD','source_receipt_sha256'=>str_repeat('b',64),'source_receipt'=>['secret'=>'must_not_expose']],
          ['domain'=>'accommodation','source_type'=>'property-rates','amount'=>240,'currency'=>'USD','source_receipt_sha256'=>str_repeat('c',64),'source_receipt'=>['secret'=>'must_not_expose']],
          ['domain'=>'transportation','source_type'=>'transport-rates','amount'=>150,'currency'=>'USD','source_receipt_sha256'=>str_repeat('d',64),'source_receipt'=>['secret'=>'must_not_expose']]
        ]];
}
function assert_ok($condition,$desc){if(!$condition){fwrite(STDERR,"FAIL: $desc
");exit(3);}echo "PASS: $desc
";}
require dirname(__DIR__).'/includes/class-cmb-provider-gateway.php';
$payload=['schema_version'=>1,'context'=>'tour_component','currency'=>'USD','components'=>[
    ['domain'=>'tour','rate_id'=>61100,'audience'=>'general','occupancy'=>'double'],
    ['domain'=>'accommodation','rate_id'=>66526,'rooms'=>1,'checkin'=>'2027-01-15','checkout'=>'2027-01-17'],
    ['domain'=>'transportation','rate_id'=>66572,'product_id'=>66528,'date'=>'2027-01-15','quantities'=>['persons'=>2]]
]];
$read=CMB_Provider_Gateway::pricing_preview($payload);
assert_ok(is_array($read) && $read['state']==='unified_preview_only' &&
    $read['total']===2230.0 && !$read['booking_authorized'] && !$read['checkout_ready'],'single native multi-domain preview');
assert_ok($GLOBALS['native_calls']===1,'one complete request, no separate domain price engines');
assert_ok(count($read['components'])===3 && !str_contains(json_encode($read),'must_not_expose'),'minimal receipt, no supplier financial internals');
$money=$payload;$money['components'][1]['price']=0;
assert_ok(is_wp_error(CMB_Provider_Gateway::pricing_preview($money)),'client price value forbidden at any depth');
$money=$payload;$money['components'][0]['quantities']=['unit_price'=>0];
assert_ok(is_wp_error(CMB_Provider_Gateway::pricing_preview($money)),'nested overrides forbidden');
$money=$payload;$money['checkout_ready']=true;
assert_ok(is_wp_error(CMB_Provider_Gateway::pricing_preview($money)),'client checkout authority forbidden');
assert_ok($GLOBALS['native_calls']===1,'invalid inputs are rejected before native callback');
$GLOBALS['staff']=false;
assert_ok(is_wp_error(CMB_Provider_Gateway::pricing_preview($payload)),'guest cannot invoke preview');
$GLOBALS['staff']=true;$GLOBALS['enabled']=false;
$r=CMB_Provider_Gateway::pricing_preview($payload);
assert_ok(is_wp_error($r) && $r->get_error_code()==='cmb_shared_pricing_orchestrator_not_deployed','other sites fail closed; never fallback to separate pricing engine');
assert_ok($GLOBALS['native_calls']===1,'missing orchestrator never emits a price');
echo "ALL SHARED PRICING GATEWAY TESTS PASSED
";
