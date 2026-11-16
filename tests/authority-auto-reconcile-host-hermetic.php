<?php
/**
 * Executes original Host CLI reconciliation code with in-memory authority
 * dependencies. No WordPress site, real grants, SSH, database or network.
 */
define('ABSPATH', __DIR__ . '/');
define('WP_CLI', true);
$GLOBALS['qa_callbacks']=array();
$GLOBALS['qa_events']=array();
$GLOBALS['qa_options']=array();
class WP_Error {
    private $code; private $msg; private $data;
    public function __construct($code, $msg='', $data=null) {
        $this->code=$code; $this->msg=$msg; $this->data=$data;
    }
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->msg;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function get_current_user_id(){return $GLOBALS['qa_uid'];}
function add_action($hook,$handler,$priority=10,$args=1){$GLOBALS['qa_callbacks'][$hook]=$handler;}
function wp_next_scheduled($hook){return false;}
function wp_schedule_event($time,$repeat,$hook){$GLOBALS['qa_events'][]=$hook;return true;}
function wp_schedule_single_event($time,$hook){$GLOBALS['qa_events'][]=$hook;return true;}
function get_option($key,$default=''){return $GLOBALS['qa_options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['qa_options'][$key]=$value;return true;}
function do_action($name,$value=array()){$GLOBALS['qa_events'][]=$name;}
class WP_CLI {
    public static $commands=array();
    public static function add_command($name,$handler){self::$commands[$name]=$handler;}
    public static function error($error){throw new RuntimeException($error);}
    public static function line($line){$GLOBALS['qa_cli_lines'][]=$line;}
}
class MAD4B_SCP_Site_Profile {
    public static function configured(){return true;}
    public static function origin_enrolled(){return true;}
    public static function site_urls_match_enrollment(){return true;}
    public static function current_environment(){return $GLOBALS['qa_env'];}
    public static function site_uuid(){return '11111111-1111-4111-8111-111111111111';}
    public static function oauth_user_ids(){return $GLOBALS['qa_owners'];}
}
class MAD4B_SCP_Live_Acceptance_Observer {
    public static function build_provenance_status(){
        return array('manifest_valid'=>true,'runtime_manifest_match'=>true,
          'stale'=>false,'provenance_mismatch'=>false,'source_commit_sha'=>str_repeat('a',40),
          'build_fingerprint'=>str_repeat('b',64));
    }
}
class MAD4B_SCP_Staging_Write_Authority {
    public static function reconciliation_plan(){
        ++$GLOBALS['qa_plan_count'];
        if($GLOBALS['qa_after_readback_fail'] && $GLOBALS['qa_revoke_count']>0)
            return new WP_Error('qa_final_readback_unavailable');
        $items=$GLOBALS['qa_plan_rows']===null
            ?$GLOBALS['qa_grants']:$GLOBALS['qa_plan_rows'];
        $rows=array();
        foreach($items as $g) {
            $rows[]=array('id'=>$g['id'],'environment'=>$g['environment'],
                'provider'=>$g['provider'],'ability'=>$g['ability_name'],
                'reason'=>'stale_allow_unreviewed');
        }
        return array('agent_public_id'=>'test-owner-agent',
            'persisted_grant_records_fingerprint'=>hash('sha256',json_encode($items)),
            'unreviewed_stale_allow_grants'=>$rows, 'exact_grants_missing'=>array(),
            'subject_preflight_blockers'=>array(), 'candidate_binding'=>array('match'=>true),
            'effective_ready'=>true);
    }
}
class MAD4B_SCP_CLI_Execution_Context {
    public static function resolve($operation,$apply=false){
        $origin='https://tours.example.invalid';
        if($operation==='authority.reconcile')
            return array('read_context_verified'=>true,'host_site_binding_verified'=>true,
              'blockers'=>array(),'canonical_origin'=>$origin);
        if($operation==='authority.apply' && $apply)
            return array('local_operation_authorized'=>$GLOBALS['qa_apply'],
              'host_site_binding_verified'=>true,'blockers'=>array(),
              'canonical_origin'=>$GLOBALS['qa_origin_drift']?'https://foreign.invalid':$origin);
        return array('blockers'=>array('unknown_host_operation'));
    }
}
class MAD4B_SCP_Agent_Registry {
    public static function get_agent_by_public_id($public){
        if($public!=='test-owner-agent')return null;
        return array('id'=>7,'public_id'=>$public,'slug'=>'chatgpt-governed-write',
            'status'=>'enabled','environment'=>'staging','wp_user_id'=>22);
    }
    public static function subject_binding($type,$fingerprint){return $GLOBALS['qa_binding'];}
    public static function bind_subject($agent,$type,$fingerprint,$label){
        ++$GLOBALS['qa_bind_count'];
        $GLOBALS['qa_binding']=array('agent_id'=>7,'status'=>'enabled');
        return true;
    }
    public static function grants_for_agent($agent,$server){
        if($agent!==7||$server!=='mad4b-write')throw new RuntimeException('foreign grant scope');
        return $GLOBALS['qa_grants'];
    }
    public static function revoke_allow_grant_by_id($agent,$id,$server,array $expected=array()){
        ++$GLOBALS['qa_revoke_count'];
        $GLOBALS['qa_revoke_expected'][]=$expected;
        if($GLOBALS['qa_fail_revoke_at']===$GLOBALS['qa_revoke_count'])
            return new WP_Error('qa_concurrent_grant_revision','Simulated conditional DELETE lost CAS');
        foreach($GLOBALS['qa_grants'] as $index=>$g){
            if($g['id']===$id && $agent==='test-owner-agent' && $server==='mad4b-write'
                && $g['ability_name']===$expected['ability_name']
                && $g['environment']===$expected['environment']
                && $g['provider']===$expected['provider']){
                unset($GLOBALS['qa_grants'][$index]);return true;
            }
        }
        return new WP_Error('qa_grant_cas_missing');
    }
}
class MAD4B_SCP_Execution_Authority {
    public static function resolve($operation){
        if(!$GLOBALS['qa_actor'])return new WP_Error('qa_actor_denied');
        return array('transport'=>'host_cli','registry_bound'=>true,
            'wp_user_id'=>0,'registered_host_agent_id'=>7,
            'subject_fingerprint'=>hash('sha256',"mad4b.host-operator.v1\0".
                '11111111-1111-4111-8111-111111111111'."\0staging\0https://tours.example.invalid"));
    }
}
class MAD4B_SCP_Audit {
    public static function storage_status(){
        return array('ready'=>$GLOBALS['qa_audit_ready'],
          'retention_mode'=>'append_only_no_automatic_deletion');
    }
    public static function record($event,$data,$status='ok'){
        if($GLOBALS['qa_fail_audit_at']===count($GLOBALS['qa_intents'])+1)
            return new WP_Error('qa_audit_intent_failed');
        $GLOBALS['qa_intents'][]=array($event,$data);
        return true;
    }
}
function qa_grant($id,$env,$ability) {
    return array('id'=>$id,'server_id'=>'mad4b-write','effect'=>'allow',
        'ability_name'=>$ability,'provider'=>'core','environment'=>$env);
}
function qa_reset(){
    $GLOBALS['qa_env']='staging';
    $GLOBALS['qa_uid']=0;$GLOBALS['qa_owners']=array(22);
    $GLOBALS['qa_apply']=true;$GLOBALS['qa_origin_drift']=false;
    $GLOBALS['qa_actor']=true;$GLOBALS['qa_audit_ready']=true;
    $GLOBALS['qa_binding']=array('agent_id'=>7,'status'=>'enabled');
    $GLOBALS['qa_bind_count']=0;$GLOBALS['qa_revoke_count']=0;
    $GLOBALS['qa_plan_count']=0;$GLOBALS['qa_fail_revoke_at']=0;
    $GLOBALS['qa_fail_audit_at']=0;$GLOBALS['qa_after_readback_fail']=false;
    $GLOBALS['qa_grants']=array(qa_grant(11,'production','mad4b/old-prod'),
        qa_grant(12,'local','mad4b/old-local'),qa_grant(13,'staging','mad4b/current'));
    $GLOBALS['qa_plan_rows']=null;$GLOBALS['qa_intents']=array();
    $GLOBALS['qa_revoke_expected']=array();
}
function qa_check($ok,$msg) {
    if(!$ok)throw new RuntimeException('FAIL HOST RECONCILIATION: '.$msg);
}
function qa_error($res,$code) {
    qa_check(is_wp_error($res)&&$res->get_error_code()===$code,
        'Expected denied '.$code);
}
function qa_recovery($res,$expected_ids) {
    qa_check(is_wp_error($res),'Expected uncertain WordPress error');
    $data=$res->get_error_data();
    qa_check(is_array($data) && $data['recovery_required']===true
        && $data['reconciliation_required']===true
        && $data['blind_retry_allowed']===false
        && $data['retired_stale_grant_ids']===$expected_ids
        && $data['production_mutation']===false
        && strlen($data['plan_sha256'])===64,
        'Partial mutation was not reconciled fail-closed');
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-authority-auto-reconciliation.php';
$c='MAD4B_SCP_Authority_Auto_Reconciliation';
qa_check(isset(WP_CLI::$commands['mad4b authority reconcile-safe']),'CLI owner was not registered');
// Even absent human OAuth enrollment, only the existing first-party
// registered Host NHI and fully reviewed canonical target agent matter.
qa_reset();
$GLOBALS['qa_owners']=array();
$host_independent=$c::reconcile_safe();
qa_check(is_array($host_independent),'Host should not impersonate a WordPress owner to reconcile existing scoped grants');
qa_reset();
$GLOBALS['qa_owners']=array(22,24);
$host_multi=$c::reconcile_safe();
qa_check(is_array($host_multi),'Multiple site OAuth owners do not change registered Host NHI authority');
qa_reset();
$GLOBALS['qa_env']='production';
qa_error($c::reconcile_safe(),'mad4b_auto_reconcile_site_denied');
qa_check($GLOBALS['qa_revoke_count']===0 && $GLOBALS['qa_bind_count']===0,'Production may not mutate');
qa_reset();
$GLOBALS['qa_uid']=1;
qa_error($c::reconcile_safe(),'mad4b_auto_reconcile_host_denied');
qa_check($GLOBALS['qa_bind_count']===0,'Impersonating WP-CLI may not bind');
qa_reset();
$GLOBALS['qa_apply']=false;
qa_error($c::reconcile_safe(),'mad4b_auto_reconcile_apply_denied');
qa_check($GLOBALS['qa_bind_count']===0,'Read-only host may not create write binding');
qa_reset();
$GLOBALS['qa_origin_drift']=true;
qa_error($c::reconcile_safe(),'mad4b_auto_reconcile_apply_denied');
qa_check($GLOBALS['qa_bind_count']===0,'Host origin drift must deny before binding');
qa_reset();
$GLOBALS['qa_audit_ready']=false;
qa_error($c::reconcile_safe(),'mad4b_auto_reconcile_audit_denied');
qa_check($GLOBALS['qa_bind_count']===0,'Audit unavailable must deny before binding');
qa_reset();
$GLOBALS['qa_binding']=array('agent_id'=>99,'status'=>'disabled');
qa_error($c::reconcile_safe(),'mad4b_auto_reconcile_host_conflict');
qa_check($GLOBALS['qa_bind_count']===0,'Disabled foreign ownership must still deny');
qa_reset();
$GLOBALS['qa_plan_rows']=array(qa_grant(11,'production','mad4b/old-prod'),
    qa_grant(12,'local','mad4b/inconsistent'),qa_grant(13,'staging','mad4b/current'));
qa_error($c::reconcile_safe(),'mad4b_auto_reconcile_grant_changed');
qa_check($GLOBALS['qa_revoke_count']===0,'All candidate identities must be preflighted before first revoke');
qa_reset();
$res=$c::reconcile_safe();
qa_check(is_array($res)&&$res['retired_stale_grant_ids']===array(11,12)
    && $res['mutation_performed']===true && $res['production_mutation']===false,
    'Original host retirement failed exact bounded grants');
qa_check(count($GLOBALS['qa_grants'])===1 && $GLOBALS['qa_grants'][2]['environment']==='staging',
    'Active Staging grant must remain unchanged');
qa_check($GLOBALS['qa_revoke_expected'][0]===array(
        'ability_name'=>'mad4b/old-prod','provider'=>'core','environment'=>'production'),
    'Conditional DELETE must pin exact ability, provider and environment');
qa_reset();
$GLOBALS['qa_fail_revoke_at']=2;
$res=$c::reconcile_safe();
qa_recovery($res,array(11));
qa_check($GLOBALS['qa_revoke_count']===2,'Second CAS conflict was not encountered');
qa_reset();
$GLOBALS['qa_fail_revoke_at']=2;
try {
    $c::cli_reconcile(array(),array());
    throw new RuntimeException('CLI returned success on uncertain partial mutation');
} catch(RuntimeException $e) {
    qa_check($e->getMessage()==='qa_concurrent_grant_revision',
        'CLI nonzero exit must preserve original error code');
}
$cli=json_decode(end($GLOBALS['qa_cli_lines']),true);
qa_check($cli['state']==='RECONCILIATION_REQUIRED'
    && $cli['retired_stale_grant_ids']===array(11)
    && $cli['blind_retry_allowed']===false,
    'CLI must output explicit structured non-secret recovery receipt');
qa_reset();
$GLOBALS['qa_binding']=null;
$GLOBALS['qa_actor']=false;
$res=$c::reconcile_safe();
qa_recovery($res,array());
qa_check($GLOBALS['qa_bind_count']===1 && $GLOBALS['qa_revoke_count']===0,
    'New binding followed by denied actor must request independent readback');
qa_reset();
$GLOBALS['qa_binding']=null;
$GLOBALS['qa_grants']=array(qa_grant(13,'staging','mad4b/current'));
$res=$c::reconcile_safe();
qa_check(is_array($res)&&$res['mutation_performed']===true
    && $GLOBALS['qa_bind_count']===1 && $GLOBALS['qa_revoke_count']===0,
    'Host binding itself must count as a mutation');
qa_reset();
$GLOBALS['qa_after_readback_fail']=true;
qa_recovery($c::reconcile_safe(),array(11,12));
qa_reset();
$GLOBALS['qa_fail_audit_at']=2;
qa_recovery($c::reconcile_safe(),array(11));
qa_reset();
$c::scheduled_inspect();
qa_check($GLOBALS['qa_revoke_count']===0 && $GLOBALS['qa_bind_count']===0,
    'Cron inspection must not mutate grants or host subjects');
echo "PASS: AUTHORITY_AUTO_RECONCILE_HOST 15 host and error path scenarios\n";
