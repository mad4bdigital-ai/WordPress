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
 /** A saved test belongs to the exact active credential profile, not only the mode. */
 public static function test_status() {
  $c=self::config();$mode=$c['mode']??'cloud';$profiles=self::profiles();
  $profile=$profiles[$mode]??array();
  $last=get_option('mad4b_firecrawl_last_connection_test_v1',array());
  $fingerprint=hash('sha256',wp_json_encode(array($mode,$profile['endpoint']??'',$profile['username']??'',$profile['secret_cipher']??'')));
  if(!is_array($last)||($last['mode']??'')!==$mode||($last['profile_sha256']??'')!==$fingerprint)return array('state'=>'network_test_pending','tested'=>false);
  $http_status=(int)($last['http_status']??0);
  return array('state'=>sanitize_key((string)($last['result']??'unknown')),'tested'=>true,
   'at'=>(string)($last['at']??''),'http_status'=>$http_status>=100&&$http_status<=599?$http_status:0);
 }
 /** UI interpretation only: a reachable gateway is not evidence of a successful scrape. */
 public static function test_feedback($last) {
  $state=(string)($last['state']??'network_test_pending');
  $messages=array(
   'reachable'=>array('success','Connection test successful','The saved credentials were accepted by the provider endpoint. Scrape execution was not tested.'),
   'authentication_failed'=>array('error','Authentication failed','The provider rejected the saved credentials (HTTP 401/403). Check username, password or API key.'),
   'network_error'=>array('error','Connection failed','The gateway could not be reached. Check DNS, TLS, firewall and timeout.'),
   'unsafe_endpoint'=>array('error','Invalid endpoint','The saved endpoint failed the HTTPS security check.'),
   'configuration_missing'=>array('warning','Configuration incomplete','Save the provider endpoint and credentials, then run the test.'),
   'rate_limited'=>array('warning','Provider rate limit reached','The provider returned HTTP 429. Try again later.'),
   'quota_exhausted'=>array('warning','Provider quota exhausted','The provider returned HTTP 402. Review the provider account.'),
   'network_test_pending'=>array('info','Connection not tested','Run Test saved connection to confirm access using the stored credentials.')
  );
  $row=isset($messages[$state])?$messages[$state]:array('error','Connection test failed','The provider returned an unexpected response. Check its service health and API route.');
  $http_status=(int)($last['http_status']??0);
  if($http_status>=100&&$http_status<=599)$row[2].=' HTTP '.$http_status.'.';
  return array('level'=>$row[0],'title'=>$row[1],'message'=>$row[2],'state'=>$state);
 }
 public static function test_connection() {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  check_admin_referer('mad4b_firecrawl_test');
  $c=self::config();$mode=$c['mode']??'cloud';$profiles=self::profiles();$profile=$profiles[$mode]??array();
  $endpoint=(string)($profile['endpoint']??'');$secret=self::decrypt($profile['secret_cipher']??'');
  $state='configuration_missing';$http_status=0;
  $valid_mode=in_array($mode,array('cloud','self_hosted'),true);
  $valid_endpoint=$valid_mode && $endpoint!=='' && strncmp($endpoint,'https://',8)===0 && wp_http_validate_url($endpoint);
  if($mode==='cloud' && rtrim($endpoint,'/')!=='https://api.firecrawl.dev')$valid_endpoint=false;
  if($valid_endpoint && $secret!=='') {
   $headers=array('Accept'=>'application/json');
   if($mode==='self_hosted'&&!empty($profile['username']))$headers['Authorization']='Basic '.base64_encode($profile['username'].':'.$secret);
   elseif($mode==='cloud')$headers['Authorization']='Bearer '.$secret;
   if(isset($headers['Authorization'])) {
    // Cloud: authenticated read-only API operation; self-hosted: Basic-auth gateway check.
    // Neither request performs scraping or establishes scrape capability certification.
    $url=rtrim($endpoint,'/').($mode==='cloud'?'/v2/team/credit-usage':'/');
    if(!wp_http_validate_url($url))$state='unsafe_endpoint';
    else {
     $response=wp_safe_remote_get($url,array('timeout'=>8,'redirection'=>0,'headers'=>$headers,'sslverify'=>true,'limit_response_size'=>2048));
     if(is_wp_error($response))$state='network_error';
     else {
      $status=(int)wp_remote_retrieve_response_code($response);$http_status=$status;
      $state=$status>=200&&$status<300?'reachable':($status===401||$status===403?'authentication_failed':($status===429?'rate_limited':($status===402?'quota_exhausted':'http_'.$status)));
     }
    }
   }
  } elseif($endpoint!=='' && !$valid_endpoint) $state='unsafe_endpoint';
  $fingerprint=hash('sha256',wp_json_encode(array($mode,$profile['endpoint']??'',$profile['username']??'',$profile['secret_cipher']??'')));
  update_option('mad4b_firecrawl_last_connection_test_v1',array('mode'=>$mode,'profile_sha256'=>$fingerprint,'result'=>$state,'http_status'=>$http_status,'at'=>gmdate('c'),'user_id'=>get_current_user_id()),false);
  wp_safe_redirect(admin_url('admin.php?page=mad4b-provider-connections&firecrawl_tested=1#mad4b-firecrawl-provider'));exit;
 }
 /**
  * Staging-only, explicitly requested scrape of this site's public origin.
  * Outbound content is not published and no credentials leave the provider class.
  */
 public static function scrape_owned_source($source_url) {
  if(wp_get_environment_type()!=='staging' || !current_user_can('manage_options'))
   return new WP_Error('mad4b_firecrawl_stage_admin_required','Staging administrator required.');
  $origin=rtrim(home_url(),'/');
  if(!is_string($source_url) || ($source_url!==$origin.'/' && $source_url!==$origin))
   return new WP_Error('mad4b_firecrawl_owned_source_only','Only the current site homepage is accepted for this E2E smoke test.');
  $config=self::config();$mode=(string)($config['mode']??'');
  $profiles=self::profiles();$p=$profiles[$mode]??array();
  $endpoint=rtrim((string)($p['endpoint']??''),'/');
  $secret=self::decrypt($p['secret_cipher']??'');
  if($endpoint===''||$secret===''||!wp_http_validate_url($endpoint))
   return new WP_Error('mad4b_firecrawl_setup_incomplete','Saved provider configuration not available.');
  $headers=array('Accept'=>'application/json','Content-Type'=>'application/json');
  if($mode==='self_hosted' && !empty($p['username']))
   $headers['Authorization']='Basic '.base64_encode($p['username'].':'.$secret);
  elseif($mode==='cloud' && $endpoint==='https://api.firecrawl.dev')
   $headers['Authorization']='Bearer '.$secret;
  else return new WP_Error('mad4b_firecrawl_mode_invalid','Unsupported provider profile.');
  $url=$endpoint.'/v2/scrape';
  if(!wp_http_validate_url($url))return new WP_Error('mad4b_firecrawl_unsafe_endpoint','Unsafe provider URL.');
  $res=wp_safe_remote_post($url,array(
   'timeout'=>35,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>260000,
   'headers'=>$headers,'body'=>wp_json_encode(array(
     'url'=>$origin.'/','formats'=>array('markdown'),'onlyMainContent'=>true))
  ));
  if(is_wp_error($res))return new WP_Error('mad4b_firecrawl_scrape_network',$res->get_error_message());
  $status=(int)wp_remote_retrieve_response_code($res);
  $payload=json_decode((string)wp_remote_retrieve_body($res),true);
  if($status<200||$status>=300)
   return new WP_Error('mad4b_firecrawl_scrape_http','Provider returned HTTP '.$status,array('http_status'=>$status));
  $markdown=(string)($payload['data']['markdown']??$payload['markdown']??'');
  if(!is_array($payload) || !($payload['success']??false) || trim($markdown)==='')
   return new WP_Error('mad4b_firecrawl_scrape_invalid','Scrape did not return usable markdown.');
  if(strlen($markdown)>60000)$markdown=substr($markdown,0,60000);
  return array('source_url'=>$origin.'/','provider'=>'firecrawl','http_status'=>$status,
   'source_sha256'=>hash('sha256',$markdown),'captured_at'=>gmdate('c'),
   'markdown'=>$markdown,'length'=>strlen($markdown),'network_executed'=>true);
 }
 public static function render() {
  if(!current_user_can('manage_options'))return;
  $c=self::config();$mode=$c['mode']??'cloud';$profiles=self::profiles();
  $gateway=$profiles['self_hosted']??array();$cloud=$profiles['cloud']??array();
  echo '<hr><h2 id="mad4b-firecrawl-provider">Firecrawl — Native Provider Connection</h2>';
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
  echo '<tr data-mad4b-firecrawl-mode="self_hosted"><th>Self-hosted HTTPS URL</th><td><input type="url" name="firecrawl_endpoint" class="regular-text" value="'.esc_attr($gateway['endpoint']??'').'" placeholder="https://"></td></tr>';
  echo '<tr data-mad4b-firecrawl-mode="self_hosted"><th>Gateway Service Username</th><td><input type="text" name="firecrawl_username" autocomplete="off" class="regular-text" value="'.esc_attr($gateway['username']??'').'" placeholder="mad4b-control-plane"></td></tr>';
  echo '<tr data-mad4b-firecrawl-mode="self_hosted"><th>Gateway Password</th><td><input type="password" name="firecrawl_gateway_password" autocomplete="new-password" class="regular-text" placeholder="Leave blank to retain saved Gateway password"><p class="description">'.(!empty($gateway['secret_cipher'])?'Gateway password saved':'No Gateway password saved').'; encrypted at rest.</p></td></tr></table>';
  submit_button('Save Firecrawl Connection');
  echo '</form>';
  echo '<script>(function(){var select=document.getElementById("mad4b-firecrawl-mode");if(!select)return;function toggle(){document.querySelectorAll("[data-mad4b-firecrawl-mode]").forEach(function(row){var active=row.getAttribute("data-mad4b-firecrawl-mode")===select.value;row.hidden=!active;row.style.display=active?"":"none";row.querySelectorAll("input").forEach(function(input){input.disabled=!active;});});}select.addEventListener("change",toggle);toggle();})();</script>';
  $state=self::probe();$last=self::test_status();
  echo '<p><strong>Readiness:</strong> '.esc_html($state['ready']?'Configured (scrape not certified)':($state['reason']??'unavailable')).'</p>';
  $feedback=self::test_feedback($last);
  $notice_class=in_array($feedback['level'],array('success','error','warning','info'),true)?$feedback['level']:'info';
  echo '<div class="notice notice-'.esc_attr($notice_class).' inline mad4b-provider-test-feedback" role="'.($notice_class==='error'?'alert':'status').'" aria-live="polite">';
  echo '<p><strong>'.esc_html($feedback['title']).'</strong> — '.esc_html($feedback['message']).'</p>';
  if(!empty($last['tested']))echo '<p class="description">Last test: '.esc_html($last['at']).' UTC | Result: '.esc_html($feedback['state']).'</p>';
  echo '</div>';
  if(!empty($state['ready'])) {
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_firecrawl_test">';
   wp_nonce_field('mad4b_firecrawl_test');
   echo '<button class="button button-secondary" type="submit">Test saved connection</button></form>';
  }
 }
}
MAD4B_SCP_Firecrawl_Provider::boot();
