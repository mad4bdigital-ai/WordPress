<?php
/**
 * Plugin Name: MAD4B Company Reconcile — Target Evidence V1
 * Description: On-demand, read-only local runtime/translation/media/commerce evidence for Company Reconcile and Start Company. Never an execution grant or publication receipt.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Company_Reconcile_Target_Evidence_V1',false)) {
final class MAD4B_Company_Reconcile_Target_Evidence_V1 {
 const CONTRACT='mad4b.company-reconcile.target-evidence.v1';
 private static function check(string $name,string $state,string $owner,string $scope,array $facts=[]):array {
  return ['check'=>$name,'state'=>$state,'owner'=>$owner,'ownership_scope'=>$scope,'facts'=>$facts,
   'native_readback_verified'=>false,'closure_authorized'=>false];
 }
 private static function main_languages():array {
  if(!class_exists('SitePress',false))return ['en'];
  $langs=(array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
  if(!$langs)return ['en'];
  return array_values(array_filter(array_keys($langs),static fn($x)=>is_string($x)&&preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',$x)));
 }
 private static function archive_date_widgets():array {
  global $wpdb;
  $languages=self::main_languages();
  $hasIcl=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.'icl_translations'))===$wpdb->prefix.'icl_translations';
  $rows=$wpdb->get_results($wpdb->prepare(
   "SELECT p.ID, p.post_status, m.meta_value FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_elementor_data' WHERE p.post_type='elementor_library' AND p.post_status='publish' AND m.meta_value LIKE %s ORDER BY p.ID LIMIT 80",
   '%mad4b_unified_date_picker%'),ARRAY_A);
  $found=[];
  foreach((array)$rows as $r){
   $data=json_decode((string)$r['meta_value'],true);
   if(!is_array($data))continue;
   $foundDate=false;
   $walk=static function(array $nodes)use(&$walk,&$foundDate):void {
    foreach($nodes as $node){
     if(!is_array($node))continue;
     $shortcode=$node['settings']['shortcode']??null;
     if(is_string($shortcode)&&trim($shortcode)==='[mad4b_unified_date_picker context="archive"]')$foundDate=true;
     if(isset($node['elements'])&&is_array($node['elements']))$walk($node['elements']);
    }
   };
   $walk($data);
   if(!$foundDate)continue;
   $lang='unknown';
   if($hasIcl)$lang=(string)($wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type='post_elementor_library' LIMIT 1",(int)$r['ID']))?:'unknown');
   $found[$lang]=($found[$lang]??0)+1;
  }
  // Every active public language needs a local Date control; fallback to EN only when WPML is absent.
  $expected=$languages?:['en'];
  $missing=array_values(array_filter($expected,static fn($lang)=>empty($found[$lang])));
  return ['state'=>$missing?'ADVISORY':'PASS_LOCAL','owner'=>'Elementor + Unified Date',
   'facts'=>['expected_languages'=>$expected,'render_source_templates_by_locale'=>$found,'missing_locales'=>$missing,
    'explicit_translation_missing_not_frontend_failure'=>true,
    'fallback_requires_browser_locale_acceptance'=>true,
    'elementor_generated_cache_is_not_source'=>true]];
 }
 private static function age():array {
  $configured=get_option('mad4b_age_occupancy_policy_product_types_v1',[]);
  $row=is_array($configured)?($configured['tour']??null):null;
  $collection=get_option('mad4b_age_collection_policy_v1',[]);
  $bands=[];
  foreach((array)($row['bands']??[]) as $band){if(is_array($band)&&is_string($band['id']??null))$bands[$band['id']]=[$band['min_age']??null,$band['max_age']??null];}
  $ok=is_array($row)&&is_array($collection)&&isset($bands['child'],$bands['infant'],$bands['adult']);
  foreach(['child'=>'children','infant'=>'infants'] as $group=>$type){
   if(($bands[$group]??[])!==[$collection[$type]['min']??null,$collection[$type]['max']??null])$ok=false;
  }
  return ['state'=>$ok?'PASS_LOCAL':'DRIFT','owner'=>'Age Occupancy Registry',
   'facts'=>['tour_policy_present'=>is_array($row),'bands_match_company_collection'=>$ok,
    'fluent_age_hook_registered'=>(bool)has_filter('fluentform/insert_response_data',['MAD4B_Age_Fluent_Forms','persist']),
    'age_pricing_enabled'=>(bool)($row['age_pricing_enabled']??false),
    'commercial_pricing_is_separate'=>true]];
 }
 private static function media():array {
  global $wpdb;
  $rows=$wpdb->get_results("SELECT post_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='dfsb_background_gallery' AND meta_value<>'' ORDER BY post_id LIMIT 70",ARRAY_A);
  $total=0;$missing=[];$templates=0;
  foreach((array)$rows as $r){
   if(get_post_type((int)$r['post_id'])!=='elementor_library')continue;
   $gallery=maybe_unserialize($r['meta_value']);if(!is_array($gallery)||!$gallery)continue;
   $templates++;
   foreach($gallery as $id){$total++;$n=absint($id);$f=$n?get_attached_file($n):false;
    if(!$f||!is_file($f)||get_post_type($n)!=='attachment'){
     if(count($missing)<20)$missing[]=['template_id'=>(int)$r['post_id'],'attachment_id'=>$n];
    }
   }
  }
  return ['state'=>$missing?'DRIFT':($templates?'PASS_LOCAL':'UNVERIFIED'),
   'owner'=>'Media / Elementor','facts'=>['gallery_template_count'=>$templates,'attachments_checked'=>$total,
   'invalid_local_attachment_references'=>$missing,'rights_and_visual_approval_not_inferred'=>true]];
 }
 private static function translations():array {
  global $wpdb;
  $table=$wpdb->prefix.'icl_translations';
  if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return ['state'=>'UNVERIFIED','owner'=>'WPML','facts'=>['wpml_translation_table_available'=>false]];
  $rows=$wpdb->get_results("SELECT p.ID,t.trid FROM {$wpdb->posts} p JOIN {$table} t ON t.element_id=p.ID AND t.element_type='post_jet-engine' AND t.language_code='en' WHERE p.post_type='jet-engine' AND p.post_status='publish' ORDER BY p.ID LIMIT 200",ARRAY_A);
  $missing=[];
  foreach((array)$rows as $r){
   $linked=(int)$wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$table} t JOIN {$wpdb->posts} p ON p.ID=t.element_id WHERE t.trid=%d AND t.element_type='post_jet-engine' AND t.language_code='es' AND p.post_type='jet-engine' AND p.post_status='publish' LIMIT 1",(int)$r['trid']));
   if(!$linked&&count($missing)<30)$missing[]=(int)$r['ID'];
  }
  return ['state'=>$missing?'ADVISORY':'PASS_LOCAL','owner'=>'WPML + JetEngine','facts'=>['en_templates_checked'=>count((array)$rows),
   'missing_es_translation_count'=>count($missing),'unpaired_es_en_template_ids_for_review'=>$missing,'not_all_templates_require_translation'=>true,'local_id_remap_required'=>true]];
 }
 private static function rate_lifecycle():array {
  global $wpdb;
  $n=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='related_tour_id' JOIN {$wpdb->posts} tour ON tour.ID=CAST(m.meta_value AS UNSIGNED) WHERE p.post_type='tour-rates' AND p.post_status='publish' AND tour.post_type='tours-and-activities' AND tour.post_status='draft'");
  return ['state'=>$n?'ADVISORY':'PASS_LOCAL','owner'=>'Company Content / Commerce',
   'facts'=>['published_rates_attached_to_draft_tours'=>$n,'auto_publish_tours'=>false,'auto_reprice_rates'=>false]];
 }
 public static function collect():array {
  $profile=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::profile():[];
  $status=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():[];
  $uuid=(string)($profile['site_uuid']??'');$origin=untrailingslashit(home_url('/'));
  // A syntactically valid UUID is not enough. Verify the enrolled Core
  // site identity against runtime origin, environment and deployment binding.
  $identity=wp_is_uuid($uuid)&&str_starts_with($origin,'https://')&&
   ($profile['canonical_origin']??null)===$origin&&
   ($profile['environment']??null)===wp_get_environment_type()&&
   ($status['configured']??false)===true&&
   ($status['origin_match']??false)===true&&
   ($status['environment_match']??false)===true&&
   ($status['deployment_binding_match']??false)===true&&
   ($status['site_uuid']??null)===$uuid;
  $age=self::age();$date=self::archive_date_widgets();$media=self::media();$wpml=self::translations();$rates=self::rate_lifecycle();
  $dateRuntime=function_exists('mad4b_unified_date_enabled_v1')&&mad4b_unified_date_enabled_v1()&&shortcode_exists('mad4b_unified_date_picker');
  $checks=[
   self::check('site_identity',$identity?'PASS_LOCAL':'DRIFT','Site Profile','site_local',['site_uuid_valid'=>wp_is_uuid($uuid),'https_origin'=>str_starts_with($origin,'https://'),
    'core_origin_match'=>($status['origin_match']??false)===true,
    'core_environment_match'=>($status['environment_match']??false)===true,
    'core_deployment_binding_match'=>($status['deployment_binding_match']??false)===true]),
   self::check('age_policy_contract',$age['state'],$age['owner'],'company_policy',$age['facts']),
   self::check('age_order_metadata_bridge',has_action('commerce_composition_request_advance_created',['MAD4B_Fluent_Woo_Age_Context_V1','created'])!==false?'PASS_LOCAL':'DRIFT','Fluent Forms + WooCommerce','shared_runtime',['metadata_only'=>true,'no_payment_policy_override'=>true]),
   self::check('unified_date_runtime',$dateRuntime?'PASS_LOCAL':'DRIFT','Unified Date','shared_runtime',['runtime_enabled'=>$dateRuntime]),
   self::check('archive_date_shortcode',$date['state'],$date['owner'],'shared_template',$date['facts']),
   self::check('wpml_jet_template_translation',$wpml['state'],$wpml['owner'],'shared_template',$wpml['facts']),
   self::check('archive_media_local_identity',$media['state'],$media['owner'],'tenant_media',$media['facts']),
   self::check('published_rate_draft_tour',$rates['state'],$rates['owner'],'tenant_commerce',$rates['facts']),
   self::check('visual_ajax_acceptance','UNVERIFIED','Browser Acceptance','site_generated',['desktop_mobile_wpml_ajax_required'=>true]),
   self::check('seo_and_conversion','UNVERIFIED','Rank Math + Analytics','tenant_configuration',['local_seo_settings_not_authoritative_readback'=>true]),
  ];
  $counts=array_count_values(array_column($checks,'state'));
  $active=(array)get_option('active_plugins',[]);sort($active,SORT_STRING);
  return ['contract'=>self::CONTRACT,'collected_utc'=>gmdate('c'),'site'=>['site_uuid'=>$identity?$uuid:null,'origin'=>$origin,'environment'=>wp_get_environment_type()],
   'source_of_evidence'=>'LOCAL_WORDPRESS_READ_ONLY','provider_signature_present'=>false,
   'target_fleet_readback_verified'=>false,'native_receipt_313_present'=>false,
   'native_execution_authorized'=>false,'reconcile_closure_accepted'=>false,
   'active_plugin_inventory_sha256'=>hash('sha256',wp_json_encode($active)),
   'checks'=>$checks,'status_counts'=>$counts,
   'state'=>!empty($counts['DRIFT'])?'LOCAL_DRIFT_DETECTED':'LOCAL_CHECKS_REPORTED',
   'mutation_performed'=>false];
 }
 public static function routes():void {
  register_rest_route('mad4b/v1','/company-reconcile/target-evidence',['methods'=>'GET',
   'permission_callback'=>static fn()=>current_user_can('manage_options')||current_user_can('edit_commerce_templates'),
   'callback'=>static function($request){$r=self::collect();$want=(string)$request->get_param('expected_site_uuid');
    if($want!==''&&!hash_equals((string)($r['site']['site_uuid']??''),$want))return new WP_Error('target_identity_mismatch','Expected Site UUID mismatch',['status'=>409]);
    $out=rest_ensure_response($r);$out->header('Cache-Control','private, no-store');return $out;}]);
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'routes'],30);
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
   WP_CLI::add_command('mad4b company-reconcile-evidence',static function($args){if(($args[0]??'audit')!=='audit')WP_CLI::error('Read-only audit only');WP_CLI::line(wp_json_encode(self::collect(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));});
 }
}
MAD4B_Company_Reconcile_Target_Evidence_V1::boot();
}
