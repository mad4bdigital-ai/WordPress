<?php
/** Hermetic regression for the owner-attestation / restart-grace deadlock. */
define('ABSPATH', '/fixture/');
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can($cap) { return !empty($GLOBALS['admin']) && in_array($cap, array('read', 'manage_options'), true); }
final class MAD4B_SCP_Environment { public static $env='staging'; public static function effective(){ return self::$env; } }
final class MAD4B_SCP_Site_Profile {
    public static $enrolled=true;
    public static function origin_enrolled(){ return self::$enrolled; }
    public static $owner_enrolled=true;
    public static function user_is_enrolled($id){ return self::$owner_enrolled && 7===$id; }
}
final class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE='mad4b:authority:step-up';
    public static $active=true, $scope=true, $client=true;
    public static function verified_bearer_active(){ return self::$active; }
    public static function verified_bearer_has_scope($s){ return self::$scope && $s===self::AUTHORITY_STEP_UP_SCOPE; }
    public static function verified_bearer_client_is($id){ return self::$client && $id === MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID; }
}
final class MAD4B_SCP_Staging_Write_Owner_Intent {
    public static $allowed=true;
    public static function can_remote_attest() { return self::$allowed; }
}
function get_current_user_id(){ return 7; }
final class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID='https://chatgpt.com/oauth/client.json'; }
final class MAD4B_SCP_Live_Acceptance_Observer {
    public static $match = true;
    public static function build_provenance_status() {
        return array(
            'runtime_manifest_match' => self::$match,
            'stale' => ! self::$match,
            'source_commit_sha' => str_repeat('b',40),
            'build_fingerprint' => str_repeat('c',64),
            'package_manifest_digest' => str_repeat('d',64),
        );
    }
}
final class FixtureRequest {
    private $rpc;
    public function __construct(array $rpc){ $this->rpc=$rpc; }
    public function get_json_params(){ return $this->rpc; }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-reconnect-hardening.php';
$method=new ReflectionMethod('MAD4B_SCP_Reconnect_Hardening','historical_authenticated_read_tool_admission');
if(PHP_VERSION_ID<80100)$method->setAccessible(true);
function permitted($tool,$op,$input,$extra=array()) {
    global $method;
    $rpc=array('method'=>'tools/call','params'=>array('name'=>$tool,'arguments'=>array_merge(array(
        'operation_id'=>$op,
        'expected_registration_digest'=>str_repeat('a',64),
        'expected_dispatch_policy_digest'=>str_repeat('b',64),
        'expected_input_schema_sha256'=>str_repeat('c',64),
        'input'=>$input
    ),$extra)));
    return $method->invoke(null,new FixtureRequest($rpc),
        array('active'=>true,'historical_mcp_read_safe'=>true),true);
}
function verify($ok,$label){ if(!$ok){fwrite(STDERR,"FAIL ".$label.PHP_EOL);exit(1);} }
$GLOBALS['logged_in']=true; $GLOBALS['admin']=true;
$owner_input=array('review_digest'=>str_repeat('d',64),
    'confirmation'=>'AUTHORIZE EXACT STAGING WRITE CONVERGENCE');
$owner='core_step_up_mad4b_staging_write_owner_attest_2cc8769d389d';
$invoke=function($input=null,$op=null,$extra=array())use($owner_input,$owner){
    return permitted('mad4b-enrollment-execute',null===$op?$owner:$op,
        null===$input?$owner_input:$input,$extra);
};
verify($invoke(), 'the exact enrolled owner step-up must be admitted during historical restart grace');
verify(!$invoke(array('review_digest'=>str_repeat('d',64),'confirmation'=>'WRONG')),
    'unexpected confirmation rejected');
verify(!$invoke(array('review_digest'=>'bad','confirmation'=>'AUTHORIZE EXACT STAGING WRITE CONVERGENCE')),
    'review digest must be exact 64 hex');
verify(!$invoke(null,'unrelated_write'), 'unrelated enrollment mutation rejected');
verify(!$invoke(null,null,array('expected_registration_digest'=>'bad')),
    'unbound registration digest rejected');
verify(!$invoke(null,null,array('unexpected'=>'extra')),
    'unexpected arbitrary arguments rejected');
MAD4B_SCP_Environment::$env='production';
verify(!$invoke(), 'Production rejected');
MAD4B_SCP_Environment::$env='staging';
MAD4B_SCP_Site_Profile::$enrolled=false;
verify(!$invoke(), 'unenrolled origin rejected');
MAD4B_SCP_Site_Profile::$enrolled=true;
MAD4B_SCP_Site_Profile::$owner_enrolled=false;
verify(!$invoke(), 'unenrolled WordPress owner rejected');
MAD4B_SCP_Site_Profile::$owner_enrolled=true;
MAD4B_SCP_Staging_Write_Owner_Intent::$allowed=false;
verify(!$invoke(), 'target owner permission rejection preserves barrier');
MAD4B_SCP_Staging_Write_Owner_Intent::$allowed=true;
MAD4B_SCP_OAuth_Resource_Bridge::$scope=false;
verify(!$invoke(), 'missing OAuth step-up rejected');
MAD4B_SCP_OAuth_Resource_Bridge::$scope=true;
MAD4B_SCP_OAuth_Resource_Bridge::$client=false;
verify(!$invoke(), 'foreign OAuth client rejected');
MAD4B_SCP_OAuth_Resource_Bridge::$client=true;
MAD4B_SCP_OAuth_Resource_Bridge::$active=false;
verify(!$invoke(), 'unverified OAuth rejected');
MAD4B_SCP_OAuth_Resource_Bridge::$active=true;
$GLOBALS['admin']=false;
verify(!$invoke(), 'non-admin rejected');
$GLOBALS['admin']=true;
$GLOBALS['logged_in']=false;
verify(!$invoke(), 'anonymous rejected');
// A verified new build must retain a narrow recovery/diagnostic lane
// when skills or cached native update offer is pending independently.
$GLOBALS['logged_in']=true; $GLOBALS['admin']=true;
$stale_grace=array('active'=>true, 'historical_mcp_read_safe'=>false,
  'persisted_checkpoint_pending'=>true, 'state'=>'pending_manual_resume',
  'resume_blocker'=>'mad4b_automation_automation_kill_switch',
  'exact_runtime_identity_match'=>false,
  'quiet_period_active'=>false, 'checkpoint_age_seconds'=>3600,
  'target_identity'=>array('source_commit_sha'=>str_repeat('a',40)),
  'current_identity'=>array('source_commit_sha'=>str_repeat('b',40),
    'build_fingerprint'=>str_repeat('c',64),
    'package_manifest_digest'=>str_repeat('d',64)));
$guarded = function($tool,$grace)use($method) {
    $rpc=array('method'=>'tools/call','params'=>array('name'=>$tool,'arguments'=>array()));
    return $method->invoke(null,new FixtureRequest($rpc),$grace,true);
};
verify($guarded('mad4b-site-info',$stale_grace),'current certified build admits read when auxiliary gates pending');
$owner_rpc=array('method'=>'tools/call','params'=>array('name'=>'mad4b-enrollment-execute',
  'arguments'=>array('operation_id'=>$owner,
    'expected_registration_digest'=>str_repeat('a',64),
    'expected_dispatch_policy_digest'=>str_repeat('b',64),
    'expected_input_schema_sha256'=>str_repeat('c',64),
    'input'=>$owner_input)));
verify($method->invoke(null,new FixtureRequest($owner_rpc),$stale_grace,true),
  'reviewed owner flow must remain available despite independent skills readiness');
verify(!$guarded('mad4b-content-create-post',$stale_grace),'content writes stay blocked');
$changed=$stale_grace;
$changed['current_identity']['source_commit_sha']=str_repeat('9',40);
verify(!$guarded('mad4b-site-info',$changed),'runtime manifest mismatch fails closed');
$changed=$stale_grace; $changed['state']='blocked';
verify(!$guarded('mad4b-site-info',$changed),'blocked checkpoint fails closed');
$changed=$stale_grace; $changed['quiet_period_active']=true;
verify(!$guarded('mad4b-site-info',$changed),'post-update quiet period remains guarded');
MAD4B_SCP_Live_Acceptance_Observer::$match=false;
verify(!$guarded('mad4b-site-info',$stale_grace),'tampered package fails closed');
MAD4B_SCP_Live_Acceptance_Observer::$match=true;
MAD4B_SCP_Environment::$env='production';
verify(!$guarded('mad4b-site-info',$stale_grace),'production has no maintenance exception');
MAD4B_SCP_Environment::$env='staging';
echo "HISTORICAL_OWNER_ATTESTATION_TRANSPORT: PASS\n";
