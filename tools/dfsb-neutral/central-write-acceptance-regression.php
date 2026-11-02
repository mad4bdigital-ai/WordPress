<?php
if(!class_exists('MAD4B_SCP_Central_Write_Acceptance'))require_once '/tmp/mad4b-central-write-acceptance.php';
$c='MAD4B_SCP_Central_Write_Acceptance';
$scope=$c::site_scope();
if(empty($scope['site_uuid']))throw new RuntimeException('site_scope_unavailable');
$now=time();
$source=[
 'site_uuid'=>$scope['site_uuid'],'environment'=>$scope['environment'],
 'subject_id'=>'test:subject:123','ability'=>'mad4b/origin-test-enrichment',
 'job_id'=>'test-job-874','source_sha256'=>hash('sha256','real-source-874'),
 'revision'=>'17','reference_id'=>'ChIJ_ExactPlaceKey874',
 'evidence_sha256'=>hash('sha256','verified-independent-source'),
 'evidence_verified_at'=>$now-120,
 'independent_verification'=>true,'quality_pass'=>true,'rights_pass'=>true];
$claim=[
 'contract'=>$c::CONTRACT,'site_uuid'=>$source['site_uuid'],
 'environment'=>$source['environment'],
 'subject_id'=>$source['subject_id'],'ability'=>$source['ability'],
 'job_id'=>$source['job_id'],'source_sha256'=>$source['source_sha256'],
 'revision'=>$source['revision'],'reference_id'=>$source['reference_id'],
 'evidence_sha256'=>$source['evidence_sha256'],'verified_at'=>$now-120,
 'independent_verification'=>true,'quality_pass'=>true,'rights_pass'=>true];
$claim['idempotency_key']=$c::intent_key($source,$scope,$source['evidence_sha256']);
$cases=[
 ['name'=>'exact_all_evidence','diff'=>[],'source'=>[],'scope'=>[],'pass'=>true,'reason'=>'QUALIFIED'],
 ['name'=>'place_id_mismatch','diff'=>['reference_id'=>'ChIJ_WRONG'],'source'=>[],'scope'=>[],'reason'=>'reference_id_mismatch'],
 ['name'=>'source_digest_mismatch','diff'=>['source_sha256'=>hash('sha256','stale')],'source'=>[],'scope'=>[],'reason'=>'source_hash_stale'],
 ['name'=>'wrong_job','diff'=>['job_id'=>'foreign-job'],'source'=>[],'scope'=>[],'reason'=>'content_job_mismatch'],
 ['name'=>'wrong_site','diff'=>['site_uuid'=>'foreign-site'],'source'=>[],'scope'=>[],'reason'=>'site_binding_mismatch'],
 ['name'=>'wrong_environment','diff'=>['environment'=>'production'],'source'=>[],'scope'=>[],'reason'=>'environment_binding_mismatch'],
 ['name'=>'wrong_subject','diff'=>['subject_id'=>'test:other'],'source'=>[],'scope'=>[],'reason'=>'subject_mismatch'],
 ['name'=>'wrong_ability','diff'=>['ability'=>'other/run'],'source'=>[],'scope'=>[],'reason'=>'ability_mismatch'],
 ['name'=>'revision_changed','diff'=>['revision'=>'18'],'source'=>[],'scope'=>[],'reason'=>'source_revision_changed'],
 ['name'=>'expired_evidence','diff'=>['verified_at'=>$now-400000],'source'=>['evidence_verified_at'=>$now-400000],'scope'=>[],'reason'=>'evidence_missing_expired_or_future'],
 ['name'=>'future_evidence','diff'=>['verified_at'=>$now+3600],'source'=>['evidence_verified_at'=>$now+3600],'scope'=>[],'reason'=>'evidence_missing_expired_or_future'],
 ['name'=>'missing_independent_review','diff'=>['independent_verification'=>false],'source'=>[],'scope'=>[],'reason'=>'fact_quality_or_rights_not_verified'],
 ['name'=>'failed_fact_quality','diff'=>['quality_pass'=>false],'source'=>[],'scope'=>[],'reason'=>'fact_quality_or_rights_not_verified'],
 ['name'=>'failed_rights','diff'=>['rights_pass'=>false],'source'=>[],'scope'=>[],'reason'=>'fact_quality_or_rights_not_verified'],
 ['name'=>'evidence_hash_changed','diff'=>['evidence_sha256'=>hash('sha256','other-evidence')],'source'=>[],'scope'=>[],'reason'=>'evidence_untrusted_or_changed'],
 ['name'=>'forged_client_independent_claim','diff'=>['independent_verification'=>true],
    'source'=>['independent_verification'=>false],'scope'=>[],
    'reason'=>'server_verified_fact_quality_or_rights_missing'],
 ['name'=>'forged_client_rights_claim','diff'=>['rights_pass'=>true],
    'source'=>['rights_pass'=>false],'scope'=>[],
    'reason'=>'server_verified_fact_quality_or_rights_missing'],
 ['name'=>'source_timestamp_changed','diff'=>[],
    'source'=>['evidence_verified_at'=>$now-200],'scope'=>[],
    'reason'=>'evidence_timestamp_not_source_bound'],

 ['name'=>'idempotency_forged','diff'=>['idempotency_key'=>hash('sha256','forged')],'source'=>[],'scope'=>[],'reason'=>'idempotency_identity_mismatch'],
 ['name'=>'native_source_changed','diff'=>[],'source'=>['source_sha256'=>hash('sha256','changed')],'scope'=>[],'reason'=>'source_hash_stale'],
 ['name'=>'scope_site_changed','diff'=>[],'source'=>[],'scope'=>['site_uuid'=>'unknownsite'],'reason'=>'site_binding_mismatch'],
 ['name'=>'invalid_freshness_policy','diff'=>[],'source'=>[],'scope'=>['max_evidence_age_seconds'=>3600000],'reason'=>'evidence_missing_expired_or_future'],
 ['name'=>'untrusted_ref_deleted','diff'=>[],'source'=>['reference_id'=>''],'scope'=>[],'reason'=>'reference_id_mismatch']
];
$errors=[];$results=[];
foreach($cases as $row) {
 $r=$c::verify(array_replace($claim,$row['diff']),
     array_replace($source,$row['source']),array_replace($scope,$row['scope']),$now);
 $pass=(($r['reason']??'')===$row['reason'] && ($r['eligible']??false)===($row['pass']??false));
 $results[]=['name'=>$row['name'],'pass'=>$pass,'reason'=>$r['reason']??''];
 if(!$pass)$errors[]=$row['name'];
}
$synthetic=new WP_REST_Request('POST','/mad4b-content-readiness/v1/sync-one');
$rest=$c::route_admission($synthetic);
if(($rest['eligible']??true)!==false)$errors[]='synthetic_rest_auth_not_denied';
$descriptor=$c::descriptor();
if(($descriptor['generic_target_executor_enabled']??true)!==false)$errors[]='generic_executor_must_be_disabled';
if(($descriptor['trusted_adapter_executor_supported']??false)!==true)$errors[]='adapter_contract_missing';
echo wp_json_encode(['contract'=>$c::CONTRACT.'.acceptance','pass'=>!$errors,
 'checks'=>count($cases)+3,'errors'=>$errors,'cases'=>$results,
 'no_content_mutation'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
if($errors)throw new RuntimeException('central_acceptance_regression_failed');
