<?php
// Offline fixtures: never contact WordPress tenants or write to their databases.
define('ABSPATH','/tmp/');define('DB_NAME','etg_commerce_staging');define('DAY_IN_SECONDS',86400);
const UUID='b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b';
$GLOBALS['opts']=[
 'mad4b_tourism_company_site_registry_v1'=>[
  'wayfatours'=>['company_site_uuid'=>UUID,'host_binding_verified'=>true,'canonical_origin'=>'https://wayfatours.mad4b.com'],
  'otherbrand'=>['company_site_uuid'=>'12345678-1234-4123-8123-123456789abc','host_binding_verified'=>true,'canonical_origin'=>'https://otherbrand.mad4b.com']],
 'mad4b_company_bootstrap_registry_v1'=>['wayfatours'=>['revision'=>1]],
 'mad4b_company_vps_executor_registry_v1'=>[]];
$GLOBALS['queue_count']=0;$GLOBALS['queue_seen']=[];$GLOBALS['scoped']='wayfatours';
function add_action(...$a){}
function register_rest_route(...$a){}
function get_option($k,$d=[]){return $GLOBALS['opts'][$k]??$d;}
function update_option($k,$v,$a=false){$GLOBALS['opts'][$k]=$v;return true;}
function wp_parse_url($u,$c){return parse_url($u,$c);}
function home_url(){return 'https://tours.dev.mad4b.com';}
function wp_json_encode($d,$f=0){return json_encode($d,$f);}
function wp_is_uuid($v){return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',$v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
function current_user_can($cap){return true;}
function get_current_user_id(){return 1;}
function wp_generate_uuid4(){return '24629c27-5d94-4e70-a637-913d8ab21101';}
function get_userdata($id){return (object)['ID'=>$id];}
function user_can($user,$cap){return $user->ID===1;}
function do_action(...$a){}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;function __construct($c,$m='',$d=[]){$this->c=$c;}function get_error_code(){return $this->c;}}
class WP_REST_Request{private $p=[];function __construct($m='GET',$u='/'){}function set_param($k,$v){$this->p[$k]=$v;}function get_param($k){return $this->p[$k]??null;}function get_json_params(){return $this->p;}function get_params(){return $this->p;}}
class MAD4B_Company_Readiness_Governance{static function guard($r){return $r->get_param('company')===$GLOBALS['scoped']?true:new WP_Error('cross_company');}}
class MAD4B_SCP_Remote_Work_Queue {
 static function enqueue($o,$p,$i,$ttl){
  $sha=hash('sha256',json_encode([$o,$p,$i]));
  if(isset($GLOBALS['queue_seen'][$sha]))return ['state'=>'already_queued','job'=>$GLOBALS['queue_seen'][$sha]];
  $GLOBALS['queue_count']++;
  $job=['job_id'=>sprintf('00000000-0000-4000-8000-%012d',$GLOBALS['queue_count']),'payload'=>$p,'status'=>'pending'];
  $GLOBALS['queue_seen'][$sha]=$job;
  return ['state'=>'queued','job'=>$job];
 }
 static function list_jobs($operation_id=''){
  $items=array_values($GLOBALS['queue_seen']);
  $items[]=['job_id'=>'foreign-tenant-job','payload'=>[
    'company_code'=>'otherbrand','site_uuid'=>'12345678-1234-4123-8123-123456789abc',
    'origin'=>'https://otherbrand.mad4b.com','mode'=>'repair_existing',
    'stage'=>'plugins'],'status'=>'failed','reconciliation_required'=>true];
  return ['items'=>$items];
 }
}
class MAD4B_SCP_Operation_Journal {
 static function status($op){return ['lifecycle_state'=>'completed','terminal_outcome'=>'success','operation_binding_sha256'=>str_repeat('f',64)];}
}
class MAD4B_SCP_Live_Acceptance_Observer{
 static function build_provenance_status(){return ['manifest_valid'=>true,'runtime_manifest_match'=>true,'stale'=>false,'provenance_mismatch'=>[], 'source_commit_sha'=>str_repeat('a',40),'build_fingerprint'=>str_repeat('b',64),'package_manifest_digest'=>str_repeat('c',64)];}
}
require dirname(__DIR__).'/staging-mu/252-mad4b-start-your-company-runtime.php';
function t($name,$pass){static $n=0;$n++;if(!$pass){fwrite(STDERR,"FAIL $n $name\n");exit(2);}echo "PASS $n $name\n";}
function req($code,$params=[]){$r=new WP_REST_Request();$r->set_param('company',$code);foreach($params as $k=>$v)$r->set_param($k,$v);return $r;}
function plan(){return MAD4B_Start_Company_Runtime::plan(req('wayfatours',['mode'=>'repair_existing']));}
function mark_verified($stage) {
 $p=plan();
 $GLOBALS['opts']['mad4b_company_lifecycle_receipt_index_v1']['wayfatours'][$stage]=[
   'independent_readback'=>true,'verified'=>true,'site_uuid'=>UUID,
   'stage'=>$stage,'stage_revision'=>$p['stages'][$stage]['stage_revision'],
   'operation_id'=>'11111111-1111-4111-8111-111111111111',
   'operation_binding_sha256'=>str_repeat('f',64)
 ];
}
$other=MAD4B_Start_Company_Runtime::plan(req('otherbrand'));
t('company_scope',is_wp_error($other)&&$other->get_error_code()==='cross_company');
$p=plan();
t('deterministic_plan',is_array($p)&&preg_match('/^[a-f0-9]{64}$/',$p['plan_sha256']));
t('unverified_identity_ready',$p['stages']['identity']['state']==='READY');
t('plugin_dep_requires_backup',$p['stages']['plugins']['state']==='WAITING_DEPENDENCY');
t('commercial_not_ready',$p['readiness']['commercial_ready']===false);
t('preserve_users',$p['preserve_existing_users']===true);
t('preserve_options',$p['preserve_existing_options']===true);
$out=MAD4B_Start_Company_Runtime::status(req('wayfatours'));
t('single_outcome_contract',$out['contract']==='mad4b.start-your-company.outcome.v1');
t('outcome_is_not_false_success',$out['successful']===false && $out['verified_count']===0);
t('unregistered_executor_is_blocker',$out['overall_state']==='WAITING_EXECUTOR');
t('foreign_jobs_are_filtered',count($out['jobs'])===0);
$initial=['stage'=>'identity','mode'=>'repair_existing','expected_plan_sha256'=>$p['plan_sha256']];
$before=MAD4B_Start_Company_Runtime::resume(req('wayfatours',$initial));
t('no_unverified_executor',is_wp_error($before)&&$before->get_error_code()==='mad4b_company_executor_unregistered');
t('no_enqueue_on_denial',$GLOBALS['queue_count']===0);
$GLOBALS['opts']['mad4b_company_vps_executor_registry_v1']['wayfatours']=[
 'provider'=>'vps_mcp_client','site_uuid'=>UUID,'authority_profile'=>'company_stage_v1',
 'bound_to_exact_company'=>true,'health'=>'ready','executor_id'=>'vps-runner-1',
 'verified_at'=>gmdate('c'),'supported_actions'=>array_values(MAD4B_Start_Company_Runtime::ACTIONS)];
