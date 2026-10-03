<?php
define('ABSPATH',__DIR__);
class WP_Error{private $c;public function __construct($c,$m='',$d=null){$this->c=(string)$c;}public function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function wp_generate_uuid4(){return '11111111-1111-4111-8111-'.substr(hash('sha256',microtime(true).mt_rand()),0,12);}
$GLOBALS['obs_metric_fail']=false;
$GLOBALS['obs_records']=array();
class MAD4B_SCP_Runtime_Metrics{
 const MAX_NAMES=50;
 public static function record($n,$v=1){if(!empty($GLOBALS['obs_metric_fail']))return new WP_Error('ci_metric_backend_down');$GLOBALS['obs_records'][]=array($n,$v);return true;}
 public static function summary(array $names=array(),$hours=24){
  $metrics=array();
  foreach($names as $n){
   $count=0;
   if(false!==strpos($n,'.count'))$count=100;
   elseif(false!==strpos($n,'.error'))$count=2;
   elseif(preg_match('/\.le([0-9]{6})$/',$n,$m)){$b=(int)$m[1];$count=$b>=100?100:($b>=50?96:50);}
   $metrics[]=array('name'=>$n,'count'=>$count,'sum'=>$count,'min'=>0,'max'=>0,'avg'=>0);
  }
  return array('metrics'=>$metrics);
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-observability.php';

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL observability-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};

MAD4B_SCP_Observability::reset_request();
$root=MAD4B_SCP_Observability::begin_trace('site-a');
$check(is_array($root)&&32===strlen($root['trace_id'])&&16===strlen($root['span_id'])&&!empty($root['traceparent'])&&empty($root['authorizing']),'Root trace invalid.',$root);
$parsed=MAD4B_SCP_Observability::parse_traceparent($root['traceparent']);
$check(!is_wp_error($parsed)&&$root['trace_id']===$parsed['trace_id'],'W3C traceparent round-trip failed.',$parsed);
$scoped=array();
$scoped_result=MAD4B_SCP_Observability::run_stage('preparation',static function() use (&$scoped){
 $scoped['outer']=MAD4B_SCP_Observability::current();
 $headers=MAD4B_SCP_Observability::propagation_headers($scoped['outer'],'site-a');
 $scoped['headers']=$headers;
 return MAD4B_SCP_Observability::run_stage('authorization',static function() use (&$scoped){
  $scoped['inner']=MAD4B_SCP_Observability::current();
  return 'nested-ok';
 },'site-a');
},'site-a');
$check('nested-ok'===$scoped_result,'Nested observed stage changed callback result.',$scoped_result);
$check(
 is_array($scoped['outer'])&&is_array($scoped['inner'])
 && $root['trace_id']===$scoped['outer']['trace_id']
 && $scoped['outer']['trace_id']===$scoped['inner']['trace_id']
 && $scoped['outer']['span_id']===$scoped['inner']['parent_span_id'],
 'Nested stage did not inherit the exact parent span.',
 $scoped
);
$check(is_array($scoped['headers'])&&$scoped['outer']['traceparent']===$scoped['headers']['traceparent'],'Scoped stage traceparent was not exportable for same-tenant provider propagation.',$scoped['headers']);
$after_scoped=MAD4B_SCP_Observability::current();
$check($root['span_id']===$after_scoped['span_id'],'Observed stage did not restore the request root context.',$after_scoped);
$invalid=MAD4B_SCP_Observability::parse_traceparent('00-'.str_repeat('0',32).'-'.str_repeat('0',16).'-01');
$check(is_wp_error($invalid),'All-zero traceparent was accepted.',$invalid);

$child=MAD4B_SCP_Observability::child('authorization','site-a',array('site_uuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','authorization'=>'Bearer secret','ability'=>'demo/read'));
$check(!is_wp_error($child)&&$root['trace_id']===$child['trace_id']&&$root['span_id']===$child['parent_span_id'],'Same-tenant child lost causal trace.',$child);
$inherited=MAD4B_SCP_Observability::child('approval','',array('phase'=>'inheritance-test'));
$check(!is_wp_error($inherited)&&$root['trace_id']===$inherited['trace_id']&&$root['tenant_scope_sha256']===$inherited['tenant_scope_sha256'],'Omitted tenant scope forked away from the current tenant.',$inherited);
$check(isset($child['attributes']['site_uuid_sha256'])&&'[REDACTED]'===$child['attributes']['authorization'],'Trace attributes leaked sensitive/tenant values.',$child['attributes']);

$fork=MAD4B_SCP_Observability::fork_for_tenant($child,'site-b');
$check(!is_wp_error($fork)&&$fork['trace_id']!==$child['trace_id']&&!empty($fork['causal_link_sha256'])&&empty($fork['cross_tenant_trace_id_reused']),'Cross-tenant fan-out reused the trace id or lost causal link.',$fork);
$current_after_fork=MAD4B_SCP_Observability::current();
$check($root['trace_id']===$current_after_fork['trace_id']&&$root['tenant_scope_sha256']===$current_after_fork['tenant_scope_sha256'],'Cross-tenant fork contaminated the request-global origin trace.',$current_after_fork);
$denied=MAD4B_SCP_Observability::propagation_headers($child,'site-b');
$check(is_wp_error($denied)&&'mad4b_observability_cross_tenant_propagation_denied'===$denied->get_error_code(),'Cross-tenant trace header export was admitted.',$denied);

$redacted=MAD4B_SCP_Observability::redact(array('nested'=>array('client_secret'=>'super-secret','safe'=>'ok'),'raw_payload'=>array('x'=>'y'),'long'=>str_repeat('x',700)));
$check('[REDACTED]'===$redacted['nested']['client_secret']&&'[REDACTED]'===$redacted['raw_payload']&&strlen($redacted['long'])<560,'Recursive redaction/bounds failed.',$redacted);

$GLOBALS['obs_metric_fail']=true;
$callback_result=MAD4B_SCP_Observability::run_stage('discovery',static function(){return array('safe_read'=>'unchanged');},'',array('safe'=>'yes'));
$check(is_array($callback_result)&&'unchanged'===$callback_result['safe_read'],'Telemetry outage changed an observed callback result.',$callback_result);
$optional=MAD4B_SCP_Observability::record_stage('discovery',17,true,array('safe'=>'yes'));
$check(is_array($optional)&&empty($optional['recorded'])&&empty($optional['safe_read_blocked'])&&'ci_metric_backend_down'===$optional['telemetry_error_code'],'Optional telemetry outage became blocking.',$optional);
$GLOBALS['obs_metric_fail']=false;
$policy_record=MAD4B_SCP_Observability::record_stage('policy',21,true,array('capability_sha256'=>hash('sha256','demo/read')));
$check(!empty($policy_record['recorded'])&&'policy'===$policy_record['stage'],'Policy stage metric is not registered.',$policy_record);
$recorded=MAD4B_SCP_Observability::record_stage('provider_execution',87,false,array('provider_id'=>'provider-a','token'=>'secret'));
$check(!empty($recorded['recorded'])&&'[REDACTED]'===$recorded['attributes']['token'],'Stage metric did not record/redact.',$recorded);

$q=MAD4B_SCP_Observability::quantiles(array(10=>50,50=>96,100=>100),100);
$check(10===$q['p50_ms']&&50===$q['p95_ms']&&100===$q['p99_ms'],'Percentile derivation failed.',$q);
$slo=MAD4B_SCP_Observability::slo_status(24);
$check(!is_wp_error($slo)&&!empty($slo['read_only'])&&empty($slo['telemetry_grants_authority'])&&isset($slo['stages']['discovery']['p50_ms'],$slo['stages']['provider_execution']['burn_rate']),'SLO/operator evidence is incomplete.',$slo);
$check(isset($slo['stages']['provider_execution']['burn_state'],$slo['stages']['provider_execution']['burn_warning_multiplier'],$slo['stages']['provider_execution']['burn_critical_multiplier'])&&empty($slo['stages']['provider_execution']['operator_action_authorizing']),'Burn-rate thresholds are not bound to non-authorizing operator evidence.',$slo['stages']['provider_execution']);
$budget=MAD4B_SCP_Observability::error_budget_status();
$check(!is_wp_error($budget)&&!empty($budget['operator_evidence_only'])&&empty($budget['telemetry_grants_authority'])&&isset($budget['stages']['discovery']['short_burn_rate'],$budget['stages']['discovery']['long_burn_rate'],$budget['stages']['discovery']['combined_burn_state']),'Short/long error-budget evidence is incomplete or authorizing.',$budget);
$sem=MAD4B_SCP_Observability::failure_semantics();
$check(empty($sem['optional_telemetry_failure_blocks_safe_reads'])&&!empty($sem['mandatory_audit_or_execution_evidence_failure_blocks_governed_write']),'Observability failure semantics drifted.',$sem);

echo "mad4b.observability.runtime.v1: PASS\n";
