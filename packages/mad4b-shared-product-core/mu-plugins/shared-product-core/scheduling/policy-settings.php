<?php
/** Generic scheduling policy owned by WooCommerce Settings; JetEngine taxonomies own source data. Lab only. */
if (!defined('ABSPATH') || wp_get_environment_type()!=='local' || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
final class Shared_Product_Schedule_Policy {
 const VERSION='1.0.1';
 const PREFIX='shared_product_schedule_';
 public static function option(string $name,$default=null) {
  $defaults=[
   'weekday_taxonomy'=>'schudle-days-of-weak',
   'mode'=>'inherit', 'unset_mode'=>'unknown',
   'default_weekdays'=>'', 'lead_days'=>5, 'lead_meta_key'=>'',
   'mode_meta_key'=>'', 'timezone_meta_key'=>'',
   'lead_term_taxonomy'=>'','lead_term_meta_key'=>'',
   'timezone'=>'site', 'quote_unknown'=>'allow',
   'date_inclusion_meta_key'=>'','date_exclusion_meta_key'=>'',
  ];
  $fallback=$defaults[$name]??$default;
  $v=get_option(self::PREFIX.$name,$fallback);
  return apply_filters('shared_product_schedule_policy_value',$v,$name,$fallback);
 }
 public static function taxonomy_options(): array {
  $choices=[''=>'Use registered weekday taxonomy (default)'];
  foreach(get_object_taxonomies('tours-and-activities','objects') as $slug=>$term) {
   if($term->public || in_array($slug,['season','schudle-days-of-weak'],true))
    $choices[$slug]=sprintf('%s (%s)',$term->label,$slug);
  }
  return $choices;
 }
 public static function weekdays_taxonomy(): string {
  $value=(string)self::option('weekday_taxonomy');
  return taxonomy_exists($value) && is_object_in_taxonomy('tours-and-activities',$value)
   ?$value:'schudle-days-of-weak';
 }
 public static function mode(int $tour): array {
  $value=(string)self::option('mode','inherit');$origin='woocommerce_setting';
  $key=sanitize_key((string)self::option('mode_meta_key',''));
  if($key!==''){$raw=get_post_meta($tour,$key,true);if(is_string($raw)&&in_array($raw,['inherit','explicit','all_days','unknown'],true)){$value=$raw;$origin='post_meta:'.$key;}}
  $value=in_array($value,['inherit','explicit','all_days','unknown'],true)?$value:'inherit';
  return [$value,$origin];
 }
 public static function weekdays(int $tour): array {
  $tax=self::weekdays_taxonomy();
  $map=['sunday'=>0,'monday'=>1,'tuesday'=>2,'wednesday'=>3,'thursday'=>4,'friday'=>5,'saturday'=>6];
  $list=get_the_terms($tour,$tax);
  $days=[];
  if(is_array($list))foreach($list as $term){
   $mapped=$term;
   if(has_filter('wpml_object_id')){
    $ref=apply_filters('wpml_object_id',(int)$term->term_id,$tax,false,'en');
    if($ref){$other=get_term((int)$ref,$tax);if($other instanceof WP_Term)$mapped=$other;}
   }
   $slug=strtolower((string)$mapped->slug);
   if(isset($map[$slug]))$days[]=$map[$slug];
  }
  $days=array_values(array_unique($days));sort($days);return $days;
 }
 public static function lead_days(int $tour): array {
  $value=Shared_Product_Advance_Notice::source($tour);
  if(is_wp_error($value))return [5,'fallback'];
  return [$value['days'],$value['source'].(isset($value['key'])?':'.$value['key']:'')];
 }
 public static function exceptional_dates(int $tour): array {
  $output=['include'=>[],'exclude'=>[]];
  foreach(['include'=>'date_inclusion_meta_key','exclude'=>'date_exclusion_meta_key'] as $type=>$keyName){
   $key=sanitize_key((string)self::option($keyName,''));
   if($key==='')continue;
   $raw=get_post_meta($tour,$key,true);
   $values=is_array($raw)?$raw:(is_string($raw)?preg_split('/[\s,;|]+/',$raw):[]);
   foreach(array_slice((array)$values,0,366) as $value){
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))continue;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,wp_timezone());
    if($date && $date->format('Y-m-d')===$value)$output[$type][]=$value;
   }
   $output[$type]=array_values(array_unique($output[$type]));sort($output[$type]);
  }
  return $output;
 }
 public static function timezone(int $tour): array {
  $tz=wp_timezone();$origin='wordpress_timezone';
  $mode=(string)self::option('timezone','site');
  if($mode==='tour_meta'){
   $key=sanitize_key((string)self::option('timezone_meta_key',''));
   $str=$key!==''?get_post_meta($tour,$key,true):'';
   if(is_string($str)&&in_array($str,timezone_identifiers_list(),true)){$tz=new DateTimeZone($str);$origin='post_meta:'.$key;}
  }
  return [$tz,$origin];
 }
 public static function defaults(): array {
  $res=[];
  foreach(['weekday_taxonomy','mode','unset_mode','default_weekdays','lead_days','lead_meta_key','mode_meta_key','timezone_meta_key','lead_term_taxonomy','lead_term_meta_key','timezone','quote_unknown','date_inclusion_meta_key','date_exclusion_meta_key'] as $key)$res[$key]=self::option($key);
  return $res;
 }
}
// Enforce server-side notice bounds; HTML numeric constraints alone are not authority.
add_filter('woocommerce_admin_settings_sanitize_option',static function($value,$option,$raw){
 $id=(string)($option['id']??'');
 if($id!=='shared_product_schedule_lead_days' && !str_starts_with($id,'shared_product_schedule_lead_default_'))return $value;
 if($value==='' && $id!=='shared_product_schedule_lead_days')return '';
 if(filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>365]])===false){
  if(class_exists('WC_Admin_Settings'))WC_Admin_Settings::add_error('Advance notice must be a whole number from 0 to 365 days.');
  return null; // Retain the existing option; never silently reinterpret invalid input as a new policy.
 }
 return (string)(int)$value;
},10,3);
add_filter('woocommerce_settings_tabs_array',static function($tabs){
 $tabs['program_scheduling']=__('Program Scheduling','woocommerce');
 return $tabs;
},80);
function shared_product_schedule_woocommerce_fields(): array {
 $tax=Shared_Product_Schedule_Policy::taxonomy_options();
 return [
 ['title'=>__('Program Scheduling','woocommerce'),'type'=>'title','desc'=>'Shared scheduling policy, without duplicating JetEngine source fields or booking inventory. Lab only.','id'=>'shared_product_schedule_title'],
 ['title'=>'Weekday taxonomy','id'=>'shared_product_schedule_weekday_taxonomy','type'=>'select','options'=>$tax,'default'=>'schudle-days-of-weak','desc'=>'Read taxonomy terms live, including WPML term mapping.'],
 ['title'=>'Program schedule mode','id'=>'shared_product_schedule_mode','type'=>'select','options'=>['inherit'=>'Inherit stored weekdays / configured fallback','explicit'=>'Only published weekday terms','all_days'=>'Daily operation','unknown'=>'Leave program dates unresolved'],'default'=>'inherit'],
 ['title'=>'When no weekday terms exist','id'=>'shared_product_schedule_unset_mode','type'=>'select','options'=>['unknown'=>'Unknown — require program configuration','all_days'=>'Allow daily schedule','inherit'=>'Inherit weekdays from setting below'],'default'=>'unknown'],
 ['title'=>'Inherited weekdays','id'=>'shared_product_schedule_default_weekdays','type'=>'text','desc'=>'Comma-separated weekday numbers, Sunday=0 to Saturday=6 (e.g., 1,3,5). Only used with fallback inherit.'],
 ['title'=>'Default request lead (days)','id'=>'shared_product_schedule_lead_days','type'=>'number','css'=>'width:90px','custom_attributes'=>['min'=>0,'max'=>365,'step'=>1],'default'=>5,'desc'=>'Legacy-compatible default; can be overridden per tour/term.'],
 ['title'=>'Program mode post meta key','id'=>'shared_product_schedule_mode_meta_key','type'=>'text','desc'=>'Optional existing JetEngine post meta key containing inherit, explicit, all_days, unknown.'],
 ['title'=>'Lead days post meta key','id'=>'shared_product_schedule_lead_meta_key','type'=>'text','desc'=>'Optional existing numeric JetEngine tour post meta key (0–365).'],
 ['title'=>'Lead days taxonomy','id'=>'shared_product_schedule_lead_term_taxonomy','type'=>'select','options'=>$tax,'desc'=>'Optional existing taxonomy whose term meta can override lead days.'],
 ['title'=>'Lead days term meta key','id'=>'shared_product_schedule_lead_term_meta_key','type'=>'text','desc'=>'Optional existing numeric term meta key; post-level values take precedence.'],
 ['title'=>'Timezone source','id'=>'shared_product_schedule_timezone','type'=>'select','options'=>['site'=>'WordPress site timezone','tour_meta'=>'Valid IANA timezone from tour meta, falling back to site'],'default'=>'site'],
 ['title'=>'Timezone post meta key','id'=>'shared_product_schedule_timezone_meta_key','type'=>'text'],
 ['title'=>'Additional operating dates meta key','id'=>'shared_product_schedule_date_inclusion_meta_key','type'=>'text','desc'=>'Optional existing tour meta: YYYY-MM-DD dates, comma-separated or stored array.'],
 ['title'=>'Excluded operating dates meta key','id'=>'shared_product_schedule_date_exclusion_meta_key','type'=>'text','desc'=>'Optional existing tour meta for blackout dates. Exclusions win over inclusions.'],
 ['title'=>'Unknown schedule for price quote','id'=>'shared_product_schedule_quote_unknown','type'=>'select','options'=>['allow'=>'Allow indicative quotation only; never booking confirmation','deny'=>'Reject quotation until calendar policy defined'],'default'=>'allow'],
 ...Shared_Product_Advance_Notice::woocommerce_fields(),
 ['type'=>'sectionend','id'=>'shared_product_schedule_title'],
 ];
}
add_action('woocommerce_settings_tabs_program_scheduling',static function(){
 if(!current_user_can('manage_woocommerce'))return;
 WC_Admin_Settings::output_fields(shared_product_schedule_woocommerce_fields());
});
add_action('woocommerce_update_options_program_scheduling',static function(){
 if(!current_user_can('manage_woocommerce'))return;
 WC_Admin_Settings::save_fields(shared_product_schedule_woocommerce_fields());
});
