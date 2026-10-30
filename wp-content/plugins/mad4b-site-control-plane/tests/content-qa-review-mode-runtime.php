<?php
define('ABSPATH',__DIR__.'/');
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function add_action(){}
function wp_json_encode($d,$flags=0){return json_encode($d,$flags);}
class WP_Error {
 private $code;public function __construct($c,$m=''){$this->code=$c;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_Content_Jobs {
 public static function get_job($input){
  $id=$input['job_id']??'';
  $m=array('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'=>'autonomous',
    'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'=>'assisted',
    'cccccccc-cccc-cccc-cccc-cccccccccccc'=>'review_gated');
  return isset($m[$id])?array('job'=>array('automation_level'=>$m[$id])):new WP_Error('job_not_found');
 }
}
class MAD4B_SCP_Context_Pack {
 public static function writer_profile_binding_for_job_id($job) {
  return array('writer_profile_id'=>str_repeat('a',64),
    'writer_profile_version'=>str_repeat('b',64),'writer_profile_fingerprint'=>str_repeat('c',64));
 }
}
class MAD4B_SCP_Artifacts {
 public static $appends=array();
 public static function get_artifact($input){
  $id=$input['artifact_id'];
  $j=substr($id,0,1);
  $job=($j==='a'?'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa':($j==='b'?'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb':'cccccccc-cccc-cccc-cccc-cccccccccccc'));
  return array('artifact'=>array(
    'artifact_id'=>$id,'job_id'=>$job,'artifact_type'=>'draft','status'=>'active',
    'payload'=>array('writer_profile_id'=>str_repeat('a',64),
       'writer_profile_version'=>str_repeat('b',64),'writer_profile_fingerprint'=>str_repeat('c',64))));
 }
 public static function append_artifact($input){
  self::$appends[]=$input;
  $n=count(self::$appends);
  return array('artifact'=>array('artifact_id'=>sprintf('%08d-0000-0000-0000-000000000000',$n)));
 }
 public static function link_artifacts($input){return true;}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-content-intelligence-pipeline.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$tests=array(
 array('job'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa','expected'=>'SELF_REVIEW_PASSED','save'=>true),
 array('job'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb','expected'=>'NEEDS_REVIEW','save'=>false),
 array('job'=>'cccccccc-cccc-cccc-cccc-cccccccccccc','expected'=>'NEEDS_INDEPENDENT_REVIEW','save'=>false),
);
foreach($tests as $x){
 MAD4B_SCP_Artifacts::$appends=array();
 $qa=array('job_id'=>$x['job'],'draft_artifact_id'=>$x['job'],
  'fact_ledger'=>array('hard_blockers'=>array()),
  'editorial_qa'=>array('hard_blockers'=>array()),
  'seo_qa'=>array('hard_blockers'=>array()));
 $res=MAD4B_SCP_Content_Intelligence_Pipeline::append_qa_bundle($qa);
 check(!is_wp_error($res),$x['expected'].' pipeline returned WP_Error '.(is_wp_error($res)?$res->get_error_code():''));
 check($res['quality_evidence_status']===$x['expected'],$x['expected'].' final status');
 check($res['can_store_in_hub']===$x['save'],$x['expected'].' Hub staging');
 check($res['can_publish']===false && $res['publication_authorized']===false,'No auto-publish');
 check(count(MAD4B_SCP_Artifacts::$appends)===4,'3 QA artifacts + final QA');
 $final=MAD4B_SCP_Artifacts::$appends[3]['payload'];
 check($final['quality_evidence_status']===$x['expected'],'Persisted artifact agrees with result');
 check($final['can_store_in_hub']===$x['save'],'Persisted Hub eligibility');
 check($final['independent_review_certified']===false,'Do not forge independent review');
}
MAD4B_SCP_Artifacts::$appends=array();
$input=array('job_id'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
 'draft_artifact_id'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
 'fact_ledger'=>array('hard_blockers'=>array('missing_facts')),
 'editorial_qa'=>array('hard_blockers'=>array()),
 'seo_qa'=>array('hard_blockers'=>array()));
$r=MAD4B_SCP_Content_Intelligence_Pipeline::append_qa_bundle($input);
check(!is_wp_error($r) && $r['quality_evidence_status']==='QA_BLOCKED' && !$r['can_store_in_hub'],'Autonomous hard blocker must block');
MAD4B_SCP_Artifacts::$appends=array();
unset($input['seo_qa']);
$invalid=MAD4B_SCP_Content_Intelligence_Pipeline::append_qa_bundle($input);
check(is_wp_error($invalid) && count(MAD4B_SCP_Artifacts::$appends)===0,'Invalid bundle cannot partially append');
echo "CONTENT_QA_REVIEW_MODE: PASS (typed artifacts, autonomous self-review, assisted, strict, hard blockers, no publication)\n";
