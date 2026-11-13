<?php
/** Offline first-party WordPress schema contract, no WP boot, no mutation. */
define('ABSPATH','/');
require '/work/ops/dynamic-operation-registry-v1/php/class-mad4b-dynamic-313-verifier.php';
require '/work/ops/dynamic-operation-registry-v1/php/class-mad4b-dynamic-313-wordpress.php';
$schema=json_decode(file_get_contents('/work/ops/native-source-publisher-v1/catalog/evidence-schema.json'),true,512,JSON_THROW_ON_ERROR);
$payload=[
 'result_status'=>'HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313',
 'output_sha256'=>str_repeat('a',64),
 'readback_sha256'=>str_repeat('a',64),
 'expected_readback_sha256'=>str_repeat('a',64),
 'grant_id'=>str_repeat('b',32),
 'transport_id'=>str_repeat('c',32),
 'production_mutation'=>false,
 'target_sha256'=>str_repeat('a',64),
 'file_count'=>1,
];
$schema_check=new ReflectionMethod('MAD4B_Dynamic_313_Proof_V1','schema_check');
$valid=$schema_check->invoke(null,$payload,$schema)===true;
$invalids=[];
foreach (['output_sha256','readback_sha256','production_mutation','file_count','result_status'] as $k) {
 $bad=$payload;
 $bad[$k]=match($k){
    'output_sha256'=>'fake',
    'readback_sha256'=>'fake',
    'production_mutation'=>'false',
    'file_count'=>-1,
    default=>'FALSIFIED_SUCCESS',
 };
 $invalids[$k]=$schema_check->invoke(null,$bad,$schema)===false;
}
$approved=new ReflectionMethod('MAD4B_Dynamic_WP_313_Readback_V1','approved_record');
$provider=json_decode(file_get_contents('/work/ops/native-source-publisher-v1/catalog/generated/source.code.release.manifest.json'),true,512,JSON_THROW_ON_ERROR);
$record=['registry_contract'=>'mad4b.dynamic-operation-index.v1',
    'first_party_enrolled'=>true,'provider'=>$provider,'evidence_schema'=>$schema,
    'independent_verifier_public_key_b64'=>base64_encode(str_repeat('z',32))];
$approved_candidate=$approved->invoke(null,$record,'source.code.release')===true;
$mutated=$record;$schema_missing=$schema;unset($schema_missing['fields']['output_sha256']);$mutated['evidence_schema']=$schema_missing;
$missing_output_denied=$approved->invoke(null,$mutated,'source.code.release')===false;
$source_not_activated=MAD4B_Dynamic_WP_313_Readback_V1::operation_operational_ready('source.code.release')===false;
if (!$valid||in_array(false,$invalids,true)||!$approved_candidate||!$missing_output_denied||!$source_not_activated) {
  fwrite(STDERR,"V26_WORDPRESS_EVIDENCE_CONTRACT_FAILED\n"); exit(2);
}
echo json_encode(['state'=>'PASS','wordpress_schema_valid'=>true,
 'strict_invalid_fields_rejected'=>$invalids,
 'source_candidate_schema_eligible'=>true,'missing_mandatory_output_denied'=>true,
 'source_operation_live_ready'=>false,'proof_signed'=>false,'site_modified'=>false],JSON_THROW_ON_ERROR);
