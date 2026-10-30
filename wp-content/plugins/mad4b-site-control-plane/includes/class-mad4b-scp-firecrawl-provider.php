<?php
if (!defined('ABSPATH')) exit;
/** Native Control Plane Firecrawl connection: explicit admin setup and bounded probe. */
final class MAD4B_SCP_Firecrawl_Provider {
 const OPTION = 'mad4b_firecrawl_provider_v1';
 public static function boot() { add_action('admin_post_mad4b_firecrawl_save', array(__CLASS__,'save')); add_action('admin_post_mad4b_firecrawl_test', array(__CLASS__,'test_connection')); }
 private static function secret_key() { return hash('sha256', wp_salt('auth').'|mad4b-firecrawl-v1', true); }
 private static function encrypt($plain) {
  if (!function_exists('openssl_encrypt')) return new WP_Error('mad4b_firecrawl_crypto_missing','OpenSSL required.');
  $iv=random_bytes(12);$tag='';
  $cipher=openssl_encrypt($plain,'aes-256-gcm',self::secret_key(),OPENSSL_RAW_DATA,$iv,$tag);
  return $cipher===false?new WP_Error('mad4b_firecrawl_crypto_failed','Encryption failed.'):base64_encode($iv.$tag.$cipher);
 }
 private static function decrypt($value) {
  $raw=base64_decode((string)$value,true);
  if($raw===false||strlen($raw)<29)return '';
  $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::secret_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
  return $plain===false?'':$plain;
 }
 public static function config() { $v=get_option(self::OPTION,array());return is_array($v)?$v:array(); }
 public static function profiles() {
  $raw=self::config();$profiles=isset($raw['profiles'])&&is_array($raw['profiles'])?$raw['profiles']:array();
  // One-time backward-compatible read: the legacy credential belongs ONLY to its saved mode.
  $legacy_mode=$raw['mode']??'';
  if(in_array($legacy_mode,array('cloud','self_hosted'),true)&&!isset($profiles[$legacy_mode])) {
   $profiles[$legacy_mode]=array('endpoint'=>$raw['endpoint']??'', 'username'=>$raw['username']??'', 'secret_cipher'=>$raw['secret_cipher']??'');
  }
  return $profiles;
 }
 public static function save() {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  check_admin_referer('mad4b_firecrawl_save');
  $mode=isset($_POST['firecrawl_mode'])?sanitize_key(wp_unslash($_POST['firecrawl_mode'])):'';
  if(!in_array($mode,array('cloud','self_hosted'),true))wp_die('Invalid mode',400);
  $profiles=self::profiles();$current=$profiles[$mode]??array();
  $field=$mode==='cloud'?'firecrawl_cloud_secret':'firecrawl_gateway_password';
  $secret=isset($_POST[$field])?trim((string)wp_unslash($_POST[$field])):'';
  if(strlen($secret)>4096)wp_die('Invalid credential length',400);
  $sealed=$secret!==''?self::encrypt($secret):($current['secret_cipher']??'');
  if(is_wp_error($sealed))wp_die(esc_html($sealed->get_error_message()),500);
  if($mode==='self_hosted') {
   $endpoint=isset($_POST['firecrawl_endpoint'])?esc_url_raw(wp_unslash($_POST['firecrawl_endpoint'])):'';
   if(!str_starts_with($endpoint,'https://')||!wp_http_validate_url($endpoint))wp_die('Valid HTTPS gateway URL required',400);
   $username=isset($_POST['firecrawl_username'])?sanitize_user(wp_unslash($_POST['firecrawl_username']),true):'';
   if(!preg_match('/^[A-Za-z0-9._-]{3,64}$/',$username))wp_die('Valid gateway service username required',400);
  } else {
   $endpoint='https://api.firecrawl.dev';$username='';
  }
  $profiles[$mode]=array('endpoint'=>rtrim($endpoint,'/'),'username'=>$username,'secret_cipher'=>$sealed);
  update_option(self::OPTION,array('mode'=>$mode,'profiles'=>$profiles,'updated_by'=>get_current_user_id(),'updated_at'=>gmdate('c')),false);
  wp_safe_redirect(admin_url('admin.php?page=mad4b-provider-connections&firecrawl_saved=1'));exit;
 }
 public static function probe() {
  $c=self::config();$mode=$c['mode']??'cloud';$profiles=self::profiles();$selected=$profiles[$mode]??array();
  $endpoint=$selected['endpoint']??'';$token=self::decrypt($selected['secret_cipher']??'');
  if(!$endpoint||!$token||($mode==='self_hosted'&&empty($selected['username'])))return array('ready'=>false,'reason'=>'configuration_missing');
  $url=rtrim($endpoint,'/').'/v2/scrape';
  if(!wp_http_validate_url($url))return array('ready'=>false,'reason'=>'unsafe_endpoint');
  return array('ready'=>true,'endpoint'=>$endpoint,'mode'=>$mode,'credentials_saved'=>true,'network_probe_performed'=>false,'authentication_mode'=>$mode==='self_hosted'?'basic':'bearer');
 }
 public static function test_connection() {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  check_admin_referer('mad4b_firecrawl_test');
  $c=self::config();$mode=$c['mode']??'cloud';$profiles=self::profiles();$profile=$profiles[$mode]??array();
  $endpoint=(string)($profile['endpoint']??'');$secret=self::decrypt($profile['secret_cipher']??'');
  $state='missing';
  if($endpoint!==''&&$secret!==''&&wp_http_validate_url($endpoint)) {
   $headers=array('Accept'=>'application/json');
   if($mode==='self_hosted'&&!empty($profile['username']))$headers['Authorization']='Basic '.base64_encode($profile['username'].':'.$secret);
   elseif($mode==='cloud')$headers['Authorization']='Bearer '.$secret;
   else $headers=array();
   if(isset($headers['Authorization'])) {
    $response=wp_remote_get(rtrim($endpoint,'/').'/',array('timeout'=>8,'redirection'=>0,'headers'=>$headers,'sslverify'=>true,'limit_response_size'=>2048));
    if(is_wp_error($response))$state='network_error';
    else {$status=(int)wp_remote_retrieve_response_code($response);$state=$status>=200&&$status<300?'reachable':($status===401||$status===403?'authentication_failed':($status===429?'rate_limited':'http_'.$status));}
   }
  }
  update_option('mad4b_firecrawl_last_connection_test_v1',array('mode'=>$mode,'result'=>$state,'at'=>gmdate('c'),'user_id'=>get_current_user_id()),false);
  wp_safe_redirect(admin_url('admin.php?page=mad4b-provider-connections&firecrawl_tested=1'));exit;
 }
 public static function render() {
  if(!current_user_can('manage_options'))return;
  $c=self::config();$mode=$c['mode']??'cloud';$profiles=self::profiles();
  $gateway=$profiles['self_hosted']??array();$cloud=$profiles['cloud']??array();
  echo '<hr><h2>Firecrawl — Native Provider Connection</h2>';
  echo '<p>Choose Cloud (Bearer API key) or Self-hosted (HTTPS gateway with service username and password). Credentials are stored separately and never displayed.</p>';
  if(isset($_GET['firecrawl_saved']))echo '<div class="notice notice-success inline"><p>Firecrawl connection settings saved.</p></div>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field('mad4b_firecrawl_save');
  echo '<input type="hidden" name="action" value="mad4b_firecrawl_save">';
  echo '<table class="form-table"><tr><th>Mode</th><td><select id="mad4b-firecrawl-mode" name="firecrawl_mode">';
  foreach(array('cloud'=>'Firecrawl Cloud','self_hosted'=>'Self-hosted (HTTPS gateway)') as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($mode,$key,false).'>'.esc_html($label).'</option>';
  echo '</select></td></tr>';
  echo '<tr data-mad4b-firecrawl-mode="cloud"><th>Cloud API Endpoint</th><td><code>https://api.firecrawl.dev</code></td></tr>';
  echo '<tr data-mad4b-firecrawl-mode="cloud"><th>Cloud API Key</th><td><input type="password" name="firecrawl_cloud_secret" autocomplete="new-password" class="regular-text" placeholder="Leave blank to retain saved Cloud key"><p class="description">'.(!empty($cloud['secret_cipher'])?'Cloud key saved':'No Cloud key saved').'; encrypted at rest.</p></td></tr>';
  echo '<tr data-mad4b-firecrawl-mode="self_hosted"><th>Self-hosted HTTPS URL</th><td><input type="url" name="firecrawl_endpoint" class="regular-text" value="'.esc_attr($gateway['endpoint']??'').'" placeholder="https://firecrawl.mad4b.com"></td></tr>';
  echo '<tr data-mad4b-firecrawl-mode="self_hosted"><th>Gateway Service Username</th><td><input type="text" name="firecrawl_username" autocomplete="off" class="regular-text" value="'.esc_attr($gateway['username']??'').'" placeholder="mad4b-control-plane"></td></tr>';
  echo '<tr data-mad4b-firecrawl-mode="self_hosted"><th>Gateway Password</th><td><input type="password" name="firecrawl_gateway_password" autocomplete="new-password" class="regular-text" placeholder="Leave blank to retain saved Gateway password"><p class="description">'.(!empty($gateway['secret_cipher'])?'Gateway password saved':'No Gateway password saved').'; encrypted at rest.</p></td></tr></table>';
  submit_button('Save Firecrawl Connection');
  echo '</form>';
  echo '<script>(function(){var select=document.getElementById("mad4b-firecrawl-mode");if(!select)return;function toggle(){document.querySelectorAll("[data-mad4b-firecrawl-mode]").forEach(function(row){var active=row.getAttribute("data-mad4b-firecrawl-mode")===select.value;row.hidden=!active;row.style.display=active?"":"none";row.querySelectorAll("input").forEach(function(input){input.disabled=!active;});});}select.addEventListener("change",toggle);toggle();})();</script>';
  $state=self::probe();echo '<p><strong>Readiness:</strong> '.esc_html($state['ready']?'Configured; network test pending':($state['reason']??'unavailable')).'</p>';
 }
}
MAD4B_SCP_Firecrawl_Provider::boot();
