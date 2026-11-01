<?php
declare(strict_types=1);
define('ABSPATH', '/test/');
class MAD4B_Central_Dual_Lane_Registry_V1 { public static function central() { return true; } }
class MAD4B_Central_SSH_Command_Intake_V1 {
 const REQUEST_PREFIX='request:';const PREFIX='grant:';const TRANSPORT_PREFIX='transport:';const CLAIM_PREFIX='claim:';
}
class MAD4B_Central_VPS_Broker_V1 {
 const JOB='job:';const CLAIM='broker_claim:';const PUBKEY='public';
}
class MAD4B_Manager_Auto_Write_V1 {
 public static function company_target($company) {return ['state'=>'REGISTERED','site_uuid'=>'customer-staging-uuid','canonical_origin'=>'https://customer-staging.example','environment'=>'staging'];}
}
class MAD4B_Company_Operation_Admission_V1 {public static function manager_id(){return 'central-manager';}}
class MAD4B_Company_Portfolio_Manager_V1 {public static function has_delegation($manager,$company,$operation,$actor){return $manager==='central-manager' && $company==='customer-staging' && $operation==='operations_manage' && $actor===26;}}
class MAD4B_Native_VPS_Principal_V1 {public static function valid_job_chain(){return false;}}
function get_option($k,$fallback=false){return $GLOBALS['options'][$k]??$fallback;}
function must($yes,$tag){if(!$yes){fwrite(STDERR,"FAILED: $tag\n");exit(1);}}
require dirname(__DIR__).'/312-mad4b-broker-exact-ssh-authority.php';
$id=str_repeat('a',32);$grant_id=str_repeat('b',32);$transport_id=str_repeat('c',32);
$digest=str_repeat('d',64);$origin='https://customer-staging.example';$t=time()+260;
$job=[
  'job_id'=>$id,'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY','executed'=>false,
  'broker_host_id'=>'srv2030908','target_host_id'=>'srv2030908','target_ssh_principal'=>'root',
  'company'=>'customer-staging','site_uuid'=>'customer-staging-uuid','canonical_origin'=>$origin,
  'environment'=>'staging','command_sha256'=>$digest,'grant_id'=>$grant_id,
  'grant_mode'=>'persistent','operation'=>'company.remote.wpcli.full','command_format'=>'wpcli.argv.json.v1',
  'job_type'=>'signed_vps_wpcli','target_scope'=>'wordpress','central_principal_id'=>26,
  'transport_id'=>$transport_id,'expires_epoch'=>$t,'correlation_id'=>'same-host-test-01'
];
$claim=['job_id'=>$id,'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
        'host_id'=>'srv2030908','claimed_epoch'=>time()];
$req=['request_id'=>$id,'status'=>'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY','grant_id'=>$grant_id,
 'target_scope'=>'wordpress','operation'=>'company.remote.wpcli.full','command_format'=>'wpcli.argv.json.v1',
 'command_sha256'=>$digest,'expires_epoch'=>$t];
$grant=[
 'state'=>'CENTRAL_OPERATION_SCOPE_GRANTED','grant_mode'=>'persistent','expires_epoch'=>null,
 'company'=>'customer-staging','site_uuid'=>'customer-staging-uuid','canonical_origin'=>$origin,
 'environment'=>'staging','central_principal_id'=>26,'target_host_id'=>'srv2030908',
 'target_ssh_principal'=>'root','transport_id'=>$transport_id,'operation'=>'company.remote.wpcli.full'
];
$transport=[
 'state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION','host_id'=>'srv2030908',
 'transport_id'=>$transport_id,'company'=>'customer-staging',
 'site_uuid'=>'customer-staging-uuid','canonical_origin'=>$origin,'environment'=>'staging',
 'central_principal_id'=>26,'assignment_revision'=>4,'host_principal'=>'root',
 'credential_ref'=>'vault:registry/local-srv2030908','host_key_fingerprint'=>'SHA256:TEST_ONLY_NOT_A_REAL_HOST_KEY','full_wpcli_grant_id'=>$grant_id,
 'full_wpcli_pending_native_enrollment'=>true,'capabilities'=>['wpcli.full']
];
$grant['assignment_revision']=4;
$GLOBALS['options']=[
 'job:'.$id=>$job,'broker_claim:'.$id=>$claim,'request:'.$id=>$req,
 'grant:'.$grant_id=>$grant,'transport:'.$transport_id=>$transport,
 'claim:'.$grant_id.'_'.hash('sha256',$job['correlation_id'])=>$id
];
function check($label,$edit,$allow=false){
 $old=$GLOBALS['options'];$edit();
 $r=MAD4B_Broker_Exact_SSH_Authority_V1::evaluate(str_repeat('a',32),'srv2030908',str_repeat('d',64));
 must(($r['eligible']??false)===$allow,$label.' result: '.json_encode($r));
 $GLOBALS['options']=$old;
}
check('valid exact Same-Host signed WPCLI',static function(){},true);
check('missing explicit signed job type',static function()use($id){unset($GLOBALS['options']['job:'.$id]['job_type']);});
check('missing original per-correlation claim',static function()use($grant_id,$job){unset($GLOBALS['options']['claim:'.$grant_id.'_'.hash('sha256',$job['correlation_id'])]);});
check('grant operation changed',static function()use($grant_id){$GLOBALS['options']['grant:'.$grant_id]['operation']='maintenance.shell';});
check('WordPress target replaced by host',static function()use($id){$GLOBALS['options']['request:'.$id]['target_scope']='host';});
check('owner enrolled capability missing',static function()use($transport_id){$GLOBALS['options']['transport:'.$transport_id]['capabilities']=[];});
check('company origin drift',static function()use($id){$GLOBALS['options']['job:'.$id]['canonical_origin']='https://wrong.example';});
check('site assignment revoked',static function()use($id){$GLOBALS['options']['job:'.$id]['site_uuid']='wrong';});
check('expired claim',static function()use($id){$GLOBALS['options']['job:'.$id]['expires_epoch']=time()-1;});
check('wrong host owner ID',static function()use($id,$grant_id,$transport_id){
 $GLOBALS['options']['job:'.$id]['central_principal_id']=0;
 $GLOBALS['options']['grant:'.$grant_id]['central_principal_id']=0;
 $GLOBALS['options']['transport:'.$transport_id]['central_principal_id']=0;
});
echo "SAME_HOST_312_EXACT_JOB_CONTRACT_PASS\n";
