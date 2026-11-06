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
 public static function roles():void {
  // User Role Editor owns subsequent role edits. Seed the schema once, never regrant staff caps on every request.
  if(get_option('mad4b_lifecycle_access_schema')===self::VERSION)return;
  foreach(self::definitions() as $slug=>[$label,$caps]){$role=get_role($slug)?:add_role($slug,$label,['read'=>true]);foreach($caps as $cap)if(!$role->has_cap($cap))$role->add_cap($cap);}
  $accountant=get_role('commerce_accountant');$native=get_role('erp_ac_manager');if($accountant&&$native)foreach($native->capabilities as $c=>$on)if($on&&str_starts_with($c,'erp_ac_')&&!$accountant->has_cap($c))$accountant->add_cap($c);
  $master=get_role('master_admin')?:add_role('master_admin','Master Admin',['read'=>true]);
  $all=self::all();
  // Site operations only; no plugin installation, arbitrary user promotion or platform-wide admin.
  foreach(['editor','shop_manager','erp_ac_manager'] as $slug){$role=get_role($slug);if(!$role)continue;foreach($role->capabilities as $cap=>$on)if($on&&($slug!=='editor'||!in_array($cap,['manage_options'],true)))$all[]=$cap;}
  $admin=get_role('administrator');if($admin)foreach($admin->capabilities as $cap=>$on)if($on&&(str_starts_with($cap,'fluentform_')||str_starts_with($cap,'wpml_')))$all[]=$cap;
  foreach(array_unique($all) as $cap)if(!$master->has_cap($cap))$master->add_cap($cap);
  if($admin)foreach(self::all() as $cap)if(!$admin->has_cap($cap))$admin->add_cap($cap);
  update_option('mad4b_lifecycle_access_schema',self::VERSION,false);
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
   $actual=$records->get((int)$id);$same=true;foreach($pairs as $from=>$to)if(array_diff($to,$actual[$from]??[])){$same=false;break;}
   if(!$same)$records->store((int)$id,array_replace((array)$actual,$pairs));
   // WPML translator discovery queries per-user capabilities, not merely role labels.
   if(empty($u->caps['translate']))$u->add_cap('translate',true);
   if(empty($u->caps['manage_translations']))$u->add_cap('manage_translations',true);
  }
 }
 public static function boot():void {
  add_filter('ure_capabilities_groups_tree',static function($groups){$groups['mad4b_lifecycle']=['caption'=>'MAD4B Lifecycle','parent'=>'custom','level'=>2];return $groups;});
  add_filter('ure_custom_capability_groups',static function($groups,$cap){if(str_starts_with($cap,'commerce_')||str_contains($cap,'_commerce_rates')||str_contains($cap,'_commerce_templates'))$groups[]='mad4b_lifecycle';return $groups;},10,2);
  add_filter('register_post_type_args',[self::class,'cpt'],100,2);
  add_filter('wp_insert_post_empty_content',static function($empty,$data){$t=$data['post_type']??'';if(!in_array($t,['tour-rates','property-rates','transport-rates','elementor_library'],true))return $empty;$o=get_post_type_object($t);if(!$o)return true;$cap=empty($data['ID'])?$o->cap->create_posts:'edit_post';if(!current_user_can($cap,(int)($data['ID']??0)))return true;if(in_array($data['post_status']??'',['publish','future','private'],true)&&!current_user_can($o->cap->publish_posts))return true;return $empty;},10,2);
  add_action('init',[self::class,'roles'],1);
  add_action('wp_loaded',[self::class,'wpml'],1000);
  add_filter('map_meta_cap',static function($caps,$cap,$uid,$args){if($cap==='edit_post'&&!empty($args[0])&&in_array(get_post_type((int)$args[0]),['tours-and-activities','properties','units','transportations'],true)&&self::can('commerce_manage_pricing',(int)$uid))return ['commerce_manage_pricing'];return $caps;},100,4);
  add_filter('fluent_crm/user_permissions',static function($permissions,$user){if($user&&in_array('master_admin',(array)$user->roles,true)&&class_exists(\FluentCrm\App\Services\PermissionManager::class))return \FluentCrm\App\Services\PermissionManager::pluginPermissions();return $permissions;},100,2);
  foreach(['set_user_role','add_user_role','user_register'] as $hook)add_action($hook,static function(){self::roles();self::wpml();},100);
  foreach(['add_post_metadata','update_post_metadata','delete_post_metadata'] as $hook)add_filter($hook,[self::class,'financialMeta'],9,3);
 }
}
MAD4B_Lifecycle_Access::boot();
