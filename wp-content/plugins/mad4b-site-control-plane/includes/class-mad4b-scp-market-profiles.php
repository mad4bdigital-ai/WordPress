<?php
/** MAD4B site-bound Market Profiles. Read-only consumer contract; explicit admin writes. */
if (!defined('ABSPATH')) exit;
final class MAD4B_SCP_Market_Profiles {
 const CONTRACT='mad4b.market-profiles.v1';
 const OPTION='mad4b_scp_market_profiles_v1';
 const TARGETING_POLICY='mad4b_scp_market_targeting_policy_v1';
 const AUDIT='mad4b_scp_market_profiles_audit_v1';
 private static $booted=false;
 public static function site_uuid(){
  if(!class_exists('MAD4B_SCP_Site_Profile'))return '';
  $s=MAD4B_SCP_Site_Profile::status();$p=MAD4B_SCP_Site_Profile::profile();
  if(!is_array($s)||empty($s['configured'])||!empty($s['wordpress_profile_mismatch'])||!is_array($p)||($p['environment']??'')!==wp_get_environment_type())return '';
  return (string)($p['site_uuid']??'');
 }
 public static function records(){
  $r=get_option(self::OPTION,[]);return is_array($r)?$r:[];
 }
 /** WPML language policy: configured AND public; hidden languages are installed but excluded. */
 public static function language_visibility(){
  $wpml=isset($GLOBALS['sitepress'])&&is_object($GLOBALS['sitepress']);
  $settings=get_option('icl_sitepress_settings',[]);
  if(!is_array($settings))$settings=[];
  $configured=[];$hidden=[];
  foreach((array)($settings['active_languages']??[]) as $code){
   $code=sanitize_key((string)$code);if(preg_match('/^[a-z]{2,8}$/D',$code))$configured[$code]=true;
  }
  foreach((array)($settings['hidden_languages']??[]) as $code){
   $code=sanitize_key((string)$code);if(preg_match('/^[a-z]{2,8}$/D',$code))$hidden[$code]=true;
  }
  $public=[];
  if($wpml&&has_filter('wpml_active_languages')){
   $result=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
   if(is_array($result))foreach($result as $entry){
    if(!is_array($entry))continue;
    $code=sanitize_key((string)($entry['language_code']??$entry['code']??''));
    if(!preg_match('/^[a-z]{2,8}$/D',$code)||isset($hidden[$code]))continue;
    if($configured && !isset($configured[$code]))continue;
    $public[$code]=(string)($entry['native_name']??$entry['translated_name']??$code);
   }
  }
  // WPML present but no public languages: fail closed, never fall back to a hidden default.
  if($wpml)return ['public'=>$public,'hidden'=>array_keys($hidden),'configured'=>array_keys($configured),'source'=>'wpml'];
  $code=sanitize_key((string)strtok(str_replace('-','_',get_locale()),'_'));
  if(preg_match('/^[a-z]{2,8}$/D',$code))$public[$code]=$code;
  return ['public'=>$public,'hidden'=>[],'configured'=>array_keys($public),'source'=>'wordpress'];
 }
 public static function enabled_languages(){return self::language_visibility()['public'];}
 public static function targeted_languages(){
  $languages=self::enabled_languages();$policy=self::targeting_policy();
  foreach((array)($policy['excluded_languages']??[]) as $code)unset($languages[$code]);
  return $languages;
 }
 public static function targeting_policy(){
  $p=get_option(self::TARGETING_POLICY,[]);
  return is_array($p)?$p:[];
 }
 /** WPML visibility is necessary but not sufficient: owner can exclude non-targeted countries/languages. */
 public static function is_targetable($country,$language){
  $country=strtoupper((string)$country);$language=sanitize_key((string)$language);
  if(!preg_match('/^[A-Z]{2}$/D',$country)||!isset(self::enabled_languages()[$language]))return false;
  $policy=self::targeting_policy();
  if(in_array($country,(array)($policy['excluded_countries']??[]),true))return false;
  if(in_array($language,(array)($policy['excluded_languages']??[]),true))return false;
  return true;
 }

