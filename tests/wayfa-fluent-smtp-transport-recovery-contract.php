<?php
/** In-memory native-provider regression; no network or live WordPress writes. */
define('ABSPATH',__DIR__.'/');define('FLUENT_MAIL_DB_PREFIX','fsmpt_');define('ARRAY_A','ARRAY_A');
// WordPress 5.9+ ships polyfills for these PHP 8 text helpers.
if (!function_exists('str_contains')) {
    function str_contains($h, $n) { return $n === '' || strpos((string)$h,(string)$n) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return $n === '' || strpos((string)$h,(string)$n) === 0; }
}
$now=['latest_failed'=>'2026-11-10 07:01:59','latest_sent'=>'2026-11-16 02:25:42',
 'healthy'=>true,'successes_after'=>8];$GLOBALS['mail_fixture']=$now;
$GLOBALS['wpdb']=new class {
 public string $prefix='wp_';public string $last_error='';
 function prepare($sql,...$args){return str_replace('%s',$args[0]??'',$sql);}
 function get_var($query){
  if(str_contains($query,'SHOW TABLES LIKE'))return 'wp_fsmpt_email_logs';
  if(str_contains($query,"status='failed'"))return 11;
  if(str_contains($query,"status='sent'"))return $GLOBALS['mail_fixture']['successes_after'];
  return 0;
 }
 function get_results($query,$format){return [
  ['status'=>'failed','n'=>11,'latest'=>$GLOBALS['mail_fixture']['latest_failed']],
  ['status'=>'sent','n'=>10,'latest'=>$GLOBALS['mail_fixture']['latest_sent']]
 ];}
};
function add_filter(...$args){}
function get_option($key,$default=null){
 if($key==='active_plugins')return ['fluent-smtp/fluent-smtp.php'];
 if($key==='fluentmail-settings')return ['connections'=>[]];
 if($key==='_fluentsmtp_connection_health')return [['status'=>$GLOBALS['mail_fixture']['healthy']?'healthy':'failed','checked_at'=>'2026-11-15 04:35:09']];
 return $default;
}
require __DIR__.'/../ops/site-company-overlays/wayfatours/configuration-runtime/wp-content/mu-plugins/mad4b-configuration-jobs/providers.php';
$cl=MAD4B_Configuration_Provider_Adapters::class;$passes=0;
function check($state,$name){global $passes;if(!$state)throw new RuntimeException($name);$passes++;}
$p=$cl::smtp_recovery_proof();check($p['recovered']===true,'healthy transport proof');
check(count($cl::fluentsmtp())===0,'old failed records not active fault after recovery');
check($p['does_not_prove_inbox_delivery']===true,'accepted not inbox-delivered');
$GLOBALS['mail_fixture']['healthy']=false;
check($cl::smtp_recovery_proof()['recovered']===false,'unhealthy transport not marked recovered');
check(count($cl::fluentsmtp())===1,'keep incident if unhealthy');
$GLOBALS['mail_fixture']['healthy']=true;$GLOBALS['mail_fixture']['successes_after']=1;
check($cl::smtp_recovery_proof()['recovered']===false,'one accepted log insufficient');
$GLOBALS['mail_fixture']['successes_after']=8;$GLOBALS['mail_fixture']['latest_sent']='2026-11-09 02:25:42';
check($cl::smtp_recovery_proof()['recovered']===false,'old sent events not sufficient');
check(count($cl::fluentsmtp())===1,'keep incident when last sent before failure');
echo json_encode(['contract'=>'mad4b.fluent-smtp-transport-recovery-regression.v1','passed'=>$passes])."\n";
