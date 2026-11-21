<?php
/**
 * Original ContentJob service registration and no-post-publish source contract.
 * No database, jobs, grants, external providers or network.
 */
define('ABSPATH', __DIR__.'/');
$GLOBALS['job_abilities']=array();
function wp_register_ability($name,$cfg){
    if(isset($GLOBALS['job_abilities'][$name]))
        throw new RuntimeException('Duplicate WordPress Ability: '.$name);
    $GLOBALS['job_abilities'][$name]=$cfg;
}
function wp_has_ability($name){return isset($GLOBALS['job_abilities'][$name]);}
function add_action($event,$callback,$priority=10){}
class MAD4B_SCP_Content_Workflow_Modes {
    public static function choices(){return array('autonomous','review_gated');}
}
class MAD4B_SCP_Identifiers {}
class MAD4B_SCP_Operational_Integrity {}
class MAD4B_SCP_Content_Relationship_Graph {}
class MAD4B_SCP_Content_Relationship_Writeback {}
class MAD4B_SCP_Content_Job_Structural_QA {}
class MAD4B_SCP_Structural_Only_CWA_Adapter {}
class MAD4B_SCP_Content_Job_Group_Orchestrator {}
class MAD4B_SCP_Elementor_Native_MCP_Awareness {}
class MAD4B_SCP_MCP_Provider_Readiness_Matrix {}
function job_assert($ok,$msg){
    if(!$ok){fwrite(STDERR,"FAIL CONTENTJOB REGISTRATION: ".$msg."\n");exit(1);}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-jobs.php';
$c='MAD4B_SCP_Content_Jobs';
$c::register_abilities();
$expected=array(
 'mad4b/content-job-elementor-executor-preflight'=>true,
 'mad4b/content-job-group-apply'=>false,
 'mad4b/content-job-group-plan'=>true,
 'mad4b/content-job-mcp-provider-preflight'=>true,
 'mad4b/content-job-relationship-plan'=>true,
 'mad4b/content-job-structural-cwa-apply'=>false,
 'mad4b/content-job-structural-qa-attest'=>false,
 'mad4b/content-job-structural-qa-plan'=>true,
 'mad4b/content-job-typed-relation-write-apply'=>false,
 'mad4b/content-job-typed-relation-write-plan'=>true,
 'mad4b/content-workflow-modes'=>true,
);
foreach($expected as $name=>$read) {
  job_assert(isset($GLOBALS['job_abilities'][$name]),'Missing exact original Ability '.$name);
  $cfg=$GLOBALS['job_abilities'][$name];
  job_assert(($cfg['category']??'')===($read?'mad4b-read':'mad4b-write')
    && ($cfg['meta']['mcp']['surface']??'')===($read?'read':'write')
    && ($cfg['meta']['annotations']['readonly']??null)===$read
    && ($cfg['meta']['annotations']['destructive']??null)===!$read
    && empty($cfg['meta']['public']) && empty($cfg['meta']['mcp']['public'])
    && ($cfg['permission_callback']??null)===array('MAD4B_SCP_Policy',$read?'can_read':'can_mutate')
    && ($cfg['input_schema']['additionalProperties']??null)===false,
    'Original read/write/permission/schema mismatch '.$name);
}
job_assert(count($GLOBALS['job_abilities'])===18,
    'Unexpected change to native ContentJob interface inventory');
$apply=$GLOBALS['job_abilities']['mad4b/content-job-group-apply']['input_schema'];
job_assert(($apply['properties']['target_ids']['maxItems']??null)===8
    && in_array('expected_snapshot_sha256',$apply['required'],true)
    && in_array('group_id',$apply['required'],true)
    && in_array('target_ids',$apply['required'],true),
    'Native group write must require exact snapshot and bounded targets');
$relation=$GLOBALS['job_abilities']['mad4b/content-job-typed-relation-write-apply']['input_schema'];
job_assert(in_array('expected_source_sha256',$relation['required'],true)
    && $relation['properties']['expected_source_sha256']['pattern']==='^[a-f0-9]{64}$',
    'Typed relationship write must bind the source digest');
$hold=$c::structural_cwa_apply(array('job_id'=>'invalid'));
job_assert(is_array($hold) && $hold['state']==='HOLD'
    && $hold['reason']==='invalid_original_structural_job',
    'Structural write must reject invalid original ContentJob identity');
$states=$c::states();
job_assert(in_array('COMPLETED',$states,true)
    && in_array('CANCELLED',$states,true)
    && !in_array('PUBLISHED_UNVERIFIED',$states,true),
    'Job state is not a publishing permission');
echo "PASS: CONTENT_JOB_REGISTRATION_GOVERNED 18 WordPress Abilities, 11 new interfaces\n";
