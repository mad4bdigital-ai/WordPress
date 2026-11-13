<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/class-mad4b-scp-unified-provider-routing.php';
require_once __DIR__.'/class-mad4b-scp-firecrawl-page-store.php';
/** Native Control Plane Firecrawl connection: explicit admin setup and bounded probe. */
final class MAD4B_SCP_Firecrawl_Provider {
 private static function enrolled_write_site():bool {
  if(!class_exists('MAD4B_SCP_Site_Profile',false))return false;
  $s=MAD4B_SCP_Site_Profile::status();
  return is_array($s) && ($s['configured']??false)===true
   && ($s['origin_match']??false)===true
   && ($s['deployment_binding_match']??false)===true
   && ($s['environment_match']??false)===true
   && ($s['write_enabled']??false)===true;
 }

 const OPTION = 'mad4b_firecrawl_provider_v1';
 const TIMEOUT_METRICS='mad4b_firecrawl_adaptive_timeout_v1';

 // Crawl scope contract: no MAD4B-specific fixed page/depth cap.
 // The provider retains its own quotas and default page limit.
 public static function crawl_plan($url,$mode='single_page'){
  $url=is_string($url)?trim($url):'';
  $mode=sanitize_key((string)$mode);
  if(!in_array($mode,['single_page','section_crawl','full_site_crawl'],true))
   return new WP_Error('mad4b_firecrawl_crawl_mode_invalid');
  if(!str_starts_with($url,'https://')||!wp_http_validate_url($url))
   return new WP_Error('mad4b_firecrawl_crawl_url_invalid');
  $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
  $path=(string)wp_parse_url($url,PHP_URL_PATH);
  $payload=[
   'url'=>$url,
   'sitemap'=>'include',
   'ignoreQueryParameters'=>true,
   'deduplicateSimilarURLs'=>true,
   'allowExternalLinks'=>false,
   'allowSubdomains'=>false,
   'scrapeOptions'=>['formats'=>['markdown'],'onlyMainContent'=>true]
  ];
  if($mode==='section_crawl'){
   if($path===''||$path==='/')
    return new WP_Error('mad4b_firecrawl_section_path_required');
   $prefix='/'.trim($path,'/').'/';
   // Firecrawl's crawlEntireDomain=false already confines discovery to children of the seed path.
   // Avoid a second includePaths regex that could exclude the seed page itself.
   $payload['crawlEntireDomain']=false;
  } elseif($mode==='full_site_crawl'){
   $payload['crawlEntireDomain']=true;
  }
  if($mode==='single_page'){
   return ['contract'=>'mad4b.firecrawl.crawl-plan.v1','mode'=>$mode,
     'endpoint'=>'/v2/scrape','payload'=>[
       'url'=>$url,'formats'=>['markdown'],'onlyMainContent'=>true],
     'host'=>$host,'fixed_limit_added'=>false,'execution_performed'=>false];
  }
  return ['contract'=>'mad4b.firecrawl.crawl-plan.v1','mode'=>$mode,
   'endpoint'=>'/v2/crawl','payload'=>$payload,
   'host'=>$host,'fixed_limit_added'=>false,'execution_performed'=>false,
   'provider_default_limit_applies'=>true];
 }

