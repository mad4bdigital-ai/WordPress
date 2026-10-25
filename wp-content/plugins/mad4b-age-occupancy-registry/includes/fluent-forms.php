<?php
/** Non-destructive age inputs for existing Fluent Forms booking/inquiry requests. */
if (!defined('ABSPATH')) exit;
final class MAD4B_Age_Fluent_Forms {
 const VERSION='age-request.v1';
 public static function forms(){
  // IDs discovered from published Local forms. Extend using the filter for other sites.
  $result=apply_filters('mad4b/age_occupancy/fluent_forms',[
   5=>['children'=>'numeric_field_1','infants'=>'numeric_field_2','strict'=>false],
   6=>['children'=>'no_of_children','infants'=>'no_of_infants','strict'=>true],
   7=>['children'=>'no_of_children','infants'=>'no_of_infants','strict'=>true],
   8=>['children'=>'no_of_children','infants'=>'no_of_infants','strict'=>true],
  ]);
  return is_array($result)?$result:[];
 }
 public static function config($form){
  $id=absint(is_object($form)?($form->id??0):$form);
  $cfg=self::forms()[$id]??null;
  if(!is_array($cfg)||empty($cfg['children'])||empty($cfg['infants']))return null;
  foreach(['children','infants'] as $field)if(!is_string($cfg[$field])||!preg_match('/^[a-z][a-z0-9_]{0,60}$/D',$cfg[$field]))return null;
  return $cfg;
 }
 public static function init(){
  add_action('wp_enqueue_scripts',static function(){wp_enqueue_style('mad4b-age-fluent-forms',plugins_url('../assets/fluent-ages.css',__FILE__),[], '0.1.0');});
  add_action('fluentform/form_element_start',[__CLASS__,'render'],10,1);
  add_filter('fluentform/validation_errors',[__CLASS__,'validate'],10,4);
  add_filter('fluentform/insert_response_data',[__CLASS__,'persist'],10,3);
 }
 public static function render($form){
  $cfg=self::config($form);if(!$cfg)return;
  wp_enqueue_script('mad4b-age-fluent-forms',plugins_url('../assets/fluent-ages.js',__FILE__),[], '0.1.0',true);
  echo '<div class="mad4b-age-collector" data-child-count="'.esc_attr($cfg['children']).'" data-infant-count="'.esc_attr($cfg['infants']).'" data-strict="'.(!empty($cfg['strict'])?'1':'0').'" hidden>';
  echo '<p class="mad4b-age-caption">Enter each child’s age at the start of the service. Enter infant ages separately. No date of birth is required.</p>';
  echo '<div class="mad4b-age-child-fields" role="group" aria-label="Children ages"></div><div class="mad4b-age-infant-fields" role="group" aria-label="Infant ages"></div>';
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
   if(!is_scalar($age)||is_bool($age)||!preg_match('/^(?:0|[1-9][0-9]{0,2})$/D',(string)$age)||(int)$age>120)return new WP_Error('age_value_invalid');
   $result[]=(int)$age;
  }
  return $result;
 }
 public static function receipt($form,$formData,$raw=null){
  $cfg=self::config($form);if(!$cfg)return null;
  $raw=$raw===null?self::raw($formData):$raw;
  if(!is_array($raw))return new WP_Error('age_form_payload_invalid');
  $counts=[];
  foreach(['children','infants'] as $group){
   $field=$cfg[$group];$value=$formData[$field]??$raw[$field]??'0';
   if(!is_scalar($value)||is_bool($value)||!preg_match('/^(?:0|[1-9][0-9]?)$/D',(string)$value)||(int)$value>50)return new WP_Error('age_guest_count_invalid');
   $counts[$group]=(int)$value;
  }
  if($counts['children']+$counts['infants']>50)return new WP_Error('age_guest_count_exceeded');
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
  return ['version'=>self::VERSION,'children'=>$counts['children'],'infants'=>$counts['infants'],'stay_children'=>$counts['children']+$counts['infants'],
   'child_ages'=>array_merge($children,$infants),'infant_ages'=>$infants,
   'age_reference'=>'service_start','pricing_authorized'=>false,
   'source'=>'fluent_forms_age_inputs'];
 }
 public static function validate($errors,$formData,$form,$fields){
  if(!self::config($form))return $errors;
  $receipt=self::receipt($form,$formData);
  if(is_wp_error($receipt)){
   $errors['mad4b_age_contract'][]='Please check the number and ages of children and infants ('.esc_html($receipt->get_error_code()).').';
  }
  return $errors;
 }
 public static function persist($data,$formId,$inputConfigs){
  if(!self::config($formId))return $data;
  $receipt=self::receipt($formId,$data);
  if(!is_wp_error($receipt)&&is_array($receipt))$data['mad4b_age_context']=$receipt;
  return $data;
 }
}
MAD4B_Age_Fluent_Forms::init();
