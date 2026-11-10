<?php
/** Plugin Name: MAD4B Reusable Company Market Portfolio */
defined('ABSPATH')||exit;
final class MAD4B_Company_Market_Portfolio_V1 {
 const CONTRACT='mad4b.company-market-portfolio.v1';
 const TEMPLATE='mad4b_central_market_portfolio_template_v1';
 const PLANS='mad4b_central_company_market_portfolio_plans_v1';
 const PAGE='mad4b-company-market-portfolio';
 const PRESETS=['EG-en','US-en','GB-en','CA-en','AU-en','AE-en','DE-de','AT-de','CH-de','ES-es','MX-es','US-es','IT-it','FR-fr','BE-fr','CA-fr','CH-fr'];
 const MODES=['language_country'=>'inherit','subregion'=>'extend','service_destination'=>'inherit','currencies'=>'inherit','pricing'=>'inherit','tax'=>'locked','supplier'=>'locked','legal'=>'locked','seo'=>'extend','audience'=>'extend'];
 static function native():bool{return class_exists('MAD4B_SCP_Market_Profiles');}
 static function central():bool{return class_exists('MAD4B_Company_Strategy_V1')&&MAD4B_Company_Strategy_V1::central();}
 static function can_write():bool{return current_user_can('manage_options')&&self::native()&&MAD4B_SCP_Market_Profiles::site_uuid()!=='';}
 static function definition():array{return [
  'contract'=>self::CONTRACT,'version'=>1,'state'=>'reusable_template_not_commercial_authority',
  'source_priority'=>['WPML_public_languages','CLDR_language_country_advisory','WooCommerce_country_registry','WooCommerce_subregion_registry','site_country_language_exclusions','verified_company_strategy'],
  'variables'=>[
    'visitor_country'=>'ISO-2 country explicitly selected or observed; not service destination',
    'content_language'=>'WPML public language; never enable hidden/excluded languages',
    'visitor_subregion'=>'WooCommerce states/provinces optional, unapproved by default',
    'customer_nationality'=>'separate explicit input; never infer from language or residence',
    'service_destination'=>'separate destination taxonomy and activation proof',
    'display_currency'=>'resolve configured WCML/provider mapping; no inferred FX',
    'price_source'=>'native tour/property/transport Rates, not generated guesses',
    'tax_jurisdiction'=>'configured tax rules; never assume tax percentage',
    'supplier_availability'=>'supplier API/evidence; cannot infer from published price',
    'marketing_segment'=>'country + language + optional subregion and consent',
  ],
  'strategies'=>[
    'localization'=>'visible WPML only; explicit original-language fallback',
    'seo'=>'country-language variant is research candidate, not automatic landing-page publication',
    'pricing'=>'inherit native rate authority; separate market, taxes, fee and discounts',
    'targeting'=>'visitor origin independent of tour destination and passport nationality',
    'legal'=>'country/region targeting needs independently verified sales/marketing eligibility',
    'retention'=>'on provision copy blueprint policy, never copy another company market approvals',
  ],
  'policy_modes'=>self::MODES,'demo_source_pairs'=>self::PRESETS,
  'company_inheritance'=>'inherit_extend_override_locked','native_approval_required'=>true,
  'new_company_auto_create'=>'company_bound_policy_plan_only',
  'auto_activate_markets'=>false,'auto_checkout'=>false,
 ];}
 static function sha(array $v):string{return hash('sha256',wp_json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
 static function template():array{
  $r=get_option(self::TEMPLATE,[]);if(!is_array($r)||($r['contract']??'')!==self::CONTRACT||!is_array($r['definition']??null))return [];
  return hash_equals((string)($r['sha256']??''),self::sha($r['definition']))?$r:[];
 }
 static function initialize_template(){
  if(!self::central()||!self::can_write())return new WP_Error('market_template_admin_required');
  $old=self::template();if($old)return ['state'=>'EXISTS','sha256'=>$old['sha256']];
  if(get_option(self::TEMPLATE,null)!==null)return new WP_Error('market_template_conflict');
  $def=self::definition();$row=['contract'=>self::CONTRACT,'version'=>1,'site_uuid'=>MAD4B_SCP_Market_Profiles::site_uuid(),'created_at_utc'=>gmdate('c'),'definition'=>$def,'sha256'=>self::sha($def)];
  if(!add_option(self::TEMPLATE,$row,'',false))return new WP_Error('market_template_save_failed');
  $check=self::template();if(!$check||$check['sha256']!==$row['sha256'])return new WP_Error('market_template_readback_failed');
  return ['state'=>'CREATED','sha256'=>$row['sha256']];
 }
 static function candidates(string $lang='',string $country='',int $limit=80):array {
  if(!self::native())return [];
  $lang=sanitize_key($lang);$country=strtoupper(sanitize_text_field($country));
  $languages=MAD4B_SCP_Market_Profiles::enabled_languages();
  $countries=function_exists('WC')&&WC()->countries?WC()->countries->get_countries():[];
  $out=[];
  foreach(MAD4B_SCP_Market_Profiles::suggestions($lang) as $row){
   $cc=(string)($row['country']??'');$ll=(string)($row['language']??'');
   if(($country&&$cc!==$country)||!isset($languages[$ll])||!isset($countries[$cc])||!MAD4B_SCP_Market_Profiles::is_targetable($cc,$ll))continue;
   $states=(array)(WC()->countries->get_states($cc)?:[]);
   $out[strtolower($cc).'-'.$ll]=['country'=>$cc,'language'=>$ll,'label'=>$countries[$cc],'language_name'=>$languages[$ll],
    'subregion_count'=>count($states),'subregion_examples'=>array_slice($states,0,5,true),
    'source'=>['WPML','CLDR_advisory','WooCommerce'],
    'status'=>'candidate_not_approved'];
   if(count($out)>=$limit)break;
  }
  return $out;
 }
 static function excluded_opportunities(int $limit=80):array {
  if(!self::native()||!function_exists('WC')||!WC()->countries)return [];
  $policy=MAD4B_SCP_Market_Profiles::targeting_policy();
  $excludeCountries=array_map('strtoupper',(array)($policy['excluded_countries']??[]));
  $excludeLanguages=array_map('strtolower',(array)($policy['excluded_languages']??[]));
  if(!$excludeCountries&&!$excludeLanguages)return [];
  $file=WP_CONTENT_DIR.'/plugins/mad4b-site-control-plane/data/market-language-territories.json';
  $source=is_file($file)?json_decode((string)file_get_contents($file),true):[];
  if(!is_array($source['languages']??null))return [];
  $languages=MAD4B_SCP_Market_Profiles::enabled_languages();
  $countries=WC()->countries->get_countries();$out=[];
  foreach($languages as $lang=>$langLabel){
   foreach((array)($source['languages'][$lang]??[]) as $row){
    $cc=(string)($row['country']??'');
    if(!preg_match('/^[A-Z]{2}$/D',$cc)||!isset($countries[$cc]))continue;
    $reasons=[];
    if(in_array($cc,$excludeCountries,true))$reasons[]='country_excluded_by_current_policy';
    if(in_array($lang,$excludeLanguages,true))$reasons[]='language_excluded_by_current_policy';
    if(!$reasons)continue;
    $key=strtolower($cc).'-'.$lang;
    $out[$key]=['country'=>$cc,'country_label'=>$countries[$cc],
     'language'=>$lang,'language_label'=>$langLabel,'blocked_reasons'=>$reasons,
     'cldr_evidence'=>$row['evidence']??'spoken','status'=>'POLICY_BLOCKED_REVIEW_ONLY',
     'market_approved'=>false,'native_draft_created'=>false];
    if(count($out)>=$limit)break 2;
   }
  }
  return $out;
 }
 static function service_coverage():array {
  if(!taxonomy_exists('destinations'))return ['state'=>'not_found','country_terms'=>[]];
  $roots=get_terms(['taxonomy'=>'destinations','parent'=>0,'hide_empty'=>false,'number'=>15]);
  if(is_wp_error($roots))return ['state'=>'error','country_terms'=>[]];
  $rows=[];
  foreach($roots as $root){
   $terms=get_terms(['taxonomy'=>'destinations','parent'=>$root->term_id,'hide_empty'=>false,'number'=>100]);
   if(is_wp_error($terms))continue;
   foreach($terms as $t){
    if((string)get_term_meta($t->term_id,'is_public',true)!=='1')continue;
    $rows[]=['term_id'=>(int)$t->term_id,'name'=>$t->name,'source'=>'destinations_public_term','supplier_coverage_verified'=>false];
   }
  }
  return ['state'=>'observed','country_terms'=>array_slice($rows,0,40)];
 }
 static function prepare_company(string $code){
  if(!self::central()||!self::can_write())return new WP_Error('company_market_admin_required');
  $code=sanitize_key($code);
  if(!class_exists('MAD4B_Company_Strategy_V1'))return new WP_Error('company_strategy_absent');
  $b=MAD4B_Company_Strategy_V1::binding($code);if(is_wp_error($b))return $b;
  $tpl=self::template();if(!$tpl)return new WP_Error('market_template_missing');
  $plans=get_option(self::PLANS,[]);if(!is_array($plans))return new WP_Error('market_plans_corrupt');
  $old=$plans[$code]??null;
  if(is_array($old)){
   if(($old['company_site_uuid']??'')!==$b['company_site_uuid'])return new WP_Error('company_site_identity_drift');
   if(($old['template_sha256']??'')!==$tpl['sha256'])return new WP_Error('company_market_template_changed_review_needed');
   return ['state'=>'EXISTS','plan'=>$old];
  }
  $plan=['contract'=>self::CONTRACT,'company_code'=>$code,'company_site_uuid'=>$b['company_site_uuid'],
   'central_source_uuid'=>$tpl['site_uuid'],'template_sha256'=>$tpl['sha256'],'revision'=>1,
   'state'=>'READY_FOR_COMPANY_REVIEW','preset_pairs'=>self::PRESETS,'inheritance'=>self::MODES,
   'target_wpml_discovery_required'=>true,'target_currency_discovery_required'=>true,
   'target_service_eligibility_required'=>true,'target_region_candidates_dynamic'=>true,
   'source_rate_revalidation_required'=>true,'delivery_state'=>'BLUEPRINT_ONLY_NOT_INSTALLED_ON_TARGET',
   'target_markets_approved'=>false,'checkout_authorized'=>false,'created_at_utc'=>gmdate('c')];
  $plans[$code]=$plan;update_option(self::PLANS,$plans,false);
  $read=(array)get_option(self::PLANS,[]);
  if(($read[$code]['template_sha256']??'')!==$tpl['sha256'])return new WP_Error('company_market_plan_readback_failed');
  return ['state'=>'CREATED','plan'=>$plan];
 }
 static function on_company_enrolled($code):void {
  // Company onboarding creates only a reusable scoped policy plan; remote site remains untouched.
  if(self::central()&&self::template()&&self::can_write())self::prepare_company((string)$code);
 }
 static function export_payload():array {
  $tpl=self::template();$plans=(array)get_option(self::PLANS,[]);
  return ['contract'=>self::CONTRACT,'exported_at_utc'=>gmdate('c'),
   'native_market_approvals_exported'=>false,'template'=>$tpl,
   'company_plans'=>$plans,'current_site_languages'=>self::native()?array_keys(MAD4B_SCP_Market_Profiles::enabled_languages()):[],
   'current_site_targeting'=>self::native()?MAD4B_SCP_Market_Profiles::targeting_policy():[],
   'country_language_candidates'=>self::candidates('','',300),
   'policy_blocked_market_opportunities'=>self::excluded_opportunities(300),
   'subregion_candidates_saved'=>(array)get_option(self::REGIONS,[]),
   'service_destination_evidence'=>self::service_coverage(),
   'target_commercial_authority'=>false];
 }
 static function export():void {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  check_admin_referer('mad4b_market_portfolio_export');
  nocache_headers();header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="company-market-portfolio-blueprint.json"');
  echo wp_json_encode(self::export_payload(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
 }
 static function menu():void {
  if(!current_user_can('manage_options'))return;
  if(self::central())add_submenu_page('mad4b-control-plane','Company Market Portfolio','Market Portfolio','manage_options',self::PAGE,[self::class,'page']);
  add_submenu_page('woocommerce','Company Market Portfolio','Market Portfolio','manage_options',self::PAGE,[self::class,'page']);
 }
 const REGIONS='mad4b_company_market_subregion_candidates_v1';
 static function create_region(string $country,string $lang,string $state){
  if(!self::can_write())return new WP_Error('market_region_admin_required');
  $country=strtoupper(sanitize_text_field($country));$lang=sanitize_key($lang);$state=strtoupper(sanitize_text_field($state));
  if(!preg_match('/^[A-Z]{2}$/D',$country)||!preg_match('/^[a-z]{2,8}$/D',$lang)||!preg_match('/^[A-Z0-9_-]{1,12}$/D',$state))return new WP_Error('market_region_codes_invalid');
  if(!isset(MAD4B_SCP_Market_Profiles::suggestions($lang)[$lang.'-'.$country])||
    !MAD4B_SCP_Market_Profiles::is_targetable($country,$lang))return new WP_Error('market_region_country_language_rejected');
  $states=function_exists('WC')&&WC()->countries?(array)(WC()->countries->get_states($country)?:[]):[];
  if(!array_key_exists($state,$states))return new WP_Error('market_region_not_in_native_registry');
  $parent='demo-'.strtolower($country).'-'.$lang;
  $p=MAD4B_SCP_Market_Profiles::records()[$parent]??null;
  if(!is_array($p)||($p['site_uuid']??'')!==MAD4B_SCP_Market_Profiles::site_uuid()||
   ($p['status']??'')!=='draft')return new WP_Error('market_region_site_bound_draft_required');
  $key=strtolower($country).'-'.$lang.'-'.strtolower($state);
  $records=get_option(self::REGIONS,[]);if(!is_array($records))return new WP_Error('market_region_store_corrupt');
  if(isset($records[$key])){
   if(($records[$key]['site_uuid']??'')!==MAD4B_SCP_Market_Profiles::site_uuid())return new WP_Error('market_region_site_identity_drift');
   return ['state'=>'EXISTS','key'=>$key];
  }
  if(count($records)>=500)return new WP_Error('market_region_registry_capacity');
  $source=['country'=>$country,'language'=>$lang,'state'=>$state,'state_label'=>$states[$state],
   'parent_draft'=>$parent,'source'=>['WooCommerce_states','WPML','CLDR_advisory']];
  $records[$key]=['contract'=>self::CONTRACT,'region_key'=>$key,'site_uuid'=>MAD4B_SCP_Market_Profiles::site_uuid(),
   'parent_profile_id'=>$parent,'country'=>$country,'language'=>$lang,'state_code'=>$state,
   'state_label'=>(string)$states[$state],'source_sha256'=>self::sha($source),
   'status'=>'candidate_draft','site_targeting_approved'=>false,'supplier_availability_approved'=>false,
   'country_tax_approved'=>false,'regional_seo_publish_authorized'=>false,'created_at_utc'=>gmdate('c')];
  update_option(self::REGIONS,$records,false);$read=(array)get_option(self::REGIONS,[]);
  if(($read[$key]['source_sha256']??'')!==self::sha($source))return new WP_Error('market_region_readback_failed');
  return ['state'=>'CREATED','key'=>$key,'status'=>'candidate_draft'];
 }
 static function region_action():void {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  check_admin_referer('mad4b_market_portfolio_region');
  $result=self::create_region((string)($_POST['country']??''),(string)($_POST['language']??''),(string)($_POST['state_code']??''));
  $msg=is_wp_error($result)?$result->get_error_code():$result['state'];
  wp_safe_redirect(add_query_arg('market_msg',sanitize_key($msg),admin_url('admin.php?page='.self::PAGE)));exit;
 }
 static function health():array {
  $t=self::template();$plans=(array)get_option(self::PLANS,[]);$regions=(array)get_option(self::REGIONS,[]);
  $issues=[];$company=[];$checked=0;
  $policy=self::native()?MAD4B_SCP_Market_Profiles::targeting_policy():[];
  $policyProvenance=(string)($policy['environment']??'');
  if($policyProvenance!==''&&$policyProvenance!==wp_get_environment_type())
   $issues[]=['source'=>'market_targeting_policy','issue'=>'source_environment_requires_review',
    'policy_environment'=>$policyProvenance,'current_environment'=>wp_get_environment_type(),
    'impact'=>'excluded countries/languages may be stale, no automatic policy change'];

  foreach($plans as $code=>$p){
   if(!is_array($p)){$issues[]=['company'=>(string)$code,'issue'=>'invalid_plan_structure'];continue;}
   $b=class_exists('MAD4B_Company_Strategy_V1')?MAD4B_Company_Strategy_V1::binding((string)$code):new WP_Error('strategy_unavailable');
   $valid=!is_wp_error($b) && ($p['company_site_uuid']??'')===($b['company_site_uuid']??'') &&
      ($p['template_sha256']??'')===($t['sha256']??'') &&
      empty($p['target_markets_approved'])&&empty($p['checkout_authorized']);
   $company[(string)$code]=['valid_scope'=>$valid,'delivery_state'=>$p['delivery_state']??null,
     'plan_state'=>$p['state']??null,'approved_on_target'=>false];
   if(!$valid)$issues[]=['company'=>(string)$code,'issue'=>'identity_or_template_drift'];
  }
  foreach($regions as $key=>$r){
   if(!is_array($r)){$issues[]=['region'=>(string)$key,'issue'=>'invalid_region'];continue;}
   $cc=(string)($r['country']??'');$lang=(string)($r['language']??'');$state=(string)($r['state_code']??'');
   $states=function_exists('WC')&&WC()->countries?(array)(WC()->countries->get_states($cc)?:[]):[];
   $src=['country'=>$cc,'language'=>$lang,'state'=>$state,
    'state_label'=>$states[$state]??'','parent_draft'=>$r['parent_profile_id']??'',
    'source'=>['WooCommerce_states','WPML','CLDR_advisory']];
   $isValid=isset($states[$state]) &&
     hash_equals((string)($r['source_sha256']??''),self::sha($src)) &&
     MAD4B_SCP_Market_Profiles::is_targetable($cc,$lang) &&
     ($r['site_uuid']??'')===MAD4B_SCP_Market_Profiles::site_uuid();
   $checked++;
   if(!$isValid)$issues[]=['region'=>(string)$key,'issue'=>'source_or_site_policy_drift'];
  }
  return ['contract'=>'mad4b.company-market-health.v1','template_ready'=>!empty($t),
   'template_sha256'=>$t['sha256']??null,'company_plans'=>$company,
   'company_count'=>count($plans),'demo_draft_count'=>self::native()?count(array_filter(
     MAD4B_SCP_Market_Profiles::records(),fn($p,$id)=>str_starts_with((string)$id,'demo-')&&($p['status']??'')==='draft',ARRAY_FILTER_USE_BOTH)):0,
   'candidate_count'=>count(self::candidates('','',300)),'excluded_opportunity_count'=>count(self::excluded_opportunities(500)),'region_drafts_checked'=>$checked,
   'issues'=>$issues,'no_automatic_commercial_approval'=>true];
 }
 static function reconcile_all():array {
  if(!self::central()||!self::can_write())return ['state'=>'DENIED'];
  $codes=array_keys(MAD4B_Company_Strategy_V1::companies());
  $result=['state'=>'RECONCILED','checked'=>0,'created'=>0,'existing'=>0,'errors'=>[]];
  foreach($codes as $code){
   if(!is_string($code))continue;
   $r=self::prepare_company($code);
   $result['checked']++;
   if(is_wp_error($r))$result['errors'][$code]=$r->get_error_code();
   elseif(($r['state']??'')==='CREATED')$result['created']++;
   elseif(($r['state']??'')==='EXISTS')$result['existing']++;
  }
  if($result['errors'])$result['state']='PARTIAL_REVIEW';
  return $result;
 }
 static function reconcile_action():void {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  check_admin_referer('mad4b_market_portfolio_reconcile');
  $out=self::reconcile_all();
  $msg=$out['state']??'ERROR';
  wp_safe_redirect(add_query_arg('market_msg',sanitize_key($msg),admin_url('admin.php?page='.self::PAGE)));exit;
 }
 static function rest_permission():bool{return self::central()&&current_user_can('manage_options');}
 static function rest():void {
  register_rest_route('mad4b-central/v1','/market-portfolio/overview',[
   'methods'=>'GET','callback'=>static fn()=>rest_ensure_response([
    'health'=>self::health(),'targeting_policy'=>MAD4B_SCP_Market_Profiles::targeting_policy(),
    'company_inheritance_template'=>self::template()]),
   'permission_callback'=>[__CLASS__,'rest_permission']]);
  register_rest_route('mad4b-central/v1','/market-portfolio/companies/(?P<company>[a-z][a-z0-9-]{2,47})',[
   'methods'=>'GET','callback'=>static function($request){
    $code=(string)$request['company'];$p=(array)get_option(self::PLANS,[]);
    $b=MAD4B_Company_Strategy_V1::binding($code);
    if(is_wp_error($b))return $b;
    $row=$p[$code]??null;
    if(!is_array($row)||($row['company_site_uuid']??'')!==$b['company_site_uuid'])
     return new WP_Error('market_company_plan_not_ready','Company requires market portfolio prelink',['status'=>404]);
    return rest_ensure_response(['contract'=>self::CONTRACT,'company_code'=>$code,
      'template_sha256'=>self::template()['sha256']??null,
      'plan'=>$row,'target_market_approval_automatic'=>false,
      'target_deployment_automatic'=>false]);
   },'permission_callback'=>[__CLASS__,'rest_permission']]);
  register_rest_route('mad4b-central/v1','/market-portfolio/candidates',[
   'methods'=>'GET','callback'=>static function($request){
    $lang=sanitize_key((string)$request->get_param('language'));
    $country=strtoupper(sanitize_text_field((string)$request->get_param('country')));
    if($lang&&!preg_match('/^[a-z]{2,8}$/D',$lang))return new WP_Error('market_bad_language','Invalid language',['status'=>422]);
    if($country&&!preg_match('/^[A-Z]{2}$/D',$country))return new WP_Error('market_bad_country','Invalid country',['status'=>422]);
    $rows=self::candidates($lang,$country,300);
    return rest_ensure_response(['contract'=>self::CONTRACT,'status'=>'suggested_only',
      'count'=>count($rows),'language'=>$lang,'country'=>$country,'candidates'=>$rows,
      'commercial_approval'=>false]);
   },'permission_callback'=>[__CLASS__,'rest_permission']]);
 }
 static function create_native_draft(string $country,string $lang){
  if(!self::can_write())return new WP_Error('market_admin_required');
  $country=strtoupper(sanitize_text_field($country));$lang=sanitize_key($lang);
  if(!preg_match('/^[A-Z]{2}$/D',$country)||!preg_match('/^[a-z]{2,8}$/D',$lang))return new WP_Error('market_key_invalid');
  $candidate=MAD4B_SCP_Market_Profiles::suggestions($lang)[$lang.'-'.$country]??null;
  if(!$candidate||!MAD4B_SCP_Market_Profiles::is_targetable($country,$lang))return new WP_Error('market_candidate_not_allowed');
  $id='demo-'.strtolower($country).'-'.$lang;$stored=MAD4B_SCP_Market_Profiles::records()[$id]??null;
  if(is_array($stored)){
   if(($stored['site_uuid']??'')!==MAD4B_SCP_Market_Profiles::site_uuid())return new WP_Error('market_draft_identity_drift');
   return ['state'=>'EXISTS','id'=>$id,'status'=>$stored['status']??'unknown'];
  }
  $label='Demo Candidate — '.$candidate['country_label'].' / '.strtoupper($lang);
  $r=MAD4B_SCP_Market_Profiles::save_draft($id,$label,$country,$lang,0);
  if(is_wp_error($r))return $r;
  return ['state'=>'CREATED','id'=>$id,'status'=>$r['status'],'site_uuid'=>$r['site_uuid']];
 }
 static function create_action():void {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  check_admin_referer('mad4b_market_portfolio_create');
  $r=self::create_native_draft((string)($_POST['country']??''),(string)($_POST['language']??''));
  $code=is_wp_error($r)?$r->get_error_code():$r['state'];
  wp_safe_redirect(add_query_arg('market_msg',sanitize_key($code),admin_url('admin.php?page='.self::PAGE)));exit;
 }
 static function page():void {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  if(!self::native()){echo '<div class="wrap"><h1>Market Portfolio</h1><p>Native Market Profile provider missing.</p></div>';return;}
  $lang=sanitize_key((string)($_GET['market_language']??''));
  $country=strtoupper(sanitize_text_field((string)($_GET['market_country']??'')));
  $variants=self::candidates($lang,$country,80);$tpl=self::template();$plans=(array)get_option(self::PLANS,[]);
  $coverage=self::service_coverage();
  echo '<div class="wrap"><h1>MAD4B · Company Market Portfolio</h1>';
  echo '<p>Source-aware multilingual variants for every company: native WPML visibility + CLDR geographic language candidates + WooCommerce state/region options. Country/language presence is NOT proof of commercial eligibility.</p>';
  if(isset($_GET['market_msg']))echo '<p><strong>Operation status:</strong> '.esc_html(sanitize_key((string)$_GET['market_msg'])).'</p>';
  echo '<p><strong>Central template:</strong> '.($tpl?'READY':'NOT INITIALIZED').' · <strong>Current site draft profiles:</strong> '.
    count(array_filter(MAD4B_SCP_Market_Profiles::records(),static fn($p)=>($p['status']??'')==='draft')).
    ' · <strong>Native commercially approved:</strong> '.count(MAD4B_SCP_Market_Profiles::approved_list()).'</p>';
  echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-market-profiles')).'">Native Market Profiles</a> ';
  echo '<a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=mad4b_market_portfolio_export'),'mad4b_market_portfolio_export')).'">Export central blueprint JSON</a></p>';
  echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
  echo '<label>Language <select name="market_language"><option value="">All visible</option>';
  foreach(MAD4B_SCP_Market_Profiles::enabled_languages() as $key=>$name)
   echo '<option value="'.esc_attr($key).'" '.selected($lang,$key,false).'>'.esc_html($name.' ('.$key.')').'</option>';
  echo '</select></label> <label>ISO Country <input name="market_country" maxlength="2" placeholder="US" value="'.esc_attr($country).'"></label> ';
  echo '<button class="button">Discover market variants</button></form>';

  if($country&&$lang){
   $states=function_exists('WC')&&WC()->countries?(array)(WC()->countries->get_states($country)?:[]):[];
   if(isset(self::candidates($lang,$country,1)[strtolower($country).'-'.$lang])&&$states){
    echo '<h3>Optional subregion variation — draft only</h3>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="mad4b_market_portfolio_region_create">';
    echo '<input type="hidden" name="country" value="'.esc_attr($country).'">';
    echo '<input type="hidden" name="language" value="'.esc_attr($lang).'">';
    wp_nonce_field('mad4b_market_portfolio_region');
    echo '<label>State / province <select name="state_code">';
    foreach($states as $key=>$value)echo '<option value="'.esc_attr((string)$key).'">'.esc_html((string)$value).'</option>';
    echo '</select></label> <button type="submit" class="button">Create regional Candidate</button></form>';
    echo '<p>Requires an existing, site-bound native Draft for the selected country/language.</p>';
   }
  }

  echo '<p>Visible candidate sample: '.count($variants).' (max 80 per filter). Regions are optional subdivisions; neither tax, legal targeting nor hotel/transport supply is inferred.</p>';
  $blocked=self::excluded_opportunities(300);
  echo '<p><strong>Policy-blocked opportunities:</strong> '.count($blocked).' candidate pairs in the bundled CLDR source. They remain excluded and require explicit policy review.</p>';
  $sourcePolicy=MAD4B_SCP_Market_Profiles::targeting_policy();
  if(!empty($sourcePolicy['environment'])&&$sourcePolicy['environment']!==wp_get_environment_type())
   echo '<p><strong>Attention:</strong> Targeting policy was authored for '.esc_html($sourcePolicy['environment']).' and is running on '.esc_html(wp_get_environment_type()).'. Review the exclusions; no automatic changes made.</p>';

  echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Language</th><th>Subregions</th><th>Draft action</th></tr></thead><tbody>';
  foreach($variants as $key=>$row){
   echo '<tr><td>'.esc_html($row['label']).' ('.esc_html($row['country']).')</td><td>'.esc_html($row['language_name']).'</td>';
   echo '<td>'.(int)$row['subregion_count'].'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
   echo '<input type="hidden" name="action" value="mad4b_market_portfolio_create">';
   echo '<input type="hidden" name="country" value="'.esc_attr($row['country']).'">';
   echo '<input type="hidden" name="language" value="'.esc_attr($row['language']).'">';
   wp_nonce_field('mad4b_market_portfolio_create');
   echo '<button class="button" type="submit">Create native Draft</button></form></td></tr>';
  }
  echo '</tbody></table><h2>Service destination coverage (independent)</h2>';
  foreach($coverage['country_terms'] as $term)echo '<span style="margin-right:1em">'.esc_html($term['name']).'</span>';
  if(self::central()){
   $health=self::health();
   echo '<h2>Central reconciliation</h2><p>Company plans: '.(int)$health['company_count'].' · Regional variants checked: '.(int)$health['region_drafts_checked'].' · Source drift alerts: '.count($health['issues']).'</p>';
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_market_portfolio_reconcile">';
   wp_nonce_field('mad4b_market_portfolio_reconcile');
   echo '<button class="button" type="submit">Reconcile eligible company plans</button></form>';
  }
  echo '<h2>Central company inheritance plans</h2><table class="widefat striped"><tr><th>Company</th><th>Plan state</th><th>Company deployment</th><th>Commercial approval</th></tr>';
  foreach($plans as $plan)echo '<tr><td>'.esc_html($plan['company_code']).'</td><td>'.esc_html($plan['state']).'</td><td>'.esc_html($plan['delivery_state']).'</td><td>None inherited</td></tr>';
  echo '</table><p>All markets remain subject to target-company site binding and independent commercial approval. No publishing, pricing mutation or checkout is performed here.</p></div>';
 }
}
add_action('admin_menu',['MAD4B_Company_Market_Portfolio_V1','menu'],90);
add_action('rest_api_init',['MAD4B_Company_Market_Portfolio_V1','rest'],90);
add_action('admin_post_mad4b_market_portfolio_reconcile',['MAD4B_Company_Market_Portfolio_V1','reconcile_action']);
add_action('admin_post_mad4b_market_portfolio_create',['MAD4B_Company_Market_Portfolio_V1','create_action']);
add_action('admin_post_mad4b_market_portfolio_region_create',['MAD4B_Company_Market_Portfolio_V1','region_action']);
add_action('admin_post_mad4b_market_portfolio_export',['MAD4B_Company_Market_Portfolio_V1','export']);
add_action('mad4b_company_strategy_enrolled',['MAD4B_Company_Market_Portfolio_V1','on_company_enrolled'],20,1);
