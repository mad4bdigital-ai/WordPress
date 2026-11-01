<?php
defined('ABSPATH') || exit;
/**
 * Server-derived evidence for original read-only ContentJob operations.
 * Caller-provided SHA256 strings are NOT authority to admit new work.
 * This lane intentionally does NOT certify AI, provider, commercial or publishing effects.
 */
final class MAD4B_Hub_Original_Context_Evidence {
    const CONTRACT='mad4b.hub.original-readback-context.v1';
    const EFFECT='read_only';
    const OPERATIONS=[
        'workflow_inspect'=>['MAD4B_Hub_Workflow_Operations','inspect'],
        'content_job_read'=>['MAD4B_SCP_Content_Jobs','get_job'],
    ];
    private static function err($name){
        return new WP_Error('mad4b_hub_evidence_'.$name,$name,['status'=>409,'contract'=>self::CONTRACT]);
    }
    private static function digest($scope){
        return hash('sha256',wp_json_encode($scope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    private static function file_digest($class,$method){
        if(!class_exists($class)||!method_exists($class,$method))return null;
        try{$p=(new ReflectionMethod($class,$method))->getFileName();}
        catch(ReflectionException $e){return null;}
        if(!is_string($p)||!is_file($p))return null;
        $hash=hash_file('sha256',$p);
        return is_string($hash)&&strlen($hash)===64?$hash:null;
    }
    private static function source_job($jobId){
        if(!class_exists('MAD4B_SCP_Content_Jobs'))return self::err('job_provider_missing');
        $record=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$jobId]);
        if(is_wp_error($record))return $record;
        $job=$record['job']??null;
        if(!is_array($job)||($job['job_id']??'')!==$jobId)return self::err('job_readback_mismatch');
        return $job;
    }
    public static function certify(array $item,$job=null){
        $op=(string)($item['operation']??'');
        if(!isset(self::OPERATIONS[$op]))return self::err('operation_requires_original_provider_proof');
        $jobId=(string)($item['job_id']??'');
        if($jobId==='')return self::err('content_job_required');
        $read=self::source_job($jobId);
        if(is_wp_error($read))return $read;
        if(is_array($job)&&($job['job_id']??'')!==$read['job_id'])return self::err('job_scope_changed');
        if(!in_array((string)($read['state']??''),['NEW','QUEUED','RUNNING'],true)
            ||($read['stage']??'')!==($item['stage']??''))
            return self::err('job_stage_not_current');
        $p=$item['payload']??null;
        if(!is_array($p))return self::err('payload_invalid');
        if(isset($p['content_job_revision']) &&
           (int)$p['content_job_revision']!==(int)$read['job_revision'])
            return self::err('job_revision_changed');
        if(!class_exists('MAD4B_Content_Hub')||!MAD4B_Content_Hub::permission()
            ||!class_exists('MAD4B_SCP_Policy')||!MAD4B_SCP_Policy::can_mutate())
            return self::err('original_policy_not_authorized');
        $sourceFields=[
            'job_id','site_uuid','tenant_id','brand_id','job_revision','stage',
            'language','country','content_type','writer_profile_id','writer_profile_version',
            'target_post_type','target_post_id','current_artifact_id',
        ];
        $source=[];foreach($sourceFields as $name)$source[$name]=$read[$name]??null;
        $sourceHash=self::digest(['contract'=>self::CONTRACT,'provider'=>'MAD4B_SCP_Content_Jobs::get_job',
            'original'=>$source]);
        $policyClasses=[
            ['MAD4B_SCP_Policy','can_mutate'],
            ['MAD4B_Hub_Workflow_Origin','executor_guard'],
            ['MAD4B_Hub_Bulk_Jobs_Queue','finish'],
        ];
        $policyFiles=[];
        foreach($policyClasses as [$class,$method]){
            $hash=self::file_digest($class,$method);
            if($hash===null)return self::err('original_policy_source_unavailable');
            $policyFiles[$class.'::'.$method]=$hash;
        }
        $policyHash=self::digest(['contract'=>self::CONTRACT,'effective_permission'=>'original_staging_admin',
            'effect'=>self::EFFECT,'operation'=>$op,'code_provenance'=>$policyFiles]);
        [$capClass,$capMethod]=self::OPERATIONS[$op];
        $providerHash=self::file_digest($capClass,$capMethod);
        $sourceProviderHash=self::file_digest('MAD4B_SCP_Content_Jobs','get_job');
        if($providerHash===null||$sourceProviderHash===null)
            return self::err('capability_source_unavailable');
        $capHash=self::digest(['contract'=>self::CONTRACT,'operation'=>$op,
            'executor'=>$p['assigned_executor']??'mcp_client',
            'original_readback'=>$capClass.'::'.$capMethod,
            'implementation_sha256'=>$providerHash,
            'job_provider_sha256'=>$sourceProviderHash,
            'output_contract'=>self::CONTRACT]);
        return ['source_snapshot_sha256'=>$sourceHash,
            'policy_snapshot_sha256'=>$policyHash,
            'capability_snapshot_sha256'=>$capHash,
            'effect_type'=>self::EFFECT,'output_contract'=>self::CONTRACT,
            'original_context_certified'=>true];
    }
    /** Runs ONLY after the original WorkflowOrigin is derived server-side. */
    public static function bind_batch(array $batch){
        if(!is_array($batch['items']??null))return self::err('batch_missing');
        foreach($batch['items'] as &$item){
            if(($item['job_id']??'')==='')continue; // unlinked source has its own contract
            if(class_exists('MAD4B_Hub_Governed_Single_Flight')
               &&MAD4B_Hub_Governed_Single_Flight::certified_only()
               &&!MAD4B_Hub_Governed_Single_Flight::applies_to($item))
                continue; // original admission and claims remain authoritative
            $p=$item['payload']??[];
            foreach(['source_snapshot_sha256','policy_snapshot_sha256',
                     'capability_snapshot_sha256','original_context_certified'] as $field){
                if(array_key_exists($field,$p))
                    return self::err('caller_supplied_evidence_forbidden');
            }
            $cert=self::certify($item);
            if(is_wp_error($cert))return $cert;
            foreach(['effect_type','output_contract'] as $field){
                if(isset($p[$field])&&$p[$field]!==$cert[$field])
                    return self::err('caller_effect_contract_mismatch');
            }
            $item['payload']=array_merge($p,$cert);
        }
        unset($item);
        return $batch;
    }
    /** Independently recompute original evidence on every claim and admission. */
    public static function verify(array $item,$job=null){
        $p=$item['payload']??null;
        if(!is_array($p) || ($p['original_context_certified']??false)!==true)
            return self::err('certificate_missing');
        $computed=self::certify($item,$job);
        if(is_wp_error($computed))return $computed;
        foreach($computed as $field=>$value){
            $given=$p[$field]??null;
            if(!is_string($value)){
                if($given!==$value)return self::err('certificate_'.$field.'_mismatch');
            }elseif(!is_string($given)||!hash_equals($value,$given)){
                return self::err('certificate_'.$field.'_mismatch');
            }
        }
        return true;
    }
}
