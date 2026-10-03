<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Deny-only transport hardening for explicitly marked governed outbound requests. */
final class MAD4B_SCP_Egress_Policy {
 const CONTRACT='mad4b.egress-policy.v1'; const CONFIG_FILE='config/egress-policy.json';
 private static $config=null,$booted=false,$dns_pins=array(),$test_resolver=null;
 public static function boot(){if(self::$booted)return;self::$booted=true;add_filter('pre_http_request',array(__CLASS__,'enforce'),PHP_INT_MAX,3);}
 public static function clear_cache(){self::$config=null;self::$dns_pins=array();}
 public static function set_test_resolver($resolver){
  if(!defined('MAD4B_SCP_TEST_MODE')||true!==constant('MAD4B_SCP_TEST_MODE'))return new WP_Error('mad4b_egress_test_hook_denied','Egress resolver injection is test-only.');
  if(null!==$resolver&&!is_callable($resolver))return new WP_Error('mad4b_egress_test_resolver_invalid','Test resolver must be callable.');
  self::$test_resolver=$resolver;self::$dns_pins=array();return true;
 }
 public static function mark_request($purpose,$url,$authority,array $args){
  $purpose=sanitize_key((string)$purpose);$v=self::validate_static($purpose,$url,$authority,$args);if(is_wp_error($v))return$v;
  $args['sslverify']=true;$args['redirection']=(int)$v['max_redirects'];$args['reject_unsafe_urls']=true;
  $args['mad4b_egress_purpose']=$purpose;$args['mad4b_egress_authority']=(string)$authority;return$args;
 }
 public static function enforce($preempt,$args,$url){
  if(false!==$preempt&&null!==$preempt)return$preempt;
  if(!is_array($args)||empty($args['mad4b_egress_purpose']))return$preempt;
  $purpose=sanitize_key((string)$args['mad4b_egress_purpose']);$authority=isset($args['mad4b_egress_authority'])?(string)$args['mad4b_egress_authority']:'';
  $v=self::validate_static($purpose,$url,$authority,$args);if(is_wp_error($v))return$v;
  $proxy=self::configured_proxy_host();if(''!==$proxy&&!self::proxy_host_allowed($proxy,self::trusted_proxy_hosts()))return new WP_Error('mad4b_egress_proxy_untrusted','Configured proxy is not explicitly trusted.',array('purpose'=>$purpose,'authorizing'=>false));
  $p=self::parse_url($url);$host=isset($p['host'])?strtolower(rtrim((string)$p['host'],'.')):'';
  $ips=self::resolve_public_addresses($host);if(is_wp_error($ips))return$ips;
  $digest=hash('sha256',implode("\n",$ips));
  if(isset(self::$dns_pins[$host])&&!hash_equals(self::$dns_pins[$host],$digest))return new WP_Error('mad4b_egress_dns_answer_changed','DNS answer set changed within the bound request scope.',array('host_sha256'=>hash('sha256',$host),'authorizing'=>false));
  self::$dns_pins[$host]=$digest;return$preempt;
 }
 public static function classify_transport_error($error,$purpose=''){
  if(!is_wp_error($error))return$error;$code=method_exists($error,'get_error_code')?(string)$error->get_error_code():'';$msg=method_exists($error,'get_error_message')?(string)$error->get_error_message():'';
  if(1===preg_match('/(?:ssl|tls|certificate|hostname|peer verification)/i',$code.' '.$msg))return new WP_Error('mad4b_egress_tls_verification_failed','TLS/certificate/hostname verification failed; insecure fallback is forbidden.',array('purpose'=>sanitize_key((string)$purpose),'upstream_error_code'=>sanitize_key($code),'authorizing'=>false));
  return$error;
 }
 public static function proxy_host_allowed($proxy,array $trusted){$proxy=strtolower(rtrim(trim((string)$proxy),'.'));if(''===$proxy)return true;$out=array();foreach($trusted as$h){$h=strtolower(rtrim(trim((string)$h),'.'));if(''!==$h)$out[]=$h;}return in_array($proxy,array_values(array_unique($out)),true);}
 public static function status(){$c=self::config();return array('contract'=>self::CONTRACT,'ready'=>!is_wp_error($c),'blocker'=>is_wp_error($c)?$c->get_error_code():'','tls_verification_required'=>true,'redirect_revalidation_mode'=>'zero_redirect_exact_endpoint','proxy_trust'=>'explicit_allowlist_only','dns_change_policy'=>'deny_within_request_scope','private_or_reserved_dns_answers_allowed'=>false,'tls_downgrade_allowed'=>false,'authorizing'=>false);}
 private static function validate_static($purpose,$url,$authority,array$args){
  $c=self::config();if(is_wp_error($c))return$c;if(empty($c['purposes'][$purpose])||!is_array($c['purposes'][$purpose]))return new WP_Error('mad4b_egress_purpose_uncertified','Outbound request purpose is not certified.');
  $policy=$c['purposes'][$purpose];$p=self::parse_url($url);if(!is_array($p)||empty($p['scheme'])||empty($p['host']))return new WP_Error('mad4b_egress_url_invalid','Governed outbound URL is invalid.');
  if(!empty($policy['require_https'])&&'https'!==strtolower((string)$p['scheme']))return new WP_Error('mad4b_egress_https_required','Governed outbound transport requires HTTPS.');
  if(isset($p['user'])||isset($p['pass'])||isset($p['fragment']))return new WP_Error('mad4b_egress_url_credentials_or_fragment_denied','Outbound URL cannot contain userinfo or fragments.');
  if(array_key_exists('sslverify',$args)&&false===$args['sslverify'])return new WP_Error('mad4b_egress_tls_verification_required','TLS verification cannot be disabled.');
  $max=isset($policy['max_redirects'])?max(0,(int)$policy['max_redirects']):0;if(isset($args['redirection'])&&(int)$args['redirection']>$max)return new WP_Error('mad4b_egress_redirect_budget_exceeded','Redirect budget exceeds the certified purpose.');
  if(''!==trim((string)$authority)){$a=self::parse_url($authority);if(!is_array($a)||empty($a['scheme'])||empty($a['host']))return new WP_Error('mad4b_egress_authority_invalid','Outbound authority binding is invalid.');$s=strtolower((string)$p['scheme']);$as=strtolower((string)$a['scheme']);$port=isset($p['port'])?(int)$p['port']:('https'===$s?443:80);$aport=isset($a['port'])?(int)$a['port']:('https'===$as?443:80);if(!hash_equals($as,$s)||strtolower((string)$p['host'])!==strtolower((string)$a['host'])||$port!==$aport)return new WP_Error('mad4b_egress_authority_origin_drift','Outbound endpoint drifted from its bound authority origin.');}
  return array('max_redirects'=>$max,'authorizing'=>false);
 }
 private static function resolve_public_addresses($host){
  $host=trim((string)$host);if(''===$host)return new WP_Error('mad4b_egress_dns_host_invalid','Outbound host is unavailable for DNS admission.');$ips=array();
  if(filter_var($host,FILTER_VALIDATE_IP))$ips[]=$host;elseif(null!==self::$test_resolver)$ips=(array)call_user_func(self::$test_resolver,$host);else{
   if(function_exists('dns_get_record')){$rows=@dns_get_record($host,DNS_A|DNS_AAAA);foreach(is_array($rows)?$rows:array()as$row){if(!empty($row['ip']))$ips[]=(string)$row['ip'];if(!empty($row['ipv6']))$ips[]=(string)$row['ipv6'];}}
   if(empty($ips)&&function_exists('gethostbynamel')){$rows=@gethostbynamel($host);if(is_array($rows))$ips=array_merge($ips,$rows);}
  }
  $ips=array_values(array_unique(array_filter(array_map('strval',$ips))));sort($ips,SORT_STRING);if(empty($ips))return new WP_Error('mad4b_egress_dns_unavailable','Governed outbound host has no validated DNS answer.');
  foreach($ips as$ip)if(false===filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return new WP_Error('mad4b_egress_dns_private_or_reserved','Governed outbound DNS resolved to a private or reserved address.',array('host_sha256'=>hash('sha256',$host),'authorizing'=>false));return$ips;
 }
 private static function configured_proxy_host(){return defined('WP_PROXY_HOST')?strtolower(trim((string)constant('WP_PROXY_HOST'))):'';}
 private static function trusted_proxy_hosts(){if(!defined('MAD4B_SCP_EGRESS_TRUSTED_PROXY_HOSTS'))return array();$v=constant('MAD4B_SCP_EGRESS_TRUSTED_PROXY_HOSTS');if(is_array($v))return$v;$r=preg_split('/[\s,]+/',(string)$v,-1,PREG_SPLIT_NO_EMPTY);return is_array($r)?$r:array();}
 private static function parse_url($url){return function_exists('wp_parse_url')?wp_parse_url((string)$url):parse_url((string)$url);}
 private static function config(){if(null!==self::$config)return self::$config;$root=defined('MAD4B_SCP_DIR')?MAD4B_SCP_DIR:dirname(__DIR__).'/';$path=$root.self::CONFIG_FILE;if(!is_readable($path))return self::$config=new WP_Error('mad4b_egress_policy_missing','Egress policy configuration is unavailable.');$d=json_decode((string)file_get_contents($path),true);if(!is_array($d)||self::CONTRACT!==(isset($d['contract'])?(string)$d['contract']:'')||empty($d['purposes'])||!is_array($d['purposes']))return self::$config=new WP_Error('mad4b_egress_policy_invalid','Egress policy configuration is invalid.');foreach(array('oauth_discovery','oauth_jwks')as$p)if(empty($d['purposes'][$p])||!is_array($d['purposes'][$p])||empty($d['purposes'][$p]['require_https'])||empty($d['purposes'][$p]['sslverify_required'])||(int)$d['purposes'][$p]['max_redirects']!==0)return self::$config=new WP_Error('mad4b_egress_policy_invalid','Egress purpose policy is incomplete.');return self::$config=$d;}
}