$ok=MAD4B_Start_Company_Runtime::resume(req('wayfatours',$initial));
t('queue_semantic_identity',is_array($ok)&&$ok['state']==='queued'&&$ok['stage']==='identity');
t('queue_not_execution',$ok['executor_executed']===false);
$again=MAD4B_Start_Company_Runtime::run(req('wayfatours',['mode'=>'repair_existing','expected_plan_sha256'=>$p['plan_sha256']]));
t('one_start_auto_idempotent',$again['state']==='already_queued'&&$GLOBALS['queue_count']===1);
$out=MAD4B_Start_Company_Runtime::status(req('wayfatours'));
t('one_run_id_preserved',(bool)preg_match('/^[a-f0-9]{64}$/',$out['run_id']));
t('one_outcome_scoped_job',count($out['jobs'])===1 && $out['jobs'][0]['stage']==='identity');
t('single_outcome_reports_active',$out['overall_state']==='IN_PROGRESS'&&!$out['successful']);
t('run_returns_same_authoritative_outcome',($again['outcome']['run_id']??null)===$out['run_id']);
t('single_outcome_no_cross_company_jobs',!in_array('foreign-tenant-job',array_column($out['jobs'],'id'),true));
$bad=MAD4B_Start_Company_Runtime::resume(req('wayfatours',['stage'=>'identity','expected_plan_sha256'=>str_repeat('0',64)]));
t('plan_hash_cas',is_wp_error($bad)&&$bad->get_error_code()==='mad4b_company_plan_changed');
$bad=MAD4B_Start_Company_Runtime::resume(req('wayfatours',['stage'=>'shell','expected_plan_sha256'=>$p['plan_sha256']]));
t('reject_arbitrary_stage',is_wp_error($bad)&&$bad->get_error_code()==='mad4b_company_stage_invalid');
$job=[
 'contract'=>'mad4b.company-stage-job.v1','company_code'=>'wayfatours','site_uuid'=>UUID,
 'origin'=>'https://wayfatours.mad4b.com','mode'=>'repair_existing','stage'=>'identity',
 'action'=>MAD4B_Start_Company_Runtime::ACTIONS['identity'],'plan_sha256'=>$p['stages']['identity']['execution_plan_sha256'],
 'stage_revision'=>$p['stages']['identity']['stage_revision'],
 'preserve_existing'=>true,'mutation_authorized'=>false
];
t('bound_job_valid',MAD4B_SCP_Company_Provisioning_Work_Operations::validate_payload($job));
$claimed=['operation_id'=>'company.stage.execute','payload'=>$job,'status'=>'claimed',
 'lease_expires_at_epoch'=>time()+600,'executor_id'=>'vps-runner-1'];
