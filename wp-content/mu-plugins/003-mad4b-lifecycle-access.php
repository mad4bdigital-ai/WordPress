<?php
/** Plugin Name: MAD4B Lifecycle Access
 * Domain capabilities shared by UI, REST, generators and workers.
 */
defined('ABSPATH')||exit;
final class MAD4B_Lifecycle_Access {
 const VERSION='20261106.2';
 public static function can(string $cap,?int $id=null):bool {
  return $id===null?(current_user_can('manage_options')||current_user_can($cap)):(user_can($id,'manage_options')||user_can($id,$cap));
 }
 public static function definitions():array {return [
  'commerce_pricing_manager'=>['Pricing Manager',['edit_posts','commerce_manage_pricing','commerce_manage_policies','commerce_view_lifecycle','edit_commerce_rates','edit_others_commerce_rates','edit_published_commerce_rates','read_private_commerce_rates']],
  'commerce_rate_publisher'=>['Rate Publisher',['edit_posts','commerce_publish_rates','commerce_view_lifecycle','edit_commerce_rates','edit_others_commerce_rates','edit_published_commerce_rates','publish_commerce_rates','read_private_commerce_rates']],
  'commerce_template_editor'=>['Template Editor',['edit_posts','commerce_manage_templates','edit_commerce_templates','edit_others_commerce_templates','edit_published_commerce_templates','publish_commerce_templates','read_private_commerce_templates']],
  'commerce_inquiry_agent'=>['Inquiry Agent',['commerce_review_requests','commerce_manage_request_tasks','commerce_manage_crm','commerce_view_lifecycle']],
  'commerce_operations_manager'=>['Operations Manager',['commerce_review_requests','commerce_approve_request_processing','commerce_manage_request_tasks','commerce_manage_crm','commerce_manage_operations','commerce_manage_advances','commerce_run_workflows','commerce_view_lifecycle']],
  'commerce_accountant'=>['Accountant',['commerce_manage_advances','commerce_manage_accounting','commerce_view_lifecycle']],
  'commerce_lifecycle_worker'=>['Lifecycle Worker',['commerce_run_workflows','commerce_manage_crm','commerce_manage_request_tasks','commerce_manage_accounting']]
 ];}
 public static function all():array {
  $caps=['commerce_manage_pricing','commerce_manage_policies','commerce_publish_rates','commerce_manage_templates','commerce_review_requests','commerce_approve_request_processing','commerce_manage_request_tasks','commerce_manage_crm','commerce_manage_operations','commerce_manage_advances','commerce_manage_accounting','commerce_run_workflows','commerce_view_lifecycle','mad4b_manage_company_operations','mad4b_work_company_tasks','manage_woocommerce','view_woocommerce_reports','manage_translations','translate','wpml_manage_translation_management','wpml_operate_woocommerce_multilingual','edit_theme_options'];
  foreach(['commerce_rates','commerce_templates'] as $type)foreach(['edit_','edit_others_','edit_private_','edit_published_','publish_','read_private_','delete_','delete_others_','delete_private_','delete_published_'] as $prefix)$caps[]=$prefix.$type;
  return array_values(array_unique($caps));
 }
 public static function roleSchemaStatus():array {
  return [
   'contract'=>'mad4b.lifecycle-roles.manual-seed.v1',
   'expected_schema'=>self::VERSION,
   'installed_schema'=>get_option('mad4b_lifecycle_access_schema',null),
   'manual_review_required'=>get_option('mad4b_lifecycle_access_schema',null)!==self::VERSION,
   'wordpress_role_grants_modified'=>false,
   'editor'=>'User Role Editor'
  ];
 }
 public static function roles():void {
  // Deliberately read-only legacy API. Company Portfolio Manager calls
  // this method during initialization: it must never silently restore
  // a capability removed by an authorized User Role Editor administrator.
  // For new role definitions or new schema versions, produce an explicit
  // reviewed migration rather than mutating live wp_user_roles.
  self::roleSchemaStatus();
 }
 public static function cpt(array $args,string $type):array {
  $group=in_array($type,['tour-rates','property-rates','transport-rates'],true)?'commerce_rate':($type==='elementor_library'?'commerce_template':null);
  if($group){$args['capability_type']=[$group,$group.'s'];$args['map_meta_cap']=true;$args['capabilities']=[];}
  return $args;
 }
 public static function financialMeta($check,int $id,string $key) {
  if($check!==null)return $check;
  if($key==='_commerce_request_advance_policy'&&!self::can('commerce_manage_policies'))return false;
  if($key==='_commerce_request_advance_policy')return $check;
  $type=get_post_type($id);$rate=in_array($type,['tour-rates','property-rates','transport-rates'],true);
  $protected=($rate&&preg_match('/price|cost|currency|margin|markup|profit|amount|factor|financial|stop_sale|rate_start|rate_end/i',$key))||in_array($key,['start_price','tour_base_currency','tour_price_unit','_commerce_request_advance_policy'],true);
  if($protected&&!self::can('commerce_manage_pricing'))return false;
  return $check;
 }
 public static function wpml():void {
  if(!class_exists('WPML_Language_Pair_Records')||!class_exists('WPML_Language_Records'))return;
  global $wpdb;$records=new WPML_Language_Pair_Records($wpdb,new WPML_Language_Records($wpdb));
  // Read the site-wide language registry, not a page-dependent switcher list.
  // wp_loaded runs before the main query; wpml_active_languages primes WPML's
  // language-switcher query cache with an empty request on singular tours.
  // This must never trigger a language-switcher render during role sync.
  $sitepress=$GLOBALS['sitepress']??null;
  if(!is_object($sitepress)||!method_exists($sitepress,'get_active_languages'))return;
  $langs=$sitepress->get_active_languages();if(!is_array($langs)||count($langs)<2)return;
  $codes=array_keys($langs);sort($codes);$pairs=[];foreach($codes as $from)$pairs[$from]=array_values(array_diff($codes,[$from]));
  foreach(get_users(['role'=>'master_admin','fields'=>'ID']) as $id){
   $u=get_userdata((int)$id);if(!$u||get_user_meta($id,'mad4b_company_suspension_v1',true))continue;
   // URE owns both role and user-cap grants. WPML language pairs may
   // be synchronized only for a user who still has effective permission.
   if(!user_can((int)$id,'translate')||!user_can((int)$id,'manage_translations'))continue;
   $actual=$records->get((int)$id);$same=true;foreach($pairs as $from=>$to)if(array_diff($to,$actual[$from]??[])){$same=false;break;}
   if(!$same)$records->store((int)$id,array_replace((array)$actual,$pairs));
  }
 }
 public static function boot():void {
  add_filter('ure_capabilities_groups_tree',static function($groups){$groups['mad4b_lifecycle']=['caption'=>'MAD4B Lifecycle','parent'=>'custom','level'=>2];return $groups;});
  add_filter('ure_custom_capability_groups',static function($groups,$cap){if(str_starts_with($cap,'commerce_')||str_contains($cap,'_commerce_rates')||str_contains($cap,'_commerce_templates'))$groups[]='mad4b_lifecycle';return $groups;},10,2);
  add_filter('register_post_type_args',[self::class,'cpt'],100,2);
  add_filter('wp_insert_post_empty_content',static function($empty,$data){$t=$data['post_type']??'';if(!in_array($t,['tour-rates','property-rates','transport-rates','elementor_library'],true))return $empty;$o=get_post_type_object($t);if(!$o)return true;$cap=empty($data['ID'])?$o->cap->create_posts:'edit_post';if(!current_user_can($cap,(int)($data['ID']??0)))return true;if(in_array($data['post_status']??'',['publish','future','private'],true)&&!current_user_can($o->cap->publish_posts))return true;return $empty;},10,2);
  add_action('wp_loaded',[self::class,'wpml'],1000);
  add_filter('map_meta_cap',static function($caps,$cap,$uid,$args){if($cap==='edit_post'&&!empty($args[0])&&in_array(get_post_type((int)$args[0]),['tours-and-activities','properties','units','transportations'],true)&&self::can('commerce_manage_pricing',(int)$uid))return ['commerce_manage_pricing'];return $caps;},100,4);
  add_filter('fluent_crm/user_permissions',static function($permissions,$user){if($user && in_array('master_admin',(array)($user->roles??[]),true) && class_exists('MAD4B_Admin_Surface_Registry_V1',false)) return MAD4B_Admin_Surface_Registry_V1::fluentcrm_permissions($permissions,$user);return $permissions;},100,2);
  // Staff role assignments do not cause automatic capability reseeding.
  foreach(['set_user_role','add_user_role','user_register'] as $hook)
   add_action($hook,[self::class,'wpml'],100);
  foreach(['add_post_metadata','update_post_metadata','delete_post_metadata'] as $hook)add_filter($hook,[self::class,'financialMeta'],9,3);
 }
}
MAD4B_Lifecycle_Access::boot();
