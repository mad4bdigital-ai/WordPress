<?php
// Offline contract fixtures; never contacts or mutates a WordPress tenant.
define('ABSPATH','/tmp/');define('DB_NAME','etg_commerce_staging');
$GLOBALS['opts']=[
 'mad4b_tourism_company_site_registry_v1'=>[
  'wayfatours'=>['company_site_uuid'=>'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b','host_binding_verified'=>true,'canonical_origin'=>'https://wayfatours.mad4b.com'],
  'otherbrand'=>['company_site_uuid'=>'12345678-1234-4123-8123-123456789abc','host_binding_verified'=>true,'canonical_origin'=>'https://otherbrand.mad4b.com']],
 'mad4b_company_bootstrap_registry_v1'=>['wayfatours'=>['revision'=>1]],
 'mad4b_company_vps_executor_registry_v1'=>[]];
$GLOBALS['queue_count']=0;$GLOBALS['scoped']='wayfatours';
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
function do_action(...$a){}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;function __construct($c,$m='',$d=[]){$this->c=$c;}function get_error_code(){return $this->c;}}
class WP_REST_Request{private $p=[];function __construct($m='GET',$u='/'){}function set_param($k,$v){$this->p[$k]=$v;}function get_param($k){return $this->p[$k]??null;}function get_json_params(){return $this->p;}function get_params(){return $this->p;}}
class MAD4B_Company_Readiness_Governance{static function guard($r){return $r->get_param('company')===$GLOBALS['scoped']?true:new WP_Error('cross_company');}}
class MAD4B_SCP_Remote_Work_Queue{static function enqueue($o,$p,$i,$ttl){$GLOBALS['queue_count']++;return ['state'=>'queued','job'=>['job_id'=>'00000000-0000-4000-8000-000000000001']];}}
class MAD4B_SCP_Live_Acceptance_Observer{static function build_provenance_status(){return ['manifest_valid'=>true,'runtime_manifest_match'=>true,'stale'=>false,'provenance_mismatch'=>[], 'source_commit_sha'=>str_repeat('a',40),'build_fingerprint'=>str_repeat('b',64),'package_manifest_digest'=>str_repeat('c',64)];}}
require dirname(__DIR__).'/staging-mu/252-mad4b-start-your-company-runtime.php';
function t($name,$pass){static $n=0;$n++;if(!$pass){fwrite(STDERR,"FAIL $n $name\n");exit(2);}echo "PASS $n $name\n";}
function req($code,$params=[]){$r=new WP_REST_Request();$r->set_param('company',$code);foreach($params as $k=>$v)$r->set_param($k,$v);return $r;}
$other=MAD4B_Start_Company_Runtime::plan(req('otherbrand'));
t('company_scope',is_wp_error($other)&&$other->get_error_code()==='cross_company');
$p=MAD4B_Start_Company_Runtime::plan(req('wayfatours',['mode'=>'repair_existing']));
t('deterministic_plan',is_array($p)&&preg_match('/^[a-f0-9]{64}$/',$p['plan_sha256']));
t('unverified_identity_pending',$p['stages']['identity']['state']==='READY');
t('dependency_handling',$p['stages']['plugins']['state']==='WAITING_DEPENDENCY');
t('commercial_not_ready',$p['readiness']['commercial_ready']===false);
t('preserves_existing_users',$p['preserve_existing_users']===true);
$input=['stage'=>'identity','mode'=>'repair_existing','expected_plan_sha256'=>$p['plan_sha256']];
$before=MAD4B_Start_Company_Runtime::resume(req('wayfatours',$input));
t('no_unverified_executor',is_wp_error($before)&&$before->get_error_code()==='mad4b_company_executor_unregistered');
t('no_side_effect_on_denial',$GLOBALS['queue_count']===0);
$GLOBALS['opts']['mad4b_company_vps_executor_registry_v1']['wayfatours']=['provider'=>'vps_mcp_client','site_uuid'=>'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b','authority_profile'=>'company_stage_v1','bound_to_exact_company'=>true,'health'=>'ready','executor_id'=>'vps-runner-1','verified_at'=>gmdate('c')];
$ok=MAD4B_Start_Company_Runtime::resume(req('wayfatours',$input));
t('queue_semantic_stage',is_array($ok)&&$ok['state']==='queued'&&$ok['stage']==='identity');
t('enqueue_not_execution',$ok['executor_executed']===false&&$ok['production_mutation_authorized']===false);
$bad=MAD4B_Start_Company_Runtime::resume(req('wayfatours',['stage'=>'identity','expected_plan_sha256'=>str_repeat('0',64)]));
t('plan_cas',is_wp_error($bad)&&$bad->get_error_code()==='mad4b_company_plan_changed');
$bad=MAD4B_Start_Company_Runtime::resume(req('wayfatours',['stage'=>'shell','expected_plan_sha256'=>$p['plan_sha256']]));
t('no_arbitrary_stage',is_wp_error($bad)&&$bad->get_error_code()==='mad4b_company_stage_invalid');
$job=['contract'=>'mad4b.company-stage-job.v1','company_code'=>'wayfatours','site_uuid'=>'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b','origin'=>'https://wayfatours.mad4b.com','mode'=>'repair_existing','stage'=>'identity','plan_sha256'=>$p['plan_sha256'],'stage_revision'=>$p['stages']['identity']['stage_revision'],'preserve_existing'=>true,'mutation_authorized'=>false];
t('job_payload_valid',MAD4B_SCP_Company_Provisioning_Work_Operations::validate_payload($job));
$job['site_uuid']='12345678-1234-4123-8123-123456789abc';
t('cross_tenant_job_rejected',!MAD4B_SCP_Company_Provisioning_Work_Operations::validate_payload($job));
$old=MAD4B_Start_Company_Runtime::plan(req('wayfatours'));
$GLOBALS['opts']['mad4b_company_bootstrap_registry_v1']['wayfatours']['revision']=2;
$new=MAD4B_Start_Company_Runtime::plan(req('wayfatours'));
t('owner_change_invalidates_access',$old['stages']['access']['stage_revision']!==$new['stages']['access']['stage_revision']);
t('owner_change_preserves_plugin_receipts',$old['stages']['plugins']['stage_revision']===$new['stages']['plugins']['stage_revision']);
MAD4B_Start_Company_Runtime::recheck_event('wayfatours');
t('event_snapshot_persisted',isset($GLOBALS['opts']['mad4b_start_company_snapshots_v3']['wayfatours']['plan_sha256']));
t('event_does_not_queue',$GLOBALS['queue_count']===1);
t('queue_operation_registered',isset(MAD4B_SCP_Company_Provisioning_Work_Operations::definitions([])['company.stage.execute']));
