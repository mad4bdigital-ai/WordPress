<?php
/** Verify provider-owned age validation and read-only quote dispatch; no WordPress. */
namespace BookingExperience {
 function unified_accommodation_preview($payload) {
  $GLOBALS['last_accommodation_input']=$payload;
  if(isset($payload['child_ages'])){
   $ages=$payload['child_ages'];
   if(!is_array($ages) || !array_is_list($ages) ||
      count($ages)!==(int)($payload['children']??0) ||
      count($ages)>16) return new \WP_Error('stay_age_count_mismatch');
   foreach($ages as $age) {
    if(!is_int($age)||$age<0||$age>17)return new \WP_Error('stay_age_invalid');
   }
  }
  if(!empty($GLOBALS['simulate_unsafe_checkout']))
   return ['checkout_ready'=>true,'preview_total'=>100];
  return ['checkout_ready'=>false,'preview_total'=>100,'rate_id'=>$payload['rate_id']??0];
 }
 function unified_transport_preview($id,$quantities=[]) {
  return ['checkout_ready'=>false,'transport_id'=>$id,'quantities'=>$quantities];
 }
}
namespace {
define('ABSPATH','/offline');
class WP_Error {
 public function __construct(private string $code){}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action($name,$fn){return true;}
function apply_filters($tag,$value){return $value;}
function absint($x){return abs((int)$x);}
function abk_accommodation_shadow_quote(){return [];}
function check($pass,$label){if(!$pass){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(2);}}
require dirname(__DIR__).'/wp-content/plugins/mad4b-pricing-registry/mad4b-pricing-registry.php';
$base=['rate_id'=>101,'adults'=>2,'children'=>2,'child_ages'=>[7,11]];
$receipt=MAD4B_Pricing_Quote_Dispatch::preview('accommodation_rate',$base);
check(is_array($receipt),'accommodation age payload accepted');
check(($receipt['checkout_authorized']??null)===false,'age preview cannot enable checkout');
check(($receipt['mutation_performed']??null)===false,'age preview does not mutate');
check(($GLOBALS['last_accommodation_input']['child_ages']??null)===[7,11],'provider received original ages unmodified');
$reject=MAD4B_Pricing_Quote_Dispatch::preview('accommodation_rate',$base+['price_override'=>1]);
check(is_wp_error($reject)&&$reject->get_error_code()==='pricing_registry_unknown_input','unknown price override denied');
$reject=MAD4B_Pricing_Quote_Dispatch::preview('accommodation_rate',array_replace($base,['child_ages'=>[7]]));
check(is_wp_error($reject)&&$reject->get_error_code()==='stay_age_count_mismatch','age count mismatch denied by owning provider');
$reject=MAD4B_Pricing_Quote_Dispatch::preview('accommodation_rate',array_replace($base,['child_ages'=>[7,90]]));
check(is_wp_error($reject)&&$reject->get_error_code()==='stay_age_invalid','age invalid denied by provider');
$GLOBALS['simulate_unsafe_checkout']=true;
$reject=MAD4B_Pricing_Quote_Dispatch::preview('accommodation_rate',$base);
check(is_wp_error($reject)&&$reject->get_error_code()==='pricing_registry_quote_unverified','handler cannot grant checkout ready');
$GLOBALS['simulate_unsafe_checkout']=false;
$zero=MAD4B_Pricing_Quote_Dispatch::preview('accommodation_rate',array_replace($base,['children'=>0,'child_ages'=>[]]));
check(is_array($zero)&&($zero['checkout_authorized']??null)===false,
 'zero children preview remains read-only');
echo json_encode(['state'=>'PASS','cases'=>8,'age_provider_owns_validation'=>true,
 'checkout_authorized'=>false,'payment_activated'=>false,'wp_runtime'=>false]).PHP_EOL;
}
