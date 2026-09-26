<?php

define('ABSPATH',__DIR__.'/');
function add_action($h,$c,$p=10){}
function wp_register_ability($n,$a){}
function wp_has_ability($n){return false;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function sanitize_text_field($v){return trim((string)$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{
 private $code;private $message;
 public function __construct($c,$m=''){ $this->code=$c;$this->message=$m; }
 public function get_error_code(){return $this->code;}
}

$GLOBALS['research_governance_mode']='ok';
final class MAD4B_SCP_Data_Governance {
 public static function validate_decision_artifact($job,$artifact,$provider,$allowed=array('ALLOW','REDACT_THEN_ALLOW')){
  $mode=$GLOBALS['research_governance_mode'];
  if('provider_mismatch'===$mode) return new WP_Error('mad4b_data_governance_provider_mismatch','provider mismatch');
  if('denied'===$mode) return new WP_Error('mad4b_data_governance_decision_denied','denied');
  if('stale'===$mode) return new WP_Error('mad4b_data_governance_artifact_stale','stale');
  return array(
   'artifact_id'=>$artifact,
   'decision'=>'ALLOW',
   'decision_fingerprint'=>str_repeat('a',64),
   'provider_id'=>$provider,
   'policy_revision'=>'7',
   'rights_summary_fingerprint'=>str_repeat('b',64),
   'processor_profile_fingerprint'=>str_repeat('c',64),
   'authorizing'=>false,
  );
 }
}

require dirname(__DIR__).'/includes/class-mad4b-scp-research-intelligence.php';

$fail=static function($m){fwrite(STDERR,"FAIL research-provider-plan-contract: $m\n");exit(1);};
$check=static function($c,$m) use($fail){if(!$c)$fail($m);};
$input=array(
 'job_id'=>'11111111-2222-4333-8444-555555555555',
 'provider_id'=>'research_vendor',
 'request_sha256'=>str_repeat('f',64),
 'data_governance_artifact_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
 'purpose'=>'research',
);
$plan=MAD4B_SCP_Research_Intelligence::provider_plan($input);
$check(is_array($plan),'valid governance-bound research plan failed');
$check('mad4b.research-provider-plan.v1'===$plan['contract'],'plan contract mismatch');
$check(true===$plan['execution_ready'],'valid plan not execution ready');
$check(str_repeat('a',64)===$plan['data_governance_fingerprint'],'decision fingerprint not bound');
$check(str_repeat('b',64)===$plan['rights_summary_fingerprint'],'rights fingerprint not bound');
$check(str_repeat('c',64)===$plan['processor_profile_fingerprint'],'processor fingerprint not bound');
$check(false===$plan['provider_execution_performed'] && false===$plan['authorizing'],'plan executed/authorized provider');
$check(1===preg_match('/^[a-f0-9]{64}$/',$plan['plan_sha256']),'plan digest invalid');

$GLOBALS['research_governance_mode']='provider_mismatch';
$r=MAD4B_SCP_Research_Intelligence::provider_plan($input);
$check(is_wp_error($r) && 'mad4b_data_governance_provider_mismatch'===$r->get_error_code(),'provider mismatch not denied');

$GLOBALS['research_governance_mode']='denied';
$r=MAD4B_SCP_Research_Intelligence::provider_plan($input);
$check(is_wp_error($r) && 'mad4b_data_governance_decision_denied'===$r->get_error_code(),'denied governance decision not denied');

$GLOBALS['research_governance_mode']='stale';
$r=MAD4B_SCP_Research_Intelligence::provider_plan($input);
$check(is_wp_error($r) && 'mad4b_data_governance_artifact_stale'===$r->get_error_code(),'stale governance evidence not denied');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-research-intelligence.php');
foreach(array('wp_remote_get(','wp_remote_post(','curl_exec(','shell_exec(','proc_open(') as $forbidden){
 $check(false===strpos($source,$forbidden),'research provider plan gained provider/network primitive: '.$forbidden);
}
echo "mad4b.research-provider-plan.v1: PASS\n";
