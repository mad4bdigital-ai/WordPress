<?php
/**
 * Company Reconcile durable Repair Jobs V1.
 *
 * This is a central-site durable coordinator, never an executor or signer.
 * Every write here changes ONLY its own WordPress option journal. No outbound
 * job submission, grant, tenant data or rollback mutation occurs in this class.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/class-mad4b-company-repair-job-contract.php';
require_once __DIR__.'/class-mad4b-company-repair-resource-resolver.php';
require_once __DIR__.'/class-mad4b-company-resource-snapshot-store.php';
require_once __DIR__.'/class-mad4b-company-repair-capability-discovery.php';
require_once __DIR__.'/class-mad4b-company-repair-baseline-review.php';
if (!class_exists('MAD4B_Company_Reconcile_Repair_Jobs_V1',false)) {
final class MAD4B_Company_Reconcile_Repair_Jobs_V1 {
    public const PREFIX='mad4b_company_repair_job_v1_';
    public const CONTRACT='mad4b.company-reconcile.repair-jobs-orchestrator.v1';
    public const RESOURCE_INDEX_PREFIX='mad4b_company_repair_children_v1_';
    public const MAX_CHILDREN_PER_INCIDENT=64;
    private static function deny(string $reason,int $status=409):WP_Error {
        return new WP_Error('mad4b_company_repair_'.$reason,
            'Company Repair Job blocked: '.$reason,['status'=>$status]);
    }
    public static function central():bool {
        return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && MAD4B_Company_Portfolio_Manager_V1::staging()
            && class_exists('MAD4B_Company_Reconcile_Work_Plan_V1',false)
            && class_exists('MAD4B_Company_Environment_Registry_V2',false);
    }
    private static function cli_owner():bool {
        return self::central()
            && defined('WP_CLI')&&WP_CLI
            && PHP_SAPI==='cli'
            && current_user_can('manage_options');
    }
    private static function read_owner():bool {
        return self::central() && current_user_can('manage_options');
    }
    public static function job_key(string $id):string {
        return self::PREFIX.$id;
    }
    private static function lock(string $id):?string {
        if(!preg_match('/^[a-f0-9]{40}$/D',$id))return null;
        global $wpdb;
        $name='mad4b_repair_'.substr(hash('sha256',$id),0,45);
        $got=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)',$name));
        return (int)$got===1?$name:null;
    }
    private static function unlock(?string $name):void {
        if(!$name)return;
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
    }
    /** Identical intent and plan hash return the same durable job ID. */
    public static function plan(string $company,string $environment,string $incident):array|WP_Error {
        if(!self::central())return self::deny('central_runtime_unavailable',403);
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
            ||!in_array($environment,['production','staging'],true)
            ||!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$incident))
            return self::deny('invalid_exact_scope',422);
        $all=MAD4B_Company_Reconcile_Work_Plan_V1::inspect($company,$environment);
        if(is_wp_error($all))return $all;
        if(($all['company_binding_verified']??false)!==true
            ||($all['requested_environment_registered']??false)!==true
            ||($all['depth_audit']['dependency_graph_valid']??false)!==true)
            return self::deny('company_identity_or_source_policy_unverified');
        $target=$all['environment_inventory'][$environment]??null;
        if(!is_array($target)
            ||!wp_is_uuid((string)($target['site_uuid']??null))
            ||!is_string($target['canonical_origin']??null)
            ||!preg_match('~^https://[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$~iD',$target['canonical_origin']))
            return self::deny('target_identity_missing');
        $candidate=null;
        foreach($all['incident_scenarios'] as $row)
            if(($row['code']??null)===$incident){$candidate=$row;break;}
        if(!is_array($candidate))return self::deny('incident_not_in_source_policy',422);
        $check=(string)$candidate['related_check'];
        $work=null;
        foreach($all['work_items'] as $item)
            if(($item['check']??null)===$check){$work=$item;break;}
        if(!is_array($work))return self::deny('incident_parent_check_missing');
        $providerPolicy=MAD4B_Company_Reconcile_Work_Plan_V1::original_policy();
        if(($providerPolicy['repair_provider_discovery_contract']??'')!==
              MAD4B_Company_Repair_Capability_Discovery_V1::CONTRACT
            ||($providerPolicy['repair_provider_discovery_mode']??'')!==
              'trusted_original_registry_scoped_readback_dynamic'
            ||($providerPolicy['repair_ambiguous_provider_policy']??'')!==
              'hold_no_auto_selection')
            return self::deny('dynamic_provider_discovery_policy_not_approved');
        // A provider opts into a supported *check* via trusted source
        // callback metadata. Operation names are never enumerated here.
        $discovery=MAD4B_Company_Repair_Capability_Discovery_V1::discover($check);
        $declared=$discovery['selected']['operation']??null;
        $scope=[
            'company_code'=>$company,'environment'=>$environment,
            'site_uuid'=>$target['site_uuid'],
            'canonical_origin'=>$target['canonical_origin'],
            'incident_code'=>$incident,
            'resource_key'=>'incident:'.$incident,
            'source_plan_sha256'=>$all['work_plan_sha256'],
        ];
        $id=MAD4B_Company_Repair_Job_Contract_V1::key($scope);
        if($id==='')return self::deny('immutable_job_identity_invalid');
        $base=$scope+[
            'id'=>$id,'contract'=>self::CONTRACT.'.plan',
            'stage'=>$work['stage'],'priority'=>$candidate['priority'],
            'target_check'=>$check,'ownership_scope'=>$candidate['ownership_scope'],
            'owner'=>$candidate['owner'],'repair_strategy'=>$candidate['repair_strategy'],
            'acceptance'=>$candidate['acceptance'],
            'evidence_lane'=>$all['depth_audit']['incident_probe_mapping']
                [array_search($incident,array_column(
                    $all['depth_audit']['incident_probe_mapping'],'code'),true)]
                ['evidence_lane']??'unmapped',
            'native_operation'=>$declared,
            'provider_resolution_state'=>$discovery['state'],
            'provider_resolution_sha256'=>$discovery['capability_fingerprint'],
            'registered_provider_candidates'=>$discovery['candidates'],
            'original_provider_required'=>true,
            'native_operation_missing_requires_provider_capability'=>$declared===null,
            'dynamic_registry_discovery_only'=>true,
            'required_dependency_checks'=>$work['depends_on']??[],
            'resource_binding_state'=>'LOGICAL_INCIDENT_ONLY__EXACT_RESOURCE_RESOLVER_REQUIRED',
            'resource_manifest_signed'=>false,
            'exact_resource_original_binding_supported'=>false,
            'target_probe_check'=>$candidate['target_probe_check']??null,
            'ready_for_first_write'=>false,
            'direct_target_write_allowed'=>false,
            'original_305_job_created'=>false,'original_313_verified'=>false,
            'postimage_independent_verified'=>false,
            'rollback_executable_without_new_original_job'=>false,
            'mutation_performed'=>false,
        ];
        return $base;
    }
    /** Resolve a specific company WordPress resource, never an incident alias. */
    public static function resource_preview(string $company,string $environment,
          string $incident,string $resourceType,string $resourceId):array|WP_Error {
        if(!self::read_owner())return self::deny('admin_only',403);
        $plan=self::plan($company,$environment,$incident);
        if(is_wp_error($plan))return $plan;
        return self::resource_preview_from_plan($plan,$resourceType,$resourceId);
    }
    private static function resource_preview_from_plan(
        array $plan,string $resourceType,string $resourceId):array|WP_Error {
        $company=$plan['company_code'];
        $environment=$plan['environment'];
        $incident=$plan['incident_code'];
        $selector=['resource_type'=>$resourceType,'resource_id'=>$resourceId];
        if(!MAD4B_Company_Repair_Resource_Resolver_V2::selector_valid($selector))
            return self::deny('invalid_resource_selector',422);
        $candidates=$plan['registered_provider_candidates'];
        $discovery=[
            'state'=>$plan['provider_resolution_state'],
            'selected'=>count($candidates)===1
                 &&$plan['provider_resolution_state']==='EXACT_CAPABILITY_DISCOVERED'
                 ?$candidates[0]:null,
        ];
        $scope=[
            'company_code'=>$plan['company_code'],
            'environment'=>$plan['environment'],
            'site_uuid'=>$plan['site_uuid'],
            'canonical_origin'=>$plan['canonical_origin'],
            'source_plan_sha256'=>$plan['source_plan_sha256'],
            'incident_code'=>$plan['incident_code'],
            'check'=>$plan['target_check'],
        ];
        $out=MAD4B_Company_Repair_Resource_Resolver_V2::resolve(
            $scope,$discovery,$selector);
        $scopeOwner=$plan['ownership_scope']??null;
        $observedOwner=$out['ownership_scope']??null;
        if(($out['state']??null)==='EXACT_RESOURCE_OBSERVED_UNSIGNED'
            &&($scopeOwner!=='mixed'&&$scopeOwner!==$observedOwner)) {
            // A provider is not allowed to reclassify tenant-owned or
            // site-generated values as portable source definitions.
            return [
                'contract'=>MAD4B_Company_Repair_Resource_Resolver_V2::CONTRACT,
                'state'=>'RESOURCE_RESOLUTION_HOLD',
                'reason'=>'PROVIDER_RESOURCE_OWNERSHIP_CONFLICT_WITH_SOURCE',
                'company_code'=>$company,'environment'=>$environment,
                'site_uuid'=>$plan['site_uuid'],
                'canonical_origin'=>$plan['canonical_origin'],
                'resource_observed'=>false,'resource_manifest_signed'=>false,
                'original_313_signed'=>false,'repair_execution_authorized'=>false,
                'target_mutated'=>false,'new_native_job_created'=>false
            ];
        }
        // Expose three independent states to the operator: local target
        // evidence, exact signed 312 capability, and absent Original 313.
        $cap=($out['state']??null)==='EXACT_RESOURCE_OBSERVED_UNSIGNED'
            ?self::exact_signed_provider_312([
                'company_code'=>$plan['company_code'],
                'environment'=>$plan['environment'],
                'site_uuid'=>$plan['site_uuid'],
                'canonical_origin'=>$plan['canonical_origin'],
                'native_operation'=>$plan['native_operation'],
            ],$plan)
            :['state'=>'NOT_CHECKED_RESOURCE_OBSERVATION_HOLD',
                'reason'=>'EXACT_RESOURCE_NOT_OBSERVED','verified'=>false];
        $out['exact_scoped_312_state']=$cap['state'];
        $out['exact_scoped_312_verified']=($cap['verified']??false)===true;
        $out['exact_scoped_312_proof_age_seconds']=($cap['verified']??false)===true
            ?max(0,time()-$cap['heartbeat_epoch']):null;
        $out['exact_scoped_312_expires_in_seconds']=($cap['verified']??false)===true
            ?max(0,300-max(0,time()-$cap['heartbeat_epoch'])):null;
        $out['imported_target_snapshot_expires_in_seconds']=
            ($out['state']??null)==='EXACT_RESOURCE_OBSERVED_UNSIGNED'
              ?max(0,MAD4B_Company_Resource_Snapshot_Store_V1::MAX_AGE-
                       (int)($out['evidence_age_seconds']??0)):null;
        $out['original_313_for_this_resource_verified']=false;
        $out['repair_execution_ready']=false;
        $out['readback_is_original_313']=false;
        $out['target_readback_evidence_level']=($out['state']??null)==='EXACT_RESOURCE_OBSERVED_UNSIGNED'
             ?'TARGET_WPCLI_SNAPSHOT_IMPORTED_UNSIGNED':'NOT_VERIFIED';
        // This preview is an authenticated, *unsigned* source observation.
        // No raw target metadata is copied to customer-facing pages.
        $out['incident_code']=$incident;
        $out['target_check']=$plan['target_check'];
        $out['source_policy_owned_data_preserved']=true;
        $out['new_native_job_created']=false;
        $out['resource_job_persisted']=false;
        return $out;
    }

    /**
     * Persist a unique exact-resource child journal after an authenticated
     * provider readback. No WPCLI target command is submitted here.
     */
    public static function create_resource(string $company,string $environment,
         string $incident,string $resourceType,string $resourceId,
         string $expectedPlanHash):array|WP_Error {
        if(!self::cli_owner())return self::deny('cli_owner_required',403);
        $plan=self::plan($company,$environment,$incident);
        if(is_wp_error($plan))return $plan;
        if(!MAD4B_Company_Repair_Job_Contract_V1::sha($expectedPlanHash)
           ||!hash_equals($plan['source_plan_sha256'],$expectedPlanHash))
            return self::deny('source_plan_changed');
        $observation=self::resource_preview_from_plan($plan,$resourceType,$resourceId);
        if(is_wp_error($observation))return $observation;
        if(($observation['state']??null)!=='EXACT_RESOURCE_OBSERVED_UNSIGNED'
           ||($observation['resource_observed']??false)!==true
           ||($observation['operation']??null)!==($plan['native_operation']??null)
           ||($observation['provider']??null)!==
             ($plan['registered_provider_candidates'][0]['provider']??null)
           ||($observation['source_plan_sha256']??null)!==$expectedPlanHash)
            return self::deny('resource_resolver_evidence_not_eligible');
        $scope=[
            'company_code'=>$company,'environment'=>$environment,
            'site_uuid'=>$plan['site_uuid'],
            'canonical_origin'=>$plan['canonical_origin'],
            'incident_code'=>$incident,
            'resource_key'=>$observation['resource_key'],
            'source_plan_sha256'=>$plan['source_plan_sha256']
        ];
        $id=MAD4B_Company_Repair_Job_Contract_V1::key($scope);
        if($id==='')return self::deny('exact_resource_job_id_invalid');
        $lock=self::lock($id);
        if(!$lock)return self::deny('resource_journal_lease_busy');
        $parentLock=self::lock(self::index_parent_id($scope));
        if(!$parentLock) {
            self::unlock($lock);
            return self::deny('resource_index_lease_busy');
        }
        try{
            $index=self::child_index_read($scope);
            if(is_wp_error($index))return $index;
            if(count($index['ids'])>=self::MAX_CHILDREN_PER_INCIDENT
               &&!in_array($id,$index['ids'],true))
                return self::deny('resource_child_index_capacity_exceeded');
            $old=get_option(self::job_key($id),false);
            if($old!==false){
                $saved=self::raw($id);if(is_wp_error($saved))return $saved;
                if(($saved['resource_resolution_sha256']??null)!==
                    $observation['resource_resolution_sha256'])
                    return self::deny('previous_resource_observation_changed');
                $profileReconciled=false;
                if(($saved['provider_resolution_sha256']??null)!==
                    $plan['provider_resolution_sha256']) {
                    // Only a still-empty, exact same-provider resource job
                    // may adopt a newly versioned capability fingerprint.
                    // A previous claim, baseline, or signed result is NEVER
                    // modified or silently reused.
                    $eligible=($saved['state']??null)==='AWAITING_RESOURCE_BASELINE'
                        &&($saved['baseline']??null)===null
                        &&($saved['original_job_id']??null)===null
                        &&($saved['signed_repair_313_sha256']??null)===null
                        &&($saved['native_operation']??null)===($plan['native_operation']??null)
                        &&($saved['resource_key']??null)===$observation['resource_key']
                        &&($saved['ownership_scope']??null)===$observation['ownership_scope']
                        &&($saved['resolved_company_preservation_sha256']??null)===
                           $observation['company_preservation_sha256']
                        &&($saved['resolved_resource_before_sha256']??null)===
                           $observation['before_sha256']
                        &&($saved['resolved_resource_manifest_sha256']??null)===
                           $observation['resource_manifest_sha256'];
                    if(!$eligible)
                        return self::deny('provider_capability_drift_started_job_hold');
                    $next=MAD4B_Company_Repair_Job_Contract_V1::append(
                        $saved,'RECONCILE_PROVIDER_CAPABILITY',[
                            'old_provider_sha256'=>$saved['provider_resolution_sha256'],
                            'new_provider_sha256'=>$plan['provider_resolution_sha256'],
                            'resource_resolution_sha256'=>$observation['resource_resolution_sha256'],
                            'no_replay'=>true,'native_job_submitted'=>false
                        ],time());
                    if(isset($next['error']))
                        return self::deny('provider_capability_journal_transition_invalid');
                    $next['provider_resolution_sha256']=$plan['provider_resolution_sha256'];
                    $next['immutable_plan_sha256']=hash('sha256',
                        wp_json_encode([$plan,$observation],
                            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                    $next['immutable_descriptor_sha256']=
                        MAD4B_Company_Repair_Job_Contract_V1::descriptor($next);
                    if(!self::persist($next))
                        return self::deny('provider_capability_reconciliation_persistence_failed');
                    $saved=$next;
                    $profileReconciled=true;
                }
                if(!self::child_index_append($scope,$id))
                    return self::deny('existing_resource_index_update_failed');
                return [
                    'state'=>$profileReconciled
                        ?'EXACT_RESOURCE_PROVIDER_PROFILE_RECONCILED_NO_REPLAY'
                        :'EXACT_RESOURCE_JOB_ALREADY_EXISTS',
                    'repair_id'=>$id,'revision'=>$saved['revision'],
                    'job_state'=>$saved['state'],
                    'provider_profile_reconciled'=>$profileReconciled,
                    'duplicate_original_job_created'=>false
                ];
            }
            $binding=[
                'resource_type'=>$observation['resource_type'],
                'resource_id'=>$observation['resource_id'],
                'resource_resolution_sha256'=>$observation['resource_resolution_sha256'],
                'resolved_resource_before_sha256'=>$observation['before_sha256'],
                'resolved_resource_manifest_sha256'=>$observation['resource_manifest_sha256'],
                'resolved_company_preservation_sha256'=>$observation['company_preservation_sha256'],
                'resolved_resource_observed_epoch'=>$observation['observed_epoch']
            ];
            $job=MAD4B_Company_Repair_Job_Contract_V1::fresh($scope,time(),$binding);
            if(!$job)return self::deny('resource_binding_immutable_job_invalid');
            $job['target_check']=$plan['target_check'];
            $job['ownership_scope']=$observation['ownership_scope'];
            $job['evidence_lane']=$plan['evidence_lane'];
            $job['native_operation']=$plan['native_operation'];
            $job['provider_resolution_sha256']=$plan['provider_resolution_sha256'];
            $job['required_dependencies']=$plan['required_dependency_checks'];
            $job['acceptance']=$plan['acceptance'];
            $job['owner']=$plan['owner'];
            $job['repair_strategy']=$plan['repair_strategy'];
            $job['immutable_plan_sha256']=hash('sha256',
                wp_json_encode([$plan,$observation],
                    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $job['immutable_descriptor_sha256']=
                MAD4B_Company_Repair_Job_Contract_V1::descriptor($job);
            if(!MAD4B_Company_Repair_Job_Contract_V1::valid_chain($job))
                return self::deny('resource_job_integrity_validation_failed');
            if(!add_option(self::job_key($id),$job,'',false))
                return self::deny('resource_job_insert_conflict');
            $stored=self::raw($id);
            if(is_wp_error($stored)
               ||($stored['immutable_resolved_binding_sha256']??null)!==
                   $job['immutable_resolved_binding_sha256'])
                return self::deny('resource_job_durable_readback_failed');
            if(!self::child_index_append($scope,$id))
                return self::deny('resource_journal_saved_index_repair_required');
            return [
                'state'=>'EXACT_RESOURCE_JOB_PERSISTED_AWAITING_BASELINE',
                'repair_id'=>$id,'revision'=>0,
                'company_code'=>$company,'environment'=>$environment,
                'resource_key'=>$observation['resource_key'],
                'source_plan_sha256'=>$expectedPlanHash,
                'resource_resolution_sha256'=>$observation['resource_resolution_sha256'],
                'resource_evidence_signed'=>false,
                'new_native_305_submitted'=>false,'target_mutated'=>false
            ];
        }finally{self::unlock($parentLock);self::unlock($lock);}
    }
    private static function index_parent_id(array $scope):string {
        $basis=$scope;
        $basis['resource_key']='incident:'.$scope['incident_code'];
        return MAD4B_Company_Repair_Job_Contract_V1::key($basis);
    }
    private static function child_index_digest(array $row):string {
        $fields=['contract','company_code','environment','site_uuid',
            'canonical_origin','incident_code','source_plan_sha256','ids'];
        $data=[];
        foreach($fields as $field) {
            if(!array_key_exists($field,$row))return '';
            $data[$field]=$row[$field];
        }
        $encoded=wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return is_string($encoded)?hash('sha256',$encoded):'';
    }
    private static function child_index_read(array $scope):array|WP_Error {
        $parent=self::index_parent_id($scope);
        if($parent==='')return self::deny('child_index_scope_invalid');
        $index=get_option(self::RESOURCE_INDEX_PREFIX.$parent,false);
        if($index===false) {
            $empty=[
                'contract'=>self::CONTRACT.'.children-index',
                'company_code'=>$scope['company_code'],
                'environment'=>$scope['environment'],
                'site_uuid'=>$scope['site_uuid'],
                'canonical_origin'=>$scope['canonical_origin'],
                'incident_code'=>$scope['incident_code'],
                'source_plan_sha256'=>$scope['source_plan_sha256'],
                'ids'=>[]
            ];
            $empty['index_sha256']=self::child_index_digest($empty);
            return $empty;
        }
        if(!is_array($index)||($index['contract']??null)!==
             self::CONTRACT.'.children-index')return self::deny('child_index_corrupt');
        foreach(['company_code','environment','site_uuid','canonical_origin',
                  'incident_code','source_plan_sha256'] as $k)
            if(($index[$k]??null)!==($scope[$k]??null))
                return self::deny('child_index_tenant_or_plan_drift');
        $ids=$index['ids']??null;
        if(!is_array($ids)||count($ids)>self::MAX_CHILDREN_PER_INCIDENT
            ||count(array_unique($ids))!==count($ids))return self::deny('child_index_overflow');
        foreach($ids as $id)
            if(!is_string($id)||!preg_match('/^[a-f0-9]{40}$/D',$id))
                return self::deny('child_index_invalid_id');
        if(!MAD4B_Company_Repair_Job_Contract_V1::sha($index['index_sha256']??null)
            ||!hash_equals($index['index_sha256'],self::child_index_digest($index)))
            return self::deny('child_index_digest_mismatch');
        return $index;
    }
    private static function child_index_append(array $scope,string $id):bool {
        $row=self::child_index_read($scope);
        if(is_wp_error($row))return false;
        if(in_array($id,$row['ids'],true))return true;
        if(count($row['ids'])>=self::MAX_CHILDREN_PER_INCIDENT)return false;
        $parent=self::index_parent_id($scope);
        $key=self::RESOURCE_INDEX_PREFIX.$parent;
        $old=get_option($key,false);
        $row['ids'][]=$id;
        $row['index_sha256']=self::child_index_digest($row);
        $saved=$old===false
            ?add_option($key,$row,'',false)
            :update_option($key,$row,false);
        $readback=self::child_index_read($scope);
        return ($saved||$readback===$row)
            &&!is_wp_error($readback)
            &&in_array($id,$readback['ids'],true);
    }
    private static function raw(string $id):array|WP_Error {
        if(!preg_match('/^[a-f0-9]{40}$/D',$id))return self::deny('invalid_job_id',422);
        $job=get_option(self::job_key($id),false);
        if(!is_array($job))return self::deny('job_not_found',404);
        $contract='MAD4B_Company_Repair_Job_Contract_V1';
        if(($job['id']??null)!==$id||!$contract::valid_chain($job))
            return self::deny('job_journal_integrity_failed');
        return $job;
    }
    private static function persist(array $job):bool {
        $id=(string)($job['id']??'');
        if(!preg_match('/^[a-f0-9]{40}$/D',$id)
            ||!MAD4B_Company_Repair_Job_Contract_V1::valid_chain($job))
            return false;
        // Store readback is mandatory; never report an event as durable
        // before the WordPress options table and cache agree on its revision.
        $written=update_option(self::job_key($id),$job,false);
        $actual=get_option(self::job_key($id),false);
        return ($written||$actual===$job)
            &&is_array($actual)
            &&($actual['revision']??-1)===$job['revision']
            &&($actual['last_event_sha256']??null)===$job['last_event_sha256'];
    }
    public static function create(string $company,string $environment,string $incident,
        string $expectedPlanHash):array|WP_Error {
        if(!self::cli_owner())return self::deny('central_cli_owner_required',403);
        $plan=self::plan($company,$environment,$incident);
        if(is_wp_error($plan))return $plan;
        if(!MAD4B_Company_Repair_Job_Contract_V1::sha($expectedPlanHash)
            ||!hash_equals($plan['source_plan_sha256'],$expectedPlanHash))
            return self::deny('source_plan_changed');
        $id=$plan['id'];
        $lock=self::lock($id);
        if(!$lock)return self::deny('job_lease_busy');
        try {
            $existing=get_option(self::job_key($id),false);
            if($existing!==false) {
                $row=self::raw($id);
                if(is_wp_error($row))return $row;
                $legacyUpgraded=false;
                if(!isset($row['immutable_descriptor_sha256'])) {
                    // Single historic V1 journal was created before descriptor
                    // binding. Upgrade only untouched revision-0 state with
                    // the exact current plan and the unchanged full metadata.
                    $planHash=hash('sha256',wp_json_encode($plan,
                        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                    $verify=[
                        'target_check'=>'target_check',
                        'ownership_scope'=>'ownership_scope',
                        'evidence_lane'=>'evidence_lane',
                        'native_operation'=>'native_operation',
                        'provider_resolution_sha256'=>'provider_resolution_sha256',
                        'required_dependency_checks'=>'required_dependencies',
                        'acceptance'=>'acceptance','owner'=>'owner',
                        'repair_strategy'=>'repair_strategy'
                    ];
                    $same=($row['revision']??null)===0
                        &&($row['state']??null)==='AWAITING_RESOURCE_BASELINE'
                        &&($row['immutable_plan_sha256']??null)===$planHash;
                    foreach($verify as $source=>$dest)
                        if(($row[$dest]??null)!==($plan[$source]??null))$same=false;
                    if(!$same)return self::deny('legacy_descriptor_exact_plan_drift');
                    $row['immutable_descriptor_sha256']=
                        MAD4B_Company_Repair_Job_Contract_V1::descriptor($row);
                    if($row['immutable_descriptor_sha256']===''
                        ||!self::persist($row))
                        return self::deny('legacy_descriptor_backfill_failed');
                    $legacyUpgraded=true;
                }
                return ['state'=>'ALREADY_EXISTS','job_id'=>$id,
                    'revision'=>$row['revision'],'job_state'=>$row['state'],
                    'immutable_descriptor_verified'=>true,
                    'legacy_descriptor_reconciled'=>$legacyUpgraded,
                    'new_native_job_submitted'=>false,'resumable'=>true];
            }
            $scope=array_intersect_key($plan,array_flip([
                'company_code','environment','site_uuid','canonical_origin',
                'incident_code','resource_key','source_plan_sha256']));
            $job=MAD4B_Company_Repair_Job_Contract_V1::fresh($scope,time());
            if(!$job)return self::deny('job_build_failed');
            $job['target_check']=$plan['target_check'];
            $job['ownership_scope']=$plan['ownership_scope'];
            $job['evidence_lane']=$plan['evidence_lane'];
            $job['native_operation']=$plan['native_operation'];
            $job['provider_resolution_sha256']=$plan['provider_resolution_sha256'];
            $job['required_dependencies']=$plan['required_dependency_checks'];
            $job['acceptance']=$plan['acceptance'];
            $job['owner']=$plan['owner'];
            $job['repair_strategy']=$plan['repair_strategy'];
            $job['immutable_plan_sha256']=hash('sha256',wp_json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $job['immutable_descriptor_sha256']=MAD4B_Company_Repair_Job_Contract_V1::descriptor($job);
            if(!MAD4B_Company_Repair_Job_Contract_V1::valid_chain($job))
                return self::deny('immutable_descriptor_build_failed');
            if(!add_option(self::job_key($id),$job,'',false))
                return self::deny('job_creation_conflict');
            $confirmed=self::raw($id);
            if(is_wp_error($confirmed)
                ||($confirmed['immutable_plan_sha256']??null)!==$job['immutable_plan_sha256'])
                return self::deny('job_creation_readback_failed');
            return ['state'=>'JOB_PERSISTED_AWAITING_RESOURCE_BASELINE',
                'job_id'=>$id,'revision'=>0,
                'job_state'=>$job['state'],
                'source_plan_sha256'=>$job['source_plan_sha256'],
                'original_job_submitted'=>false,'tenant_mutated'=>false,
                'resumable'=>true];
        }finally{self::unlock($lock);}
    }
    /** Explicit operator assessment: review a *candidate*, never record a baseline. */
    public static function baseline_review(string $id):array|WP_Error {
        if(!self::read_owner())return self::deny('admin_only',403);
        $job=self::raw($id);
        if(is_wp_error($job))return $job;
        $plan=self::plan($job['company_code'],$job['environment'],
            $job['incident_code']);
        if(is_wp_error($plan))return $plan;
        return self::baseline_review_from_plan($plan,$job);
    }
    private static function baseline_review_from_plan(
        array $plan,array $job):array|WP_Error {
        if(($plan['site_uuid']??null)!==$job['site_uuid']
            ||($plan['canonical_origin']??null)!==$job['canonical_origin']
            ||($plan['source_plan_sha256']??null)!==$job['source_plan_sha256']
            ||($plan['incident_code']??null)!==$job['incident_code']
            ||($plan['native_operation']??null)!==($job['native_operation']??null)
            ||($plan['provider_resolution_sha256']??null)!==
                 ($job['provider_resolution_sha256']??null))
            return self::deny('baseline_review_current_plan_or_provider_drift');
        if(str_starts_with((string)($job['resource_key']??''),'incident:'))
            return self::deny('baseline_review_requires_exact_resource',422);
        $preview=self::resource_preview_from_plan(
            $plan,$job['resource_type'],$job['resource_id']);
        if(is_wp_error($preview))return $preview;
        return MAD4B_Company_Repair_Baseline_Review_V1::inspect(
            $job,$preview,time());
    }
    public static function baseline_review_scope(string $company,string $environment,
        string $incident,string $type,string $resourceId):array|WP_Error {
        if(!self::read_owner())return self::deny('admin_only',403);
        if(!MAD4B_Company_Repair_Resource_Resolver_V2::selector_valid([
            'resource_type'=>$type,'resource_id'=>$resourceId]))
            return self::deny('invalid_resource_selector',422);
        $plan=self::plan($company,$environment,$incident);
        if(is_wp_error($plan))return $plan;
        $id=MAD4B_Company_Repair_Job_Contract_V1::key([
            'company_code'=>$company,'environment'=>$environment,
            'site_uuid'=>$plan['site_uuid'],
            'canonical_origin'=>$plan['canonical_origin'],
            'incident_code'=>$incident,
            'resource_key'=>$type.':'.$resourceId,
            'source_plan_sha256'=>$plan['source_plan_sha256']
        ]);
        if($id==='')return self::deny('baseline_review_exact_job_identity_invalid');
        $job=self::raw($id);
        if(is_wp_error($job))return $job;
        return self::baseline_review_from_plan($plan,$job);
    }
    public static function rest_baseline_review(WP_REST_Request $request) {
        $result=self::baseline_review_scope(
            (string)$request->get_param('company'),
            (string)$request->get_param('environment'),
            (string)$request->get_param('incident'),
            (string)$request->get_param('resource_type'),
            (string)$request->get_param('resource_id'));
        if(is_wp_error($result))return $result;
        $response=rest_ensure_response($result);
        $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
    public static function status(string $id):array|WP_Error {
        if(!self::read_owner())return self::deny('admin_only',403);
        return self::raw($id);
    }
    public static function baseline(string $id,array $baseline):array|WP_Error {
        if(!self::cli_owner())return self::deny('cli_owner_required',403);
        $lock=self::lock($id);
        if(!$lock)return self::deny('job_lease_busy');
        try {
            $job=self::raw($id);if(is_wp_error($job))return $job;
            if($job['state']!=='AWAITING_RESOURCE_BASELINE')
                return self::deny('baseline_not_replaceable_after_creation');
            if(!MAD4B_Company_Repair_Job_Contract_V1::sha(
                $job['immutable_descriptor_sha256']??null))
                return self::deny('legacy_job_descriptor_needs_reconciliation');
            $now=time();
            if(!str_starts_with((string)$job['resource_key'],'incident:')) {
                // New signed 305 work may start long after this journal was
                // created. Re-resolve exact target bytes instead of trusting
                // a stale initial timestamp or silently changing the baseline.
                $fresh=self::resource_preview($job['company_code'],$job['environment'],
                    $job['incident_code'],$job['resource_type'],$job['resource_id']);
                if(is_wp_error($fresh)
                   ||($fresh['state']??null)!=='EXACT_RESOURCE_OBSERVED_UNSIGNED'
                   ||($fresh['resource_resolution_sha256']??null)!==
                       ($job['resource_resolution_sha256']??null)
                   ||($fresh['company_preservation_sha256']??null)!==
                       ($job['resolved_company_preservation_sha256']??null)
                   ||($baseline['before_sha256']??null)!==
                       ($job['resolved_resource_before_sha256']??null)
                   ||($baseline['resource_manifest_sha256']??null)!==
                       ($job['resolved_resource_manifest_sha256']??null))
                    return self::deny('fresh_exact_resource_or_baseline_mismatch');
            }
            if(!MAD4B_Company_Repair_Job_Contract_V1::baseline_valid($baseline,$job,$now))
                return self::deny('baseline_identity_age_or_digest_invalid');
            $clean=array_intersect_key($baseline,array_flip([
                'contract','company_code','environment','site_uuid','canonical_origin',
                'resource_key','source_plan_sha256','before_sha256','rollback_sha256',
                'expected_after_sha256','resource_manifest_sha256',
                'evidence_level','observed_epoch']));
            $next=MAD4B_Company_Repair_Job_Contract_V1::append($job,
                'BASELINE_COLLECTED',[
                    'before_sha256'=>$clean['before_sha256'],
                    'rollback_sha256'=>$clean['rollback_sha256'],
                    'expected_after_sha256'=>$clean['expected_after_sha256'],
                    'resource_manifest_sha256'=>$clean['resource_manifest_sha256'],
                    'baseline_signature_verified'=>false,
                ],$now);
            if(isset($next['error']))return self::deny('transition_'.$next['error']);
            $next['baseline']=$clean;
            if(!self::persist($next))return self::deny('baseline_persistence_unverified');
            return ['state'=>$next['state'],'job_id'=>$id,
                'revision'=>$next['revision'],'baseline_signed'=>false,
                'original_305_job_created'=>false,'can_apply'=>false];
        }finally{self::unlock($lock);}
    }
    /**
     * The generic Original transport preflight is not enough. Require a
     * separately source-backed provider callback that verifies the exact
     * Original 312 signature for company+environment+operation.
     */
    private static function exact_signed_provider_312(array $job,array $plan):array {
        $blocked=static fn(string $why):array=>[
            'state'=>'EXACT_COMPANY_PROVIDER_312_HOLD',
            'reason'=>$why,'verified'=>false,
            'source_signature_verified'=>false,
            'original_313_signed'=>false
        ];
        $operation=$job['native_operation']??null;
        $registry='MAD4B_Original_Native_Operation_Providers_V1';
        $candidates=$plan['registered_provider_candidates']??[];
        if(!is_string($operation)
            ||($plan['provider_resolution_state']??null)!=='EXACT_CAPABILITY_DISCOVERED'
            ||!is_array($candidates)||count($candidates)!==1
            ||($candidates[0]['operation']??null)!==$operation
            ||!class_exists($registry,false)
            ||!is_callable([$registry,'profile']))
            return $blocked('PROVIDER_OPERATION_EXACT_SELECTION_MISSING');
        $profile=$registry::profile($operation);
        if(!is_array($profile)
            ||($profile['provider']??null)!==($candidates[0]['provider']??null)
            ||($profile['authority_family']??null)!=='original_305'
            ||($profile['target_scope']??null)!=='wordpress'
            ||!is_array($profile['readback']??null))
            return $blocked('PROVIDER_PROFILE_CHANGED');
        try {
            $readback=call_user_func($profile['readback']);
            $support=is_array($readback)?($readback['repair_support']??null):null;
            $callback=is_array($support)?($support['scoped_preflight']??null):null;
            if(!is_array($support)
               ||($readback['contract']??null)!==($profile['evidence_contract']??null)
               ||($support['operation']??null)!==$operation
               ||($support['provider']??null)!==$profile['provider']
               ||($support['scoped_preflight_contract']??null)!==
                   'mad4b.company-repair.exact-provider-312.v1'
               ||($support['scoped_preflight_read_only']??null)!==true
               ||!is_array($callback)
               ||($candidates[0]['scoped_preflight_id']??null)!==
                   implode('::',$callback)
               ||!MAD4B_Company_Repair_Resource_Resolver_V2::trusted_provider_resolver(
                  $profile,$callback))
                return $blocked('EXACT_SOURCE_PROVIDER_SIGNED_312_CALLBACK_UNTRUSTED');
            $result=call_user_func($callback,$job['company_code'],
                $job['environment'],$operation);
        }catch(Throwable $e) {
            return $blocked('EXACT_SOURCE_PROVIDER_312_READBACK_EXCEPTION');
        }
        $now=time();
        $valid=is_array($result)
           &&($result['contract']??null)==='mad4b.company-repair.exact-provider-312.v1'
           &&($result['state']??null)==='EXACT_SOURCE_SIGNED_ORIGINAL_312_VERIFIED'
           &&($result['original_312_signed']??false)===true
           &&($result['source_signature_verified']??false)===true
           &&($result['original_313_signed']??true)===false
           &&($result['native_job_submitted']??true)===false
           &&($result['company_code']??null)===$job['company_code']
           &&($result['environment']??null)===$job['environment']
           &&($result['site_uuid']??null)===$job['site_uuid']
           &&($result['canonical_origin']??null)===$job['canonical_origin']
           &&($result['operation']??null)===$operation
           &&($result['provider']??null)===$profile['provider']
           &&is_int($result['heartbeat_epoch']??null)
           &&$result['heartbeat_epoch']<=$now+10
           &&$result['heartbeat_epoch']>=$now-300;
        return $valid?[
            'state'=>'EXACT_SOURCE_SIGNED_ORIGINAL_312_VERIFIED',
            'reason'=>null,'verified'=>true,'source_signature_verified'=>true,
            'original_313_signed'=>false,
            'heartbeat_epoch'=>$result['heartbeat_epoch']
        ]:$blocked((string)($result['reason']??'EXACT_SIGNED_312_NOT_CURRENT'));
    }
    public static function preflight(string $id):array|WP_Error {
        if(!self::read_owner())return self::deny('admin_only',403);
        $job=self::raw($id);if(is_wp_error($job))return $job;
        $current=self::plan($job['company_code'],$job['environment'],$job['incident_code']);
        if(is_wp_error($current))return $current;
        $matched=hash_equals($current['source_plan_sha256'],$job['source_plan_sha256'])
            &&$current['site_uuid']===$job['site_uuid']
            &&$current['canonical_origin']===$job['canonical_origin']
            &&($current['native_operation']??null)===$job['native_operation']
            &&($current['provider_resolution_sha256']??null)===
              ($job['provider_resolution_sha256']??null);
        $op=$job['native_operation'];
        // A logical incident key is not a WordPress resource. Avoid costly
        // Original preflights until an exact signed resource resolution exists.
        $resourceBound=!str_starts_with((string)($job['resource_key']??''),'incident:')
            &&MAD4B_Company_Repair_Job_Contract_V1::sha(
                $job['resource_resolution_sha256']??null)
            &&MAD4B_Company_Repair_Job_Contract_V1::sha(
                $job['immutable_resolved_binding_sha256']??null)
            &&MAD4B_Company_Repair_Job_Contract_V1::valid_chain($job);
        if($resourceBound &&$matched) {
            $fresh=self::resource_preview_from_plan(
                $current,$job['resource_type'],$job['resource_id']);
            $resourceBound=!is_wp_error($fresh)
                &&($fresh['state']??null)==='EXACT_RESOURCE_OBSERVED_UNSIGNED'
                &&($fresh['resource_resolution_sha256']??null)===
                    ($job['resource_resolution_sha256']??null)
                &&($fresh['company_preservation_sha256']??null)===
                    ($job['resolved_company_preservation_sha256']??null);
        }
        $status=null;
        if($matched&&$resourceBound&&is_string($op)
            &&class_exists('MAD4B_Company_Native_Write_Executor_V1',false)) {
            try {
                $status=MAD4B_Company_Native_Write_Executor_V1::preflight_status(
                    $job['company_code'],$job['environment'],$op);
            }catch(Throwable $e){
                $status=['state'=>'NATIVE_PREFLIGHT_FAILED_CLOSED',
                    'reason'=>'provider_preflight_exception'];
            }
            if(is_wp_error($status))
                $status=['state'=>'NATIVE_PREFLIGHT_FAILED_CLOSED',
                    'reason'=>$status->get_error_code()];
            elseif(!is_array($status))
                $status=['state'=>'NATIVE_PREFLIGHT_UNSTRUCTURED',
                    'reason'=>'provider_non_array_response'];
        }
        $scoped=$resourceBound&&$matched
            ?self::exact_signed_provider_312($job,$current)
            :['state'=>'EXACT_COMPANY_PROVIDER_312_NOT_QUERIED',
              'reason'=>'EXACT_RESOURCE_OR_CURRENT_PLAN_NOT_VERIFIED',
              'verified'=>false,'source_signature_verified'=>false,
              'original_313_signed'=>false];
        $ready=$resourceBound&&($scoped['verified']??false)===true
            &&is_array($status)&&($status['state']??null)==='AUTHORITY_AND_EXECUTOR_READY_FOR_EXACT_JOB'
            &&($status['scope_preflight_passed']??false)===true
            &&($status['target_wordpress_actor_capability_verified']??false)===true
            &&($status['operation_provider_ready']??false)===true
            &&($status['site_uuid']??'')===$job['site_uuid']
            &&($status['canonical_origin']??'')===$job['canonical_origin'];
        return [
            'contract'=>self::CONTRACT.'.preflight',
            'job_id'=>$id,'state'=>$job['state'],
            'identity_and_current_plan_match'=>$matched,
            'native_operation'=>$op,
            'provider_resolution_state'=>$current['provider_resolution_state'],
            'provider_candidates'=>$current['registered_provider_candidates'],
            'specialized_provider_312_state'=>$scoped['state'],
            'specialized_provider_312_reason'=>$scoped['reason']??null,
            'specialized_provider_signed_312_verified'=>($scoped['verified']??false)===true,
            'specialized_provider_312_expires_in_seconds'=>($scoped['verified']??false)===true
                ?max(0,300-max(0,time()-$scoped['heartbeat_epoch'])):null,
            'general_native_transport_state'=>$status['state']??'NOT_AVAILABLE',
            'original_provider_preflight_ready'=>$ready,
            'native_capability_ready_for_external_original_job'=>$ready,
            'repair_execution_ready'=>false,
            // An imported unsigned snapshot is suitable for inspection and
            // planning only. It never proves an executor-time compare-and-swap.
            'executor_time_before_image_cas_verified'=>false,
            'executor_preimage_cas_admission_state'=>
                'EXACT_TARGET_EXECUTOR_CAS_NOT_PROVEN',
            'independent_original_313_for_this_job_verified'=>false,
            'original_provider_state'=>$resourceBound
                ?(($scoped['verified']??false)===true
                   ?($status['state']??'NOT_AVAILABLE')
                   :'EXACT_SOURCE_PROVIDER_312_HOLD')
                :(str_starts_with((string)($job['resource_key']??''),'incident:')
                    ?'EXACT_RESOURCE_RESOLVER_REQUIRED'
                    :'EXACT_RESOURCE_TARGET_DRIFT_HOLD'),
            'original_provider_reason'=>!$resourceBound
                ?(str_starts_with((string)($job['resource_key']??''),'incident:')
                    ?'EXACT_RESOURCE_RESOLVER_AND_RESOURCE_MANIFEST_REQUIRED'
                    :'EXACT_RESOURCE_REOBSERVATION_CHANGED_OR_FAILED')
                :(($scoped['verified']??false)===true
                  ?($status['reason']??($op===null
                    ?$current['provider_resolution_state']:'SIGNATURE_OR_TRANSPORT_UNVERIFIED'))
                  :($scoped['reason']??'SIGNED_ORIGINAL_312_EXACT_SCOPE_NOT_VERIFIED')),
            'baseline_recorded'=>is_array($job['baseline']??null),
            'resource_binding_state'=>$resourceBound
                ?'EXACT_SOURCE_PROVIDER_RESOURCE_EVIDENCE_PRESENT'
                :(str_starts_with((string)($job['resource_key']??''),'incident:')
                   ?'LOGICAL_INCIDENT_ONLY__EXACT_RESOURCE_RESOLVER_REQUIRED'
                   :'EXACT_RESOURCE_TARGET_CHANGED_OR_READBACK_UNAVAILABLE'),
            'baseline_independently_signed'=>false,
            'original_305_submission_performed'=>false,
            'can_submit_via_this_module'=>false,
            'requires_original_305_owner_submission'=>true,
            'requires_independent_original_313'=>true,
            'requires_exact_command_and_resource_binding'=>true,
            'target_mutation_performed'=>false,
        ];
    }
    public static function prepare(string $id):array|WP_Error {
        if(!self::cli_owner())return self::deny('cli_owner_required',403);
        $lock=self::lock($id);
        if(!$lock)return self::deny('job_lease_busy');
        try {
            $row=self::raw($id);if(is_wp_error($row))return $row;
            if($row['state']!=='BASELINE_RECORDED_UNVERIFIED')
                return self::deny('incorrect_baseline_stage');
            $pre=self::preflight($id);
            if(is_wp_error($pre))return $pre;
            if(!$pre['identity_and_current_plan_match'])
                return self::mark_plan_drift_locked($row);
            if(!$pre['original_provider_preflight_ready'])
                return ['state'=>'PROVIDER_OR_SIGNED_312_HOLD',
                    'job_id'=>$id,'provider_state'=>$pre['original_provider_state'],
                    'reason'=>$pre['original_provider_reason'],
                    'job_state'=>$row['state'],
                    'original_305_submitted'=>false];
            if(($pre['executor_time_before_image_cas_verified']??false)!==true)
                return [
                    'state'=>'EXACT_EXECUTOR_PREIMAGE_CAS_HOLD',
                    'reason'=>'SIGNED_312_AND_UNSIGNED_WPCLI_SNAPSHOT_DO_NOT_PROVE_EXECUTOR_TIME_CAS',
                    'job_id'=>$id,'job_state'=>$row['state'],
                    'baseline_remains_unsigned'=>true,
                    'can_submit_via_this_module'=>false,
                    'original_305_submitted'=>false,
                    'original_313_verified'=>false,
                ];
            $next=MAD4B_Company_Repair_Job_Contract_V1::append($row,
                'BASELINE_PREPARED',[
                    'native_operation'=>$row['native_operation'],
                    'original_job_id'=>null,
                    'exact_operation_preflight_state'=>$pre['original_provider_state'],
                ],time());
            if(isset($next['error'])||!self::persist($next))
                return self::deny('prepare_persistence_or_transition_failed');
            return ['state'=>$next['state'],'job_id'=>$id,
                'original_305_submitted'=>false,
                'external_original_owner_submission_required'=>true];
        }finally{self::unlock($lock);}
    }
    private static function mark_plan_drift_locked(array $row):array|WP_Error {
        $next=MAD4B_Company_Repair_Job_Contract_V1::append($row,'PLAN_DRIFT',[
            'source_plan_sha256'=>$row['source_plan_sha256'],
            'existing_job_id'=>$row['original_job_id'],
            'no_reexecution'=>true
        ],time());
        if(isset($next['error'])||!self::persist($next))
            return self::deny('plan_drift_record_failed');
        return ['state'=>'SOURCE_PLAN_DRIFT_HOLD','job_id'=>$row['id'],
            'original_job_id'=>$row['original_job_id'],'automatic_resubmission'=>false];
    }
    private static function original_broker_record(array $job,string $id,string $commandSha):?array {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)
           ||!MAD4B_Company_Repair_Job_Contract_V1::sha($commandSha)
           ||!class_exists('MAD4B_Central_VPS_Broker_V1',false))return null;
        $broker=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
        if(!is_array($broker))return null;
        if(($broker['job_id']??null)!==$id
           ||($broker['company']??null)!==$job['company_code']
           ||($broker['environment']??null)!==$job['environment']
           ||($broker['site_uuid']??null)!==$job['site_uuid']
           ||($broker['canonical_origin']??null)!==$job['canonical_origin']
           ||($broker['domain_intent']??null)!==$job['native_operation']
           ||($broker['command_sha256']??null)!==$commandSha
           ||($broker['domain_resource_key']??null)!==$job['resource_key']
           ||($broker['resource_set_sha256']??null)!==
                ($job['baseline']['resource_manifest_sha256']??null))return null;
        return $broker;
    }
    /** Link only an ALREADY SUBMITTED, exact-job Original 305 record. */
    public static function bind_original(string $id,string $originalId,string $commandSha):array|WP_Error {
        if(!self::cli_owner())return self::deny('cli_owner_required',403);
        $lock=self::lock($id);
        if(!$lock)return self::deny('job_lease_busy');
        try {
            $row=self::raw($id);if(is_wp_error($row))return $row;
            if($row['state']!=='AWAITING_ORIGINAL_305_JOB'||$row['original_job_id']!==null)
                return self::deny('no_second_dispatch_or_rebinding');
            $broker=self::original_broker_record($row,$originalId,$commandSha);
            if(!$broker)return self::deny('original_305_exact_job_binding_missing');
            $next=MAD4B_Company_Repair_Job_Contract_V1::append($row,
                'BIND_ORIGINAL_JOB',[
                    'original_job_id'=>$originalId,'command_sha256'=>$commandSha,
                    'native_receipt_verified'=>false
                ],time());
            if(isset($next['error']))return self::deny('original_job_state_invalid');
            $next['original_job_id']=$originalId;
            $next['expected_command_sha256']=$commandSha;
            if(!self::persist($next))return self::deny('job_binding_persistence_failed');
            return ['state'=>$next['state'],'job_id'=>$id,'original_job_id'=>$originalId,
                'no_new_job_issued'=>true,'can_auto_reexecute'=>false,
                'independent_313_needed'=>true];
        }finally{self::unlock($lock);}
    }
    /** Read-only resume; no dispatch from this class, including after timeouts. */
    public static function resume(string $id):array|WP_Error {
        if(!self::cli_owner())return self::deny('cli_owner_required',403);
        $lock=self::lock($id);
        if(!$lock)return self::deny('job_lease_busy');
        try{
            $row=self::raw($id);if(is_wp_error($row))return $row;
            $current=self::plan($row['company_code'],$row['environment'],$row['incident_code']);
            if(is_wp_error($current))return [
                'state'=>'PLAN_UNAVAILABLE_NO_REPLAY','job_id'=>$id,
                'job_state'=>$row['state'],'original_job_id'=>$row['original_job_id'],
                'reason'=>$current->get_error_code(),'new_job_submitted'=>false
            ];
            if($current['source_plan_sha256']!==$row['source_plan_sha256']
               ||$current['site_uuid']!==$row['site_uuid']
               ||$current['canonical_origin']!==$row['canonical_origin']
               ||($current['native_operation']??null)!==$row['native_operation']
               ||($current['provider_resolution_sha256']??null)!==
                    ($row['provider_resolution_sha256']??null)){
                if(isset(MAD4B_Company_Repair_Job_Contract_V1::TRANSITIONS['PLAN_DRIFT'][$row['state']]))
                    return self::mark_plan_drift_locked($row);
                return ['state'=>'SOURCE_PLAN_DRIFT_NO_REPLAY','job_id'=>$id,
                    'job_state'=>$row['state'],'new_job_submitted'=>false];
            }
            $original=$row['original_job_id'];
            if($original===null)return [
                'state'=>'WAITING_FOR_NEXT_GOVERNED_STAGE','job_id'=>$id,
                'job_state'=>$row['state'],'required_step'=>$row['state']==='AWAITING_RESOURCE_BASELINE'
                    ?'COLLECT_EXACT_RESOURCE_BEFORE_AND_ROLLBACK_DIGEST'
                    :'SIGNED_312_PROVIDER_AND_EXACT_OWNER_305_JOB',
                'new_job_submitted'=>false,'can_auto_reexecute'=>false
            ];
            // If a 305 job exists, only the cryptographic Original 313 adapter
            // may certify its particular command and before/after/rollback.
            $broker=self::original_broker_record($row,$original,$row['expected_command_sha256']??'');
            if(!$broker)return [
                'state'=>'ORIGINAL_BROKER_JOB_UNVERIFIED_NO_REPLAY',
                'job_id'=>$id,'original_job_id'=>$original,'new_job_submitted'=>false
            ];
            $status=$broker['state']??'UNKNOWN';
            $verified=self::signed_exact_result($row);
            if($verified['state']==='EXACT_SIGNED_PROOF_MATCH') {
                $ev='OBSERVE_SIGNED_REPAIR_313';
            }elseif($verified['state']==='SIGNED_EXACT_JOB_POSTIMAGE_MISMATCH') {
                $ev='OBSERVE_SIGNED_DIGEST_MISMATCH';
            }else {
                if($row['state']==='ORIGINAL_JOB_BOUND'){
                    $ev=($broker['executed']??false)===true
                        ?'OBSERVE_UNKNOWN_OUTCOME':'OBSERVE_CLAIM_PENDING';
                }elseif($row['state']==='AWAITING_EXACT_SIGNED_313'
                    &&($broker['executed']??false)===true)$ev='OBSERVE_UNKNOWN_OUTCOME';
                else return [
                    'state'=>'IN_PROGRESS_OR_RESULT_UNVERIFIED_NO_REPLAY',
                    'job_id'=>$id,'job_state'=>$row['state'],
                    'original_job_id'=>$original,
                    'broker_state'=>$status,
                    'signed_313_state'=>$verified['state'],
                    'new_job_submitted'=>false,'can_auto_reexecute'=>false
                ];
            }
            $next=MAD4B_Company_Repair_Job_Contract_V1::append($row,$ev,[
                'original_job_id'=>$original,
                'broker_state'=>$status,
                'signed_proof_state'=>$verified['state'],
                'signed_receipt_sha256'=>$verified['receipt_sha256']??null,
                'observed_after_sha256'=>$verified['observed_after_sha256']??null,
                'never_replay'=>true
            ],time());
            if(isset($next['error']))return self::deny('resume_transition_invalid');
            if(in_array($ev,['OBSERVE_SIGNED_REPAIR_313',
                'OBSERVE_SIGNED_DIGEST_MISMATCH'],true)) {
                $next['signed_repair_313_sha256']=$verified['receipt_sha256'];
                $next['observed_after_sha256']=$verified['observed_after_sha256'];
            }
            if(!self::persist($next))return self::deny('resume_persistence_failed');
            return [
                'state'=>$next['state'],'job_id'=>$id,'revision'=>$next['revision'],
                'original_job_id'=>$original,
                'signed_313_for_exact_job'=>$ev==='OBSERVE_SIGNED_REPAIR_313',
                'company_publication_certified'=>false,
                'new_job_submitted'=>false,'can_auto_reexecute'=>false
            ];
        }finally{self::unlock($lock);}
    }
    private static function signed_exact_result(array $row):array {
        $base=['state'=>'ORIGINAL_313_FOR_THIS_JOB_NOT_VERIFIED'];
        $class='MAD4B_Company_Native_Write_Executor_V1';
        if(!class_exists($class,false)
           ||!is_callable([$class,'certified_status'])
           ||!defined($class.'::PREFIX'))return $base;
        $cert=$class::certified_status(
            $row['company_code'],$row['environment'],$row['native_operation']);
        if(!is_array($cert)
           ||($cert['state']??'')!=='ORIGINAL_EXACT_WRITE_AND_ROLLBACK_CERTIFIED'
           ||($cert['site_uuid']??null)!==$row['site_uuid']
           ||($cert['canonical_origin']??null)!==$row['canonical_origin'])
            return $base;
        $proof=get_option($class::PREFIX.$row['company_code'].'_'.
            $row['environment'].'_'.$row['native_operation'],false);
        if(!is_array($proof)
           ||($proof['job_id']??null)!==$row['original_job_id']
           ||($proof['command_sha256']??null)!==($row['expected_command_sha256']??null)
           ||($cert['receipt_sha256']??null)!==hash('sha256',implode(chr(10),
               array_map(static fn($key)=>(string)($proof[$key]??''),$class::FIELDS)))
           ||($proof['before_sha256']??null)!==($row['baseline']['before_sha256']??null)
           ||($proof['rollback_sha256']??null)!==($row['baseline']['rollback_sha256']??null))
            return $base;
        if(($proof['after_sha256']??null)!==
            ($row['baseline']['expected_after_sha256']??null))
            return ['state'=>'SIGNED_EXACT_JOB_POSTIMAGE_MISMATCH',
                'observed_after_sha256'=>$proof['after_sha256'],
                'receipt_sha256'=>$cert['receipt_sha256']];
        return ['state'=>'EXACT_SIGNED_PROOF_MATCH',
            'observed_after_sha256'=>$proof['after_sha256'],
            'receipt_sha256'=>$cert['receipt_sha256']];
    }
    /** Rollback admission is read-only; a new Original signed job is mandatory. */
    public static function rollback_plan(string $id):array|WP_Error {
        if(!self::read_owner())return self::deny('admin_only',403);
        $job=self::raw($id);if(is_wp_error($job))return $job;
        $signed=($job['state']??'')==='POSTCONDITION_DRIFT_HOLD'
            &&MAD4B_Company_Repair_Job_Contract_V1::sha($job['signed_repair_313_sha256']??null)
            &&MAD4B_Company_Repair_Job_Contract_V1::sha($job['observed_after_sha256']??null);
        return [
            'contract'=>self::CONTRACT.'.rollback-plan',
            'job_id'=>$id,'company_code'=>$job['company_code'],
            'environment'=>$job['environment'],'site_uuid'=>$job['site_uuid'],
            'original_repair_job_id'=>$job['original_job_id']??null,
            'signed_postimage_mismatch'=>$signed,
            'rollback_expected_before_sha256'=>$job['baseline']['before_sha256']??null,
            'rollback_current_observed_sha256'=>$job['observed_after_sha256']??null,
            'requires_fresh_original_305_312_313'=>true,
            'requires_new_preimage_snapshot'=>true,
            'tenant_owned_values_must_be_preserved'=>true,
            'automatic_rollback_authorized'=>false,
            'rollback_performed'=>false,
            'state'=>$signed?'ORIGINAL_OWNER_ROLLBACK_JOB_REQUIRED':
                'ROLLBACK_UNVERIFIED_DO_NOT_EXECUTE',
        ];
    }
    /**
     * One bounded source assessment for all current incident candidates.
     * Avoid N independent center WP-CLI observations and avoid any provider
     * readback when merely listing the operator's queue.
     */
    public static function queue(string $company,string $environment):array|WP_Error {
        if(!self::read_owner())return self::deny('admin_only',403);
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
            ||!in_array($environment,['staging','production'],true))
            return self::deny('invalid_queue_scope',422);
        $plan=MAD4B_Company_Reconcile_Work_Plan_V1::inspect($company,$environment);
        if(is_wp_error($plan))return $plan;
        if(($plan['company_binding_verified']??false)!==true
            ||($plan['requested_environment_registered']??false)!==true
            ||($plan['depth_audit']['dependency_graph_valid']??false)!==true)
            return self::deny('queue_identity_or_policy_not_ready');
        $target=$plan['environment_inventory'][$environment]??null;
        $incidents=$plan['incident_scenarios']??null;
        if(!is_array($target)||!is_array($incidents)||count($incidents)>160)
            return self::deny('queue_source_not_structured');
        $scope=[
            'company_code'=>$company,'environment'=>$environment,
            'site_uuid'=>$target['site_uuid'],
            'canonical_origin'=>$target['canonical_origin'],
            'incident_code'=>'','resource_key'=>'',
            'source_plan_sha256'=>$plan['work_plan_sha256'],
        ];
        $rows=[];$counts=['NOT_CREATED'=>0,'JOURNAL_INTEGRITY_HOLD'=>0,'PERSISTED'=>0];
        $resourceChildTotal=0;$resourceChildHolds=0;
        $byCheck=[];
        foreach((array)($plan['depth_audit']['incident_probe_mapping']??[]) as $map)
            if(is_array($map)&&is_string($map['code']??null))
                $byCheck[$map['code']]=$map;
        foreach($incidents as $item){
            $code=$item['code']??null;
            if(!is_string($code)||!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$code))
                return self::deny('source_incident_invalid');
            $scope['incident_code']=$code;
            $scope['resource_key']='incident:'.$code;
            $id=MAD4B_Company_Repair_Job_Contract_V1::key($scope);
            if($id==='')return self::deny('queue_job_id_not_generated');
            $saved=get_option(self::job_key($id),false);
            $storage='NOT_CREATED';
            $state='PLANNED_NOT_MATERIALIZED';
            $revision=null;
            $signed=false;
            if($saved!==false) {
                $good=is_array($saved)
                    &&MAD4B_Company_Repair_Job_Contract_V1::valid_chain($saved)
                    &&($saved['id']??null)===$id
                    &&($saved['company_code']??null)===$company
                    &&($saved['environment']??null)===$environment
                    &&($saved['site_uuid']??null)===$scope['site_uuid']
                    &&($saved['source_plan_sha256']??null)===$scope['source_plan_sha256'];
                $storage=$good?'PERSISTED':'JOURNAL_INTEGRITY_HOLD';
                $state=$good?$saved['state']:'JOURNAL_INTEGRITY_HOLD';
                $revision=$good?$saved['revision']:null;
                // A signed receipt hash in our journal is still not the
                // separate independent Original verification itself.
                $signed=false;
            }
            $counts[$storage]++;
            $childScope=$scope;
            $childScope['resource_key']='incident:'.$code;
            $children=self::child_index_read($childScope);
            $childIds=is_wp_error($children)?[]:$children['ids'];
            $childStates=[];
            $childIndexState=is_wp_error($children)?'CHILD_INDEX_INTEGRITY_HOLD':'VALID';
            if($childIndexState!=='VALID')$resourceChildHolds++;
            foreach($childIds as $childId){
                $child=self::raw($childId);
                $valid=!is_wp_error($child)
                    &&($child['company_code']??null)===$company
                    &&($child['environment']??null)===$environment
                    &&($child['site_uuid']??null)===$target['site_uuid']
                    &&($child['canonical_origin']??null)===$target['canonical_origin']
                    &&($child['source_plan_sha256']??null)===$plan['work_plan_sha256']
                    &&($child['incident_code']??null)===$code
                    &&!str_starts_with((string)($child['resource_key']??''),'incident:');
                if(!$valid){$childIndexState='CHILD_INDEX_INTEGRITY_HOLD';$resourceChildHolds++;break;}
                $childStates[$child['state']]=($childStates[$child['state']]??0)+1;
                $resourceChildTotal++;
            }
            $rows[]=[
                'incident_code'=>$code,
                'priority'=>$item['priority']??'P1',
                'linked_check'=>$item['related_check']??null,
                'evidence_lane'=>$byCheck[$code]['evidence_lane']??'NOT_DECLARED',
                'repair_id'=>$id,'storage'=>$storage,'state'=>$state,
                'revision'=>$revision,
                'resource_binding'=>'LOGICAL_INCIDENT_ONLY',
                'provider_capability'=>'INSPECT_ON_DEMAND',
                'resource_child_count'=>count($childIds),
                'resource_child_states'=>$childStates,
                'resource_child_index_state'=>$childIndexState,
                'original_313_independent_verified'=>$signed,
                'autonomous_execution_authorized'=>false,
            ];
        }
        return [
            'contract'=>self::CONTRACT.'.read-only-queue',
            'state'=>'EXACT_SITE_REPAIR_QUEUE_READ_ONLY',
            'company_code'=>$company,'environment'=>$environment,
            'site_uuid'=>$target['site_uuid'],
            'source_plan_sha256'=>$plan['work_plan_sha256'],
            'incident_count'=>count($rows),
            'resource_child_journals'=>$resourceChildTotal,
            'resource_child_integrity_holds'=>$resourceChildHolds,
            'counts'=>$counts,'items'=>$rows,
            'historical_other_plan_journals_included'=>false,
            'provider_capabilities_discovered_only_on_detail_request'=>true,
            'original_313_certified_by_queue'=>false,
            'jobs_created_by_queue'=>false,
            'target_mutation_performed'=>false,
        ];
    }
    public static function rest_queue(WP_REST_Request $request) {
        $result=self::queue((string)$request->get_param('company'),
            (string)$request->get_param('environment'));
        if(is_wp_error($result))return $result;
        $response=rest_ensure_response($result);
        $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
    /** Operator-only dynamic plan reader, no option or job writes. */
    public static function rest_plan(WP_REST_Request $request) {
        $row=self::plan((string)$request->get_param('company'),
            (string)$request->get_param('environment'),
            (string)$request->get_param('incident'));
        if(is_wp_error($row))return $row;
        $response=rest_ensure_response($row);
        $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
    public static function rest_resource_preview(WP_REST_Request $request) {
        $result=self::resource_preview(
            (string)$request->get_param('company'),
            (string)$request->get_param('environment'),
            (string)$request->get_param('incident'),
            (string)$request->get_param('resource_type'),
            (string)$request->get_param('resource_id'));
        if(is_wp_error($result))return $result;
        $response=rest_ensure_response($result);
        $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
    public static function routes():void {
        if(!self::central())return;
        register_rest_route('mad4b/v1','/company-repair/baseline-review',[
            'methods'=>'GET','permission_callback'=>static fn()=>self::read_owner(),
            'callback'=>[__CLASS__,'rest_baseline_review']
        ]);
        register_rest_route('mad4b/v1','/company-repair/resource-preview',[
            'methods'=>'GET','permission_callback'=>static fn()=>self::read_owner(),
            'callback'=>[__CLASS__,'rest_resource_preview']
        ]);
        register_rest_route('mad4b/v1','/company-repair/queue',[
            'methods'=>'GET','permission_callback'=>static fn()=>self::read_owner(),
            'callback'=>[__CLASS__,'rest_queue']
        ]);
        register_rest_route('mad4b/v1','/company-repair/plan',[
            'methods'=>'GET','permission_callback'=>static fn()=>self::read_owner(),
            'callback'=>[__CLASS__,'rest_plan']
        ]);
        register_rest_route('mad4b/v1','/company-repair/jobs/(?P<repair_id>[a-f0-9]{40})',[
            'methods'=>'GET','permission_callback'=>static fn()=>self::read_owner(),
            'callback'=>static function(WP_REST_Request $request){
                $row=self::status((string)$request->get_param('repair_id'));
                if(is_wp_error($row))return $row;
                $response=rest_ensure_response($row);
                $response->header('Cache-Control','private, no-store, max-age=0');
                return $response;
            }
        ]);
    }
    /** Import a bounded, digest-only, unsigned Wayfatours WP-CLI observation. */
    public static function import_resource_snapshot(
        string $company,string $environment,string $incident,
        string $payloadBase64,string $expectedPlanHash):array|WP_Error {
        if(!self::cli_owner())return self::deny('central_cli_owner_required',403);
        if(strlen($payloadBase64)>12000
            ||!preg_match('/^[A-Za-z0-9+\/=]+$/D',$payloadBase64))
            return self::deny('resource_snapshot_payload_invalid',422);
        $decoded=base64_decode($payloadBase64,true);
        if(!is_string($decoded)||strlen($decoded)>8192||strlen($decoded)<200)
            return self::deny('resource_snapshot_size_invalid',422);
        try{$source=json_decode($decoded,true,32,JSON_THROW_ON_ERROR);}
        catch(JsonException $error){return self::deny('resource_snapshot_json_invalid',422);}
        if(!is_array($source))return self::deny('resource_snapshot_schema_invalid',422);
        $plan=self::plan($company,$environment,$incident);
        if(is_wp_error($plan))return $plan;
        if(!MAD4B_Company_Repair_Job_Contract_V1::sha($expectedPlanHash)
            ||!hash_equals($plan['source_plan_sha256'],$expectedPlanHash))
            return self::deny('resource_source_plan_hash_changed');
        $candidates=(array)($plan['registered_provider_candidates']??[]);
        $candidate=count($candidates)===1?$candidates[0]:null;
        $ownership=$plan['ownership_scope']??null;
        if(($plan['provider_resolution_state']??null)!=='EXACT_CAPABILITY_DISCOVERED'
            ||!is_array($candidate)
            ||($plan['native_operation']??null)!==($candidate['operation']??null)
            ||($candidate['resource_resolver_contract']??null)!==
                  MAD4B_Company_Repair_Resource_Resolver_V2::CONTRACT
            ||!in_array($source['resource_type']??null,
                (array)($candidate['resource_types']??[]),true)
            ||($ownership!=='mixed'&&($source['ownership_scope']??null)!==$ownership)
            ||in_array($ownership,['never_clone','inspect_only'],true))
            return self::deny('resource_provider_or_ownership_not_eligible');
        return MAD4B_Company_Resource_Snapshot_Store_V1::ingest($plan,$source);
    }
    public static function cli(array $args,array $assoc):void {
        if(!self::cli_owner())WP_CLI::error('CENTRAL_REPAIR_CLI_OWNER_REQUIRED');
        $command=$args[0]??'';
        $allowed=['company','environment','incident','plan-sha','repair-id',
            'baseline-path','original-job','command-sha','resource-type','resource-id',
            'snapshot-base64'];
        if(count($args)!==1||array_diff(array_keys($assoc),$allowed))
            WP_CLI::error('UNSUPPORTED_REPAIR_JOB_ARGUMENTS');
        $company=(string)($assoc['company']??'');
        $env=(string)($assoc['environment']??'');
        $incident=(string)($assoc['incident']??'');
        $id=(string)($assoc['repair-id']??'');
        $value=match($command) {
            'plan'=>self::plan($company,$env,$incident),
            'create'=>self::create($company,$env,$incident,
                (string)($assoc['plan-sha']??'')),
            'resource-preview'=>self::resource_preview($company,$env,$incident,
                (string)($assoc['resource-type']??''),
                (string)($assoc['resource-id']??'')),
            'baseline-review'=>self::baseline_review_scope($company,$env,$incident,
                (string)($assoc['resource-type']??''),
                (string)($assoc['resource-id']??'')),
            'snapshot-ingest'=>self::import_resource_snapshot($company,$env,
                $incident,(string)($assoc['snapshot-base64']??''),
                (string)($assoc['plan-sha']??'')),
            'create-resource'=>self::create_resource($company,$env,$incident,
                (string)($assoc['resource-type']??''),
                (string)($assoc['resource-id']??''),
                (string)($assoc['plan-sha']??'')),
            'status'=>self::status($id),
            'preflight'=>self::preflight($id),
            'rollback-plan'=>self::rollback_plan($id),
            'prepare'=>self::prepare($id),
            'bind-original'=>self::bind_original($id,
                (string)($assoc['original-job']??''),
                (string)($assoc['command-sha']??'')),
            'resume'=>self::resume($id),
            // Baseline file must be trusted, scoped and readable by WP-CLI.
            // Nothing in the file is executed. No generic path is returned.
            'baseline'=>self::cli_baseline($id,(string)($assoc['baseline-path']??'')),
            default=>self::deny('unsupported_command',422)
        };
        if(is_wp_error($value))WP_CLI::error($value->get_error_code());
        WP_CLI::line(wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    private static function cli_baseline(string $id,string $path):array|WP_Error {
        $root=realpath('/opt/mad4b-company-provisioning/reports');
        $real=realpath($path);
        if(!is_string($root)||!is_string($real)
           ||strlen($path)>250||!str_starts_with($real,$root.'/')
           ||str_contains($path,'..')||is_link($path)||!is_file($real)
           ||filesize($real)>8192
           ||(fileperms($real)&0022)!==0)
            return self::deny('baseline_file_path_not_allowed');
        $source=json_decode((string)file_get_contents($real),true);
        return is_array($source)?self::baseline($id,$source):
            self::deny('baseline_file_invalid');
    }
    public static function admin_menu():void {
        if(!self::central())return;
        add_submenu_page('mad4b-company-brand-readiness',
            'Reconcile Repair Jobs','Repair Jobs','manage_options',
            'mad4b-company-repair-jobs',[__CLASS__,'screen']);
    }
    public static function screen():void {
        if(!self::read_owner())wp_die('Company Manager administrator required',403);
        $policy=MAD4B_Company_Reconcile_Work_Plan_V1::original_policy();
        echo '<div class="wrap"><h1>Company Repair Jobs</h1>'
            .'<p>Dynamic trusted Provider Registry. Inspect an exact incident and '
            .'environment without issuing a native Job, Grant or target write.</p>'
            .'<form id="mad4b-repair-plan" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">'
            .'<label for="mad4b-repair-company">Company</label>'
            .'<input id="mad4b-repair-company" name="company" required pattern="[a-z][a-z0-9-]{2,47}" autocomplete="off">'
            .'<label for="mad4b-repair-environment">Environment</label>'
            .'<select id="mad4b-repair-environment" name="environment">'
            .'<option value="production">Production</option><option value="staging">Staging</option></select>'
            .'<label for="mad4b-repair-incident">Incident</label>'
            .'<select id="mad4b-repair-incident" name="incident">';
        foreach((array)($policy['incident_scenarios']??[]) as $item) {
            $code=$item['code']??null;
            if(!is_string($code)||!preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$code))continue;
            echo '<option value="'.esc_attr($code).'">'.esc_html($code).'</option>';
        }
        echo '</select><button type="submit" class="button button-primary">'
            .'Inspect dynamic repair capabilities</button>'
            .'<button type="button" id="mad4b-repair-queue-button" class="button">'
            .'View all incident states</button>'
            .'<label for="mad4b-repair-resource-type">Resource type</label>'
            .'<input id="mad4b-repair-resource-type" name="resource_type"'
            .' placeholder="wordpress.post" autocomplete="off">'
            .'<label for="mad4b-repair-resource-id">Resource ID</label>'
            .'<input id="mad4b-repair-resource-id" name="resource_id"'
            .' placeholder="123" autocomplete="off">'
            .'<button type="button" id="mad4b-repair-resource-button" class="button">'
            .'Inspect exact resource (read-only)</button>'
            .'<button type="button" id="mad4b-repair-baseline-button" class="button">'
            .'Review baseline candidate (read-only)</button></form>'
            .'<p role="status" aria-live="polite" id="mad4b-repair-status">Not inspected.</p>'
            .'<div id="mad4b-repair-result"></div>'
            .'<p><strong>No job creation in this screen.</strong> Creating a durable '
            .'journal requires an exact central WP-CLI operator and a current source plan.</p>'
            .'</div>';
    }
    public static function assets():void {
        if(!is_admin()||!current_user_can('manage_options')
           ||sanitize_key((string)($_GET['page']??''))!=='mad4b-company-repair-jobs')return;
        $config=['url'=>rest_url('mad4b/v1/company-repair/plan'),
            'resourceUrl'=>rest_url('mad4b/v1/company-repair/resource-preview'),
            'baselineUrl'=>rest_url('mad4b/v1/company-repair/baseline-review'),
            'queueUrl'=>rest_url('mad4b/v1/company-repair/queue'),
            'nonce'=>wp_create_nonce('wp_rest')];
        $js='(()=>{const f=document.getElementById("mad4b-repair-plan");'
            .'const status=document.getElementById("mad4b-repair-status");'
            .'const output=document.getElementById("mad4b-repair-result");'
            .'if(!f||!status||!output)return;const c='.wp_json_encode($config).';'
            .'const qb=document.getElementById("mad4b-repair-queue-button");'
            .'const bb=document.getElementById("mad4b-repair-baseline-button");'
            .'if(bb)bb.addEventListener("click",async()=>{output.replaceChildren();bb.disabled=true;'
            .'status.textContent="Reviewing unsigned baseline candidate (no writes)…";'
            .'try{const u=new URL(c.baselineUrl);'
            .'for(const k of ["company","environment","incident","resource_type","resource_id"]){'
            .'let v=String(f.elements[k].value).trim();'
            .'if(k==="company")v=v.toLowerCase();u.searchParams.set(k,v)}'
            .'const res=await fetch(u.toString(),{cache:"no-store",credentials:"same-origin",'
            .'headers:{"X-WP-Nonce":c.nonce}});'
            .'if(!res.ok)throw new Error("review_unavailable");'
            .'const d=await res.json();'
            .'status.textContent=String(d.state)+" · No rollback artifact · No after-image · No job";'
            .'const pre=document.createElement("pre");pre.textContent=JSON.stringify({'
            .'job_id:d.job_id,resource_key:d.resource_key,state:d.state,'
            .'before_sha256:d.before_sha256,resource_manifest_sha256:d.resource_manifest_sha256,'
            .'company_preservation_sha256:d.company_preservation_sha256,'
            .'snapshot_expires_in_seconds:d.snapshot_expires_in_seconds,'
            .'expected_after_sha256:d.expected_after_sha256,rollback_sha256:d.rollback_sha256,'
            .'missing_requirements:d.missing_requirements,'
            .'repair_execution_authorized:false,original_313_verified:false},null,2);'
            .'output.appendChild(pre);'
            .'}catch(err){status.textContent="Exact baseline review unavailable. No readiness inferred.";}'
            .'finally{bb.disabled=false;}});'
            .'const rb=document.getElementById("mad4b-repair-resource-button");'
            .'if(rb)rb.addEventListener("click",async()=>{output.replaceChildren();rb.disabled=true;'
            .'status.textContent="Resolving exact site resource (read-only)…";'
            .'try{const u=new URL(c.resourceUrl);'
            .'u.searchParams.set("company",f.elements.company.value.trim().toLowerCase());'
            .'u.searchParams.set("environment",f.elements.environment.value);'
            .'u.searchParams.set("incident",f.elements.incident.value);'
            .'u.searchParams.set("resource_type",f.elements.resource_type.value.trim());'
            .'u.searchParams.set("resource_id",f.elements.resource_id.value.trim());'
            .'const res=await fetch(u.toString(),{cache:"no-store",credentials:"same-origin",'
            .'headers:{"X-WP-Nonce":c.nonce}});'
            .'if(!res.ok)throw new Error("not_admitted");const d=await res.json();'
            .'status.textContent="Resource: "+String(d.state)'
            .'+" · Exact signed Original312: "+(d.exact_scoped_312_verified?"YES":"NO")'
            .'+" · Repair execution ready: NO · Signed Original313: NO";'
            .'const pre=document.createElement("pre");pre.textContent=JSON.stringify({'
            .'state:d.state,reason:d.reason||null,resource_key:d.resource_key||null,'
            .'before_sha256:d.before_sha256||null,resource_manifest_sha256:d.resource_manifest_sha256||null,'
            .'resource_resolution_sha256:d.resource_resolution_sha256||null,'
            .'target_readback_evidence_level:d.target_readback_evidence_level||"NOT_VERIFIED",'
            .'exact_scoped_312_state:d.exact_scoped_312_state||"NOT_CHECKED",'
            .'exact_scoped_312_verified:d.exact_scoped_312_verified===true,'
            .'exact_scoped_312_expires_in_seconds:d.exact_scoped_312_expires_in_seconds,'
            .'imported_target_snapshot_expires_in_seconds:d.imported_target_snapshot_expires_in_seconds,'
            .'original_313_for_this_resource_verified:false,'
            .'repair_execution_ready:false,'
            .'new_native_job_created:false},null,2);output.appendChild(pre);'
            .'}catch(err){status.textContent="Exact resource unavailable. No write permitted.";}'
            .'finally{rb.disabled=false;}});'
            .'if(qb)qb.addEventListener("click",async()=>{output.replaceChildren();qb.disabled=true;'
            .'status.textContent="Reading current company queue (no writes)…";'
            .'try{const u=new URL(c.queueUrl);u.searchParams.set("company",f.elements.company.value.trim().toLowerCase());'
            .'u.searchParams.set("environment",f.elements.environment.value);'
            .'const res=await fetch(u.toString(),{cache:"no-store",credentials:"same-origin",'
            .'headers:{"X-WP-Nonce":c.nonce}});'
            .'if(!res.ok)throw new Error("not_admitted");const q=await res.json();'
            .'status.textContent="Company queue: "+String(q.incident_count||0)+" incidents"'
            .'+" · Persisted "+String(q.counts?.PERSISTED||0)'
            .'+" · Pending "+String(q.counts?.NOT_CREATED||0)'
            .'+" · Journal holds "+String(q.counts?.JOURNAL_INTEGRITY_HOLD||0)'
            .'+" · Resource jobs "+String(q.resource_child_journals||0)'
            .'+" · Resource index holds "+String(q.resource_child_integrity_holds||0);'
            .'const table=document.createElement("table");table.className="widefat striped";'
            .'const th=document.createElement("tr");'
            .'for(const label of ["Priority","Incident","Job state","Resource jobs","Evidence lane","Provider"]){'
            .'const h=document.createElement("th");h.textContent=label;th.appendChild(h)}table.appendChild(th);'
            .'for(const item of q.items||[]){const tr=document.createElement("tr");'
            .'for(const key of ["priority","incident_code","state","resource_child_count","evidence_lane","provider_capability"]){'
            .'const td=document.createElement("td");td.textContent=String(item[key]||"");tr.appendChild(td)}'
            .'table.appendChild(tr)}output.appendChild(table);'
            .'}catch(err){status.textContent="Queue unavailable or not permitted. No readiness inferred.";}'
            .'finally{qb.disabled=false;}});'
            .'f.addEventListener("submit",async e=>{e.preventDefault();output.replaceChildren();'
            .'const company=f.elements.company.value.trim().toLowerCase();'
            .'const env=f.elements.environment.value;const incident=f.elements.incident.value;'
            .'if(!/^[a-z][a-z0-9-]{2,47}$/.test(company))return;'
            .'const b=f.querySelector("button");b.disabled=true;status.textContent="Reading Provider Registry…";'
            .'try{const u=new URL(c.url);u.searchParams.set("company",company);'
            .'u.searchParams.set("environment",env);u.searchParams.set("incident",incident);'
            .'const response=await fetch(u.toString(),{cache:"no-store",credentials:"same-origin",'
            .'headers:{"X-WP-Nonce":c.nonce}});'
            .'if(!response.ok)throw new Error("not_admitted");const p=await response.json();'
            .'status.textContent="Provider discovery: "+String(p.provider_resolution_state)'
            .'+" · Candidates: "+String(p.registered_provider_candidates?.length||0);'
            .'const t=document.createElement("p");t.textContent="Incident: "+p.incident_code'
            .'+" · Exact Site: "+p.site_uuid+" · Scope: "+p.ownership_scope'
            .'+" · Operation: "+String(p.native_operation||"NOT DECLARED")'
            .'+" · Can execute: NO";output.appendChild(t);'
            .'const pre=document.createElement("pre");pre.textContent=JSON.stringify({'
            .'job_id:p.id,source_plan_sha256:p.source_plan_sha256,'
            .'candidates:p.registered_provider_candidates,'
            .'dependency_checks:p.required_dependency_checks},null,2);output.appendChild(pre);'
            .'}catch(err){status.textContent="Not authorized or unavailable. No readiness inferred.";}'
            .'finally{b.disabled=false;}});})();';
        wp_register_script('mad4b-company-repair-jobs',false,[],null,true);
        wp_enqueue_script('mad4b-company-repair-jobs');
        wp_add_inline_script('mad4b-company-repair-jobs',$js);
    }
    public static function boot():void {
        // Safe when the source Work Plan is loaded by standalone policy
        // auditing tests or release tooling without WordPress bootstrapped.
        if(!function_exists('add_action'))return;
        add_action('rest_api_init',[__CLASS__,'routes'],96);
        add_action('admin_menu',[__CLASS__,'admin_menu'],97);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets'],25);
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b company-repair',[__CLASS__,'cli']);
    }
}
MAD4B_Company_Reconcile_Repair_Jobs_V1::boot();
}
