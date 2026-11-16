<?php
/**
 * Execute original enrolled Site-Profile WP-CLI identity, bounded local
 * inventory and scheduled Readiness PLAN. No shell/SQL/Host execution.
 * Original WP-CLI APIs are replaced with a synthetic command recorder.
 */
define('ABSPATH',__DIR__.'/');
define('WP_CLI',true);
define('WP_ENVIRONMENT_TYPE','production');
define('DB_NAME','demo_site_database');
$GLOBALS['cli_env']='production';
$GLOBALS['cli_current_user']=0;
$GLOBALS['cli_origin']='https://company.example.invalid';
$GLOBALS['cli_uuid']='00000000-0000-4000-8000-111111111111';
$GLOBALS['cli_enrolled']=true;
$GLOBALS['cli_policy_read']=true;
$GLOBALS['cli_schedule_enabled']=true;
$GLOBALS['cli_audit_ready']=true;
$GLOBALS['cli_commands']=array();
$GLOBALS['cli_inventory_output']='[{"name":"demo-plugin","status":"active","version":"1.0.2"}]';
$GLOBALS['cli_pipeline_safe']=true;
$GLOBALS['cli_mutations']=0;
$GLOBALS['cli_scans']=0;
$GLOBALS['cli_scan_audits']=array();
$GLOBALS['cli_fail_intent_audit']=false;
class WP_Error {
 private $code;public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
class WP_CLI {
 public static $registered=array();
 public static $lines=array();
 public static function add_command($name,$callback){self::$registered[$name]=$callback;}
 public static function runcommand($command,$options){
   $GLOBALS['cli_commands'][]=array('command'=>$command,'options'=>$options);
   if($command!=='plugin list --format=json --fields=name,status,version'
    && $command!=='theme list --format=json --fields=name,status,version')
     throw new \RuntimeException('Unknown operation attempted: '.$command);
   return $GLOBALS['cli_inventory_output'];
 }
 public static function line($line){self::$lines[]=$line;}
 public static function error($reason){throw new \RuntimeException('WP_CLI_ERROR: '.$reason);}
}
function get_current_user_id(){return $GLOBALS['cli_current_user'];}
function get_current_blog_id(){return 1;}
function wp_get_environment_type(){return $GLOBALS['cli_env'];}
function home_url($path=''){return $GLOBALS['cli_origin'].($path==='/'?'/':'');}
function untrailingslashit($value){return rtrim($value,'/');}
function absint($v){return abs((int)$v);}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function get_option($key,$default=array()){return $default;}
function update_option($key,$value,$autoload=false){
 ++$GLOBALS['cli_mutations'];throw new \RuntimeException('No data mutation by CLI readback');
}
class MAD4B_SCP_Site_Profile {
 public static function site_uuid(){return $GLOBALS['cli_uuid'];}
 public static function site_origin(){return $GLOBALS['cli_origin'];}
 public static function current_environment(){return $GLOBALS['cli_env'];}
 public static function origin_enrolled(){return $GLOBALS['cli_enrolled'];}
 public static function status(){
  return array(
   'configured'=>true,'authority_ready'=>$GLOBALS['cli_enrolled'],
   'same_origin_clone_protection'=>true,
   'deployment_binding_match'=>true,
   'origin_match'=>true,'mutation_pending_audit'=>false,
   'configured_environment'=>$GLOBALS['cli_env'],
   'site_uuid'=>$GLOBALS['cli_uuid'],
   'profile_digest'=>str_repeat('a',64),
   'write_enabled'=>true
  );
 }
}
class MAD4B_SCP_Audit {
 public static function storage_status(){
  return array('ready'=>$GLOBALS['cli_audit_ready'],'append_only'=>true);
 }
 public static function record($type,$row,$status){
  if($GLOBALS['cli_fail_intent_audit'] && $status==='intent')
   return new WP_Error('mad4b_scheduled_intent_audit_failed');
  $GLOBALS['cli_scan_audits'][]=array($type,$status,$row);
  return array('ok'=>true);
 }
}
class MAD4B_SCP_Content_Readiness_Alerts {
 const AUDIT='mad4b_content_readiness_audit_v1';
 const STORE='mad4b_content_readiness_issues_v1';
 public static function rules(){
  return array('scheduled_scan_enabled'=>$GLOBALS['cli_schedule_enabled'],
   'scheduled_scan_interval_minutes'=>60);
 }
 public static function run_scheduled(){
  ++$GLOBALS['cli_scans'];
  return array('status'=>'completed','count'=>0);
 }
}
class MAD4B_SCP_Policy {
 public static function can_read(){return $GLOBALS['cli_policy_read'];}
}
class MAD4B_SCP_Operational_Integrity {
 public static function capture(){
  return array('scope'=>array('site_uuid'=>$GLOBALS['cli_uuid'],
    'environment'=>$GLOBALS['cli_env']),'fingerprint'=>str_repeat('c',64));
 }
 public static function assert_unchanged($before,$mutating=false){
  return array('complete'=>true,'unchanged'=>true);
 }
}
class MAD4B_SCP_Operation_Registry {
 public static function operation($operation){
  if($operation!=='company.code-reconciliation.plan')return new WP_Error('unsupported_operation');
  return array('effect'=>'read_only',
   'pipeline_profile'=>'governed_read_only_v1',
   'generic_mutation_dispatch'=>false);
 }
}
class MAD4B_SCP_Operation_Pipeline {
 public static function compile($input){
  return $GLOBALS['cli_pipeline_safe']?array('ready'=>true,
   'executor'=>'mad4b/company-code-reconciliation-compare',
   'authority_created'=>false,'mutation_performed'=>false)
   :array('ready'=>false);
 }
}
function cli_assert($ok,$reason){
 if(!$ok)throw new \RuntimeException('FAIL NATIVE COMPANY CLI: '.$reason);
}
$source=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/';
require $source.'class-mad4b-scp-cli-execution-context.php';
require $source.'class-mad4b-scp-cli-readiness.php';
require $source.'class-mad4b-scp-company-local-wpcli.php';
$ctx='MAD4B_SCP_CLI_Execution_Context';
$scanner='MAD4B_SCP_CLI_Readiness';
$company='MAD4B_SCP_Company_Local_WPCLI';
$ctx::boot();$scanner::boot();$company::boot();
cli_assert(count(WP_CLI::$registered)===5
 && isset(WP_CLI::$registered['mad4b context inspect'])
 && isset(WP_CLI::$registered['mad4b context check'])
 && isset(WP_CLI::$registered['mad4b readiness plan'])
 && isset(WP_CLI::$registered['mad4b readiness run'])
 && isset(WP_CLI::$registered['mad4b company-local-readback']),
 'Exactly five original native CLI routes, no shell or SQL expansion');
$read=$ctx::resolve('runtime.status.read',false);
$scheduled=$ctx::resolve('content_readiness.scheduled_scan',true);
$unlisted=$ctx::resolve('operator.shell',true);
cli_assert($read['read_context_verified']===true
 && $scheduled['local_operation_authorized']===true
 && $scheduled['production_mutation_authorized']===true
 && $scheduled['mutation_performed']===false
 && $unlisted['local_operation_authorized']===false
 && $unlisted['production_mutation_authorized']===false,
 'Only exact tenant-authorized Content Readiness maintenance may use local CLI Production lane');
$GLOBALS['cli_schedule_enabled']=false;
$disabled=$ctx::resolve('content_readiness.scheduled_scan',true);
cli_assert($disabled['local_operation_authorized']===false,
 'Source company policy must disable unattended scheduled scanning');
$GLOBALS['cli_schedule_enabled']=true;
$GLOBALS['cli_enrolled']=false;
cli_assert($ctx::resolve('content_readiness.scheduled_scan',true)['local_operation_authorized']===false,
 'Unenrolled WordPress instance must not schedule mutations');
$GLOBALS['cli_enrolled']=true;
$GLOBALS['cli_current_user']=9;
cli_assert($ctx::resolve('runtime.status.read',false)['read_context_verified']===false,
 'Host context cannot be created by impersonating --user=9');
$GLOBALS['cli_current_user']=0;
$GLOBALS['cli_audit_ready']=false;
cli_assert($ctx::resolve('content_readiness.scheduled_scan',true)['local_operation_authorized']===false,
 'Missing append-only Audit must stop scheduled write');
$GLOBALS['cli_audit_ready']=true;
$plugins=$company::inspect('plugins');
cli_assert(is_array($plugins) && $plugins['items_count']===1
 && $plugins['transport']==='local_wp_cli_only'
 && $plugins['remote_ssh_executed']===false
 && $plugins['complete_inventory_coverage_verified']===false
 && $plugins['signed_inventory_issued']===false
 && $plugins['original_core_pipeline_verified']===true
 && $plugins['mutation_performed']===false,
 'Exact local WP-CLI plugin list cannot become signed full inventory or Host release');
$themes=$company::inspect('themes');
cli_assert(is_array($themes)&&$themes['kind']==='themes'
 && count($GLOBALS['cli_commands'])===2
 && $GLOBALS['cli_commands'][0]['options']['launch']===false
 && $GLOBALS['cli_commands'][0]['options']['return']===true,
 'Local inventory must only use the two original commands without process launch');
$bad=$company::inspect('eval');
cli_assert($bad instanceof WP_Error
 && $bad->get_error_code()==='mad4b_company_local_wpcli_unsupported_operation',
 'Caller cannot run arbitrary WP-CLI from managed Company context');
$GLOBALS['cli_policy_read']=false;
cli_assert($company::inspect('plugins')->get_error_code()==='mad4b_company_local_wpcli_read_permission_denied',
 'Native WordPress read permission must precede command execution');
$GLOBALS['cli_policy_read']=true;
$GLOBALS['cli_pipeline_safe']=false;
cli_assert($company::inspect('plugins')->get_error_code()==='mad4b_company_local_wpcli_core_pipeline_not_ready',
 'Core registry/executor not ready: no fallback shell execution');
$GLOBALS['cli_pipeline_safe']=true;
$GLOBALS['cli_enrolled']=false;
cli_assert($company::inspect('plugins')->get_error_code()==='mad4b_company_local_wpcli_exact_enrolled_site_scope_mismatch',
 'Cross-origin or non-enrolled Site Profile must fail before WP-CLI spawn');
$GLOBALS['cli_enrolled']=true;
$GLOBALS['cli_inventory_output']='[{"name":23,"version":"none","status":"active"}]';
cli_assert($company::inspect('plugins')->get_error_code()==='mad4b_company_local_wpcli_row_invalid',
 'Invalid native inventory schema must never be certified');
$GLOBALS['cli_inventory_output']='['.str_repeat('a',263000).']';
cli_assert($company::inspect('plugins')->get_error_code()==='mad4b_company_local_wpcli_output_unbounded',
 'Unbounded host command output is not accepted');
$scanner::plan_command(array(),array());
$plan=json_decode(end(WP_CLI::$lines),true);
cli_assert(is_array($plan)
 && $plan['scope']==='existing_content_readiness_issues_only'
 && $plan['may_create_external_tasks']===false
 && $plan['may_enable_mcp_write']===false
 && $plan['mutation_performed']===false,
 'Scheduled Readiness plan must not authorize MCP/SSH or additional tasks');
cli_assert($GLOBALS['cli_mutations']===0,
 'The original local CLI inspection paths never mutate WordPress');
$badPlan=$plan['plan_sha256'];
try{
 $scanner::run_command(array(),array('expected-plan'=>str_repeat('0',64),
   'confirm'=>$scanner::CONFIRM));
 throw new \RuntimeException('FAIL scheduled scan should reject stale plan');
}catch(\RuntimeException $e){
 cli_assert(strpos($e->getMessage(),'Stale plan')!==false,
  'A stale plan must never run a scheduled scan');
}
cli_assert($GLOBALS['cli_scans']===0,'Stale scan cannot call original scheduler');
$scanner::run_command(array(),array('expected-plan'=>$badPlan,
 'confirm'=>$scanner::CONFIRM));
$completed=json_decode(end(WP_CLI::$lines),true);
cli_assert($GLOBALS['cli_scans']===1
 && $completed['source_of_write']==='MAD4B_SCP_Content_Readiness_Alerts::run_scheduled'
 && $completed['oauth_used']===false
 && $completed['mcp_write_enabled']===false
 && $completed['external_tasks_created']===false
 && count($GLOBALS['cli_scan_audits'])===2,
 'Exact owner-confirmed scheduled CLI must use the existing scanner with intent/result audit');
$GLOBALS['cli_fail_intent_audit']=true;
try{
 $scanner::run_command(array(),array('expected-plan'=>$badPlan,'confirm'=>$scanner::CONFIRM));
 throw new \RuntimeException('FAIL audit must deny the scan');
}catch(\RuntimeException $e){
 cli_assert(strpos($e->getMessage(),'Append-only intent audit failed')!==false,
  'Missing Audit intent must stop scheduled scan before mutation');
}
cli_assert($GLOBALS['cli_scans']===1,
 'Failed source audit cannot run the WordPress scheduled scanner');
echo "PASS: COMPANY_LOCAL_CLI_BOUNDARY read inventory, exact scheduled CAS/audit, no Host bridge\n";
