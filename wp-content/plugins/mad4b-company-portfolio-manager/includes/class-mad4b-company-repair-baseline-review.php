<?php
/**
 * Source-backed read-only baseline review.
 * Never generates an expected after-image, signs proof or changes any journal.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Company_Repair_Baseline_Review_V1',false)){
final class MAD4B_Company_Repair_Baseline_Review_V1{
    public const CONTRACT='mad4b.company-repair.baseline-review.v1';
    public const SNAPSHOT_MAX_AGE=300;
    private static function sha($v):bool{
        return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;
    }
    public static function inspect(array $job,array $preview,int $now):array {
        $result=[
            'contract'=>self::CONTRACT,
            'state'=>'BASELINE_REVIEW_HOLD',
            'job_id'=>$job['id']??null,
            'job_state'=>$job['state']??null,
            'company_code'=>$job['company_code']??null,
            'environment'=>$job['environment']??null,
            'site_uuid'=>$job['site_uuid']??null,
            'resource_key'=>$job['resource_key']??null,
            'before_sha256'=>null,
            'rollback_sha256'=>null,
            'expected_after_sha256'=>null,
            'resource_manifest_sha256'=>null,
            'company_preservation_sha256'=>null,
            'snapshot_observed_epoch'=>null,
            'snapshot_expires_in_seconds'=>null,
            'snapshot_origin_independently_signed'=>false,
            'exact_scoped_312_was_verified'=>false,
            'executor_time_before_image_cas_verified'=>false,
            'baseline_signed'=>false,
            'baseline_recorded'=>is_array($job['baseline']??null),
            'original_305_submitted'=>false,
            'original_313_verified'=>false,
            'repair_execution_authorized'=>false,
            'target_mutated'=>false,
            'journal_updated_by_review'=>false,
            'missing_requirements'=>[],
        ];
        $missing=[];
        $good=MAD4B_Company_Repair_Job_Contract_V1::valid_chain($job)
            &&!str_starts_with((string)($job['resource_key']??''),'incident:')
            &&self::sha($job['resource_resolution_sha256']??null)
            &&self::sha($job['resolved_resource_before_sha256']??null)
            &&self::sha($job['resolved_resource_manifest_sha256']??null)
            &&self::sha($job['resolved_company_preservation_sha256']??null);
        if(!$good)$missing[]='IMMUTABLE_RESOURCE_JOURNAL_NOT_VERIFIED';
        $matches=$good
            &&($preview['state']??null)==='EXACT_RESOURCE_OBSERVED_UNSIGNED'
            &&($preview['resource_observed']??false)===true
            &&($preview['company_code']??null)===$job['company_code']
            &&($preview['environment']??null)===$job['environment']
            &&($preview['site_uuid']??null)===$job['site_uuid']
            &&($preview['canonical_origin']??null)===$job['canonical_origin']
            &&($preview['source_plan_sha256']??null)===$job['source_plan_sha256']
            &&($preview['resource_key']??null)===$job['resource_key']
            &&($preview['resource_resolution_sha256']??null)===
                $job['resource_resolution_sha256']
            &&($preview['before_sha256']??null)===$job['resolved_resource_before_sha256']
            &&($preview['resource_manifest_sha256']??null)===
                $job['resolved_resource_manifest_sha256']
            &&($preview['company_preservation_sha256']??null)===
                $job['resolved_company_preservation_sha256']
            &&($preview['ownership_scope']??null)===$job['ownership_scope'];
        if(!$matches)$missing[]='FRESH_EXACT_TARGET_RESOURCE_MISMATCH';
        $epoch=$preview['observed_epoch']??null;
        $fresh=$matches &&is_int($epoch)
            &&$epoch>=$now-self::SNAPSHOT_MAX_AGE
            &&$epoch<=$now+30;
        if(!$fresh)$missing[]='TARGET_SNAPSHOT_NOT_CURRENT';
        if($fresh){
            $result['before_sha256']=$preview['before_sha256'];
            $result['resource_manifest_sha256']=$preview['resource_manifest_sha256'];
            $result['company_preservation_sha256']=$preview['company_preservation_sha256'];
            $result['snapshot_observed_epoch']=$epoch;
            $result['snapshot_expires_in_seconds']=max(0,
                self::SNAPSHOT_MAX_AGE-max(0,$now-$epoch));
            $result['exact_scoped_312_was_verified']=
                ($preview['exact_scoped_312_verified']??false)===true;
            // A digest of original bytes is not itself a restorable
            // before-image or a verified rollback artifact.
        }
        $state=$job['state']??null;
        if($state!=='AWAITING_RESOURCE_BASELINE')
            $missing[]='JOURNAL_NOT_IN_AWAITING_BASELINE_STATE';
        if(($job['original_job_id']??null)!==null)
            $missing[]='ORIGINAL_JOB_ALREADY_BOUND';
        if(($job['signed_repair_313_sha256']??null)!==null)
            $missing[]='ORIGINAL_313_ALREADY_RECORDED';
        $missing[]='PROVIDER_GOVERNED_EXPECTED_AFTER_IMAGE_NOT_SUPPLIED';
        $missing[]='VERIFIED_RESTORABLE_ROLLBACK_ARTIFACT_NOT_SUPPLIED';
        $missing[]='INDEPENDENT_TARGET_ORIGIN_AND_EXECUTOR_TIME_CAS_REQUIRED';
        $result['missing_requirements']=$missing;
        if($fresh &&$state==='AWAITING_RESOURCE_BASELINE'
           &&($job['original_job_id']??null)===null){
            $result['state']='UNSIGNED_BASELINE_CANDIDATE_REVIEW_ONLY';
            $material=[
                'contract'=>self::CONTRACT,
                'job_id'=>$job['id'],'source_plan_sha256'=>$job['source_plan_sha256'],
                'resource_key'=>$job['resource_key'],
                'before_sha256'=>$preview['before_sha256'],
                'resource_manifest_sha256'=>$preview['resource_manifest_sha256'],
                'company_preservation_sha256'=>$preview['company_preservation_sha256'],
                'observed_epoch'=>$epoch,
            ];
            $encoded=json_encode($material,
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $result['candidate_fingerprint_sha256']=is_string($encoded)
                ?hash('sha256',$encoded):null;
        }
        return $result;
    }
}
}
