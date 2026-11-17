<?php
/**
 * Immutable repair intent, append-only hash-chained events and no-replay rules.
 * Pure functions: no WordPress storage or remote execution.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Company_Repair_Job_Contract_V1',false)) {
final class MAD4B_Company_Repair_Job_Contract_V1 {
    public const CONTRACT='mad4b.company-reconcile.repair-job.v1';
    public const BASELINE='mad4b.company-reconcile.repair-baseline.v1';
    public const MAX_EVENTS=64;
    public const STATES=[
        'AWAITING_RESOURCE_BASELINE','BASELINE_RECORDED_UNVERIFIED',
        'AWAITING_ORIGINAL_305_JOB','ORIGINAL_JOB_BOUND',
        'EXECUTION_OUTCOME_UNKNOWN','AWAITING_EXACT_SIGNED_313',
        'RESOURCE_REPAIR_313_VERIFIED','POSTCONDITION_DRIFT_HOLD',
        'SOURCE_PLAN_DRIFT_HOLD','ROLLBACK_REQUIRED',
        'ROLLBACK_ORIGINAL_JOB_BOUND','ROLLED_BACK_313_VERIFIED',
        'MANUAL_RECOVERY_REQUIRED'
    ];
    public const TRANSITIONS=[
        // A capability manifest may be updated without changing the target
        // resource. Only an untouched, unexecuted journal may reconcile it;
        // the exact old/new fingerprints stay in the append-only log.
        'RECONCILE_PROVIDER_CAPABILITY'=>[
            'AWAITING_RESOURCE_BASELINE'=>'AWAITING_RESOURCE_BASELINE'
        ],
        'BASELINE_COLLECTED'=>[
            'AWAITING_RESOURCE_BASELINE'=>'BASELINE_RECORDED_UNVERIFIED'
        ],
        'BASELINE_PREPARED'=>[
            'BASELINE_RECORDED_UNVERIFIED'=>'AWAITING_ORIGINAL_305_JOB'
        ],
        'BIND_ORIGINAL_JOB'=>[
            'AWAITING_ORIGINAL_305_JOB'=>'ORIGINAL_JOB_BOUND'
        ],
        'OBSERVE_CLAIM_PENDING'=>[
            'ORIGINAL_JOB_BOUND'=>'AWAITING_EXACT_SIGNED_313',
        ],
        'OBSERVE_UNKNOWN_OUTCOME'=>[
            'ORIGINAL_JOB_BOUND'=>'EXECUTION_OUTCOME_UNKNOWN',
            'AWAITING_EXACT_SIGNED_313'=>'EXECUTION_OUTCOME_UNKNOWN',
        ],
        'OBSERVE_SIGNED_REPAIR_313'=>[
            'ORIGINAL_JOB_BOUND'=>'RESOURCE_REPAIR_313_VERIFIED',
            'AWAITING_EXACT_SIGNED_313'=>'RESOURCE_REPAIR_313_VERIFIED',
            'EXECUTION_OUTCOME_UNKNOWN'=>'RESOURCE_REPAIR_313_VERIFIED',
        ],
        'OBSERVE_SIGNED_DIGEST_MISMATCH'=>[
            'ORIGINAL_JOB_BOUND'=>'POSTCONDITION_DRIFT_HOLD',
            'AWAITING_EXACT_SIGNED_313'=>'POSTCONDITION_DRIFT_HOLD',
            'EXECUTION_OUTCOME_UNKNOWN'=>'POSTCONDITION_DRIFT_HOLD',
        ],
        'PLAN_DRIFT'=>[
            'AWAITING_RESOURCE_BASELINE'=>'SOURCE_PLAN_DRIFT_HOLD',
            'BASELINE_RECORDED_UNVERIFIED'=>'SOURCE_PLAN_DRIFT_HOLD',
            'AWAITING_ORIGINAL_305_JOB'=>'SOURCE_PLAN_DRIFT_HOLD',
            'ORIGINAL_JOB_BOUND'=>'SOURCE_PLAN_DRIFT_HOLD',
            'AWAITING_EXACT_SIGNED_313'=>'SOURCE_PLAN_DRIFT_HOLD',
            'EXECUTION_OUTCOME_UNKNOWN'=>'SOURCE_PLAN_DRIFT_HOLD',
        ],
        'REQUEST_ROLLBACK'=>[
            'POSTCONDITION_DRIFT_HOLD'=>'ROLLBACK_REQUIRED',
            'MANUAL_RECOVERY_REQUIRED'=>'ROLLBACK_REQUIRED',
        ],
        'BIND_ROLLBACK_JOB'=>[
            'ROLLBACK_REQUIRED'=>'ROLLBACK_ORIGINAL_JOB_BOUND',
        ],
        'OBSERVE_SIGNED_ROLLBACK_313'=>[
            'ROLLBACK_ORIGINAL_JOB_BOUND'=>'ROLLED_BACK_313_VERIFIED',
        ],
        'RECOVERY_HOLD'=>[
            'POSTCONDITION_DRIFT_HOLD'=>'MANUAL_RECOVERY_REQUIRED',
            'ROLLBACK_ORIGINAL_JOB_BOUND'=>'MANUAL_RECOVERY_REQUIRED',
        ],
    ];
    public static function sha($s):bool {
        return is_string($s)&&preg_match('/^[a-f0-9]{64}$/D',$s)===1;
    }
    public const DESCRIPTOR_FIELDS=[
        'contract','id','company_code','environment','site_uuid',
        'canonical_origin','incident_code','resource_key','source_plan_sha256',
        'target_check','ownership_scope','evidence_lane','native_operation',
        'provider_resolution_sha256','required_dependencies','acceptance',
        'owner','repair_strategy','immutable_plan_sha256'
    ];
    public static function descriptor(array $job):string {
        $values=[];
        foreach(self::DESCRIPTOR_FIELDS as $name) {
            if(!array_key_exists($name,$job))return '';
            $values[$name]=$job[$name];
        }
        $encoded=json_encode($values,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return is_string($encoded)?hash('sha256',$encoded):'';
    }
    /**
     * Resource-bound jobs have a separate immutable observation fingerprint.
     * Existing logical incident journals retain their historic descriptor.
     */
    public static function resolved_binding_hash(array $job):string {
        $fields=['resource_type','resource_id','resource_key',
            'resource_resolution_sha256','resolved_resource_before_sha256',
            'resolved_resource_manifest_sha256','resolved_company_preservation_sha256',
            'resolved_resource_observed_epoch'];
        $row=[];
        foreach($fields as $field){
            if(!array_key_exists($field,$job))return '';
            $row[$field]=$job[$field];
        }
        $encoded=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($encoded)?hash('sha256',$encoded):'';
    }
    public static function key(array $scope):string {
        $fields=['company_code','environment','site_uuid','canonical_origin',
            'incident_code','resource_key','source_plan_sha256'];
        $lines=[self::CONTRACT];
        foreach($fields as $field) {
            if(!is_string($scope[$field]??null))return '';
            $lines[]=$scope[$field];
        }
        return substr(hash('sha256',implode("\n",$lines)),0,40);
    }
    public static function baseline_valid(array $base,array $job,int $now):bool {
        if(($base['contract']??null)!==self::BASELINE)return false;
        foreach(['company_code','environment','site_uuid','canonical_origin','resource_key',
                 'source_plan_sha256'] as $field)
            if(($base[$field]??null)!==($job[$field]??null))return false;
        foreach(['before_sha256','rollback_sha256','expected_after_sha256',
                 'resource_manifest_sha256'] as $field)
            if(!self::sha($base[$field]??null))return false;
        if(!hash_equals($base['before_sha256'],$base['rollback_sha256'])
            ||hash_equals($base['before_sha256'],$base['expected_after_sha256']))
            return false;
        $seen=$base['observed_epoch']??null;
        if(!is_int($seen)||$seen>$now+60||$seen<$now-1800)
            return false;
        // This local baseline does not confer authorization; the Original 313
        // signed proof must independently corroborate its before/after hashes.
        return ($base['evidence_level']??'')==='LOCAL_WORDPRESS_UNSIGNED';
    }
    public static function append(array $job,string $event,array $facts,int $epoch):array {
        $source=$job['state']??null;
        $dest=self::TRANSITIONS[$event][$source]??null;
        if(!is_string($dest) || !in_array($dest,self::STATES,true))
            return ['error'=>'INVALID_TRANSITION','state'=>$source];
        $events=$job['events']??[];
        if(!is_array($events)||count($events)>=self::MAX_EVENTS
           ||!self::valid_chain($job))
            return ['error'=>'JOURNAL_CAPACITY_OR_INTEGRITY','state'=>$source];
        $revision=(int)($job['revision']??0)+1;
        $seq=[
            'revision'=>$revision,'event'=>$event,'from'=>$source,'to'=>$dest,
            'epoch'=>$epoch,'facts'=>$facts,
            'prev_hash'=>$job['last_event_sha256']??str_repeat('0',64),
        ];
        $seq['hash']=hash('sha256',json_encode($seq,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $job['events'][]=$seq;
        $job['revision']=$revision;
        $job['last_event_sha256']=$seq['hash'];
        $job['state']=$dest;
        $job['updated_epoch']=$epoch;
        $job['execution_authorized_by_repair_job']=false;
        $job['original_305_submitted_by_repair_job']=false;
        return $job;
    }
    public static function valid_chain(array $job):bool {
        if(($job['contract']??null)!==self::CONTRACT)return false;
        $events=$job['events']??null;
        if(!is_array($events)||count($events)>self::MAX_EVENTS)return false;
        $prev=str_repeat('0',64);
        $state='AWAITING_RESOURCE_BASELINE';
        $index=0;
        foreach($events as $event) {
            if(!is_array($event)||($event['prev_hash']??null)!==$prev
                ||($event['revision']??null)!==++$index
                ||($event['from']??null)!==$state
                ||!is_string($event['event']??null)
                ||(self::TRANSITIONS[$event['event']][$state]??null)!==($event['to']??null)
                ||!self::sha($event['hash']??null))
                return false;
            $hash=$event['hash'];
            unset($event['hash']);
            if(!hash_equals($hash,hash('sha256',json_encode($event,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))))
                return false;
            $prev=$hash;
            $state=$event['to'];
        }
        if(($job['id']??null)!==self::key($job))return false;
        $descriptor=$job['immutable_descriptor_sha256']??null;
        if($descriptor===null) {
            // Pre-V2 on-disk job: only inspect/reconcile the empty journal.
            // Never permit baseline or execution with an unbound descriptor.
            if($index!==0||($job['baseline']??null)!==null
                ||($job['original_job_id']??null)!==null)return false;
        }elseif(!self::sha($descriptor)
                ||!hash_equals($descriptor,self::descriptor($job)))return false;
        if(!str_starts_with((string)($job['resource_key']??''),'incident:')) {
            $binding=$job['immutable_resolved_binding_sha256']??null;
            if(!self::sha($binding)
                ||self::resolved_binding_hash($job)===''
                ||!hash_equals($binding,self::resolved_binding_hash($job))
                ||!self::sha($job['resource_resolution_sha256']??null)
                ||!self::sha($job['resolved_resource_before_sha256']??null)
                ||!self::sha($job['resolved_resource_manifest_sha256']??null)
                ||!self::sha($job['resolved_company_preservation_sha256']??null)
                ||!is_int($job['resolved_resource_observed_epoch']??null)
                ||($job['resource_key']??null)!==
                   ($job['resource_type']??'').':'.($job['resource_id']??''))
                return false;
        }
        $baseline=$job['baseline']??null;
        $baselineEvent=null;
        foreach($events as $item)
            if(($item['event']??null)==='BASELINE_COLLECTED')$baselineEvent=$item;
        if($baselineEvent===null && $baseline!==null)return false;
        if($baselineEvent!==null) {
            if(!is_array($baseline))return false;
            $facts=$baselineEvent['facts']??[];
            foreach(['before_sha256','rollback_sha256',
                'expected_after_sha256','resource_manifest_sha256'] as $field)
                if(($facts[$field]??null)!==($baseline[$field]??null))
                    return false;
        }
        foreach($events as $item) {
            if(($item['event']??null)==='BIND_ORIGINAL_JOB') {
                if(($job['original_job_id']??null)!==($item['facts']['original_job_id']??null)
                   ||($job['expected_command_sha256']??null)!==
                      ($item['facts']['command_sha256']??null))return false;
            }
            if(in_array(($item['event']??''),['OBSERVE_SIGNED_REPAIR_313',
                'OBSERVE_SIGNED_DIGEST_MISMATCH'],true)) {
                if(($job['signed_repair_313_sha256']??null)!==
                    ($item['facts']['signed_receipt_sha256']??null)
                  ||($job['observed_after_sha256']??null)!==
                    ($item['facts']['observed_after_sha256']??null))
                    return false;
            }
        }
        return ($job['revision']??null)===$index
            && ($job['last_event_sha256']??null)===$prev
            && ($job['state']??null)===$state
            && ($job['execution_authorized_by_repair_job']??true)===false
            && ($job['original_305_submitted_by_repair_job']??true)===false;
    }
    public static function fresh(array $scope,int $epoch,array $resourceBinding=[]):array {
        $id=self::key($scope);
        if($id===''||!self::sha($scope['source_plan_sha256']??null))return [];
        $job=array_merge($scope,[
            'contract'=>self::CONTRACT,'id'=>$id,
            'state'=>'AWAITING_RESOURCE_BASELINE','revision'=>0,
            'events'=>[],'last_event_sha256'=>str_repeat('0',64),
            'created_epoch'=>$epoch,'updated_epoch'=>$epoch,
            'baseline'=>null,'original_job_id'=>null,
            'rollback_job_id'=>null,'signed_repair_313_sha256'=>null,
            'signed_rollback_313_sha256'=>null,
            'execution_attempted_by_this_module'=>false,
            'execution_authorized_by_repair_job'=>false,
            'original_305_submitted_by_repair_job'=>false,
            'company_publication_certified'=>false,
            'rollback_available_without_original_job'=>false,
        ]);
        if(!str_starts_with($job['resource_key'],'incident:')) {
            $fields=[
                'resource_type','resource_id','resource_resolution_sha256',
                'resolved_resource_before_sha256',
                'resolved_resource_manifest_sha256',
                'resolved_company_preservation_sha256',
                'resolved_resource_observed_epoch'
            ];
            if(array_keys($resourceBinding)!==$fields)return [];
            foreach($fields as $field)$job[$field]=$resourceBinding[$field];
            $job['immutable_resolved_binding_sha256']=self::resolved_binding_hash($job);
        }
        return self::valid_chain($job)?$job:[];
    }
}
}