 // Executable crawl actions are bound to the same enrolled Staging origin
 // and Administrator, not arbitrary incoming URLs or other companies.
 public static function start_owned_crawl($url,$crawl_mode){
  if(!self::enrolled_write_site()||!current_user_can('manage_options'))
   return new WP_Error('mad4b_firecrawl_crawl_admin_required');
  $plan=self::crawl_plan($url,$crawl_mode);
  if(is_wp_error($plan))return $plan;
  if($plan['mode']==='single_page')
   return new WP_Error('mad4b_firecrawl_use_single_scrape');
  $home=rtrim(home_url(),'/');
  if(strtolower((string)wp_parse_url($url,PHP_URL_HOST))!==
     strtolower((string)wp_parse_url($home,PHP_URL_HOST)))
   return new WP_Error('mad4b_firecrawl_target_not_enrolled');
  $config=self::config();$mode=(string)($config['mode']??'');
  $profile=self::profiles()[$mode]??[];
  $endpoint=rtrim((string)($profile['endpoint']??''),'/');
  $secret=self::decrypt($profile['secret_cipher']??'');
  if(!$endpoint||!$secret)return new WP_Error('mad4b_firecrawl_provider_unavailable');
  $headers=['Accept'=>'application/json','Content-Type'=>'application/json'];
  if($mode==='self_hosted'&&!empty($profile['username']))
   $headers['Authorization']='Basic '.base64_encode($profile['username'].':'.$secret);
  elseif($mode==='cloud'&&$endpoint==='https://api.firecrawl.dev')
   $headers['Authorization']='Bearer '.$secret;
  else return new WP_Error('mad4b_firecrawl_profile_invalid');
  $response=MAD4B_SCP_Unified_Provider_Routing::request_crawl($mode,$endpoint,
   'start','',['headers'=>$headers,'body'=>wp_json_encode($plan['payload']),
   'timeout'=>25,'limit_response_size'=>65536]);
  if(is_wp_error($response))return $response;
  $status=(int)wp_remote_retrieve_response_code($response);
  $json=json_decode((string)wp_remote_retrieve_body($response),true);
  if($status<200||$status>=300||!is_array($json)||empty($json['success'])||
      !preg_match('/^[a-zA-Z0-9-]{16,72}$/D',(string)($json['id']??'')))
   return new WP_Error('mad4b_firecrawl_crawl_start_failed','Crawl start failed',['http_status'=>$status]);
  $id=(string)$json['id'];
  $runs=get_option('mad4b_firecrawl_owned_crawl_runs_v1',[]);
  if(!is_array($runs))$runs=[];
  $runs[$id]=['id'=>$id,'source_url'=>$url,'mode'=>$crawl_mode,
   'source_host'=>$plan['host'],'state'=>'started',
   'created_at'=>gmdate('c'),'job_id'=>null,
   'review_only'=>true,'artifacts_persisted'=>false];
  if(count($runs)>30)$runs=array_slice($runs,-30,null,true);
  update_option('mad4b_firecrawl_owned_crawl_runs_v1',$runs,false);
  return ['crawl_id'=>$id,'status'=>'started','mode'=>$crawl_mode,
   'review_only'=>true,'content_job_changed'=>false];
 }
 // Content Hub entrypoint: bind one verified ContentJob to an owned
 // crawl before any pages are persisted. No source substitution allowed.
 public static function start_content_crawl($url,$crawl_mode,$job_id){
  if(!self::enrolled_write_site() || !current_user_can('manage_options')||
   !class_exists('MAD4B_SCP_Content_Jobs',false)||
   !class_exists('MAD4B_SCP_Policy',false)||!MAD4B_SCP_Policy::can_mutate())
   return new WP_Error('mad4b_firecrawl_content_crawl_not_authorized');
  if(!wp_is_uuid($job_id))return new WP_Error('mad4b_firecrawl_job_id_invalid');
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
  if(is_wp_error($job))return $job;
  // An existing source-bound ContentJob may never be silently rebound
  // to an unrelated owned-domain crawl.
  $target=(int)($job['job']['target_post_id']??0);
  $bound=$target>0?(string)get_post_meta($target,'_mad4b_source_research_url',true):'';
  if($bound===''){
   $subject=(string)($job['job']['subject']??'');
   if(preg_match('~(?:^|\\s)source\\s+(https://[^\\s|]+)~iu',$subject,$match))
    $bound=(string)$match[1];
  }
  // Exact independently supplied source provenance is required before dispatch.
  // Do not bind unrelated owned-domain crawling to a government ContentJob.
  if($bound==='')return new WP_Error('mad4b_firecrawl_job_source_binding_required');
  if(!hash_equals(rtrim($bound,'/'),rtrim((string)$url,'/')))
   return new WP_Error('mad4b_firecrawl_content_job_source_mismatch');
  if(!in_array($job['job']['state']??'',['QUEUED','RUNNING'],true))
   return new WP_Error('mad4b_firecrawl_job_state_ineligible');
  $result=self::start_owned_crawl($url,$crawl_mode);
  if(is_wp_error($result))return $result;
  $id=$result['crawl_id'];$runs=get_option('mad4b_firecrawl_owned_crawl_runs_v1',[]);
  if(!isset($runs[$id]))return new WP_Error('mad4b_firecrawl_run_receipt_missing');
  $runs[$id]['job_id']=$job_id;
  $runs[$id]['review_only']=true;
  update_option('mad4b_firecrawl_owned_crawl_runs_v1',$runs,false);
  $readback=get_option('mad4b_firecrawl_owned_crawl_runs_v1',[]);
  if(($readback[$id]['job_id']??'')!==$job_id)
    return new WP_Error('mad4b_firecrawl_job_bind_readback_failed');
  return $result+['job_id'=>$job_id,'contenthub_pages_enabled'=>true];
 }
 public static function owned_robots_readiness($url){
  $host=strtolower((string)wp_parse_url((string)$url,PHP_URL_HOST));
  if($host!==strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST)))
   return ['state'=>'NOT_OWNED','robots_verified'=>false];
  $robots=wp_safe_remote_get(home_url('/robots.txt'),[
   'timeout'=>6,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>100000]);
  if(is_wp_error($robots)||(int)wp_remote_retrieve_response_code($robots)!==200)
   return ['state'=>'ROBOTS_UNAVAILABLE','robots_verified'=>false];
  $lines=preg_split('/\r?\n/',(string)wp_remote_retrieve_body($robots));
  $star=false;$deny_all=false;
  foreach($lines as $line){
   $line=trim(explode('#',(string)$line,2)[0]);
   if(preg_match('/^User-agent:\s*(.+)$/i',$line,$m))
    $star=trim($m[1])==='*';
   elseif($star && preg_match('/^Disallow:\s*\/\s*$/i',$line))
    $deny_all=true;
  }
  return ['state'=>$deny_all?'ROBOTS_DISALLOW_ALL':'ROBOTS_NO_GLOBAL_BLOCK_FOUND',
    'robots_verified'=>true,'robots_disallow_all'=>$deny_all];
 }
 public static function owned_crawl_status($crawl_id,$internal=false){
  $poll_authorized=$internal===true && doing_action('mad4b_firecrawl_crawl_poll_v1');
  if(!self::enrolled_write_site()||
    (!current_user_can('manage_options') && !$poll_authorized))
   return new WP_Error('mad4b_firecrawl_crawl_admin_required');
  $runs=get_option('mad4b_firecrawl_owned_crawl_runs_v1',[]);
  if(!is_array($runs)||!isset($runs[$crawl_id]))
   return new WP_Error('mad4b_firecrawl_crawl_not_owned');
  $config=self::config();$mode=(string)($config['mode']??'');
  $profile=self::profiles()[$mode]??[];
  $endpoint=rtrim((string)($profile['endpoint']??''),'/');
  $secret=self::decrypt($profile['secret_cipher']??'');
  if(!$endpoint||!$secret)return new WP_Error('mad4b_firecrawl_provider_unavailable');
  $headers=['Accept'=>'application/json'];
  if($mode==='self_hosted'&&!empty($profile['username']))
   $headers['Authorization']='Basic '.base64_encode($profile['username'].':'.$secret);
  elseif($mode==='cloud'&&$endpoint==='https://api.firecrawl.dev')
   $headers['Authorization']='Bearer '.$secret;
  else return new WP_Error('mad4b_firecrawl_profile_invalid');
  $cursor=(string)($runs[$crawl_id]['next_url']??'');
  $response=MAD4B_SCP_Unified_Provider_Routing::request_crawl($mode,$endpoint,
   'status',$crawl_id,['headers'=>$headers,'timeout'=>25,
   'limit_response_size'=>1000000],$cursor);
  if(is_wp_error($response))return $response;
  $status=(int)wp_remote_retrieve_response_code($response);
  $json=json_decode((string)wp_remote_retrieve_body($response),true);
  if($status!==200||!is_array($json))
   return new WP_Error('mad4b_firecrawl_crawl_status_failed','Status unavailable',
     ['http_status'=>$status]);
  $pages=is_array($json['data']??null)?$json['data']:[];
  $stored=MAD4B_SCP_Firecrawl_Page_Store::save_pages(
    $crawl_id,(string)$runs[$crawl_id]['source_url'],$pages,$poll_authorized);
  if(is_wp_error($stored))return $stored;
  $runs[$crawl_id]['state']=sanitize_key((string)($json['status']??'unknown'));
  $runs[$crawl_id]['updated_at']=gmdate('c');
  $runs[$crawl_id]['completed']=(int)($json['completed']??0);
  $runs[$crawl_id]['total']=(int)($json['total']??0);
  $runs[$crawl_id]['more_results']=!empty($json['next']);
  $runs[$crawl_id]['next_url']=is_string($json['next']??null)?$json['next']:'';
  $runs[$crawl_id]['page_batches']=(int)($runs[$crawl_id]['page_batches']??0)+1;
  update_option('mad4b_firecrawl_owned_crawl_runs_v1',$runs,false);
  return ['crawl_id'=>$crawl_id,'status'=>$runs[$crawl_id]['state'],
   'completed'=>$runs[$crawl_id]['completed'],'total'=>$runs[$crawl_id]['total'],
   'returned_pages'=>count($pages),
   'stored_pages_this_read'=>$stored['stored_in_batch'],
   'page_store'=>MAD4B_SCP_Firecrawl_Page_Store::summary($crawl_id,$poll_authorized),
   'more_results'=>$runs[$crawl_id]['more_results'],
   'review_only'=>true,'artifacts_persisted'=>false,
   'empty_result_diagnostic'=>($runs[$crawl_id]['state']==='completed' &&
     $runs[$crawl_id]['completed']===0)?
      self::owned_robots_readiness((string)$runs[$crawl_id]['source_url']):null];
 }
 // Per host + operation metrics. Never change WordPress' global HTTP timeout.
 public static function timeout_plan($source,$kind='research'){
  $kind=in_array($kind,['owned_smoke','full_capture','native_tour','research'],true)?$kind:'research';
  $host=strtolower((string)wp_parse_url((string)$source,PHP_URL_HOST));
  $bucket=hash('sha256',$kind.'|'.$host);
  $all=get_option(self::TIMEOUT_METRICS,[]);
  $record=is_array($all)?($all[$bucket]??[]):[];
  $baseline=$kind==='full_capture'?90:($kind==='research'?75:65);
  // EWMA reflects actual successful latency; failure adds a modest bounded margin.
  $observed=(float)($record['ewma_seconds']??0);
  $timeout=$observed>0?max($baseline,(int)ceil($observed*1.5+15)):$baseline;
  if((int)($record['timeouts']??0)>0)
   $timeout+=min(20,5*(int)$record['timeouts']);
  $timeout=max(45,min(115,$timeout)); // Gateway proxy_read_timeout is 150s.
  return ['timeout'=>$timeout,'kind'=>$kind,'bucket'=>$bucket,
   'observed_seconds'=>$observed,'samples'=>(int)($record['samples']??0),
   'timeouts'=>(int)($record['timeouts']??0)];
 }
 private static function scrape_timed($mode,$endpoint,$args,$source,$kind){
  $plan=self::timeout_plan($source,$kind);
  $args['timeout']=$plan['timeout'];
  $start=microtime(true);
  $result=MAD4B_SCP_Unified_Provider_Routing::post_scrape($mode,$endpoint,$args);
  $elapsed=max(0,microtime(true)-$start);
  $ok=!is_wp_error($result) && (int)wp_remote_retrieve_response_code($result)>=200 &&
    (int)wp_remote_retrieve_response_code($result)<300;
  $all=get_option(self::TIMEOUT_METRICS,[]);
  if(!is_array($all))$all=[];
  $old=is_array($all[$plan['bucket']]??null)?$all[$plan['bucket']]:[];
  $was=(float)($old['ewma_seconds']??0);
  $fail=is_wp_error($result);
  $timeout_fail=$fail && ($elapsed>=0.8*$plan['timeout'] ||
    str_contains(strtolower($result->get_error_message()),'timed out'));
  // Never let errors for protected sites inflate success latency metrics.
  $all[$plan['bucket']]=[
   'kind'=>$kind,'host'=>strtolower((string)wp_parse_url((string)$source,PHP_URL_HOST)),
   'ewma_seconds'=>$ok?round($was>0?$was*.65+$elapsed*.35:$elapsed,3):$was,
   'samples'=>min(1000,(int)($old['samples']??0)+($ok?1:0)),
   'timeouts'=>$ok?0:min(4,(int)($old['timeouts']??0)+($timeout_fail?1:0)),
   'last_result'=>$ok?'success':($fail?'network_error':'provider_http_error'),
   'last_http_status'=>$fail?0:(int)wp_remote_retrieve_response_code($result),
   'last_elapsed_seconds'=>round($elapsed,3),
   'updated_at'=>gmdate('c')];
  // Small bounded inventory, not raw page content, credentials or source URLs.
  if(count($all)>120)$all=array_slice($all,-120,null,true);
  update_option(self::TIMEOUT_METRICS,$all,false);
  return $result;
 }

 public static function boot() {
  add_action('admin_post_mad4b_firecrawl_save', array(__CLASS__,'save'));
  add_action('admin_post_mad4b_firecrawl_test', array(__CLASS__,'test_connection'));
  MAD4B_SCP_Firecrawl_Crawl_Integration_V1::boot();
 }
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
  do_action('mad4b_provider_registration_changed','firecrawl');
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
 public static function scrape_owned_source($source_url,$full_capture=false) {
  if(!self::enrolled_write_site() || !current_user_can('manage_options'))
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
  $res=self::scrape_timed($mode,$endpoint,array(
   'timeout'=>35,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>$full_capture?1500000:260000,
   'headers'=>$headers,'body'=>wp_json_encode(array(
     'url'=>$origin.'/','formats'=>$full_capture?array('markdown','html'):array('markdown'),'onlyMainContent'=>true))
  ),$origin.'/',$full_capture?'full_capture':'owned_smoke');
  if(is_wp_error($res))return new WP_Error('mad4b_firecrawl_scrape_network',$res->get_error_message());
  $status=(int)wp_remote_retrieve_response_code($res);
  $payload=json_decode((string)wp_remote_retrieve_body($res),true);
  if($status<200||$status>=300)
   return new WP_Error('mad4b_firecrawl_scrape_http','Provider returned HTTP '.$status,array('http_status'=>$status));
  $markdown=(string)($payload['data']['markdown']??$payload['markdown']??'');
  if(!is_array($payload) || !($payload['success']??false) || trim($markdown)==='')
   return new WP_Error('mad4b_firecrawl_scrape_invalid','Scrape did not return usable markdown.');
  $html=$full_capture?(string)($payload['data']['html']??$payload['html']??''):'';
  if($full_capture && (trim($html)==='' || strlen($html)>MAD4B_Hub_Capture_Parser::HTML_LIMIT ||
    strlen($markdown)>MAD4B_Hub_Capture_Parser::MARKDOWN_LIMIT))
    return new WP_Error('mad4b_firecrawl_capture_full_html_required','Raw HTML + Markdown must be complete and bounded.');
  if(!$full_capture && strlen($markdown)>60000)$markdown=substr($markdown,0,60000);
  return array('source_url'=>$origin.'/','provider'=>'firecrawl','http_status'=>$status,
   'source_sha256'=>hash('sha256',$markdown),'captured_at'=>gmdate('c'),
   'markdown'=>$markdown,'html'=>$html,'length'=>strlen($markdown),
   'html_sha256'=>$html!==''?hash('sha256',$html):'',
   'network_executed'=>true);
 }
 /**
  * Bounded original Firecrawl provider call for a published tour, solely from
  * an exact signed Native Origin intake. Returns source evidence, not authority
  * to write an itinerary, price, point of pickup or boarding berth.
  */
 public static function scrape_native_origin_tour($post_id) {
  if(!self::enrolled_write_site() ||
     !class_exists('MAD4B_Native_Origin_Content_Jobs_V1',false) ||
     !MAD4B_Native_Origin_Content_Jobs_V1::is_admitted())
   return new WP_Error('mad4b_firecrawl_native_origin_grant_required');
  $post_id=absint($post_id);
  $post=get_post($post_id);
  if(!$post || $post->post_status!=='publish' || $post->post_type!=='tours-and-activities')
   return new WP_Error('mad4b_firecrawl_native_tour_invalid');
  $source=get_permalink($post_id);
  $site=wp_parse_url(home_url(),PHP_URL_HOST);
  if(!$source || !wp_http_validate_url($source) ||
     wp_parse_url($source,PHP_URL_HOST)!==$site || wp_parse_url($source,PHP_URL_SCHEME)!=='https')
   return new WP_Error('mad4b_firecrawl_native_source_invalid');
  $c=self::config();
  $mode=(string)($c['mode']??'');
  $profiles=self::profiles();$profile=$profiles[$mode]??array();
  $endpoint=rtrim((string)($profile['endpoint']??''),'/');
  $secret=self::decrypt($profile['secret_cipher']??'');
  if(!$endpoint || !$secret || !wp_http_validate_url($endpoint))
   return new WP_Error('mad4b_firecrawl_native_provider_missing');
  $headers=['Accept'=>'application/json','Content-Type'=>'application/json'];
  if($mode==='self_hosted' && !empty($profile['username']))
   $headers['Authorization']='Basic '.base64_encode($profile['username'].':'.$secret);
  elseif($mode==='cloud' && $endpoint==='https://api.firecrawl.dev')
   $headers['Authorization']='Bearer '.$secret;
  else return new WP_Error('mad4b_firecrawl_native_profile_invalid');
  $res=self::scrape_timed($mode,$endpoint,[
    'timeout'=>35,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>180000,
    'headers'=>$headers,
    'body'=>wp_json_encode(['url'=>$source,'formats'=>['markdown'],'onlyMainContent'=>true])
  ],$source,'native_tour');
  if(is_wp_error($res))return new WP_Error('mad4b_firecrawl_native_network',$res->get_error_message());
  $status=(int)wp_remote_retrieve_response_code($res);
  if($status<200 || $status>=300)
   return new WP_Error('mad4b_firecrawl_native_http_'.$status,'Provider HTTP status '.$status);
  $response=json_decode((string)wp_remote_retrieve_body($res),true);
  $markdown=(string)($response['data']['markdown']??$response['markdown']??'');
  if(!is_array($response) || empty($response['success']) || trim($markdown)==='')
   return new WP_Error('mad4b_firecrawl_native_empty');
  $markdown=mb_substr($markdown,0,20000);
  return ['source_url'=>$source,'provider'=>'firecrawl','source_sha256'=>hash('sha256',$markdown),
    'markdown'=>$markdown,'captured_at'=>gmdate('c'),'http_status'=>$status,
    'source_type'=>'own_published_tour_scraped','independent_supplier_verified'=>false,
    'maps_verified'=>false,'writeback_authorized'=>false];
 }
 /** Provider-owned external research call for an exact original ContentJob source.
  * The provider never decides publication or transport eligibility. */

 /** Owner-enrolled, bounded public-URL research for Source Loop v1.
  * Provider data is evidence only; no post, price or publication authority. */
 public static function scrape_intake_url($source_url) {
  if(!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='staging' ||
     !current_user_can('manage_options') || !class_exists('MAD4B_SCP_Policy') ||
     !MAD4B_SCP_Policy::can_mutate()) return new WP_Error('mad4b_source_loop_cli_admin_required');
  $url=esc_url_raw(trim((string)$source_url));
  if(!wp_http_validate_url($url) || wp_parse_url($url,PHP_URL_SCHEME)!=='https' ||
     strlen($url)>1500 || wp_parse_url($url,PHP_URL_USER) || wp_parse_url($url,PHP_URL_PASS))
     return new WP_Error('mad4b_source_loop_url_rejected');
  $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
  if(!$host || filter_var($host,FILTER_VALIDATE_IP) || str_ends_with($host,'.local') ||
     str_ends_with($host,'.internal'))return new WP_Error('mad4b_source_loop_private_host_rejected');
  $allowed=get_option('mad4b_source_loop_allowed_hosts_v1',[]);
  if(!is_array($allowed)||!in_array($host,$allowed,true))
     return new WP_Error('mad4b_source_loop_host_not_enrolled');
  $config=self::config();$mode=(string)($config['mode']??'');
  $profile=self::profiles()[$mode]??[];
  $endpoint=rtrim((string)($profile['endpoint']??''),'/');
  $secret=self::decrypt($profile['secret_cipher']??'');
  if(!$endpoint||!$secret||!wp_http_validate_url($endpoint))
     return new WP_Error('mad4b_source_loop_provider_not_configured');
  $headers=['Accept'=>'application/json','Content-Type'=>'application/json'];
  if($mode==='self_hosted' && !empty($profile['username']))
     $headers['Authorization']='Basic '.base64_encode($profile['username'].':'.$secret);
  elseif($mode==='cloud' && $endpoint==='https://api.firecrawl.dev')
     $headers['Authorization']='Bearer '.$secret;
  else return new WP_Error('mad4b_source_loop_provider_mode_rejected');
  $args=['timeout'=>35,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>900000,
      'headers'=>$headers,'body'=>wp_json_encode([
      'url'=>$url,'formats'=>['markdown'],'onlyMainContent'=>true])];
  $response=self::scrape_timed($mode,$endpoint,$args,$url,'research');
  if(is_wp_error($response))return new WP_Error('mad4b_source_loop_transport',$response->get_error_message());
  $http=(int)wp_remote_retrieve_response_code($response);
  $json=json_decode((string)wp_remote_retrieve_body($response),true);
  $markdown=trim((string)($json['data']['markdown']??$json['markdown']??''));
  if($http!==200 || !is_array($json) || empty($json['success']) ||
     strlen($markdown)<180 || strlen($markdown)>450000)
     return new WP_Error('mad4b_source_loop_capture_invalid','Source capture was unavailable or incomplete.',
       ['http_status'=>$http,'markdown_bytes'=>strlen($markdown)]);
  if(preg_match('/just a moment|checking your browser|verify you are human|access denied.*cloudflare/i',substr($markdown,0,800)))
     return new WP_Error('mad4b_source_loop_access_challenge');
  return ['source_url'=>$url,'provider'=>'firecrawl','http_status'=>$http,
      'markdown'=>$markdown,'source_sha256'=>hash('sha256',$markdown),
      'captured_at'=>gmdate('c'),'evidence_only'=>true,'network_executed'=>true];
 }

 public static function scrape_research_url($job_id,$draft_post_id,$source_url,$render_dynamic=false){
  if(!class_exists('MAD4B_Firecrawl_Research_Bridge_V1',false))
   return new WP_Error('mad4b_firecrawl_research_adapter_unavailable');
  $v=MAD4B_Firecrawl_Research_Bridge_V1::validate([
   'job_id'=>$job_id,'draft_post_id'=>$draft_post_id,'source_url'=>$source_url]);
  if(is_wp_error($v))return $v;
  $c=self::config();$mode=(string)($c['mode']??'');
  $profile=self::profiles()[$mode]??[];
  $endpoint=rtrim((string)($profile['endpoint']??''),'/');
  $secret=self::decrypt($profile['secret_cipher']??'');
  if(!$secret||!$endpoint||!wp_http_validate_url($endpoint))
   return new WP_Error('mad4b_firecrawl_research_provider_unavailable');
  $headers=['Accept'=>'application/json','Content-Type'=>'application/json'];
  if($mode==='self_hosted' && !empty($profile['username']))
   $headers['Authorization']='Basic '.base64_encode($profile['username'].':'.$secret);
  elseif($mode==='cloud' && $endpoint==='https://api.firecrawl.dev')
   $headers['Authorization']='Bearer '.$secret;
  else return new WP_Error('mad4b_firecrawl_research_profile_invalid');
  $scrape_payload=['url'=>$source_url,'formats'=>['markdown'],'onlyMainContent'=>!$render_dynamic];
  if($render_dynamic){$scrape_payload['waitFor']=3000;$scrape_payload['maxAge']=0;$scrape_payload['timeout']=80000;}
  $res=self::scrape_timed($mode,$endpoint,[
   'timeout'=>35,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>1500000,
   'headers'=>$headers,'body'=>wp_json_encode($scrape_payload)],$source_url,'research');
  if(is_wp_error($res)){
   error_log('MAD4B_FIRECRAWL_PROVIDER_TRANSPORT_RESULT '.wp_json_encode([
    'error_code'=>$res->get_error_code(),'mode'=>$mode,
    'route'=>MAD4B_SCP_Unified_Provider_Routing::status()['selected_transport']??'unknown']));
   return new WP_Error('mad4b_firecrawl_research_transport_error');
  }
  $http=(int)wp_remote_retrieve_response_code($res);
  if($http<200||$http>=300)
   return new WP_Error('mad4b_firecrawl_research_http_'.$http);
  $json=json_decode((string)wp_remote_retrieve_body($res),true);
  if(!is_array($json)||($json['success']??false)!==true)
   return new WP_Error('mad4b_firecrawl_research_provider_failed');
  $markdown=trim((string)($json['data']['markdown']??$json['markdown']??''));
  // Bounded diagnostics only: no raw page content or provider credentials.
  $markdown_limit=MAD4B_Hub_Capture_Parser::MARKDOWN_LIMIT;
  if($markdown===''||strlen($markdown)>$markdown_limit)
   return new WP_Error('mad4b_firecrawl_research_markdown_invalid',
    'Research markdown is empty or exceeds the shared parser artifact limit.',
    ['reason'=>$markdown===''?'empty_markdown':'artifact_size_limit',
     'markdown_bytes'=>strlen($markdown),'limit_bytes'=>$markdown_limit,
     'http_status'=>$http]);
  return ['source_url'=>$source_url,'markdown'=>$markdown,'http_status'=>$http,
   'captured_at'=>gmdate('c'),'provider'=>'firecrawl','evidence_only'=>true];
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
  echo '<tr data-mad4b-firecrawl-mode="self_hosted"><th>Self-hosted HTTPS URL</th><td><input type="url" name="firecrawl_endpoint" class="regular-text" value="'.esc_attr($gateway['endpoint']??'').'" placeholder="https://firecrawl.mad4b.com"></td></tr>';
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
require_once __DIR__.'/class-mad4b-scp-firecrawl-crawl-integration-v1.php';
MAD4B_SCP_Firecrawl_Provider::boot();
