<?php
if (!defined('ABSPATH')) exit;
/** Native Control Plane Firecrawl connection: explicit admin setup and bounded probe. */
final class MAD4B_SCP_Firecrawl_Provider {
 const OPTION = 'mad4b_firecrawl_provider_v1';
 public static function boot() { add_action('admin_post_mad4b_firecrawl_save', array(__CLASS__,'save')); }
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
 public static function save() {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  check_admin_referer('mad4b_firecrawl_save');
  $mode=isset($_POST['firecrawl_mode'])?sanitize_key(wp_unslash($_POST['firecrawl_mode'])):'cloud';
  if(!in_array($mode,array('cloud','self_hosted'),true))wp_die('Invalid mode',400);
  $old=self::config();$secret=isset($_POST['firecrawl_secret'])?trim((string)wp_unslash($_POST['firecrawl_secret'])):'';
  if(strlen($secret)>4096)wp_die('Invalid credential length',400);
  $sealed=$secret!==''?self::encrypt($secret):($old['secret_cipher']??'');
  if(is_wp_error($sealed))wp_die(esc_html($sealed->get_error_message()),500);
  $endpoint=$mode==='cloud'?'https://api.firecrawl.dev':(isset($_POST['firecrawl_endpoint'])?esc_url_raw(wp_unslash($_POST['firecrawl_endpoint'])):'');
  if($mode==='self_hosted'&&(!str_starts_with($endpoint,'https://') || !wp_http_validate_url($endpoint)))wp_die('Self-hosted endpoint must be externally validated HTTPS; internal gateways require separately provisioned network binding.',400);
  $username=$mode==='self_hosted'&&isset($_POST['firecrawl_username'])?sanitize_user(wp_unslash($_POST['firecrawl_username']),true):'';
  if($mode==='self_hosted'&&!preg_match('/^[A-Za-z0-9._-]{3,64}$/',$username))wp_die('Valid service username required',400);
  $config=array('mode'=>$mode,'username'=>$username,'endpoint'=>rtrim($endpoint,'/'),'secret_cipher'=>$sealed,'updated_by'=>get_current_user_id(),'updated_at'=>gmdate('c'));
  update_option(self::OPTION,$config,false);
  wp_safe_redirect(admin_url('admin.php?page=mad4b-provider-connections&firecrawl_saved=1'));exit;
 }
 public static function probe() {
  $c=self::config();$endpoint=$c['endpoint']??'';$token=self::decrypt($c['secret_cipher']??'');
  if(!$endpoint||!$token)return array('ready'=>false,'reason'=>'configuration_missing');
  $url=rtrim($endpoint,'/').'/v2/scrape';
  if(!wp_http_validate_url($url))return array('ready'=>false,'reason'=>'unsafe_endpoint');
  // Safe HTTP API, no test crawl without explicit invocation.
  return array('ready'=>true,'endpoint'=>$endpoint,'mode'=>$c['mode']??'cloud','credentials_saved'=>true,'network_probe_performed'=>false,'authentication_mode'=>($c['mode']??'cloud')==='self_hosted'?'basic':'bearer');
 }
 public static function render() {
  if(!current_user_can('manage_options'))return;
  $c=self::config();
  echo '<hr><h2>Firecrawl — Native Provider Connection</h2>';
  echo '<p>Provider configuration only. API credentials are write-only; source discovery and scraping require separately governed abilities.</p>';
  if(isset($_GET['firecrawl_saved']))echo '<div class="notice notice-success inline"><p>Firecrawl connection settings saved.</p></div>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field('mad4b_firecrawl_save');
  echo '<input type="hidden" name="action" value="mad4b_firecrawl_save">';
  echo '<table class="form-table"><tr><th>Mode</th><td><select name="firecrawl_mode">';
  foreach(array('cloud'=>'Firecrawl Cloud','self_hosted'=>'Self-hosted (HTTPS gateway)') as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($c['mode']??'cloud',$key,false).'>'.esc_html($label).'</option>';
  echo '</select></td></tr><tr><th>Self-hosted HTTPS URL</th><td><input type="url" class="regular-text" name="firecrawl_endpoint" value="'.esc_attr(($c['mode']??'')==='self_hosted'?($c['endpoint']??''):'').'" placeholder="https://firecrawl.example.com"></td></tr>';
  echo '<tr><th>Gateway Service Username</th><td><input type="text" name="firecrawl_username" autocomplete="off" class="regular-text" value="'.esc_attr($c['username']??'').'" placeholder="mad4b-control-plane"></td></tr>';
  echo '<tr><th>API Key / Gateway Password</th><td><input type="password" name="firecrawl_secret" value="" autocomplete="new-password" class="regular-text" placeholder="Leave blank to keep existing">';
  echo '<p class="description">Credentials '.(!empty($c['secret_cipher'])?'saved':'not configured').' (encrypted at rest). No secret echoed.</p></td></tr></table>';
  submit_button('Save Firecrawl Connection');
  echo '</form>';
  $state=self::probe();echo '<p><strong>Readiness:</strong> '.esc_html($state['ready']?'Configured; network test pending':($state['reason']??'unavailable')).'</p>';
 }
}
MAD4B_SCP_Firecrawl_Provider::boot();
