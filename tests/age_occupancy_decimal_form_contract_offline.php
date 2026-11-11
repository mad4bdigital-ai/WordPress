<?php
/** Stub-driven provider contract, isolated from WordPress and real bookings. */
define('ABSPATH','/offline');
define('HOUR_IN_SECONDS',3600);
class WP_Error {
 private string $code;
 public function __construct($code,$message='',$data=null){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
class WP_Query {public function is_singular($type){return false;} public function get_queried_object_id(){return 0;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function is_admin(){return false;}
function add_action($name,$callback,$priority=10,$argc=1){return true;}
function add_filter($name,$callback,$priority=10,$argc=1){return true;}
function absint($n){return abs((int)$n);}
function wp_json_encode($v,$opts=0){return json_encode($v,$opts);}
function wp_salt($type='auth'){return 'isolated-offline-test-salt';}
function wp_unslash($v){return stripslashes((string)$v);}
function current_user_can($name){return false;}
function post_password_required($p){return false;}
function get_post($id){
 if($id===99)return (object)['post_type'=>'tours-and-activities','post_status'=>'publish','post_password'=>''];
 if($id===101)return (object)['post_type'=>'tour-rates','post_status'=>'publish','post_password'=>''];
 return null;
}
function get_post_meta($id,$key,$single=true){return $id===101&&$key==='related_tour_id'?99:'';}
function get_post_type($id){return get_post($id)?get_post($id)->post_type:'';}
function get_post_status($id){return get_post($id)?get_post($id)->post_status:'draft';}
$GLOBALS['maxAgeGuests']=3;
function get_option($name,$default=null){
 if($name==='mad4b_age_occupancy_fluent_forms_v1')
  return [6=>['children'=>'no_of_children','infants'=>'no_of_infants',
             'strict'=>true,'context_mode'=>'adaptive']];
 if($name==='shared_product_age_collection_limit')return $GLOBALS['maxAgeGuests'];
 if($name==='mad4b_age_collection_policy_v1')
  return ['age_reference'=>'service_start','children'=>['min'=>2,'max'=>12],
          'infants'=>['min'=>0,'max'=>1.99]];
 return $default;
}
function apply_filters($name,$value,...$args){
 if($name==='mad4b/age_occupancy/policy_level'&&($args[0]??null)==='global'){
  return ['bands'=>[
    ['id'=>'infant','min_age'=>0,'max_age'=>2],
    ['id'=>'child','min_age'=>2.01,'max_age'=>11.99],
    ['id'=>'adult','min_age'=>12,'max_age'=>120]],
    'age_reference'=>'service_start'];
 }
 return $value;
}
function check($b,$msg){if(!$b){fwrite(STDERR,"FAIL ".$msg.PHP_EOL);exit(2);}}
require dirname(__DIR__).'/wp-content/plugins/mad4b-age-occupancy-registry/mad4b-age-occupancy-registry.php';
$R=MAD4B_Age_Occupancy_Registry::class;
$F=MAD4B_Age_Fluent_Forms::class;
$P=MAD4B_Age_Fluent_Policy::class;
check($R::normalize_age('1.50')===1.5,'strict decimal years normalized');
check($R::normalize_age('7')===7,'strict integer years normalized');
check($R::age_cents('1.25')===125,'precision 100ths kept');
check(is_wp_error($R::normalize_age('1e2')),'scientific notation rejected');
check(is_wp_error($R::normalize_age('1.234')),'excess decimal precision rejected');
check(is_wp_error($R::normalize_age(121)),'age above 120 rejected');
check(is_wp_error($R::normalize_age(true)),'boolean age rejected');
check($F::limit()===3,'site technical collector max honored');
$general=$F::generic_policy();
check(($general['pricing_authorized']??null)===false,'general collection is not pricing');
check(($general['mode']??'')==='collect_only','general collection never implies booked tour');
$fields=['no_of_children'=>'1','no_of_infants'=>'1'];
$raw=$fields+['mad4b_age_contract'=>$F::VERSION,
 'mad4b_child_ages'=>['7.50'],'mad4b_infant_ages'=>['1.50']];
$_POST=[];
$receipt=$F::receipt(6,$fields,$raw);
check(is_array($receipt)&&$receipt['child_ages']===[7.5,1.5],'native form accepts decimal age in general flow');
check($receipt['context_mode']==='collect_only' &&
 $receipt['classification_status']==='pending_product_selection','unselected form cannot classify a fare');
check($receipt['pricing_authorized']===false,'general receipt has no pricing authority');
$bad=$F::receipt(6,$fields,array_replace($raw,['mad4b_child_ages'=>['15']]));
check(is_wp_error($bad)&&$bad->get_error_code()==='age_general_range_invalid','general age bands enforced');
$GLOBALS['maxAgeGuests']=1;
$bad=$F::receipt(6,$fields,$raw);
check(is_wp_error($bad)&&$bad->get_error_code()==='age_guest_count_exceeded','guest count bound enforced');
$GLOBALS['maxAgeGuests']=3;
$ctx=['domain'=>'tour','product_id'=>99,'rate_id'=>101];
$token=$P::token(6,$ctx);
check(is_string($token)&&str_contains($token,'.'),'tour token is signed and bounded');
check($P::from_token($token,6)===$ctx,'tour token can be verified for current form');
check(is_wp_error($P::from_token($token,7)),'token cannot authorize another form');
$embed='__fluent_form_embded_post_id';
$tourRaw=$fields+[$embed=>99,'mad4b_age_contract'=>$F::VERSION,
  'mad4b_child_ages'=>['7.50'],'mad4b_infant_ages'=>['1.50'],
  'mad4b_age_context_token'=>$token];
$tourReceipt=$F::receipt(6,$fields,$tourRaw);
check(is_array($tourReceipt)&&$tourReceipt['context_mode']==='tour','verified native tour form retains policy context');
check($tourReceipt['classification_status']==='tour_policy_verified','verified Tour band classified');
check($tourReceipt['pricing_authorized']===false,'Tour form never grants checkout');
$bad=$F::receipt(6,$fields,array_replace($tourRaw,['mad4b_age_context_token'=>'invalid']));
check(is_wp_error($bad)&&$bad->get_error_code()==='age_context_token_invalid','invalid token denied');
$bad=$F::receipt(6,$fields,array_replace($tourRaw,['mad4b_child_ages'=>['18']]));
check(is_wp_error($bad)&&$bad->get_error_code()==='age_children_category_mismatch','invalid child age category denied');
$e=$R::booking_evidence($tourReceipt,$ctx,2);
check(is_array($e), 'native Fluent age receipt accepted by canonical booking evidence');
$invalidMeta=array_replace($tourReceipt,['classification_status'=>'pending_product_selection']);
$bad=$R::booking_evidence($invalidMeta,$ctx,2);
check(is_wp_error($bad)&&$bad->get_error_code()==='age_context_classification_invalid',
 'forged classification mismatch rejected');
$bad=$R::booking_evidence($tourReceipt+['untrusted_discount'=>10],$ctx,2);
check(is_wp_error($bad)&&$bad->get_error_code()==='age_context_unexpected_keys',
 'unexpected age-discount field rejected');
$bad=$R::booking_evidence(array_replace($tourReceipt,['pricing_authorized'=>true]),$ctx,2);
check(is_wp_error($bad)&&$bad->get_error_code()==='age_context_pricing_not_authorized',
 'browser can never grant price eligibility');
check(($e['pricing_authorized']??null)===false,'canonical evidence cannot certify price');
check($R::verify_booking_evidence($e,$ctx,2),'booking evidence readback matches input');
$tamper=$e;$tamper['child_ages']=[8,1.5];
check(!$R::verify_booking_evidence($tamper,$ctx,2),'edited evidence invalid');
echo json_encode(['state'=>'PASS','cases'=>30,'commercial_price_approved'=>false,
 'site_modified'=>false,'live_wordpress_tested'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
