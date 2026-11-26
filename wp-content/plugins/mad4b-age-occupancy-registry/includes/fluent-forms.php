<?php
/** Non-destructive age inputs for existing Fluent Forms booking/inquiry requests. */
if (!defined('ABSPATH')) exit;
final class MAD4B_Age_Fluent_Forms {
 const VERSION='age-request.v1';
 const OPTION='mad4b_age_occupancy_fluent_forms_v1';
 const LIMIT_OPTION='shared_product_age_collection_limit';
 public static function limit(): int {
  $n=get_option(self::LIMIT_OPTION,50);
  return filter_var($n,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>50]])!==false?(int)$n:50;
 }
 public static function forms(){
  $configured=get_option(self::OPTION,[]);
  if(!is_array($configured))$configured=[];
  $result=apply_filters('mad4b/age_occupancy/fluent_forms',$configured);
  return is_array($result)?$result:[];
 }

 public static function config($form){
  $id=absint(is_object($form)?($form->id??0):$form);
  $cfg=self::forms()[$id]??null;
  if(!is_array($cfg)||empty($cfg['children'])||empty($cfg['infants']))return null;
  foreach(['children','infants'] as $field)if(!is_string($cfg[$field])||!preg_match('/^[a-z][a-z0-9_]{0,60}$/D',$cfg[$field]))return null;
  $mode=(string)($cfg['context_mode']??'adaptive');
  $cfg['context_mode']=in_array($mode,['adaptive','collect_only','tour_only','disabled'],true)
   ?$mode:'disabled';
  return $cfg;
 }
 /**
  * Shared age collection is valid even before a commercial product has
  * been selected. It never classifies a fare, room or supplier occupancy.
  * All enlisted forms use this ONE site-wide collection contract.
  */
 public static function generic_policy(): array {
  $settings=get_option('mad4b_age_collection_policy_v1',[]);
  if(!is_array($settings))$settings=[];
  $reference=($settings['age_reference']??'service_start')==='checkin'
   ?'checkin':'service_start';
  $limits=[];
  foreach(['children','infants'] as $name){
   $row=is_array($settings[$name]??null)?$settings[$name]:[];
   $min=MAD4B_Age_Occupancy_Registry::normalize_age($row['min']??0);
   $max=MAD4B_Age_Occupancy_Registry::normalize_age($row['max']??120);
   if(is_wp_error($min)||is_wp_error($max)||$min>$max){
    $min=0;$max=120; // Fail safe for preexisting invalid config; admin rejects writes.
   }
   $limits[$name]=[['min'=>$min,'max'=>$max]];
  }
  return ['mode'=>'collect_only','context'=>['domain'=>'general',
    'product_id'=>0,'rate_id'=>0],
    'age_reference'=>$reference,'groups'=>$limits+['adults'=>[]],
    'policy_version'=>'age-collection.v1','pricing_authorized'=>false,
    'classification'=>'pending_product_selection',
    'token'=>''];
 }
 public static function age_context_mode($form): string {
  $cfg=self::config($form);
  return $cfg['context_mode']??'disabled';
 }
 /**
  * Age bands for Tour pricing are defined only by an actual published Tour,
  * not by an archive, destination listing, Plan a Trip page or Form ID.
  */
 public static function current_tour_id(): int {
  global $wp_the_query;
  if(!$wp_the_query instanceof WP_Query||
     !$wp_the_query->is_singular('tours-and-activities'))return 0;
  $tour=absint($wp_the_query->get_queried_object_id());
  return $tour&&get_post_type($tour)==='tours-and-activities'&&
    get_post_status($tour)==='publish'?$tour:0;
 }
 /**
  * Fluent AJAX submissions do not have a Tour single main query.
  * The native Fluent Forms embedded-post field identifies request context.
  * This alone does NOT authorize pricing; tokens and age bands are verified
  * independently when a Tour-scoped request supplies children/infants.
  */
 public static function submission_tour_id($data=null): int {
  $request=[];
  if(is_string($_POST['data']??null))
   parse_str(wp_unslash($_POST['data']),$request);
  // Native embed context belongs to the submitted form, not to a user
  // parameter named tour_id or the archive's current listing item.
  $id=absint($request['__fluent_form_embded_post_id']??
     (is_array($data)?($data['__fluent_form_embded_post_id']??0):0));
  return $id&&get_post_type($id)==='tours-and-activities'&&
    get_post_status($id)==='publish'?$id:0;
 }
 public static function init(){
  add_action('wp_enqueue_scripts',static function(){
   wp_enqueue_style('mad4b-age-fluent-forms',
    plugins_url('../assets/fluent-ages-20261103-r2.css',__FILE__),[],
    (string)filemtime(dirname(__DIR__).'/assets/fluent-ages-20261103-r2.css'));
  });
  add_action('fluentform/form_element_start',[__CLASS__,'render'],10,1);
  add_filter('fluentform/validation_errors',[__CLASS__,'validate'],10,4);
  add_filter('fluentform/insert_response_data',[__CLASS__,'persist'],10,3);
 }
 public static function render($form){
  $cfg=self::config($form);
  if(!$cfg||$cfg['context_mode']==='disabled')return;
  $tour=self::current_tour_id();
  if($cfg['context_mode']==='tour_only'&&!$tour)return;
  wp_enqueue_script('mad4b-age-fluent-forms',plugins_url('../assets/fluent-ages.js',__FILE__),[], (string)filemtime(dirname(__DIR__).'/assets/fluent-ages.js'),true);
  $useTour=$tour&&$cfg['context_mode']!=='collect_only';
  $policy=$useTour?MAD4B_Age_Fluent_Policy::response((int)$form->id,
     ['domain'=>'tour','product_id'=>$tour]):self::generic_policy();
  $projection=is_wp_error($policy)?['error'=>$policy->get_error_code(),'mode'=>$useTour?'tour':'collect_only','context'=>['domain'=>'tour','product_id'=>$tour,'rate_id'=>0]]:$policy;
  if(!is_wp_error($policy)&&$useTour)$projection['mode']='tour';
  if(!is_wp_error($policy)&&!$useTour)$projection['mode']='collect_only';
  echo '<div data-tour-id="'.esc_attr($tour).'" data-age-policy="'.esc_attr(wp_json_encode($projection)).'" data-form-id="'.esc_attr((int)$form->id).'" data-policy-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'" data-policy-url="'.esc_url(rest_url('age-occupancy/v1/form-policy')).'" class="mad4b-age-collector" data-child-count="'.esc_attr($cfg['children']).'" data-infant-count="'.esc_attr($cfg['infants']).'" data-strict="'.(!empty($cfg['strict'])?'1':'0').'" data-max-guests="'.esc_attr(self::limit()).'" hidden>';
  echo '<p class="mad4b-age-caption">Enter each child’s age at the start of the service. Enter infant ages separately. No date of birth is required.</p>';
  echo '<div class="mad4b-age-child-fields" role="group" aria-label="Children ages"></div><div class="mad4b-age-infant-fields" role="group" aria-label="Infant ages"></div>';
  echo '<input type="hidden" name="mad4b_age_context_token" value="'.esc_attr(is_wp_error($policy)?'':$policy['token']).'" />';
  echo '<input type="hidden" name="mad4b_age_contract" value="'.esc_attr(self::VERSION).'" />';
  echo '<p class="mad4b-age-error" role="alert" hidden></p></div>';
 }
 public static function raw($data=null){
  if(is_array($data)&&isset($data['mad4b_age_contract']))return $data;
  $source=$_POST['data']??null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Fluent Forms owns its nonce.
  if(is_string($source)){
   $result=[];parse_str(wp_unslash($source),$result);
   return is_array($result)?$result:[];
  }
  return is_array($data)?$data:[];
 }
 public static function clean_ages($value,$count){
  if($count===0){if($value===null||$value===[]||$value==='')return [];return new WP_Error('age_list_unexpected');}
  if(!is_array($value)||!array_is_list($value)||count($value)!==$count)return new WP_Error('age_count_mismatch');
  $result=[];
  foreach($value as $age){
   $age=MAD4B_Age_Occupancy_Registry::normalize_age($age);
   if(is_wp_error($age))return $age;
   $result[]=$age;
  }
  return $result;
 }
 public static function validated_receipt($form,array $formData,array $trustedContext,$raw=null){
  return self::receipt($form,$formData,$raw,$trustedContext);
 }
 public static function receipt($form,$formData,$raw=null,$trustedContext=null){
  $cfg=self::config($form);if(!$cfg||$cfg['context_mode']==='disabled')return null;
  $raw=$raw===null?self::raw($formData):$raw;
  $isTour=$cfg['context_mode']!=='collect_only'&&(
    is_array($trustedContext)&&($trustedContext['domain']??'')==='tour'
      ||self::submission_tour_id($raw)>0);
  if($cfg['context_mode']==='tour_only'&&!$isTour)return null;
  if(!is_array($raw))return new WP_Error('age_form_payload_invalid');
  $counts=[];
  foreach(['children','infants'] as $group){
   $field=$cfg[$group];$value=$formData[$field]??$raw[$field]??'0';
   if(!is_scalar($value)||is_bool($value)||!preg_match('/^(?:0|[1-9][0-9]?)$/D',(string)$value)||(int)$value>self::limit())return new WP_Error('age_guest_count_invalid');
   $counts[$group]=(int)$value;
  }
  if($counts['children']+$counts['infants']>self::limit())return new WP_Error('age_guest_count_exceeded');
  $present=($raw['mad4b_age_contract']??'')===self::VERSION;
  $has_ages=isset($raw['mad4b_child_ages'])||isset($raw['mad4b_infant_ages']);
  if(!$present&&!$has_ages&&!$cfg['strict'])return null; // Legacy inquiry stays compatible.
  if(!$present&&!$has_ages&&$counts['children']+$counts['infants']===0)return null;
  if(!$present&&!$has_ages)return new WP_Error('age_form_contract_missing');
  if(!$present)return new WP_Error('age_form_contract_invalid');
  $children=self::clean_ages($raw['mad4b_child_ages']??null,$counts['children']);
  $infants=self::clean_ages($raw['mad4b_infant_ages']??null,$counts['infants']);
  if(is_wp_error($children))return $children;
  if(is_wp_error($infants))return $infants;
  $age_reference='service_start';
  if($isTour&&$counts['children']+$counts['infants']>0){
   $context=is_array($trustedContext)?MAD4B_Age_Fluent_Policy::context($trustedContext)
     :MAD4B_Age_Fluent_Policy::from_token($raw['mad4b_age_context_token']??null,
         absint(is_object($form)?$form->id:$form));
   if(is_wp_error($context))return $context;
   if(!is_array($context)||($context['product_id']??0)!==
       self::submission_tour_id($raw))return new WP_Error('age_tour_context_mismatch');
   $policy=MAD4B_Age_Fluent_Policy::validate($context,$children,$infants);
   if(is_wp_error($policy))return $policy;
   $age_reference=$policy['age_reference'];
  }elseif(!$isTour){
   $policy=self::generic_policy();
   $age_reference=$policy['age_reference'];
   foreach(['children'=>$children,'infants'=>$infants] as $kind=>$ages){
    $range=$policy['groups'][$kind][0];
    foreach($ages as $age)if($age<$range['min']||$age>$range['max'])
      return new WP_Error('age_general_range_invalid');
   }
  }
  return ['version'=>self::VERSION,'children'=>$counts['children'],'infants'=>$counts['infants'],
   'stay_children'=>$counts['children']+$counts['infants'],
   'child_ages'=>array_merge($children,$infants),'infant_ages'=>$infants,
   'age_reference'=>$age_reference,'pricing_authorized'=>false,
   'source'=>'fluent_forms_age_inputs',
   'context_mode'=>$isTour?'tour':'collect_only',
   'classification_status'=>$isTour?'tour_policy_verified':'pending_product_selection'];
 }
 public static function validate($errors,$formData,$form,$fields){
  $cfg=self::config($form);
  if(!$cfg||$cfg['context_mode']==='disabled'||(
     $cfg['context_mode']==='tour_only'&&!self::submission_tour_id($formData)))return $errors;
  $receipt=self::receipt($form,$formData);
  if(is_wp_error($receipt)){
   $errors['mad4b_age_contract'][]='Please check the number and ages of children and infants ('.esc_html($receipt->get_error_code()).').';
  }
  return $errors;
 }
 public static function persist($data,$formId,$inputConfigs){
  $cfg=self::config($formId);
  if(!$cfg||$cfg['context_mode']==='disabled'||(
     $cfg['context_mode']==='tour_only'&&!self::submission_tour_id($data)))return $data;
  // This field is server-owned. Discard anything supplied by the browser
  // before constructing the verified age receipt from the validated inputs.
  unset($data['mad4b_age_context']);
  $receipt=self::receipt($formId,$data);
  if(!is_wp_error($receipt)&&is_array($receipt))$data['mad4b_age_context']=$receipt;
  return $data;
 }
}
MAD4B_Age_Fluent_Forms::init();
