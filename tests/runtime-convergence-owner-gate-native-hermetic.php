<?php
/** Original Runtime Convergence source; no WordPress, server or write executor. */
define('ABSPATH', __DIR__ . '/');
function add_action($name, $callback, $priority=10) { return true; }
function sanitize_text_field($value) { return trim(strip_tags((string)$value)); }
class WP_Error { private $code; function __construct($code) {$this->code=$code;} }
final class MAD4B_SCP_Policy {
    public static $mutation = false;
    public static function can_mutate() { return self::$mutation; }
}
final class MAD4B_SCP_Live_Acceptance_Observer {
    public static $manifest=array();
    public static function build_provenance_identity_status() {return self::$manifest;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-runtime-convergence.php';
function native_check($condition,$message) {
    if (!$condition) {fwrite(STDERR, "FAIL: $message\n");exit(1);}
}
$owner = new ReflectionMethod('MAD4B_SCP_Runtime_Convergence', 'superseded_owner_gate_audit_only');
$graph_method = new ReflectionMethod('MAD4B_SCP_Runtime_Convergence', 'superseded_checkpoint_graph_safe');
if (PHP_VERSION_ID < 80100) { $owner->setAccessible(true); $graph_method->setAccessible(true); }
$current = array(
    'version'=>'0.4.0-rc.103', 'source_commit_sha'=>str_repeat('b',40),
    'build_fingerprint'=>str_repeat('c',64),
    'package_manifest_digest'=>str_repeat('d',64),
    'artifact_identity'=>'current-test-build',
);
$checkpoint = array(
    'source'=>'self_update','state'=>'pending_manual_resume',
    'target_identity'=>array(
        'version'=>'0.4.0-rc.102','source_commit_sha'=>str_repeat('a',40),
        'build_fingerprint'=>str_repeat('e',64),
        'package_manifest_digest'=>str_repeat('f',64),
        'artifact_identity'=>'old-build',
    ),
);
$graph = array(
    'state'=>'convergence_required','ready'=>false,'environment'=>'staging',
    'phase_graph_valid'=>true,'current_identity'=>$current,
    'required_blockers'=>array('authority_binding:owner_gate'),
    'checkpoint'=>$checkpoint,
    'checkpoint_operator_state'=>MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state(
        $checkpoint,$current,array('authority_binding:owner_gate')
    ),
    'phases'=>array(
        'deployment'=>array('required'=>true,'state'=>'ready'),
        'schema'=>array('required'=>true,'state'=>'ready'),
        'local_oauth_store'=>array('required'=>true,'state'=>'ready'),
        'managed_skills'=>array('required'=>true,'state'=>'ready'),
        'authority_binding'=>array('required'=>true,'state'=>'owner_gate')
    )
);
$allowed = static function($g) use($owner,$checkpoint,$current) {
    return (bool)$owner->invoke(null,$checkpoint,$current,$g);
};
$release = static function($g,$c=null) use($graph_method,$checkpoint,$current) {
    return (bool)$graph_method->invoke(null,$checkpoint,$c===null?$current:$c,time()-60,$g);
};
native_check($allowed($graph) && $release($graph), 'Only strictly known denied write policy admits read-only historical checkpoint interpretation');
foreach (array(true, null, new WP_Error('failed_authority_evaluation'), 1) as $value) {
    MAD4B_SCP_Policy::$mutation=$value;
    native_check(!$allowed($graph) && !$release($graph),
        'Unknown, failed, or permitted mutation gate must not open historical checkpoint release.');
}
MAD4B_SCP_Policy::$mutation=false;
$mutations=array(
 'not_staging'=>static function(&$g){$g['environment']='production';},
 'invalid_graph'=>static function(&$g){$g['phase_graph_valid']=false;},
 'schema_pending'=>static function(&$g){$g['required_blockers'][]='schema:pending';$g['phases']['schema']['state']='pending';},
 'skills_pending'=>static function(&$g){$g['phases']['managed_skills']['state']='pending';},
 'owner_not_required'=>static function(&$g){$g['phases']['authority_binding']['required']=false;},
 'owner_inactive'=>static function(&$g){$g['phases']['authority_binding']['state']='ready';},
 'replay_old'=>static function(&$g){$g['checkpoint_operator_state']['replay_old_checkpoint_allowed']=true;},
 'identity_drift'=>static function(&$g){$g['current_identity']['source_commit_sha']=str_repeat('7',40);},
 'current_ready'=>static function(&$g){$g['ready']=true;},
 'checkpoint_change'=>static function(&$g){$g['checkpoint']['target_identity']['source_commit_sha']=str_repeat('5',40);},
);
foreach($mutations as $name=>$change) {
  $bad=$graph;$change($bad);
  native_check(!$allowed($bad) && !$release($bad), "Historical owner gate false-positive: $name");
}
$equal=$current;$equal['version']='0.4.0-rc.102';
$equal_graph=$graph;$equal_graph['current_identity']=$equal;
native_check(!$release($equal_graph,$equal), 'Equal versions never bypass live restart');
// The separate MCP-read diagnostic lane may expose exact historical
// managed-Skills diagnostics, but must not convert that into an executable
// full runtime release while owner/write and Skills are still blocked.
$checkpoint_for_read=$checkpoint;
$checkpoint_for_read['resume_blocker']='mad4b_automation_automation_kill_switch';
$skills=$graph;
$skills['checkpoint']=$checkpoint_for_read;
$skills['phases']['managed_skills']['state']='pending';
$skills['required_blockers']=array('managed_skills:pending','authority_binding:owner_gate');
$skills['checkpoint_operator_state']=MAD4B_SCP_Runtime_Convergence::checkpoint_operator_state(
    $checkpoint_for_read,$current,$skills['required_blockers']
);
$read_ref=new ReflectionMethod('MAD4B_SCP_Runtime_Convergence',
    'superseded_checkpoint_mcp_read_safe');
if(PHP_VERSION_ID<80100)$read_ref->setAccessible(true);
native_check($read_ref->invoke(null,$checkpoint_for_read,$current,time()-60,$skills)===true,
    'Exact historical skills diagnostic read is preserved without authorizing mutation');
native_check(!$release($skills),
    'Managed Skills plus owner gate must NEVER pass full restart-grace release');
$skills['phases']['schema']['state']='pending';
native_check($read_ref->invoke(null,$checkpoint_for_read,$current,time()-60,$skills)===false,
    'Independent schema drift denies even historical MCP-only reads');
$skills['phases']['schema']['state']='ready';
$skills['checkpoint_operator_state']['replay_old_checkpoint_allowed']=true;
native_check($read_ref->invoke(null,$checkpoint_for_read,$current,time()-60,$skills)===false,
    'Replayable historical checkpoint is not a read grant');

// Newer local build evidence is read-only; it can supersede a previously
// cached target only when the original first-party manifest matches in full.
$build_ref=new ReflectionMethod('MAD4B_SCP_Runtime_Convergence',
    'staging_local_build_supersedes_target');
if(PHP_VERSION_ID<80100)$build_ref->setAccessible(true);
$profile=array('origin_match'=>true,'deployment_binding_match'=>true);
$target=$checkpoint['target_identity'];
MAD4B_SCP_Live_Acceptance_Observer::$manifest=array_merge(
    $current,array('identity_ready'=>true,'manifest_valid'=>true)
);
$local_build=static function($environment,$pf,$identity,$cached)use($build_ref) {
    return (bool)$build_ref->invoke(null,$environment,$pf,$identity,$cached);
};
native_check($local_build('staging',$profile,$current,$target),
    'Exact newer native build may supersede old cached target for read status');
$build_denials=array(
 'production'=>array('environment'=>'production'),
 'no_enrolled_origin'=>array('profile'=>array('origin_match'=>false,'deployment_binding_match'=>true)),
 'no_deployment_binding'=>array('profile'=>array('origin_match'=>true,'deployment_binding_match'=>false)),
 'same_version'=>array('current'=>array_merge($current,array('version'=>$target['version']))),
 'bad_cached_sha'=>array('target'=>array_merge($target,array('source_commit_sha'=>'bad'))),
 'bad_cached_digest'=>array('target'=>array_merge($target,array('package_manifest_digest'=>'bad'))),
);
foreach($build_denials as $label=>$change){
  native_check(!$local_build($change['environment']??'staging',
      $change['profile']??$profile,$change['current']??$current,$change['target']??$target),
      'Native cached target guard rejects '.$label);
}
MAD4B_SCP_Live_Acceptance_Observer::$manifest['build_fingerprint']=str_repeat('0',64);
native_check(!$local_build('staging',$profile,$current,$target),
    'Independent build manifest fingerprint mismatch cannot be ignored.');
echo "PASS: RUNTIME_CONVERGENCE_OWNER_GATE 26 original-source positive and denial cases\n";
