<?php
define('ABSPATH',__DIR__.'/');
function add_action($h,$c,$p=10){}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',strtolower(trim((string)$v))));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;public function __construct($c,$m='',$d=null){$this->c=$c;}public function get_error_code(){return $this->c;}}
final class MAD4B_SCP_Data_Governance{
 public static $mode='ok';
 public static function validate_decision_artifact($job,$artifact,$provider){
  if('deny'===self::$mode)return array('artifact_id'=>$artifact,'decision'=>'DENY','decision_fingerprint'=>str_repeat('a',64),'rights_summary_fingerprint'=>str_repeat('b',64),'processor_profile_fingerprint'=>str_repeat('c',64),'policy_revision'=>'7');
  if('drift'===self::$mode)return array('artifact_id'=>$artifact,'decision'=>'ALLOW','decision_fingerprint'=>str_repeat('9',64),'rights_summary_fingerprint'=>str_repeat('b',64),'processor_profile_fingerprint'=>str_repeat('c',64),'policy_revision'=>'7');
  return array('artifact_id'=>$artifact,'decision'=>'ALLOW','decision_fingerprint'=>str_repeat('a',64),'rights_summary_fingerprint'=>str_repeat('b',64),'processor_profile_fingerprint'=>str_repeat('c',64),'policy_revision'=>'7');
 }
}
final class MAD4B_SCP_Provider_Execution_Binding{
 public static $drift=false;
 public static function bind($i=array()){return array('contract'=>'mad4b.provider-execution-binding.v1','provider_id'=>$i['provider_id'],'capability_id'=>$i['capability_id'],'binding_sha256'=>str_repeat('d',64));}
 public static function revalidate($i=array()){return self::$drift?new WP_Error('mad4b_provider_binding_drift','drift'):array('valid'=>true);}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-governed-provider-plan.php';

$fail=static function($m){fwrite(STDERR,"FAIL governed-provider-plan-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$input=array(
 'plan_family'=>'ai_model_generation',
 'job_id'=>'11111111-2222-4333-8444-555555555555',
 'provider_id'=>'model-provider',
 'capability_id'=>'model.generate',
 'data_governance_artifact_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'request_sha256'=>str_repeat('e',64),
 'purpose'=>'generation',
 'release_ring'=>'canary',
);
$plan=MAD4B_SCP_Governed_Provider_Plan::build($input);
$check(is_array($plan),'generic plan failed');
$check('mad4b.governed-provider-plan.v1'===$plan['contract'],'contract mismatch');
$check(true===$plan['execution_ready'],'valid generic plan not ready');
$check(false===$plan['provider_execution_performed'] && false===$plan['authorizing'] && false===$plan['mutation_performed'],'plan widened execution/authority');
$check(str_repeat('a',64)===$plan['data_governance_fingerprint'],'governance fingerprint not bound');
$check(str_repeat('d',64)===$plan['provider_binding_sha256'],'provider binding not bound');
$check(1===preg_match('/^[a-f0-9]{64}$/',$plan['plan_sha256']),'plan digest invalid');

$valid=MAD4B_SCP_Governed_Provider_Plan::revalidate(array('plan'=>$plan));
$check(is_array($valid)&&true===$valid['valid'],'stable plan failed revalidation');

MAD4B_SCP_Data_Governance::$mode='drift';
$drift=MAD4B_SCP_Governed_Provider_Plan::revalidate(array('plan'=>$plan));
$check(is_wp_error($drift)&&'mad4b_governed_provider_governance_drift'===$drift->get_error_code(),'governance drift was not fenced');
MAD4B_SCP_Data_Governance::$mode='ok';
MAD4B_SCP_Provider_Execution_Binding::$drift=true;
$drift=MAD4B_SCP_Governed_Provider_Plan::revalidate(array('plan'=>$plan));
$check(is_wp_error($drift)&&'mad4b_provider_binding_drift'===$drift->get_error_code(),'provider certification drift was not fenced');
MAD4B_SCP_Provider_Execution_Binding::$drift=false;
MAD4B_SCP_Data_Governance::$mode='deny';
$deny=MAD4B_SCP_Governed_Provider_Plan::build($input);
$check(is_wp_error($deny)&&'mad4b_governed_provider_decision_not_allow'===$deny->get_error_code(),'DENY governance decision did not fail closed');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-governed-provider-plan.php');
foreach(array('wp_remote_get(','wp_remote_post(','curl_exec(','shell_exec(','proc_open(','wp_insert_post(') as $forbidden){
 $check(false===strpos($source,$forbidden),'generic provider plan contains execution primitive: '.$forbidden);
}
echo "mad4b.governed-provider-plan.v1: PASS\n";
