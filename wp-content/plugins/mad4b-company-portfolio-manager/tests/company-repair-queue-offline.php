<?php
/** One-assessment queue, current-scope-only, no writes and admin guard. */
define('ABSPATH','/');
class WP_Error {
 private $code;
 public function __construct($code,$message='',$data=[]){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($obj){return $obj instanceof WP_Error;}
function add_action(...$args){}
function get_option($key,$default=false){return $GLOBALS['TEST_OPTIONS'][$key]??$default;}
function current_user_can($name){return $GLOBALS['TEST_ADMIN']??false;}
class MAD4B_Company_Portfolio_Manager_V1 {
 public static function staging(){return true;}
}
class MAD4B_Company_Environment_Registry_V2 {}
class MAD4B_Company_Reconcile_Work_Plan_V1 {
 public static function inspect($company,$environment){
  $plan=$GLOBALS['TEST_PLAN'];
  if($company!=='wayfatours'||$environment!=='production')
   return new WP_Error('exact_scope_not_registered');
  return $plan;
 }
}
require dirname(__DIR__).'/includes/class-mad4b-company-repair-job-contract.php';
require dirname(__DIR__).'/includes/class-mad4b-company-repair-capability-discovery.php';
require dirname(__DIR__).'/includes/class-mad4b-company-repair-jobs.php';
$policy=json_decode(file_get_contents('/work/ops/deployment-overlays/center/company-start-reconcile/policy.json'),true);
$uuid='a3a7cc36-db7e-4a14-8456-b41857def05c';
$GLOBALS['TEST_PLAN']=[
 'company_binding_verified'=>true,'requested_environment_registered'=>true,
 'depth_audit'=>[
  'dependency_graph_valid'=>true,'incident_probe_mapping'=>array_map(
    fn($r)=>['code'=>$r['code'],
      'evidence_lane'=>$policy['incident_evidence_lanes'][$r['code']]],
    $policy['incident_scenarios']
  ),
 ],
 'environment_inventory'=>['production'=>[
   'site_uuid'=>$uuid,'canonical_origin'=>'https://wayfatours.com']],
 'incident_scenarios'=>$policy['incident_scenarios'],
 'work_plan_sha256'=>str_repeat('a',64)
];
$GLOBALS['TEST_ADMIN']=true;
$r='MAD4B_Company_Reconcile_Repair_Jobs_V1';
$c='MAD4B_Company_Repair_Job_Contract_V1';
$assertions=[];
$test=function($label,$pass)use(&$assertions){$assertions[$label]=(bool)$pass;};
$queue=$r::queue('wayfatours','production');
$test('queue_no_write_19_items',($queue['incident_count']??null)===19
 &&($queue['jobs_created_by_queue']??true)===false);
$test('single_registry_scope',$queue['site_uuid']===$uuid);
$test('zero_preexisting',$queue['counts']['NOT_CREATED']===19);
$test('current_plan_identities_unique',count(array_unique(
 array_column($queue['items'],'repair_id')) )===19);
$test('all_incidents_exposure_from_policy',
 array_column($queue['items'],'incident_code')===
 array_column($policy['incident_scenarios'],'code'));
$test('queue_no_implicit_signed_grant',($queue['original_313_certified_by_queue']??true)===false);
$test('no_historical_plan_inclusion',($queue['historical_other_plan_journals_included']??true)===false);
$first=$queue['items'][0];
$scope=[
 'company_code'=>'wayfatours','environment'=>'production',
 'site_uuid'=>$uuid,'canonical_origin'=>'https://wayfatours.com',
 'incident_code'=>$first['incident_code'],
 'resource_key'=>'incident:'.$first['incident_code'],
 'source_plan_sha256'=>str_repeat('a',64)
];
$job=$c::fresh($scope,10000);
$test('stable_id_matches_catalog_item',$job['id']===$first['repair_id']);
$GLOBALS['TEST_OPTIONS'][$r::job_key($job['id'])]=$job;
$one=$r::queue('wayfatours','production');
$test('journal_present_in_queue',$one['counts']['PERSISTED']===1
 &&$one['counts']['NOT_CREATED']===18);
$test('journal_still_not_313',$one['items'][0]['original_313_independent_verified']===false);
$GLOBALS['TEST_OPTIONS'][$r::job_key($job['id'])]['site_uuid']='wrong';
$bad=$r::queue('wayfatours','production');
$test('tampered_journal_not_treated_as_success',
 $bad['counts']['JOURNAL_INTEGRITY_HOLD']===1
 &&$bad['counts']['PERSISTED']===0);
$GLOBALS['TEST_ADMIN']=false;
$rejected=$r::queue('wayfatours','production');
$test('non_admin_rejected',is_wp_error($rejected));
$GLOBALS['TEST_ADMIN']=true;
$wrong=$r::queue('other-company','production');
$test('wrong_company_rejected',is_wp_error($wrong));
$wrong=$r::queue('wayfatours','staging');
$test('wrong_environment_rejected',is_wp_error($wrong));
$GLOBALS['TEST_PLAN']['company_binding_verified']=false;
$invalid=$r::queue('wayfatours','production');
$test('unbound_company_identity_rejected',is_wp_error($invalid));
echo json_encode(['pass'=>!in_array(false,$assertions,true),
 'assertions'=>count($assertions),
 'failed'=>array_keys(array_filter($assertions,fn($x)=>!$x)),
 'source_scenarios'=>19],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(in_array(false,$assertions,true))exit(1);
