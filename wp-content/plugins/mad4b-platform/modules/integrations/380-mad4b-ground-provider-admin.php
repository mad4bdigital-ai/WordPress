<?php
/**
 * Plugin Name: MAD4B Ground Transport Provider Settings
 * Version: 0.1.0
 * Description: WooCommerce Ground Transport API Fares Providers options and native JetEngine import actions.
 */
defined('ABSPATH') || exit;
final class MAD4B_Ground_Provider_Admin_V1 {
 const SLUG='mad4b-ground-transport-providers';
 const ACTION='mad4b_ground_provider_admin_action';
 const TESTS='mad4b_ground_provider_test_results_v1';
 static function authorized():bool {
  return class_exists('MAD4B_Ground_Provider_Core_V1') &&
    MAD4B_Ground_Provider_Core_V1::can_manage();
 }
 static function menus():void{
  add_submenu_page('woocommerce','Ground Transport Providers','Ground Transport Providers',
    'manage_woocommerce',self::SLUG,[__CLASS__,'page']);
 }
 static function link():void {
  if(!self::authorized())return;
  echo '<hr><h3>Bus, Van &amp; Limousine API Fares Providers</h3>';
  echo '<p>Control supplier connections, price-markup policies, source catalogue refresh and native JetEngine drafts.</p>';
  echo '<p><a class="button button-secondary" href="'.
    esc_url(admin_url('admin.php?page='.self::SLUG)).
    '">Open Ground Transport Provider Options</a></p>';
 }
 static function output_status(string $id):string {
  $c=MAD4B_Ground_Provider_Core_V1::ready($id);
  $s=(string)($c['state']??'unknown');
  $last=(array)(get_option(self::TESTS,[])[$id]??[]);
  $fresh=!empty($last['fingerprint'])&&hash_equals(
    MAD4B_Ground_Provider_Core_V1::configuration_fingerprint($id),
    (string)$last['fingerprint']);
  $note=($last['success']??false)===true && $fresh?
     ' · Last API test OK '.($last['at']??''):'';
  if(isset($last['success']) && !$fresh)$note=' · Credentials/configuration changed: test again';
  elseif(isset($last['success'])&&!$last['success'])$note=' · Last test: '.($last['reason']??'failed');
  return strtoupper(str_replace('_',' ',$s)).$note;
 }
 static function page():void {
  if(!self::authorized())wp_die('WooCommerce manager access is required.',403);
  $defs=MAD4B_Ground_Provider_Core_V1::definitions();
  $selected=sanitize_key((string)($_GET['provider']??'egyptlimo'));
  if(!isset($defs[$selected]))$selected='egyptlimo';
  $state=MAD4B_Ground_Provider_Core_V1::public();
  $notice=sanitize_key((string)($_GET['result']??''));
  echo '<div class="wrap" style="max-width:1180px"><h1>Ground Transport · API Fares Providers</h1>';
  echo '<p>Live supplier APIs feed native JetEngine <code>transportations</code> drafts. Date/route-specific fares belong to the existing Pricing/Transport Rate provider. API vehicle classes alone never authorize WooCommerce orders.</p>';
  if($notice!=='')echo '<div class="notice notice-'.($notice==='success'?'success':'warning').
    ' is-dismissible"><p>'.esc_html($notice==='success'?'Settings saved or provider action completed.':'Provider action needs attention. Review the latest status below.').'</p></div>';
  echo '<nav class="nav-tab-wrapper">';
  foreach($defs as $id=>$d){
    echo '<a class="nav-tab '.($id===$selected?'nav-tab-active':'').'" href="'.
      esc_url(admin_url('admin.php?page='.self::SLUG.'&provider='.$id)).'">'.
      esc_html($d['title']).'</a>';
  }
  echo '</nav>';
  $d=$defs[$selected];$p=MAD4B_Ground_Provider_Core_V1::profile($selected);
  $connected=MAD4B_Ground_Provider_Core_V1::ready($selected);
  echo '<h2>'.esc_html($d['title']).'</h2><p>Integration: <code>'.esc_html($d['model']).
    '</code> | Authentication: <code>'.esc_html($d['authentication']).'</code></p>';
  echo '<p><strong>Connection:</strong> '.esc_html(self::output_status($selected)).
    ' · <strong>Native JetEngine records:</strong> '.(int)$state['providers'][$selected]['jetengine_catalog_count'].'</p>';
  if($d['source'])echo '<p><a target="_blank" rel="noopener noreferrer" href="'.esc_url($d['source']).'">Provider documentation / onboarding</a></p>';
  if($selected!=='egyptlimo')
    echo '<p>API transport protocol is not configured until the provider supplies approved partner credentials and its actual interface contract. No synthetic endpoint or supplier price will be used.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
  echo '<input type="hidden" name="operation" value="save">';
  echo '<input type="hidden" name="provider" value="'.esc_attr($selected).'">';
  wp_nonce_field(self::ACTION.'_'.$selected);
  echo '<table class="form-table"><tbody>';
  self::check('Provider enabled','enabled',!empty($p['enabled']));
  if($selected==='egyptlimo'){
   self::select('Connection environment','mode',['sandbox'=>'Sandbox — safe testing','production'=>'Production — assigned merchant only'],$p['mode']);
   self::field('Merchant subdomain','merchant_slug',$p['merchant_slug'],
     'From your assigned EgyptLimo Partner Portal. Do not include .partner-api.egyptlimo.com.');
   self::field('Client ID','client_id',$p['client_id']);
   self::field('Merchant ID','merchant_id',$p['merchant_id']);
   self::field('Signing key ID','key_id',$p['key_id']);
   foreach(['client_secret'=>'OAuth Client Secret','signing_secret'=>'HMAC Signing Secret'] as $key=>$name){
    echo '<tr><th>'.esc_html($name).'</th><td><input type="password" name="'.
      esc_attr($key).'" value="" class="regular-text" autocomplete="new-password" placeholder="'.
      (MAD4B_Ground_Provider_Core_V1::secret($selected,$key)!==''?'Stored securely — leave blank to retain':'Enter assigned credential').
      '"><p class="description">Encrypted server-side; blank preserves the existing secret. Never printed back to HTML.</p></td></tr>';
   }
  }
  self::select('Unverified fare display','fare_mode',['on_request'=>'Price on request','hide'=>'Do not display fare'],$p['fare_mode']);
  self::select('Source cost pricing rule','pricing_rule_kind',[
    'not_configured'=>'Not configured — no commercial rate',
    'markup_percent'=>'Markup on net supplier cost (%)',
    'target_margin_percent'=>'Target margin of selling price (%)',
    'fixed_profit'=>'Fixed profit (same source currency)'],$p['pricing_rule_kind']);
  self::field('Price rule value','pricing_rule_value',$p['pricing_rule_value'],
    'No assumed profit percentage. Catalog sync never creates a financial rate.');
  self::select('Catalog refresh schedule','catalog_sync',[
    'manual'=>'Manual only',
    'daily'=>'Daily when an authenticated API is connected'],$p['catalog_sync']);
  echo '<tr><th>JetEngine import policy</th><td><strong>Draft only</strong>. Native post type <code>transportations</code>; mapping to native taxonomies and meta. No automatic Rate approval, public product, booking or order.</td></tr>';
  echo '</tbody></table>';
  submit_button('Save Provider Options');
  echo '</form>';
  if($selected==='egyptlimo'){
   self::action_form($selected,'test','Test signed API and capabilities','secondary');
   self::action_form($selected,'sync','Import API Vehicle Catalog into JetEngine Drafts','primary');
   echo '<p class="description">The catalogue endpoint <code>/integration/vehicle-classes</code> returns class/capacity, not route or dated prices. Real quotes remain a separate provider workflow.</p>';
   if(class_exists('MAD4B_Ground_Provider_Quotes_V1'))
     MAD4B_Ground_Provider_Quotes_V1::render_form();
  }
  $ledger=(array)get_option(MAD4B_Ground_Provider_Core_V1::LEDGER,[]);
  echo '<h3>Last native JetEngine import results</h3>';
  $tail=array_reverse(array_slice(array_filter($ledger,static function($r)use($selected){
    return ($r['provider_id']??'')===$selected;
  }),-4));
  if(!$tail)echo '<p>No authenticated import receipts yet.</p>';
  else {
   echo '<table class="widefat striped"><thead><tr><th>UTC</th><th>Received</th><th>Created drafts</th><th>Updated</th><th>Skipped unchanged</th><th>Needs review</th><th>Errors</th></tr></thead><tbody>';
   foreach($tail as $r)
    echo '<tr><td>'.esc_html($r['at']??'').'</td><td>'.(int)($r['records_received']??0).
    '</td><td>'.(int)($r['created_drafts']??0).'</td><td>'.(int)($r['updated_drafts']??0).
    '</td><td>'.(int)($r['unchanged']??0).'</td><td>'.(int)($r['review_needed']??0).
    '</td><td>'.count((array)($r['errors']??[])).'</td></tr>';
   echo '</tbody></table>';
  }
  echo '<h3>Content readiness</h3><p>Imported vehicle classes remain <code>AWAITING_ROUTE_PRICE_AVAILABILITY_VERIFICATION</code>. Verify origin/destination, passenger market, luggage and pickup, dated supplier quote and availability before enabling checkout.</p>';
  if(class_exists('MAD4B_Ground_Content_Readiness_V1'))
    MAD4B_Ground_Content_Readiness_V1::page_section();
  echo '<p><a href="'.esc_url(admin_url('edit.php?post_type=transportations')).'">Open JetEngine Transportation CPT</a> · ';
  echo '<a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=booking_experience')).'">Booking Experience settings</a></p>';
  echo '</div>';
 }
 static function field(string $label,string $field,string $value,string $help=''):void{
  echo '<tr><th><label for="'.esc_attr($field).'">'.esc_html($label).
    '</label></th><td><input id="'.esc_attr($field).'" name="'.esc_attr($field).
    '" class="regular-text" value="'.esc_attr($value).'">';
  if($help!=='')echo '<p class="description">'.esc_html($help).'</p>';
  echo '</td></tr>';
 }
 static function select(string $label,string $field,array $values,$current):void{
  echo '<tr><th>'.esc_html($label).'</th><td><select name="'.esc_attr($field).'">';
  foreach($values as $k=>$name)echo '<option value="'.esc_attr($k).'" '.
    selected($current,$k,false).'>'.esc_html($name).'</option>';
  echo '</select></td></tr>';
 }
 static function check(string $label,string $name,bool $on):void{
  echo '<tr><th>'.esc_html($label).'</th><td><label><input type="checkbox" name="'.
    esc_attr($name).'" value="1" '.checked($on,true,false).
    '> Enable this supplier in the admin workflow</label></td></tr>';
 }
 static function action_form(string $provider,string $operation,string $label,string $style):void{
  echo '<form style="display:inline-block;margin:0 12px 8px 0" method="post" action="'.
    esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.
    esc_attr(self::ACTION).'"><input type="hidden" name="operation" value="'.
    esc_attr($operation).'"><input type="hidden" name="provider" value="'.
    esc_attr($provider).'">';
  wp_nonce_field(self::ACTION.'_'.$provider);
  echo '<button type="submit" class="button button-'.esc_attr($style).'">'.
    esc_html($label).'</button></form>';
 }
 static function dispatch():void{
  $provider=sanitize_key((string)($_POST['provider']??''));
  $operation=sanitize_key((string)($_POST['operation']??''));
  if(!self::authorized()||!isset(MAD4B_Ground_Provider_Core_V1::definitions()[$provider]))
    wp_die('Not permitted',403);
  check_admin_referer(self::ACTION.'_'.$provider);
  if(!in_array($operation,['save','test','sync'],true))wp_die('Invalid action',400);
  $success=false;$reason='';
  if($operation==='save'){
   $raw=wp_unslash($_POST);
   $r=MAD4B_Ground_Provider_Core_V1::save($provider,is_array($raw)?$raw:[]);
   $success=!is_wp_error($r);
   $reason=$success?'saved':$r->get_error_code();
  }elseif($operation==='test'){
   $r=$provider==='egyptlimo'?
      MAD4B_Ground_Provider_Core_V1::egyptlimo_get('/integration/capabilities'):
      new WP_Error('ground_provider_test_not_available');
   $success=!is_wp_error($r);
   $reason=$success?'authenticated_capabilities_response':$r->get_error_code();
   $data=(array)get_option(self::TESTS,[]);
   $data[$provider]=['success'=>$success,'reason'=>$reason,'at'=>gmdate('c'),
     'fingerprint'=>MAD4B_Ground_Provider_Core_V1::configuration_fingerprint($provider)];
   update_option(self::TESTS,$data,false);
  }else{
   $r=MAD4B_Ground_Provider_Core_V1::sync($provider);
   $success=!is_wp_error($r);
   $reason=$success?'synced':$r->get_error_code();
   $data=(array)get_option(self::TESTS,[]);
   $data[$provider]=['success'=>$success,'reason'=>$reason,'at'=>gmdate('c'),
     'fingerprint'=>MAD4B_Ground_Provider_Core_V1::configuration_fingerprint($provider)];
   update_option(self::TESTS,$data,false);
  }
  if(!$success && function_exists('error_log'))
    error_log('[MAD4B Ground Provider] '.$provider.' '.$operation.' '.$reason);
  wp_safe_redirect(add_query_arg([
   'page'=>self::SLUG,'provider'=>$provider,'result'=>$success?'success':'review'
  ],admin_url('admin.php')));
  exit;
 }
}
add_action('admin_menu',['MAD4B_Ground_Provider_Admin_V1','menus'],35);
add_action('woocommerce_settings_tabs_booking_experience',['MAD4B_Ground_Provider_Admin_V1','link'],110);
add_action('admin_post_'.MAD4B_Ground_Provider_Admin_V1::ACTION,['MAD4B_Ground_Provider_Admin_V1','dispatch']);
