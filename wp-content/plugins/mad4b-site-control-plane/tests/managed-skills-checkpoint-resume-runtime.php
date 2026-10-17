<?php
define('ABSPATH', __DIR__ . '/');
function add_action( $tag, $callback, $priority = 10, $accepted = 1 ) { return true; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower((string)$s) ); }
function get_option( $key, $fallback = false ) {
    return array_key_exists($key, $GLOBALS['test_skill_options']) ? $GLOBALS['test_skill_options'][$key] : $fallback;
}
class MAD4B_SCP_Live_Acceptance_Observer {
    public static function build_provenance_identity_status() {
        return $GLOBALS['build_identity'];
    }
}
require dirname(__DIR__) . '/includes/class-mad4b-scp-remote-operation-parity.php';
function assert_ckpt($v,$s) { if (!$v) { fwrite(STDERR,"FAIL ".$s."\n");exit(1); } }
$identity=array('source_commit_sha'=>str_repeat('a',40),
    'build_fingerprint'=>str_repeat('b',64),'package_manifest_digest'=>str_repeat('c',64));
$checkpoint=array('status'=>'running','operation_id'=>'3c18d89c-e1d4-4cf8-9740-310d7c95e3bd',
    'stage'=>'seed_reconciliation','seed_cursor'=>6,'expected_identity'=>$identity);
$GLOBALS['build_identity']=$identity;
$GLOBALS['test_skill_options']=array('mad4b_scp_remote_skills_reconciliation_state_v1'=>$checkpoint);
$policy=MAD4B_SCP_Remote_Operation_Parity::skills_checkpoint_resume_policy($checkpoint,true,false);
assert_ckpt($policy['state']==='awaiting_exact_resume','normal pause recognized');
assert_ckpt($policy['resume_eligible_after_fresh_permission'] && !$policy['blind_retry_allowed'],'explicit authorized continuation only');
assert_ckpt($policy['next_action']==='resume_same_operation_after_exact_authorization','next step');
$stale=MAD4B_SCP_Remote_Operation_Parity::skills_checkpoint_resume_policy($checkpoint,false,false);
assert_ckpt($stale['state']==='stale_build_checkpoint' && !$stale['resume_eligible_after_fresh_permission'],'never resume stale identity');
$locked=MAD4B_SCP_Remote_Operation_Parity::skills_checkpoint_resume_policy($checkpoint,true,true);
assert_ckpt($locked['state']==='running' && !$locked['resume_eligible_after_fresh_permission'] && $locked['next_action']==='observe_current_attempt','do not race live lease');
$bad=$checkpoint;$bad['operation_id']='bad';$bad['stage']='invalid';
assert_ckpt(!MAD4B_SCP_Remote_Operation_Parity::skills_checkpoint_resume_policy($bad,true,false)['resume_eligible_after_fresh_permission'],'unverified checkpoint cannot resume');
$completed=$checkpoint;$completed['status']='completed';$completed['certification_ready']=true;
assert_ckpt(!MAD4B_SCP_Remote_Operation_Parity::skills_checkpoint_resume_policy($completed,true,false)['resume_eligible_after_fresh_permission'],'do not repeat completed');
echo "MANAGED_SKILLS_RESUME_POLICY_PASS\n";
