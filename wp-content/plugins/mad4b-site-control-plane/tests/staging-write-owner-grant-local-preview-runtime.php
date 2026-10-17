<?php
/** WordPress-cookie preview can read, but NEVER obtain OAuth execution authority. */
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code; private $data;
    public function __construct($code,$message='',$data=array()){ $this->code=$code;$this->data=$data; }
    public function get_error_code(){return $this->code;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($c){return $GLOBALS['is_owner_admin'] && $c==='manage_options';}
function is_admin(){return $GLOBALS['admin_route'];}
function is_user_logged_in(){return $GLOBALS['logged_in'];}
function get_current_user_id(){return 23;}
function sanitize_key($s){return strtolower((string)$s);}
function wp_parse_url($v,$component){return parse_url($v,$component);}
function check_preview($pass,$why){if(!$pass){fwrite(STDERR,"FAIL ".$why."\n");exit(1);}}
class MAD4B_SCP_Staging_Write_Owner_Intent {
    public static function owner(){return $GLOBALS['explicit_owner'];}
}
class MAD4B_SCP_Site_Profile {
    public static function configured(){return true;}
    public static function current_environment(){return $GLOBALS['environment'];}
    public static function origin_enrolled(){return true;}
    public static function site_urls_match_enrollment(){return true;}
    public static function write_enabled(){return true;}
    public static function current_origin(){return 'https://staging.example.test';}
    public static function agent_slug(){return 'chatgpt-governed-write';}
    public static function user_is_enrolled($id){return $id===23;}
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE='mad4b:authority:step-up';
    public static function verified_bearer_active(){return false;}
    public static function verified_bearer_has_scope($s){return false;}
}
class MAD4B_SCP_Agent_Registry {
    public static function get_agent_by_slug($slug){
        check_preview($slug==='chatgpt-governed-write','registry_exact_slug');
        return $GLOBALS['agent'];
    }
    public static function resolve_agent($identity) {
        check_preview(($identity['auth_method'] ?? '')==='oauth2_bearer','oauth_identity_verified');
        return isset($GLOBALS['resolved_agent']) ? $GLOBALS['resolved_agent'] : $GLOBALS['agent'];
    }
}
class MAD4B_SCP_Identity_Context {
    public static function current() {
        return array('authenticated'=>true,'subject_type'=>'oauth','auth_method'=>'oauth2_bearer');
    }
}
class MAD4B_SCP_Schema {
    public static function critical_ready(){if(!empty($GLOBALS['simulate_throw'])) throw new RuntimeException('schema_read_error'); return false;}
}
class MAD4B_SCP_Policy {public static function can_breakglass(){return false;}}
class MAD4B_SCP_Governed_Runtime_Gates {public static function raw_sql_breakglass_enabled(){return false;}}
$GLOBALS['environment']='staging';$GLOBALS['is_owner_admin']=true;$GLOBALS['admin_route']=true;
$GLOBALS['logged_in']=true;$GLOBALS['explicit_owner']=true;$GLOBALS['simulate_throw']=false;
$GLOBALS['agent']=array('id'=>42,'slug'=>'chatgpt-governed-write','status'=>'enabled',
    'environment'=>'staging','wp_user_id'=>23,'public_id'=>'agent-42');

require dirname(__DIR__) . '/includes/class-mad4b-scp-staging-write-grant-reconciliation-plan.php';
require dirname(__DIR__) . '/includes/class-mad4b-scp-staging-write-authority-convergence.php';
$grant='MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan';
$conv='MAD4B_SCP_Staging_Write_Authority_Convergence';

$remote=$grant::can_plan();
check_preview(is_wp_error($remote)&&$remote->get_error_code()==='mad4b_grant_reconcile_plan_bearer_required','normal_grant_remote_needs_bearer');
$local=$grant::plan_for_local_owner();
check_preview(is_wp_error($local)&&$local->get_error_code()==='mad4b_grant_reconcile_plan_schema_unavailable','local_preview_passes_bearer_gate_then_fails_real_schema_gate');
check_preview(is_wp_error($grant::can_plan())&&$grant::can_plan()->get_error_code()==='mad4b_grant_reconcile_plan_bearer_required','no_authority_leak_after_preview');

$ref=new ReflectionClass($grant);$flag=$ref->getProperty('local_owner_preview');$flag->setAccessible(true);
$agent_method=$ref->getMethod('current_agent');$agent_method->setAccessible(true);
$flag->setValue(null,true);
$agent=$agent_method->invoke(null);
check_preview(is_array($agent)&&$agent['id']===42,'exact_local_agent_record');
$GLOBALS['agent']['wp_user_id']=22;
$bad=$agent_method->invoke(null);
check_preview(is_wp_error($bad)&&$bad->get_error_code()==='mad4b_grant_local_preview_agent_not_exact','other_owner_agent_denied');
$GLOBALS['agent']['wp_user_id']=23;
$GLOBALS['agent']['environment']='production';
$bad=$agent_method->invoke(null);
check_preview(is_wp_error($bad)&&$bad->get_error_code()==='mad4b_grant_local_preview_agent_not_exact','other_environment_agent_denied');
$GLOBALS['agent']['environment']='staging';
$local_agent=$agent_method->invoke(null);
$flag->setValue(null,false);
$remote_agent=$agent_method->invoke(null);
check_preview(is_array($remote_agent) && $remote_agent===$local_agent,
    'same enrolled agent registry record used by admin and oauth planning');
$GLOBALS['resolved_agent']=$GLOBALS['agent'];
$GLOBALS['resolved_agent']['id']=99;
$mismatch=$agent_method->invoke(null);
check_preview(is_wp_error($mismatch)
    && $mismatch->get_error_code()==='mad4b_grant_reconcile_plan_subject_agent_mismatch',
    'different OAuth subject agent denied even with same slug and owner');
$GLOBALS['resolved_agent']['id']=42;
$GLOBALS['resolved_agent']['public_id']='different-public-agent';
$mismatch=$agent_method->invoke(null);
check_preview(is_wp_error($mismatch)
    && $mismatch->get_error_code()==='mad4b_grant_reconcile_plan_subject_agent_mismatch',
    'same ID but different OAuth public agent identity denied');
$GLOBALS['resolved_agent']['public_id']='agent-42';
$GLOBALS['resolved_agent']['status']='disabled';
$mismatch=$agent_method->invoke(null);
check_preview(is_wp_error($mismatch)
    && $mismatch->get_error_code()==='mad4b_grant_reconcile_plan_subject_agent_mismatch',
    'disabled OAuth-resolved agent denied');
unset($GLOBALS['resolved_agent']);
$remote_agent=$agent_method->invoke(null);
check_preview(is_array($remote_agent) && $remote_agent===$local_agent,
    'verified OAuth recovers exact canonical evidence after subject match');

$GLOBALS['simulate_throw']=true;
try{$grant::plan_for_local_owner();check_preview(false,'expected_exception');}
catch(RuntimeException $e){}
$GLOBALS['simulate_throw']=false;
check_preview(is_wp_error($grant::can_plan())&&$grant::can_plan()->get_error_code()==='mad4b_grant_reconcile_plan_bearer_required','guard_reset_after_exception');

$conv_remote=$conv::can_access();
check_preview(is_wp_error($conv_remote)&&$conv_remote->get_error_code()==='mad4b_staging_write_authority_bearer_required','convergence_remote_needs_bearer');
$conv_local=$conv::plan_for_local_owner();
check_preview(is_wp_error($conv_local)&&$conv_local->get_error_code()==='mad4b_staging_write_authority_provenance_unavailable','convergence_read_bypasses_bearer_only_when_owner');
check_preview(is_wp_error($conv::can_access())&&$conv::can_access()->get_error_code()==='mad4b_staging_write_authority_bearer_required','convergence_reset');
$GLOBALS['explicit_owner']=false;
check_preview(is_wp_error($grant::plan_for_local_owner()),'unenrolled_denied');
$GLOBALS['explicit_owner']=true;$GLOBALS['admin_route']=false;
check_preview(is_wp_error($grant::plan_for_local_owner()),'non_admin_route_denied');
$GLOBALS['admin_route']=true;$GLOBALS['environment']='production';
check_preview(is_wp_error($grant::plan_for_local_owner())&&$grant::plan_for_local_owner()->get_error_code()==='mad4b_grant_local_preview_staging_only','production_denied');
echo "STAGING_WRITE_OWNER_GRANT_PREVIEW_RUNTIME_PASS\n";
