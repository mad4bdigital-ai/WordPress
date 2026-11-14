<?php
/**
 * Isolated native Control Plane test router reducer, no WordPress DB/net/Host.
 * Actual Selected HEAD Ability remains owned by Selected_Head_Update.
 */
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__).'/includes/class-mad4b-scp-control-plane-test-router.php';
$c='MAD4B_SCP_Control_Plane_Test_Router';
function verify_route($ok,$reason) {
    if(!$ok)throw new RuntimeException('FAIL TEST ROUTE: '.$reason);
}
$production=$c::reduce(array(),array(),'production');
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
echo "CONTROL_PLANE_SELECTED_HEAD_ROUTE_HERMETIC: PASS\n";
