<?php
/** Original research ID and external data governance admission; no DB or publishing. */
define('ABSPATH',__DIR__.'/');
class WP_Error {private $code;function __construct($code,$msg='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($name,$callback,$priority=10){}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function research_check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
class MAD4B_SCP_Data_Governance {
 static $called=0,$result;
 static function validate_decision_artifact($job,$artifact,$provider){self::$called++;return self::$result;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-research-intelligence.php';
$c='MAD4B_SCP_Research_Intelligence';
foreach([new WP_Error('unverified'),[],true,null,1,'invalid'] as $bad){
 MAD4B_SCP_Data_Governance::$called=0;
 $r=$c::provider_plan(['job_id'=>$bad,'provider_id'=>'supplier','request_sha256'=>str_repeat('a',64)]);
 research_check(is_wp_error($r)&&MAD4B_SCP_Data_Governance::$called===0,
  'Malformed original job identity must not enter a governance read or execution plan');
}
$job='11111111-1111-4111-8111-111111111111';
foreach([new WP_Error('provider_unknown'),[],true,null] as $bad){
 MAD4B_SCP_Data_Governance::$called=0;
 $r=$c::provider_plan(['job_id'=>$job,'provider_id'=>$bad,'request_sha256'=>str_repeat('a',64)]);
 research_check(is_wp_error($r)&&MAD4B_SCP_Data_Governance::$called===0,
  'Malformed provider identity must stop before external governance');
}
foreach([new WP_Error('sha_unknown'),[],true,null] as $bad){
 MAD4B_SCP_Data_Governance::$called=0;
 $r=$c::provider_plan(['job_id'=>$job,'provider_id'=>'supplier','request_sha256'=>$bad]);
 research_check(is_wp_error($r)&&MAD4B_SCP_Data_Governance::$called===0,
  'Malformed request digest cannot enter source governance');
}
MAD4B_SCP_Data_Governance::$result=new WP_Error('governance_rejected');
$r=$c::provider_plan(['job_id'=>$job,'provider_id'=>'supplier','request_sha256'=>str_repeat('a',64)]);
research_check(is_wp_error($r)&&$r->get_error_code()==='governance_rejected'
 &&MAD4B_SCP_Data_Governance::$called===1,
 'Original data governance rejection is not reinterpreted as plan readiness');
$ids=new ReflectionMethod($c,'artifact_ids');
if(PHP_VERSION_ID<80100)$ids->setAccessible(true);
foreach([new WP_Error('id_unknown'),[],true] as $bad){
 $r=$ids->invoke(null,[$bad],5);
 research_check(is_wp_error($r),'Malformed source artifact ID must fail before source link');
}
echo 'PASS: RESEARCH_INTELLIGENCE_ORIGINAL '.($GLOBALS['checks']??0).
 ' native job, provider, digest, governance and source-reference no-write checks'.PHP_EOL;
