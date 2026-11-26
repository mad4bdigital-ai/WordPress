<?php
/** Policy-derived passenger categories and signed form context; no pricing authority. */
defined('ABSPATH') || exit;
final class MAD4B_Age_Fluent_Policy {
 public static function context(array $context){
  if(($context['domain']??'tour')!=='tour')return new WP_Error('age_tour_context_required');
  $tour=absint($context['product_id']??0);$rate=absint($context['rate_id']??0);
  $post=$tour?get_post($tour):null;
  if(!$post||$post->post_type!=='tours-and-activities'||$post->post_status!=='publish')return new WP_Error('age_tour_context_invalid');
  if($post->post_password!==''&&!current_user_can('manage_woocommerce')&&post_password_required($post))return new WP_Error('age_tour_context_protected');
  if($rate){$r=get_post($rate);if(!$r||$r->post_type!=='tour-rates'||$r->post_status!=='publish'||(int)get_post_meta($rate,'related_tour_id',true)!==$tour)return new WP_Error('age_rate_context_invalid');if($r->post_password!==''&&!current_user_can('manage_woocommerce')&&post_password_required($r))return new WP_Error('age_rate_context_protected');}
  return ['domain'=>'tour','product_id'=>$tour,'rate_id'=>$rate];
 }
 public static function current_context(){
  global $wp_the_query;$id=$wp_the_query instanceof WP_Query&&$wp_the_query->is_singular('tours-and-activities')?(int)$wp_the_query->get_queried_object_id():0;
  if(!$id&&get_post_type(get_queried_object_id())==='tours-and-activities')$id=(int)get_queried_object_id();
  return self::context(['domain'=>'tour','product_id'=>$id]);
 }
 public static function contract(array $context){
  $context=self::context($context);if(is_wp_error($context))return $context;
  $resolved=MAD4B_Age_Occupancy_Registry::policy($context);if(is_wp_error($resolved))return $resolved;
  $groups=['children'=>[],'infants'=>[],'adults'=>[]];
  foreach($resolved['policy']['bands']as$b){$group=in_array($b['id'],['child','children'],true)?'children':(in_array($b['id'],['infant','infants'],true)?'infants':(in_array($b['id'],['adult','adults'],true)?'adults':null));if($group)$groups[$group][]=['min'=>$b['min_age'],'max'=>$b['max_age']];}
  if(!$groups['children']||!$groups['infants'])return new WP_Error('age_category_policy_unconfigured');
  return ['context'=>$context,'age_reference'=>$resolved['policy']['age_reference'],'groups'=>$groups,'policy_version'=>$resolved['version'],'policy_sha256'=>hash('sha256',wp_json_encode(['policy'=>$resolved['policy'],'overrides'=>$resolved['overrides']]))];
 }
 public static function validate(array $context,array $children,array $infants){
  $contract=self::contract($context);if(is_wp_error($contract))return $contract;
  foreach(['children'=>$children,'infants'=>$infants]as$group=>$ages)foreach($ages as$age){$valid=false;foreach($contract['groups'][$group]as$range)if((is_int($age)||is_float($age))&&$age>=$range['min']&&$age<=$range['max']){$valid=true;break;}if(!$valid)return new WP_Error('age_'.$group.'_category_mismatch');}
  return $contract;
 }
 public static function token(int $form,array $context){
  $context=self::context($context);if(is_wp_error($context))return '';
  $body=rtrim(strtr(base64_encode(wp_json_encode(['form'=>$form,'context'=>$context,'expires'=>time()+2*HOUR_IN_SECONDS])),'+/','-_'),'=');
  return $body.'.'.hash_hmac('sha256',$body,wp_salt('nonce'));
 }
 public static function from_token($token,int $form){
  if(!is_string($token)||strlen($token)>1500||substr_count($token,'.')!==1)return new WP_Error('age_context_token_invalid');
  [$body,$sig]=explode('.',$token,2);if(!hash_equals(hash_hmac('sha256',$body,wp_salt('nonce')),$sig))return new WP_Error('age_context_token_invalid');
  $data=json_decode(base64_decode(strtr($body,'-_','+/'),true),true);
  if(!is_array($data)||($data['form']??0)!==$form||!is_int($data['expires']??null)||$data['expires']<time()||!is_array($data['context']??null))return new WP_Error('age_context_token_expired_or_mismatched');
  return self::context($data['context']);
 }
 public static function response(int $form,array $context){
  $r=self::contract($context);if(is_wp_error($r))return $r;$r['token']=self::token($form,$r['context']);return $r;
 }
}
add_action('rest_api_init',static function(){register_rest_route('age-occupancy/v1','/form-policy',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function(WP_REST_Request $r){$form=absint($r['form_id']);if(!MAD4B_Age_Fluent_Forms::config($form))return new WP_Error('age_form_not_configured','Age policy unavailable',['status'=>422]);$out=MAD4B_Age_Fluent_Policy::response($form,['domain'=>'tour','product_id'=>absint($r['tour_id']),'rate_id'=>absint($r['rate_id'])]);if(is_wp_error($out))return new WP_Error($out->get_error_code(),'Age policy unavailable',['status'=>422]);$response=rest_ensure_response($out);$response->header('Cache-Control','private, no-store, max-age=0');return $response;}]);});
