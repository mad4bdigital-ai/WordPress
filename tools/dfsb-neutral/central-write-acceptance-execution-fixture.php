<?php
if(!class_exists('MAD4B_SCP_Central_Write_Acceptance'))require_once '/tmp/mad4b-central-write-acceptance.php';
$c='MAD4B_SCP_Central_Write_Acceptance';
$scope=$c::site_scope();
$source=['site_uuid'=>$scope['site_uuid'],'environment'=>$scope['environment'],
  'subject_id'=>'fixture:central-acceptance:only',
  'ability'=>'mad4b/test-acceptance-source-readback',
  'job_id'=>'fixture-job-cwa-20261102',
  'source_sha256'=>hash('sha256','fixture-current-source'),
  'revision'=>'fixture-revision-2','reference_id'=>'fixture-precise-entity',
  'evidence_sha256'=>hash('sha256','fixture-provider-verified'),
  'evidence_verified_at'=>time()-60,'independent_verification'=>true,
  'quality_pass'=>true,'rights_pass'=>true];
$claim=['contract'=>$c::CONTRACT,
   'site_uuid'=>$source['site_uuid'],'environment'=>$source['environment'],
   'subject_id'=>$source['subject_id'],'ability'=>$source['ability'],
   'job_id'=>$source['job_id'],'source_sha256'=>$source['source_sha256'],
   'revision'=>$source['revision'],'reference_id'=>$source['reference_id'],
   'evidence_sha256'=>$source['evidence_sha256'],
   'verified_at'=>$source['evidence_verified_at'],
   'independent_verification'=>true,'quality_pass'=>true,'rights_pass'=>true];
$claim['idempotency_key']=$c::intent_key($source,$scope,$source['evidence_sha256']);
final class MAD4B_CWA_Test_Adapter implements MAD4B_Central_Enrichment_Write_Adapter {
 public $source,$count=0,$observed=false,$deny=false,$fail=false;
 public function __construct($source){$this->source=$source;}
 public function source(array $claim):array{return $this->source;}
 public function authorize(array $claim,array $source):bool{return !$this->deny;}
 public function apply(array $claim,array $source):array{$this->count++;$this->observed=true;return ['success'=>!$this->fail,'readback_value'=>'fixture-confirmed'];}
 public function readback(array $claim,array $source,array $result):bool{
  return $this->observed && ($result['readback_value']??'')==='fixture-confirmed';
 }
}
$adapter=new MAD4B_CWA_Test_Adapter($source);
$key='mad4b_cwa_'.hash('sha256',$claim['idempotency_key']);
if(get_option($key,null)!==null)throw new RuntimeException('fixture_already_exists');
$checks=[];
try {
 $adapter->deny=true;
 $denied=$c::execute($claim,$adapter);
 $checks['original_permission_denied']=($denied['reason']??'')==='original_ability_permission_denied'
   &&$adapter->count===0&&get_option($key,null)===null;
 $adapter->deny=false;
 $first=$c::execute($claim,$adapter);
 $checks['single_authorized_write_and_readback']=($first['reason']??'')==='VERIFIED_WRITE_AND_READBACK'
   &&($first['mutation_performed']??null)===true &&$adapter->count===1;
 $second=$c::execute($claim,$adapter);
 $checks['idempotent_verified_readback']=($second['reason']??'')==='ALREADY_VERIFIED'
   &&($second['mutation_performed']??null)===false&&$adapter->count===1;
 $adapter->observed=false;
 $drift=$c::execute($claim,$adapter);
 $checks['readback_drift_denied']=($drift['reason']??'')==='prior_verified_readback_drift'
   &&$adapter->count===1;
 $ledger=get_option($key,null);
 $checks['native_receipt_persisted']=is_array($ledger)
   &&($ledger['state']??'')==='VERIFIED'
   &&hash_equals((string)$ledger['receipt_sha256'],(string)$first['receipt_sha256']);
}finally{delete_option($key);}
$checks['fixture_removed']=get_option($key,null)===null;
$source2=$source;
$source2['subject_id']='fixture:central-acceptance:uncertain';
$claim2=$claim;
$claim2['subject_id']=$source2['subject_id'];
$claim2['idempotency_key']=$c::intent_key($source2,$scope,$source2['evidence_sha256']);
$key2='mad4b_cwa_'.hash('sha256',$claim2['idempotency_key']);
$failAdapter=new MAD4B_CWA_Test_Adapter($source2);
$failAdapter->fail=true;
if(get_option($key2,null)!==null)throw new RuntimeException('uncertain_fixture_already_exists');
try {
 $uncertain=$c::execute($claim2,$failAdapter);
 $retry=$c::execute($claim2,$failAdapter);
 $checks['uncertain_result_held_no_blind_retry']=
   ($uncertain['reason']??'')==='apply_result_uncertain' &&
   ($retry['reason']??'')==='prior_attempt_uncertain_requires_reconciliation' &&
   $failAdapter->count===1 &&
   (get_option($key2,[])['state']??'')==='UNKNOWN';
 $invalidResult=$c::reconcile($claim2,$failAdapter,[
   'success'=>false,'readback_value'=>'fixture-confirmed']);
 $checks['reconcile_requires_original_success_evidence']=
   ($invalidResult['reason']??'')==='reconciliation_result_not_success' &&
   (get_option($key2,[])['state']??'')==='UNKNOWN' &&
   $failAdapter->count===1;
 $native=['success'=>true,'readback_value'=>'fixture-confirmed'];
 $verified=$c::reconcile($claim2,$failAdapter,$native);
 $checks['original_unknown_reconciled_without_reexecution']=
   ($verified['reason']??'')==='VERIFIED_ORIGINAL_WRITE_BY_READBACK' &&
   !empty($verified['reconciliation_only']) &&
   ($verified['mutation_performed']??true)===false &&
   $failAdapter->count===1 &&
   (get_option($key2,[])['state']??'')==='VERIFIED';
 $again=$c::reconcile($claim2,$failAdapter,$native);
 $checks['reconciled_receipt_stable_and_idempotent']=
   ($again['reason']??'')==='ALREADY_VERIFIED' &&
   ($again['receipt_sha256']??'')===($verified['receipt_sha256']??null) &&
   $failAdapter->count===1;
}finally{delete_option($key2);}
$checks['uncertain_fixture_removed']=get_option($key2,null)===null;

$pass=array_reduce($checks,static fn($a,$v)=>$a&&$v,true);
echo wp_json_encode(['contract'=>$c::CONTRACT.'.execution-fixture',
 'pass'=>$pass,'checks'=>$checks,'mutation_class'=>'temporary_fixture_option_only',
 'external_service_called'=>false,'tour_content_modified'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(!$pass)throw new RuntimeException('central_write_execution_fixture_failed');
