<?php
define('ABSPATH',__DIR__.'/');
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
class WP_Error {
    private $code;
    public function __construct($code,$message=''){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_Content_Jobs {
    public static function get_job($data){
        $m=array(
            'legacy-job'=>array('automation_level'=>'review_gated'),
            'strict-job'=>array('automation_level'=>'strict'),
            'assisted-job'=>array('automation_level'=>'assisted'),
            'autonomous-job'=>array('automation_level'=>'autonomous')
        );
        return isset($m[$data['job_id']])?array('job'=>$m[$data['job_id']]):new WP_Error('missing_job');
    }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-content-workflow-modes.php';
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$c='MAD4B_SCP_Content_Workflow_Modes';
check($c::DEFAULT_MODE==='autonomous','autonomous default');
check($c::catalog()['selected']==='autonomous','default planner mode');
check($c::for_job('legacy-job')==='strict','legacy job stays review gated');
check($c::for_job('strict-job')==='strict','explicit strict');
check($c::for_job('assisted-job')==='assisted','explicit assisted');
check($c::for_job('autonomous-job')==='autonomous','explicit auto');
check(is_wp_error($c::for_job('missing-job')),'unknown job must not bypass per-job policy');
check(is_wp_error($c::requested('unsupported')),'invalid mode rejected');
check($c::for_input(array('job_id'=>'legacy-job'))==='strict','job mode overrides default');
foreach(array('autonomous','assisted','strict') as $mode){
    $r=$c::review_result($mode,array(),true);
    check($r['qa_passed']===true,$mode.' QA pass');
    check($r['publication_authorized']===false && $r['can_publish']===false,$mode.' cannot publish');
    check($r['self_review_accepted']===($mode==='autonomous'),$mode.' self review semantics');
    check($r['can_store_in_hub']===($mode==='autonomous'),$mode.' staging eligibility');
    check($r['independent_review_required']===($mode==='strict'),$mode.' independent reviewer is opt-in');
    $blocked=$c::review_result($mode,array('missing_source'),true);
    check(!$blocked['can_store_in_hub'] && !$blocked['self_review_accepted'] && $blocked['quality_evidence_status']==='QA_BLOCKED',$mode.' QA blockers never bypassed');
    $pending=$c::review_result($mode,array(),false);
    check(!$pending['can_store_in_hub'] && $pending['quality_evidence_status']==='QA_PENDING',$mode.' incomplete QA never accepted');
}
check($c::review_result('autonomous',array(),true)['quality_evidence_status']==='SELF_REVIEW_PASSED','auto QA status');
check($c::review_result('assisted',array(),true)['quality_evidence_status']==='NEEDS_REVIEW','assisted QA status');
check($c::review_result('strict',array(),true)['quality_evidence_status']==='NEEDS_INDEPENDENT_REVIEW','strict QA status');
echo "CONTENT_WORKFLOW_MODES: PASS (autonomous default, legacy strict, QA gates, all three modes, no auto-publish)\n";
