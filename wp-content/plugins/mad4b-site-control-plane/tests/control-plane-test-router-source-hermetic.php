<?php
/**
 * Isolated native Control Plane test router reducer, no WordPress DB/net/Host.
 * Actual Selected HEAD Ability remains owned by Selected_Head_Update.
 */
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__).'/includes/class-mad4b-scp-control-plane-test-router.php';
$GLOBALS['router_registered']=array();
$GLOBALS['router_admin']=false;
function wp_register_ability($name,$config) {
    $GLOBALS['router_registered'][$name]=$config;
}
function wp_has_ability($name) {
    return isset($GLOBALS['router_registered'][$name]);
}
function current_user_can($name) {
    return $name==='manage_options' && $GLOBALS['router_admin'];
}
class MAD4B_SCP_Policy {
    public static $read_allowed=false;
    public static function can_read(){return self::$read_allowed;}
}
class WP_Error {
    private $code;
    public function __construct($code,$description=''){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
$c='MAD4B_SCP_Control_Plane_Test_Router';
function verify_route($ok,$reason) {
    if(!$ok)throw new RuntimeException('FAIL TEST ROUTE: '.$reason);
}
$c::register_ability();
verify_route(array_keys($GLOBALS['router_registered'])===array($c::ABILITY),
    'Original router must register exactly one read-only Ability');
$ability=$GLOBALS['router_registered'][$c::ABILITY];
verify_route(($ability['execute_callback']??null)===array($c,'inspect')
    && ($ability['permission_callback']??null)===array($c,'can_read')
    && ($ability['meta']['mcp']['surface']??'')==='read'
    && ($ability['meta']['annotations']['readonly']??null)===true
    && empty($ability['meta']['mcp']['public'])
    && ($ability['input_schema']['additionalProperties']??null)===false,
    'Read-only route registered with extra permissions or unexpected handler');
verify_route(!$c::can_read(), 'Anonymous router access must be denied');
$GLOBALS['router_admin']=true;
verify_route(!$c::can_read(), 'Admin without native policy read must be denied');
MAD4B_SCP_Policy::$read_allowed=true;
verify_route($c::can_read(), 'Exact native authorized admin read required');
$GLOBALS['router_admin']=false;
verify_route($c::inspect() instanceof WP_Error,
    'Unauthorized inspect must not invoke source update plan');
$GLOBALS['router_admin']=true;

$production=$c::reduce(array(),array(),'production');
verify_route(($production['next_read_ability']??'')==='mad4b/site-profile-status'
    && ($production['readback_complete']??null)===false,
    'Production must direct site identity review, not apply');
verify_route(($production['state']??'')==='environment_blocked'
    && ($production['production_allowed']??null)===false
    && ($production['automatic_mutation_allowed']??null)===false,
    'Production cannot install or auto-apply');
$unsigned=$c::reduce(array('eligible'=>true,'target'=>array(
    'source_commit_sha'=>str_repeat('a',40),
    'archive_sha256'=>str_repeat('b',64),
    'published_from_master'=>false,
    'release_root_trust_verified'=>true,
    'release_verdict_success'=>true,
)),array('eligible'=>true), 'staging');
verify_route(($unsigned['state']??'')==='certified_source_unavailable'
    && array_key_exists('selected_route_id',$unsigned)
    && $unsigned['selected_route_id']===null,
    'Unpublished master candidate cannot qualify');
$target=array('source_commit_sha'=>str_repeat('a',40),
    'archive_sha256'=>str_repeat('b',64),
    'published_from_master'=>true,
    'release_root_trust_verified'=>true,
    'release_verdict_success'=>true);
$blocked=$c::reduce(array('eligible'=>false,'target'=>$target,
    'blockers'=>array('native_release_source_lineage_unverified')),
    array('eligible'=>false,'blockers'=>array('selected_head_opt_in_disabled')),
    'staging');
verify_route(($blocked['state']??'')==='preflight_blocked'
    && ($blocked['next_read_ability']??'')==='mad4b/wordpress-native-candidate-opt-in-plan'
    && ($blocked['automatic_mutation_allowed']??null)===false,
    'Old blocker cannot become an approval');
$selected=array('eligible'=>true,'source'=>array('resolved_sha'=>str_repeat('a',40)),
    'package_identity'=>array('source_commit_sha'=>str_repeat('a',40)),
    'blockers'=>array());
$review=$c::reduce(array('eligible'=>true,'target'=>$target,'blockers'=>array()),
    $selected,'staging');
verify_route(($review['state']??'')==='eligible_for_separate_governed_review'
    && ($review['selected_route_id']??'')==='certified_selected_head'
    && ($review['selected_plan_ability']??'')==='mad4b/control-plane-selected-head-plan'
    && ($review['independent_owner_approval_required']??null)===true
    && ($review['separate_exact_plan_required']??null)===true
    && ($review['permission_reuse_allowed']??null)===false
    && ($review['automatic_mutation_allowed']??null)===false
    && ($review['production_allowed']??null)===false
    && ($review['mutation_performed']??null)===false
    && ($review['runtime_certified']??null)===false,
    'Read-only navigation never certifies selected HEAD or promotes Production');
$other=$selected;
$other['source']['resolved_sha']=str_repeat('c',40);
$drift=$c::reduce(array('eligible'=>false,'target'=>$target),$other,'staging');
verify_route(array_key_exists('selected_route_id',$drift)
    && $drift['selected_route_id']===null
    && ($drift['state']??'')==='preflight_blocked',
    'Unmatched selected source commit remains blocked');
verify_route(($review['routes'][0]['apply_ability']??'')==='mad4b/control-plane-selected-head-apply'
    && ($review['routes'][1]['plan_ability']??'')==='mad4b/control-plane-native-plan'
    && ($review['routes'][1]['apply_ability']??'')==='mad4b/control-plane-native-apply'
    && ($review['next_read_ability']??'')==='mad4b/control-plane-selected-head-plan',
    'Routing instructions may not substitute a different plan or apply source');
verify_route(($blocked['next_read_ability']??'')==='mad4b/wordpress-native-candidate-opt-in-plan'
    && ($unsigned['next_read_ability']??'')==='mad4b/control-plane-update-status',
    'Blocked or unsigned source retains original defensive read direction');
$expected_reads=array('mad4b/control-plane-update-status','mad4b/build-provenance-status',
    'mad4b/session-safe-diagnostics','mad4b/skill-runtime-certification',
    'mad4b/staging-certification-status');
verify_route(($review['acceptance_readback_abilities']??null)===$expected_reads
    && ($review['read_only']??null)===true
    && ($review['independent_owner_approval_required']??null)===true,
    'Native readback references cannot promote to execution authorization');

echo "CONTROL_PLANE_SELECTED_HEAD_ROUTE_HERMETIC: PASS\n";