 /** CLDR linguistic evidence generates proposals, NEVER approval or market entitlement. */
 public static function suggestions($language=''){
  $language=sanitize_key((string)$language);$active=self::enabled_languages();
  if($language && !isset($active[$language]))return [];
  $path=dirname(__DIR__).'/data/market-language-territories.json';
  $payload=is_file($path)?json_decode((string)file_get_contents($path),true):[];
  if(!is_array($payload)||!is_array($payload['languages']??null))return [];
  $countries=function_exists('WC')&&WC()->countries?WC()->countries->get_countries():[];
  $out=[];
  foreach($active as $lang=>$label){
   if($language && $language!==$lang)continue;
   foreach($payload['languages'][$lang]??[] as $item){
    if(!is_array($item))continue;$code=(string)($item['country']??'');
    if(!preg_match('/^[A-Z]{2}$/D',$code))continue;
    if(!self::is_targetable($code,$lang))continue;
    if($countries&&!isset($countries[$code]))continue;
    $out[$lang.'-'.$code]=['language'=>$lang,'language_label'=>$label,'country'=>$code,
      'country_label'=>$countries[$code]??$code,'evidence'=>(string)($item['evidence']??'spoken'),
      'suggestion_id'=>$lang.'-'.$code,'source'=>'cldr_language_territory_advisory',
      'status'=>'suggested_only','requires_explicit_confirmation'=>true];
   }
  }
  ksort($out);return $out;
 }
 public static function approved($id){
  $id=sanitize_key($id);$r=self::records();$p=$r[$id]??null;$uuid=self::site_uuid();
  if(!$uuid||!is_array($p)||($p['contract']??'')!==self::CONTRACT||($p['status']??'')!=='approved'||($p['profile_id']??'')!==$id||!hash_equals($uuid,(string)($p['site_uuid']??''))||($p['environment']??'')!==wp_get_environment_type()||((int)($p['revision']??0))<1||(int)($p['approved_revision']??0)!==(int)$p['revision']||empty($p['approved_at'])||empty($p['language'])||!self::is_targetable($p['country']??'',$p['language']))return [];
  return ['profile_id'=>$id,'site_uuid'=>$uuid,'authority_status'=>'site_approved','revision'=>(int)$p['revision'],'country'=>(string)$p['country'],'language'=>(string)$p['language'],'label'=>(string)$p['label'],'contract'=>self::CONTRACT];
 }
 public static function approved_list(){
  $out=[];foreach(self::records() as $id=>$r){$a=self::approved($id);if($a)$out[]=$a;}return $out;
 }
 public static function by_country($country,$language=''){
  $country=strtoupper((string)$country);$language=sanitize_key((string)$language);
  if(!preg_match('/^[A-Z]{2}$/D',$country)||!$language||!isset(self::enabled_languages()[$language]))return [];
  $found=[];foreach(self::approved_list() as $p)if($p['country']===$country&&$p['language']===$language)$found[]=$p;
  return count($found)===1?$found[0]:[];
 }
 public static function status(){
  return ['contract'=>self::CONTRACT,'site_bound'=>self::site_uuid()!=='','count'=>count(self::records()),'approved_count'=>count(self::approved_list()),'suggestion_count'=>count(self::suggestions()),'enabled_languages'=>array_keys(self::enabled_languages()),'hidden_languages'=>self::language_visibility()['hidden'],'market_exclusions'=>self::targeting_policy(),'environment'=>wp_get_environment_type(),'write_policy'=>'local_admin_only_until_governed_release'];
 }
 private static function write_allowed(){return wp_get_environment_type()==='local'&&current_user_can('manage_options')&&self::site_uuid()!=='';}
 private static function error($code){return new WP_Error('mad4b_market_'.$code,$code);}
 private static function log_event($event,$id,$revision){
  $entries=get_option(self::AUDIT,[]);if(!is_array($entries))$entries=[];
  $entries[]=['event'=>$event,'profile_id'=>$id,'revision'=>$revision,'actor'=>get_current_user_id(),'time'=>current_time('mysql',true),'site_uuid'=>self::site_uuid()];
  update_option(self::AUDIT,array_slice($entries,-100),false);
 }
 public static function save_draft($id,$label,$country,$language,$expected_revision=0){
  if(!self::write_allowed())return self::error('write_not_allowed');
  $id=sanitize_key($id);$label=sanitize_text_field($label);$country=strtoupper(sanitize_text_field($country));$language=sanitize_key((string)$language);
  if(!$id||strlen($id)>60||!$label||strlen($label)>120||!preg_match('/^[A-Z]{2}$/D',$country)||!self::is_targetable($country,$language)||!isset(self::suggestions($language)[$language.'-'.$country])||(function_exists('WC')&&WC()->countries&&!isset(WC()->countries->get_countries()[$country])))return self::error('invalid_fields');
  $r=self::records();$existing=$r[$id]??null;
  if(!$existing&&count($r)>=50)return self::error('limit');
  $current=is_array($existing)?(int)($existing['revision']??0):0;
  if((int)$expected_revision!==$current)return self::error('stale_revision');
  if($existing&&($existing['site_uuid']??'')!==self::site_uuid())return self::error('site_mismatch');
  $next=$current+1;
  $r[$id]=['contract'=>self::CONTRACT,'profile_id'=>$id,'site_uuid'=>self::site_uuid(),'environment'=>wp_get_environment_type(),'label'=>$label,'country'=>$country,'language'=>$language,'status'=>'draft','revision'=>$next,'updated_by'=>get_current_user_id(),'updated_at'=>current_time('mysql',true)];
  update_option(self::OPTION,$r,false);
  $saved=self::records();if(($saved[$id]['revision']??0)!==$next)return self::error('write_readback');
  self::log_event('draft_saved',$id,$next);return $saved[$id];
 }
 public static function approve($id,$expected_revision){
  if(!self::write_allowed())return self::error('write_not_allowed');
  $id=sanitize_key($id);$r=self::records();$p=$r[$id]??null;
  if(!is_array($p)||($p['status']??'')!=='draft'||(int)($p['revision']??0)!==(int)$expected_revision||!hash_equals(self::site_uuid(),(string)($p['site_uuid']??''))||!self::is_targetable($p['country']??'',$p['language']??'')||!isset(self::suggestions((string)($p['language']??''))[(string)($p['language']??'').'-'.(string)($p['country']??'')]))return self::error('invalid_approval');
  foreach($r as $other=>$value)if($other!==$id&&($value['status']??'')==='approved'&&($value['country']??'')===$p['country']&&($value['language']??'')===($p['language']??''))return self::error('duplicate_country');
  $r[$id]['status']='approved';$r[$id]['approved_revision']=(int)$p['revision'];$r[$id]['approved_by']=get_current_user_id();$r[$id]['approved_at']=current_time('mysql',true);
  update_option(self::OPTION,$r,false);$saved=self::approved($id);
  if(!$saved)return self::error('approval_readback');
  self::log_event('approved',$id,(int)$p['revision']);return $saved;
 }
 public static function import_legacy_drafts($expected_count){
  if(!self::write_allowed())return self::error('write_not_allowed');
  $legacy=get_option('shared_offer_market_registry_v1',[]);if(!is_array($legacy)||count($legacy)!==(int)$expected_count)return self::error('legacy_snapshot_stale');
  $r=self::records();if($r)return self::error('destination_not_empty');
  $imported=[];
  foreach($legacy as $id=>$p){
   if(!is_array($p)||($p['site_uuid']??'')!==self::site_uuid()||($p['profile_id']??'')!==$id)return self::error('legacy_site_mismatch');
   $id=sanitize_key($id);$label=sanitize_text_field($p['label']??'');$country=strtoupper((string)($p['country']??''));
   if(!$id||!$label||!preg_match('/^[A-Z]{2}$/D',$country))return self::error('legacy_invalid');
   $imported[$id]=['contract'=>self::CONTRACT,'profile_id'=>$id,'site_uuid'=>self::site_uuid(),'environment'=>wp_get_environment_type(),'label'=>$label,'country'=>$country,'language'=>'','status'=>'draft','revision'=>max(1,(int)($p['revision']??0)),'migration_source'=>'local_registry_review_required'];
  }
  if($imported){update_option(self::OPTION,$imported,false);if(count(self::records())!==count($imported))return self::error('import_readback');self::log_event('legacy_imported_as_drafts','*',0);}
  return ['imported_as_drafts'=>count($imported),'approvals_migrated'=>0];
 }
 public static function read_ability($input=[]){
  $input=is_array($input)?$input:[];
  $country=strtoupper((string)($input['country']??''));$language=sanitize_key((string)($input['language']??''));
  if($country!==''&&!preg_match('/^[A-Z]{2}$/D',$country))return new WP_Error('mad4b_market_country_invalid','Country must be an ISO-2 code');
  if($language && !isset(self::enabled_languages()[$language]))return new WP_Error('mad4b_market_language_invalid','Language is not active on this site');
  $profiles=self::approved_list();
  if($language)$profiles=array_values(array_filter($profiles,static fn($p)=>($p['language']??'')===$language));
  if($country!=='')$profiles=array_values(array_filter($profiles,static fn($p)=>($p['country']??'')===$country));
  return ['contract'=>self::CONTRACT,'site_bound'=>self::site_uuid()!=='','status'=>self::status(),'profiles'=>$profiles,'source'=>'mad4b_core','read_only'=>true];
 }
 public static function register_ability(){
  if(!function_exists('wp_register_ability')||!class_exists('MAD4B_SCP_Policy'))return;
  if(function_exists('wp_has_ability')&&wp_has_ability('mad4b/market-profiles-read'))return;
  wp_register_ability('mad4b/market-profiles-read',[
   'label'=>'MAD4B Market Profiles Read','description'=>'Read approved, site-bound market profiles for seasonal targeting.',
   'category'=>'mad4b-governance','execute_callback'=>[__CLASS__,'read_ability'],
   'permission_callback'=>['MAD4B_SCP_Policy','can_read'],
   'input_schema'=>['type'=>'object','properties'=>['country'=>['type'=>'string'],'language'=>['type'=>'string']],'additionalProperties'=>false],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
    'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]],
  ]);
 }
 public static function boot(){
  if(self::$booted)return;self::$booted=true;
  add_action('wp_abilities_api_init',[__CLASS__,'register_ability'],9);
  if(class_exists('MAD4B_SCP_Admin_Route_Registry',false)) {
   MAD4B_SCP_Admin_Route_Registry::register('mad4b-market-profiles','manage_options');
   MAD4B_SCP_Admin_Route_Registry::schedule_submenu([__CLASS__,'admin_menu'],92);
  }
  add_action('admin_post_mad4b_market_draft',[__CLASS__,'save_action']);
  add_action('admin_post_mad4b_market_approve',[__CLASS__,'approve_action']);
 }
 public static function admin_menu(){add_submenu_page('mad4b-control-plane','Market Profiles','Market Profiles','manage_options','mad4b-market-profiles',[__CLASS__,'admin_page']);}
 public static function admin_page(){
  if(!current_user_can('manage_options'))wp_die('Not authorized');
  $visibility=self::language_visibility();$languages=$visibility['public'];$selected=sanitize_key((string)($_GET['market_lang']??''));if($selected&&!isset($languages[$selected]))$selected='';
  $suggestions=self::suggestions($selected);
  echo '<div class="wrap"><h1>MAD4B — Market Profiles</h1><p>Country-language suggestions derive from enabled site languages and bundled CLDR language-territory data. They are proposals only. Speaking a language does not make a country an approved commercial market.</p>';
  echo '<p>Contract: <code>'.esc_html(self::CONTRACT).'</code> | Approved: '.count(self::approved_list()).' | Suggested pairs: '.count(self::suggestions()).'</p>';
  if(!empty($visibility['hidden']))echo '<p><strong>Languages hidden by WPML:</strong> '.esc_html(implode(', ',$visibility['hidden'])).'. Installed, but excluded from suggestions, approval and runtime targeting.</p>';
  echo '<h2>Configured markets</h2><table class="widefat striped"><tr><th>Profile</th><th>Country</th><th>Language</th><th>Status</th><th>Revision</th></tr>';
  foreach(self::records() as $p)echo '<tr><td>'.esc_html($p['profile_id']).' — '.esc_html($p['label']).'</td><td>'.esc_html($p['country']).'</td><td>'.esc_html($p['language']??'Needs review').'</td><td>'.esc_html($p['status']).'</td><td>'.(int)$p['revision'].'</td></tr>';
  echo '</table><h2>Discover suggested markets</h2><form method="get" action="'.esc_url(admin_url('admin.php')).'"><input type="hidden" name="page" value="mad4b-market-profiles"><label>Site language <select name="market_lang"><option value="">All enabled languages</option>';
  foreach($languages as $code=>$label)echo '<option value="'.esc_attr($code).'" '.selected($selected,$code,false).'>'.esc_html($label).' ('.esc_html($code).')</option>';
  echo '</select></label> <button class="button" type="submit">Filter suggestions</button></form>';
  echo '<p>Suggestions are not saved or enabled. Choose one country-language pair to create a draft. Approval remains a separate step.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_market_draft">';wp_nonce_field('mad4b_market_draft');
  echo '<p><label>Suggested country and language <select name="suggestion_id" required>';
  foreach($suggestions as $suggestion)echo '<option value="'.esc_attr($suggestion['suggestion_id']).'">'.esc_html($suggestion['language_label'].' — '.$suggestion['country_label'].' ('.$suggestion['country'].')').'</option>';
  echo '</select></label></p><p>Market Profile ID <input name="profile_id" placeholder="us-es" required pattern="[a-z0-9_-]+"> Display label <input name="label" required> Expected revision <input name="revision" type="number" min="0" value="0"></p>';
  submit_button('Create / update draft (not approved)');echo '</form>';
  echo '<h2>Approve a reviewed market</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_market_approve">';wp_nonce_field('mad4b_market_approve');
  echo '<select name="profile_id">';foreach(self::records() as $p)if(($p['status']??'')==='draft'&&!empty($p['language'])&&isset(self::suggestions()[$p['language'].'-'.$p['country']]))echo '<option value="'.esc_attr($p['profile_id']).'">'.esc_html($p['label'].' ('.$p['country'].' / '.$p['language'].')').' #'.(int)$p['revision'].'</option>';
  echo '</select> Exact revision <input type="number" name="revision" min="1" required>';submit_button('Approve selected market');echo '</form></div>';
 }
 public static function save_suggested_draft($id,$label,$suggestion_id,$expected_revision=0){
  $suggestions=self::suggestions();$proposal=$suggestions[sanitize_text_field($suggestion_id)]??null;
  if(!$proposal)return self::error('unknown_suggestion');
  return self::save_draft($id,$label,$proposal['country'],$proposal['language'],$expected_revision);
 }
 public static function save_action(){if(!current_user_can('manage_options'))wp_die('Denied');check_admin_referer('mad4b_market_draft');$r=self::save_suggested_draft(wp_unslash($_POST['profile_id']??''),wp_unslash($_POST['label']??''),wp_unslash($_POST['suggestion_id']??''),absint($_POST['revision']??0));if(is_wp_error($r))wp_die(esc_html($r->get_error_code()));wp_safe_redirect(admin_url('admin.php?page=mad4b-market-profiles&saved=1'));exit;}
 public static function approve_action(){if(!current_user_can('manage_options'))wp_die('Denied');check_admin_referer('mad4b_market_approve');$r=self::approve(wp_unslash($_POST['profile_id']??''),absint($_POST['revision']??0));if(is_wp_error($r))wp_die(esc_html($r->get_error_code()));wp_safe_redirect(admin_url('admin.php?page=mad4b-market-profiles&approved=1'));exit;}
}
