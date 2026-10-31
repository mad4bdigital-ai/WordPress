<?php
define('ABSPATH', __DIR__);
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=array()) {$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$s));}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
class MAD4B_Provider_Qualification_Bridge {
    public static $assessment=array();
    public static function allowed($input){return true;}
    public static function plan($input){return self::$assessment;}
}
class MAD4B_SCP_Live_Acceptance_Observer {
    public static $stale=false;
    public static function build_provenance_status(){
        return array('manifest_valid'=>true,'runtime_manifest_match'=>!self::$stale,
            'stale'=>self::$stale, 'source_commit_sha'=>str_repeat('a',40),
            'build_fingerprint'=>str_repeat('b',64));
    }
}
class MAD4B_SCP_Provider_Behavioral_Recertification {
    public static $rejected=false;
    public static function validate_context($input){
        if (self::$rejected) return new WP_Error('native_permission_denied');
        return array('ready'=>true);
    }
}
require dirname(__DIR__).'/includes/class-mad4b-provider-native-handoff.php';
function assert_case($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}}
$cap=array('risk'=>'bounded_write','reversible'=>true,
    'structural_compatible'=>true,'artifact_identity_bound'=>true,
    'native_write_eligible'=>false,'capability_id'=>'seo_meta.bounded-write',
    'artifact_fingerprint'=>str_repeat('c',64),
    'capability_contract_digest'=>str_repeat('d',64));
MAD4B_Provider_Qualification_Bridge::$assessment=array(
    'matched_count'=>1,'provider_id'=>'rank_math',
    'native_provenance_state'=>'exact_current',
    'capabilities'=>array($cap));
$target=array('post_id'=>123,'title'=>'qualification probe fixture');
$input=array('provider_id'=>'rank_math','ability'=>'seo/update-meta','target_input'=>$target);
$x=MAD4B_Provider_Native_Handoff::plan($input);
assert_case(is_array($x) && ($x['state']??'')==='READY_FOR_NATIVE_EXECUTOR','valid handoff');
assert_case($x['approval_ability']==='mad4b/provider-behavioral-recertify' && $x['approval_provider']==='core','native owner');
assert_case(!$x['approval_ticket_created'] && !$x['mutation_performed'] && !$x['probe_executed'],'does not forge tickets or writes');
assert_case($x['approval_input']['expected_candidate_sha']===str_repeat('a',40),'current commit used');
assert_case($x['approval_input']['target_input']===$target,'exact target preserved');
$x=MAD4B_Provider_Native_Handoff::plan(array('ability'=>'seo/update-meta'));
assert_case(is_wp_error($x) && $x->get_error_code()==='mad4b_handoff_input_invalid','missing target denied');
MAD4B_Provider_Qualification_Bridge::$assessment['capabilities'][0]['risk']='high_risk_write';
$x=MAD4B_Provider_Native_Handoff::plan($input);
assert_case(is_wp_error($x) && $x->get_error_code()==='mad4b_handoff_ineligible','high risk not auto certified');
MAD4B_Provider_Qualification_Bridge::$assessment['capabilities'][0]=$cap;
MAD4B_SCP_Live_Acceptance_Observer::$stale=true;
$x=MAD4B_Provider_Native_Handoff::plan($input);
assert_case(is_wp_error($x) && $x->get_error_code()==='mad4b_handoff_candidate_invalid','stale candidate fails closed');
MAD4B_SCP_Live_Acceptance_Observer::$stale=false;
MAD4B_SCP_Provider_Behavioral_Recertification::$rejected=true;
$x=MAD4B_Provider_Native_Handoff::plan($input);
assert_case(is_wp_error($x) && $x->get_error_code()==='mad4b_handoff_preflight_blocked','native denial propagated');
echo "PASS: native handoff contracts, no synthetic authorization (9 cases)\n";
