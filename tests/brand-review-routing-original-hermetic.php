<?php
/** Native dynamic Brand review route inheritance; no approvals, worker or writes. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($code,$m='',$data=[]){}}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function review_check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
class MAD4B_SCP_Site_Profile {
 static function status(){return ['site_uuid'=>'11111111-1111-4111-8111-111111111111'];}
}
class MAD4B_SCP_Context_Authority {
 static $policy=['mode'=>'ai_only','ready'=>true,'exact_grant_ready'=>true,'catalog_eligible'=>true];
 static function source($id){return ['site_uuid'=>'11111111-1111-4111-8111-111111111111','mode'=>'governed'];}
 static function ai_review_policy_status(){return self::$policy;}
 static function assets(){return [];}
}
class MAD4B_SCP_AI_Review_Worker {
 static $preflight=['ready'=>true,'blockers'=>[]];
 static function preflight(){return self::$preflight;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-brand-review-routing.php';
$c='MAD4B_SCP_Brand_Review_Routing_V1';
$id=str_repeat('a',64);
$r=$c::resolve($id);
review_check($r['ai_review_eligible']===true &&$r['publication_authorized']===false
 &&$r['agent_grants_inherited']===false,
 'Verified AI policy inheritance supports review without inheriting writing/approval grants');
foreach(['ready','exact_grant_ready','catalog_eligible'] as $key){
 foreach([new WP_Error('policy_unknown'),1,'true',false,null] as $bad){
  MAD4B_SCP_Context_Authority::$policy=[
   'mode'=>'ai_only','ready'=>true,'exact_grant_ready'=>true,'catalog_eligible'=>true];
  MAD4B_SCP_Context_Authority::$policy[$key]=$bad;
  $r=$c::resolve($id);
  review_check($r['ai_review_eligible']===false
   &&$r['publication_authorized']===false
   &&$r['agent_grants_inherited']===false,
   'Unverified site/source AI policy flag must not certify review authority');
 }
}
MAD4B_SCP_Context_Authority::$policy=['mode'=>'ai_only','ready'=>true,
 'exact_grant_ready'=>true,'catalog_eligible'=>true];
foreach([new WP_Error('worker_unknown'),1,'true',false,null] as $bad){
 MAD4B_SCP_AI_Review_Worker::$preflight=['ready'=>$bad,'blockers'=>[]];
 $r=$c::resolve($id);
 review_check($r['local_ai_worker_ready']===false
  &&$r['publication_authorized']===false,
  'Unverified local AI Worker preflight cannot appear ready');
}
MAD4B_SCP_AI_Review_Worker::$preflight=['ready'=>true,'blockers'=>[]];
review_check($c::resolve($id)['local_ai_worker_ready']===true,
 'Exactly verified original AI worker readback remains observable');
echo 'PASS: BRAND_REVIEW_ROUTING_ORIGINAL '.($GLOBALS['n']??0).' source/AI policy and no-approval routing cases'.PHP_EOL;
