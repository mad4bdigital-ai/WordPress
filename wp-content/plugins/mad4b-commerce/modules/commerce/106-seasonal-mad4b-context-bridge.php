<?php
/** Local-only binding layer: term is season authority; MAD4B supplies approved context. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function shared_offer_mad4b_context(){
 $brand=[];
 if(class_exists('MAD4B_SCP_Context_Authority')&&method_exists('MAD4B_SCP_Context_Authority','profile')){
  $v=MAD4B_SCP_Context_Authority::profile();if(is_array($v))$brand=$v;
 }
 // These are explicit, trusted integration adapters, not visitor-supplied query-string values.
 $site=[];
 if(class_exists('MAD4B_SCP_Site_Profile')&&method_exists('MAD4B_SCP_Site_Profile','profile')){
  $record=MAD4B_SCP_Site_Profile::profile();
  $status=method_exists('MAD4B_SCP_Site_Profile','status')?MAD4B_SCP_Site_Profile::status():[];
  $safe=is_array($status)&&!empty($status['configured'])&&empty($status['wordpress_profile_mismatch']);
  if($safe&&is_array($record)&&($record['environment']??'')===wp_get_environment_type()&&!empty($record['site_uuid'])){
   $site=['site_uuid'=>(string)$record['site_uuid'],'environment'=>(string)$record['environment'],
    'revision'=>(int)($record['revision']??0),'display_name'=>(string)($record['display_name']??'')];
  }
 }
 $site=apply_filters('shared_offer/mad4b_site_profile',$site);
 $market=apply_filters('shared_offer/mad4b_market_profile',null);
 $site=is_array($site)?$site:[];
 $market=is_array($market)?$market:[];
 // A plugin filter is a transport, not proof of an authoritative, site-bound market record.
 $market_ok=isset($market['profile_id'],$market['site_uuid'],$market['authority_status'])
  &&($market['authority_status']==='site_approved')
  &&!empty($site['site_uuid'])
  &&hash_equals((string)$site['site_uuid'],(string)$market['site_uuid'])
  &&sanitize_key((string)$market['profile_id'])===(string)$market['profile_id']
  &&isset($market['language'])&&isset(MAD4B_SCP_Market_Profiles::enabled_languages()[$market['language']]);
 if($market_ok && function_exists('shared_offer_verified_market')){
  $stored=shared_offer_verified_market($market['profile_id']);
  $market_ok=!empty($stored)&&($stored['contract']??'')==='mad4b.market-profiles.v1'&&($stored['revision']??null)===($market['revision']??null)&&($stored['site_uuid']??'')===($market['site_uuid']??'');
 }
 if(!$market_ok)$market=[];
 return ['site'=>$site,'market'=>$market,'brand'=>$brand];
}
function shared_offer_term_context_match($rule,$context){
 $term=absint($rule['season_term_id']??0);
 if($term){
  $season=get_term($term,'season');if(!$season||is_wp_error($season))return false;
  if(get_term_meta($term,'season_enabled',true)==='0')return false;
  $mode=(string)get_term_meta($term,'season_mode',true);
  if($mode==='')$mode='travel';
  if(!in_array($mode,['travel','global','domestic'],true))return false;
  if($mode==='domestic'&&($rule['scope']??'')!=='domestic')return false;
 }
 $wanted=sanitize_key((string)($rule['market_profile_id']??''));
 if($wanted){
  $actual=sanitize_key((string)($context['market']['profile_id']??''));
  if(!$actual||!hash_equals($wanted,$actual))return false;
 }
 $brand_required=!empty($rule['brand_approval_required']);
 if($brand_required){
  $status=class_exists('MAD4B_SCP_Context_Authority')&&method_exists('MAD4B_SCP_Context_Authority','status')?MAD4B_SCP_Context_Authority::status():[];
  if(!is_array($status)||empty($status['ready'])||empty($context['brand'])||!apply_filters('shared_offer/mad4b_brand_content_approved',false,$rule,$context))return false;
 }
 return true;
}
// Reuse the existing seasonal resolver, but reject any term/profile mismatch.
add_filter('shared_offer/seasonal_rule_eligible',static function($eligible,$rule){
 if(!$eligible)return false;
 return shared_offer_term_context_match($rule,shared_offer_mad4b_context());
},10,2);

// Add seasonal occasion metadata to the existing Seasons taxonomy, not a parallel CPT.
add_action('season_edit_form_fields',static function($term){
 $mode=(string)get_term_meta($term->term_id,'season_mode',true);$windows=get_term_meta($term->term_id,'season_date_windows',true);
 echo '<tr class="form-field"><th scope="row">Season mode</th><td><select name="shared_season_mode">';
 foreach(['travel'=>'Travel (existing Winter/Summer)','global'=>'Global occasion','domestic'=>'Domestic occasion'] as $v=>$label)echo '<option value="'.esc_attr($v).'" '.selected($mode?:'travel',$v,false).'>'.esc_html($label).'</option>';
 echo '</select></td></tr><tr class="form-field"><th scope="row">Exact occasion dates</th><td><textarea name="shared_season_date_windows" rows="6" cols="45">';
 if(is_array($windows))foreach($windows as $window)if(is_array($window))echo esc_textarea(($window['start']??'').' | '.($window['end']??''))."\n";
 echo '</textarea><p class="description">For Global/Domestic occasions: one start | end (YYYY-MM-DD) per line. Add future years explicitly. Travel seasons keep existing JetEngine recurring dates.</p></td></tr>';
 wp_nonce_field('shared_season_term_update','shared_season_term_nonce');
});
add_action('edited_season',static function($term_id){
 if(!current_user_can('manage_categories')||!isset($_POST['shared_season_term_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shared_season_term_nonce'])),'shared_season_term_update'))return;
 $mode=sanitize_key(wp_unslash($_POST['shared_season_mode']??'travel'));
 if(!in_array($mode,['travel','global','domestic'],true))return;
 $raw=(string)wp_unslash($_POST['shared_season_date_windows']??'');$windows=[];
 foreach(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$raw))) as $line){
  $parts=array_map('trim',explode('|',$line));if(count($parts)!==2||!shared_offer_valid_date($parts[0])||!shared_offer_valid_date($parts[1])||$parts[0]>$parts[1])return;
  $windows[]=['start'=>$parts[0],'end'=>$parts[1]];if(count($windows)>30)return;
 }
 if($mode!=='travel'&&!$windows)return; // no switch to non-travel without a schedule
 update_term_meta($term_id,'season_mode',$mode);
 if($mode!=='travel')update_term_meta($term_id,'season_date_windows',$windows);
},20);

/** Read-only readiness report; never substitutes Search Profiles for Market Profiles. */
function shared_offer_mad4b_binding_diagnostics(){
 $ctx=shared_offer_mad4b_context();$status=[];
 if(class_exists('MAD4B_SCP_Context_Authority')&&method_exists('MAD4B_SCP_Context_Authority','status')){
  $value=MAD4B_SCP_Context_Authority::status();if(is_array($value))$status=$value;
 }
 $available=apply_filters('shared_offer/mad4b_available_market_profiles',[]);
 $approved=is_array($available)?count($available):0;
 return ['site_profile'=>!empty($ctx['site'])?'connected':'unavailable',
  'market_registry'=>$approved?'local_approved_profiles:'.$approved:'no_approved_market_profiles',
  'visitor_market'=>!empty($ctx['market']['profile_id'])?'matched_local_profile':'no_trusted_visitor_match',
  'brand_authority'=>!empty($status['ready'])&&!empty($ctx['brand'])?'ready':'not_ready',
  'brand_state'=>sanitize_key((string)($status['state']??'unknown'))];
}
add_action('shared_offer/seasons_admin_before_rules',static function(){
 $d=shared_offer_mad4b_binding_diagnostics();echo '<h2>MAD4B Context Readiness</h2><table class="widefat striped"><tbody>';
 foreach($d as $key=>$value)echo '<tr><th>'.esc_html(str_replace('_',' ',ucwords($key,'_'))).'</th><td><code>'.esc_html($value).'</code></td></tr>';
 echo '</tbody></table><p>Market matching and branded creatives remain blocked unless an authoritative provider supplies an eligible profile and approval. Search Profiles are not Market Profiles.</p>';
});
