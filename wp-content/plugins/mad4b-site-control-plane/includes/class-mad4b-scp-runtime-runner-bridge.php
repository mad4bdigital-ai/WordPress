<?php
if (!defined('ABSPATH')) exit;
/**
 * Strict read-only transport contract. Never supplies Developer authorization.
 * Site keys are provisioned outside document root by hosting policy.
 */
final class MAD4B_SCP_Runtime_Runner_Bridge {
 const CONTRACT='mad4b.runtime-runner.v1';
 public static function canonical($data) {
  if (is_array($data)) {
   if ($data===array() || array_keys($data)===range(0,count($data)-1)) return '['.implode(',',array_map(array(__CLASS__,'canonical'),$data)).']';
   ksort($data,SORT_STRING);
   $parts=array();
   foreach($data as $key=>$value) $parts[]=json_encode((string)$key,JSON_UNESCAPED_SLASHES).':'.self::canonical($value);
   return '{'.implode(',',$parts).'}';
  }
  return json_encode($data,JSON_UNESCAPED_SLASHES);
 }
 public static function prepare($site,$plan_sha256,$private_pem,$now=null) {
  if (!is_string($site)||!preg_match('/^[a-zA-Z0-9._-]{8,96}$/D',$site)
   || !is_string($plan_sha256)||!preg_match('/^[a-f0-9]{64}$/D',$plan_sha256)
   || !function_exists('openssl_sign'))return null;
  $now=$now??time();
  $request=array(
   'contract'=>self::CONTRACT,'site'=>$site,'operation'=>'runtime.status.read',
   'nonce'=>bin2hex(random_bytes(16)),'issued_at'=>$now,'expires_at'=>$now+60,
   'target'=>'isolated-diagnostic','plan_sha256'=>$plan_sha256
  );
  $signature='';
  if (!openssl_sign(self::canonical($request),$signature,$private_pem,OPENSSL_ALGO_SHA256))return null;
  return array('request'=>$request,'signature'=>base64_encode($signature));
 }
 public static function verify_receipt($payload,$envelope,$worker_public_pem,$now=null) {
  if (!is_array($payload)||!isset($payload['receipt'],$payload['signature'])
   || !is_array($payload['receipt'])||!is_array($envelope)||!isset($envelope['request'])
   || !is_string($payload['signature'])||!is_string($worker_public_pem))return false;
  $receipt=$payload['receipt'];$request=$envelope['request'];
  if (($receipt['contract']??'')!==self::CONTRACT.'.receipt'
   || ($receipt['site']??null)!==($request['site']??null)
   || ($receipt['operation']??null)!==($request['operation']??null)
   || ($receipt['nonce']??null)!==($request['nonce']??null)
   || ($receipt['request_sha256']??null)!==hash('sha256',self::canonical($request))
   || ($receipt['production_authorized']??null)!==false
   || ($receipt['mutation_performed']??null)!==false
   || !is_int($receipt['produced_at']??null))return false;
  $now=$now??time();
  if ($receipt['produced_at']>$now+30 || $receipt['produced_at']<$now-120)return false;
  $sig=base64_decode($payload['signature'],true);
  return is_string($sig) && openssl_verify(self::canonical($receipt),$sig,$worker_public_pem,OPENSSL_ALGO_SHA256)===1;
 }
 public static function dispatch_status($url,$origin,$site,$plan_sha256,$site_private_pem,$worker_public_pem) {
  // This transport MUST NOT be exposed as an unrestricted MCP write ability.
  if (!function_exists('wp_safe_remote_post')||!function_exists('wp_parse_url')
   ||!function_exists('wp_remote_retrieve_response_code')||!function_exists('wp_remote_retrieve_body')
   ||!function_exists('wp_get_environment_type')
   ||!in_array(wp_get_environment_type(),array('staging','development','local'),true))return null;
  $u=wp_parse_url($url);$o=wp_parse_url($origin);
  if (!is_array($u)||!is_array($o)
   ||($u['scheme']??'')!=='https'||($o['scheme']??'')!=='https'
   ||empty($u['host'])||strtolower($u['host'])!==strtolower($o['host'])
   ||isset($u['user'])||isset($u['pass'])||isset($u['port'])||isset($u['fragment'])
   ||isset($o['port']))return null;
  $envelope=self::prepare($site,$plan_sha256,$site_private_pem);
  if (!$envelope)return null;
  $response=wp_safe_remote_post($url,array('timeout'=>10,'redirection'=>0,'limit_response_size'=>4096,
    'headers'=>array('Content-Type'=>'application/json'),'body'=>self::canonical($envelope)));
  if (function_exists('is_wp_error')&&is_wp_error($response))return null;
  if (wp_remote_retrieve_response_code($response)!==200)return null;
  $payload=json_decode((string)wp_remote_retrieve_body($response),true);
  return self::verify_receipt($payload,$envelope,$worker_public_pem)?$payload:null;
 }
}