t('admit_verified_identity',MAD4B_SCP_Company_Provisioning_Work_Operations::provider_entry_allowed($claimed));
$stale=$claimed;$stale['lease_expires_at_epoch']=time()-1;
t('deny_expired_lease',!MAD4B_SCP_Company_Provisioning_Work_Operations::provider_entry_allowed($stale));
$foreign=$claimed;$foreign['executor_id']='another-runner';
t('deny_wrong_executor',!MAD4B_SCP_Company_Provisioning_Work_Operations::provider_entry_allowed($foreign));
$forged=$job;$forged['action']='shell.exec';
t('deny_generic_shell_action',!MAD4B_SCP_Company_Provisioning_Work_Operations::validate_payload($forged));
$forged=$job;$forged['site_uuid']='12345678-1234-4123-8123-123456789abc';
t('deny_cross_tenant_job',!MAD4B_SCP_Company_Provisioning_Work_Operations::validate_payload($forged));
$withoutBackup=$claimed;$withoutBackup['payload']['stage']='plugins';
$withoutBackup['payload']['action']=MAD4B_Start_Company_Runtime::ACTIONS['plugins'];
t('deny_plugins_before_backup',!MAD4B_SCP_Company_Provisioning_Work_Operations::provider_entry_allowed($withoutBackup));
mark_verified('identity');mark_verified('snapshot');mark_verified('backup');
$plug=plan();t('plugins_ready_after_backup',$plug['stages']['plugins']['state']==='READY');
$plugRequest=MAD4B_Start_Company_Runtime::run(req('wayfatours',['mode'=>'repair_existing','expected_plan_sha256'=>$plug['plan_sha256']]));
t('auto_dispatch_plugins',$plugRequest['stage']==='plugins'&&$plugRequest['action']==='company.plugins.converge');
$plugJob=$claimed;$plugJob['payload']['stage']='plugins';
$plugJob['payload']['action']=MAD4B_Start_Company_Runtime::ACTIONS['plugins'];
$plugJob['payload']['plan_sha256']=$plug['stages']['plugins']['execution_plan_sha256'];
$plugJob['payload']['stage_revision']=$plug['stages']['plugins']['stage_revision'];
t('permit_plugins_to_executor',MAD4B_SCP_Company_Provisioning_Work_Operations::provider_entry_allowed($plugJob));
$GLOBALS['opts']['mad4b_company_vps_executor_registry_v1']['wayfatours']['supported_actions']=['company.identity.verify'];
t('deny_unregistered_plugin_action',!MAD4B_SCP_Company_Provisioning_Work_Operations::provider_entry_allowed($plugJob));
$GLOBALS['opts']['mad4b_company_vps_executor_registry_v1']['wayfatours']['supported_actions']=array_values(MAD4B_Start_Company_Runtime::ACTIONS);
mark_verified('plugins');mark_verified('mu_providers');
$cfg=plan();t('configuration_ready_after_providers',$cfg['stages']['configuration']['state']==='READY');
$configRequest=MAD4B_Start_Company_Runtime::run(req('wayfatours',['mode'=>'repair_existing','expected_plan_sha256'=>$cfg['plan_sha256']]));
t('auto_dispatch_configuration',$configRequest['stage']==='configuration'&&$configRequest['action']==='company.configuration.converge');
$cfgJob=$claimed;$cfgJob['payload']['stage']='configuration';
$cfgJob['payload']['action']=MAD4B_Start_Company_Runtime::ACTIONS['configuration'];
$cfgJob['payload']['plan_sha256']=$cfg['stages']['configuration']['execution_plan_sha256'];
$cfgJob['payload']['stage_revision']=$cfg['stages']['configuration']['stage_revision'];
t('permit_configuration_to_executor',MAD4B_SCP_Company_Provisioning_Work_Operations::provider_entry_allowed($cfgJob));
$cfgJob['payload']['plan_sha256']=str_repeat('0',64);
t('deny_stale_stage_plan',!MAD4B_SCP_Company_Provisioning_Work_Operations::provider_entry_allowed($cfgJob));
$old=plan();
$GLOBALS['opts']['mad4b_company_bootstrap_registry_v1']['wayfatours']['revision']=2;
$new=plan();
t('owner_change_invalidates_access',$old['stages']['access']['stage_revision']!==$new['stages']['access']['stage_revision']);
t('owner_change_preserves_plugins',$old['stages']['plugins']['stage_revision']===$new['stages']['plugins']['stage_revision'] && $old['stages']['plugins']['execution_plan_sha256']===$new['stages']['plugins']['execution_plan_sha256']);
MAD4B_Start_Company_Runtime::recheck_event('wayfatours');
t('event_snapshot_saved',isset($GLOBALS['opts']['mad4b_start_company_snapshots_v3']['wayfatours']['plan_sha256']));
t('event_dispatches_next_ready_stage',$GLOBALS['queue_count']===4);
$after=MAD4B_Start_Company_Runtime::status(req('wayfatours'));
t('partially_verified_not_complete',$after['verified_count']>=5 && $after['successful']===false);
t('single_outcome_jobs_have_receipts_priority',$after['overall_state']==='IN_PROGRESS');
t('single_outcome_still_keeps_external_ownership_unverified',$after['infrastructure_ownership']==='UNVERIFIED_BY_NATIVE_RUNTIME');
t('typed_stage_registered',isset(MAD4B_SCP_Company_Provisioning_Work_Operations::definitions([])['company.stage.execute']));
