<?php
/**
 * Central validation contract for automatic evidence-backed enrichment writes.
 * Caller supplies claims; original trusted native source and actor are read
 * independently. No method here grants an Ability, OAuth token or permission.
 */
defined('ABSPATH') || exit;
interface MAD4B_Central_Enrichment_Write_Adapter {
    // Implemented by reviewed first-party provider code, never a remote
    // serialized callback. Owns original provider-specific domain rules.
    public function source(array $claim):array;
    public function authorize(array $claim,array $source):bool;
    public function apply(array $claim,array $source):array;
    public function readback(array $claim,array $source,array $result):bool;
}
final class MAD4B_SCP_Central_Write_Acceptance {
    const CONTRACT='mad4b.central-write-acceptance.v1';
    const RECEIPTS='mad4b_central_write_acceptance_receipts_v1';
    const DEFAULT_TTL=259200; // 72 hours; providers may require shorter.
    const MAX_TTL=2592000; // Provider cannot admit proof older than 30 days.
    private static function fail(string $code):array {
        return ['contract'=>self::CONTRACT,'eligible'=>false,
            'reason'=>$code,'mutation_performed'=>false,'replay_allowed'=>false];
    }
    public static function sha($value):bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D',$value)===1;
    }
    /**
     * Fixed universal claim vocabulary. Domain-specific evidence (e.g. Place ID
     * coordinates, supplier attestation, copyright, translation quality)
     * is *additive* in a provider's permission and verification logic.
     *
     * $source must be obtained from native WordPress state, never simply
     * copied from the submitted evidence. $scope is trusted Site Profile data.
     */
    public static function verify(array $claim,array $source,array $scope,int $now=0):array {
        $now=$now?:time();
        if(($claim['contract']??'')!==self::CONTRACT)
            return self::fail('contract_mismatch');
        if(($source['site_uuid']??'')===''||($scope['site_uuid']??'')===''||
            !hash_equals((string)$scope['site_uuid'],(string)$source['site_uuid']) ||
            !hash_equals((string)$scope['site_uuid'],(string)($claim['site_uuid']??'')))
            return self::fail('site_binding_mismatch');
        $env=(string)($scope['environment']??'');
        if($env===''||$env!==($claim['environment']??null)||
           $env!==($source['environment']??null))
            return self::fail('environment_binding_mismatch');
        $identifier=(string)($source['subject_id']??'');
        if($identifier===''||strlen($identifier)>191||
           !hash_equals($identifier,(string)($claim['subject_id']??'')))
            return self::fail('subject_mismatch');
        $ability=(string)($source['ability']??'');
        if(!preg_match('~^[a-z][a-z0-9._-]*/[a-z][a-z0-9._-]{1,127}$~D',$ability)||
           $ability!==($claim['ability']??null))
            return self::fail('ability_mismatch');
        $job=(string)($source['job_id']??'');
        if($job===''||strlen($job)>191||$job!==($claim['job_id']??null))
            return self::fail('content_job_mismatch');
        $sourceHash=$source['source_sha256']??null;
        if(!self::sha($sourceHash)||!self::sha($claim['source_sha256']??null)||
           !hash_equals($sourceHash,$claim['source_sha256']))
            return self::fail('source_hash_stale');
        $sourceRev=(string)($source['revision']??'');
        if($sourceRev===''||strlen($sourceRev)>128||
           !hash_equals($sourceRev,(string)($claim['revision']??'')))
            return self::fail('source_revision_changed');
        // Compare expected external entity reference (Place ID, media ID,
        // translation locale, supplier ID etc.) to server-known source value.
        $reference=(string)($source['reference_id']??'');
        if($reference===''||strlen($reference)>255||
           !hash_equals($reference,(string)($claim['reference_id']??'')))
            return self::fail('reference_id_mismatch');
        $captured=$claim['verified_at']??null;
        // Timestamp and QA booleans must also exist in independently
        // re-read first-party evidence; a client cannot grant itself QA.
        if(!is_int($source['evidence_verified_at']??null) ||
           !is_int($captured) || $source['evidence_verified_at']!==$captured)
            return self::fail('evidence_timestamp_not_source_bound');
        if(($source['independent_verification']??null)!==true ||
           ($source['quality_pass']??null)!==true ||
           ($source['rights_pass']??null)!==true)
            return self::fail('server_verified_fact_quality_or_rights_missing');
        $ttl=$scope['max_evidence_age_seconds']??self::DEFAULT_TTL;
        if(!is_int($captured) || $captured<=0 ||
           !is_int($ttl)||$ttl<1||$ttl>self::MAX_TTL ||
           $captured>$now+60||$captured<$now-$ttl)
            return self::fail('evidence_missing_expired_or_future');
        if(($claim['independent_verification']??null)!==true ||
           ($claim['quality_pass']??null)!==true ||
           ($claim['rights_pass']??null)!==true)
            return self::fail('fact_quality_or_rights_not_verified');
        if(!self::sha($claim['evidence_sha256']??null) ||
           !self::sha($source['evidence_sha256']??null) ||
           !hash_equals($source['evidence_sha256'],$claim['evidence_sha256']))
            return self::fail('evidence_untrusted_or_changed');
        $intent=$claim['idempotency_key']??null;
        $expected=hash('sha256',implode('|',[
            self::CONTRACT,$scope['site_uuid'],$env,$ability,$job,$identifier,
            $sourceHash,$sourceRev,$reference,$claim['evidence_sha256']
        ]));
        if(!self::sha($intent)||!hash_equals($expected,$intent))
            return self::fail('idempotency_identity_mismatch');
        return ['contract'=>self::CONTRACT,'eligible'=>true,'reason'=>'QUALIFIED',
            'site_uuid'=>$scope['site_uuid'],'subject_id'=>$identifier,
            'job_id'=>$job,'ability'=>$ability,'source_sha256'=>$sourceHash,
            'reference_sha256'=>hash('sha256',$reference),
            'evidence_sha256'=>$claim['evidence_sha256'],
            'idempotency_key'=>$expected,'mutation_performed'=>false];
    }
    public static function intent_key(array $source,array $scope,string $evidenceHash):string {
        return hash('sha256',implode('|',[
            self::CONTRACT,(string)($scope['site_uuid']??''),
            (string)($scope['environment']??''),
            (string)($source['ability']??''),(string)($source['job_id']??''),
            (string)($source['subject_id']??''),(string)($source['source_sha256']??''),
            (string)($source['revision']??''),(string)($source['reference_id']??''),
            $evidenceHash
        ]));
    }
    /** Current source-of-truth site binding. Never trusts transport hints. */
    public static function site_scope():array {
        $s=class_exists('MAD4B_SCP_Site_Profile',false)?
            MAD4B_SCP_Site_Profile::status():[];
        if(!is_array($s)||empty($s['site_uuid'])||empty($s['origin_match'])||
           empty($s['deployment_binding_match'])||empty($s['authority_ready']))
            return [];
        return ['site_uuid'=>(string)$s['site_uuid'],
          'environment'=>(string)wp_get_environment_type(),
          'max_evidence_age_seconds'=>self::DEFAULT_TTL];
    }
    public static function permitted():bool {
        return class_exists('MAD4B_SCP_Policy',false) &&
            MAD4B_SCP_Policy::can_mutate()===true &&
            current_user_can('manage_options') &&
            !empty(self::site_scope());
    }
    /**
     * Read-only acceptance of original write route and current operation
     * permission. Does not execute target mutation, create a grant, or
     * consider a synthetic WP-CLI REST request authenticated.
     */
    public static function route_admission($request):array {
        if(!class_exists('MAD4B_SCP_Capability_Transport_Routing',false))
            return self::fail('central_transport_unavailable');
        $e=MAD4B_SCP_Capability_Transport_Routing::rest_write_evidence($request);
        return (($e['rest_exact_write_verified']??false)===true)?
           ['contract'=>self::CONTRACT,'eligible'=>true,
             'reason'=>'NATIVE_REST_OPERATION_PERMISSION_VERIFIED',
             'mutation_performed'=>false]:self::fail('original_route_permission_denied');
    }
    /**
     * Governed durable write coordinator. Database claim prevents two PHP
     * requests executing the same intent. UNKNOWN remains held; no retries.
     * Domain-specific target operations and final readback remain in their
     * existing reviewed first-party adapter.
     */
    public static function execute(array $claim,MAD4B_Central_Enrichment_Write_Adapter $adapter):array {
        if(!self::permitted())return self::fail('actor_or_site_mutation_denied');
        $scope=self::site_scope();
        try {$source=$adapter->source($claim);}
        catch(Throwable $e){return self::fail('native_source_inspection_failed');}
        $proof=self::verify($claim,$source,$scope);
        if(!$proof['eligible'])return $proof;
        try {$authorized=$adapter->authorize($claim,$source);}
        catch(Throwable $e){return self::fail('original_ability_permission_unavailable');}
        if($authorized!==true)return self::fail('original_ability_permission_denied');
        global $wpdb;
        $key='mad4b_cwa_'.hash('sha256',$proof['idempotency_key']);
        $lock='mad4b:cwa:'.substr($key,-32);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
            return self::fail('operation_locked');
        try {
            $fresh=$adapter->source($claim);
            $again=self::verify($claim,$fresh,self::site_scope());
            if(!$again['eligible'])return $again;
            try {$permissionNow=$adapter->authorize($claim,$fresh);}
            catch(Throwable $e){$permissionNow=false;}
            if($permissionNow!==true)return self::fail('permission_changed');
            $existing=get_option($key,null);
            if(is_array($existing)) {
                if(($existing['state']??'')==='VERIFIED') {
                    try {$same=$adapter->readback($claim,$fresh,(array)($existing['result']??[]));}
                    catch(Throwable $e){$same=false;}
                    if($same===true)return [
                        'contract'=>self::CONTRACT,'eligible'=>true,
                        'reason'=>'ALREADY_VERIFIED','receipt_sha256'=>$existing['receipt_sha256']??null,
                        'mutation_performed'=>false,'replay_allowed'=>false];
                    return self::fail('prior_verified_readback_drift');
                }
                return self::fail('prior_attempt_uncertain_requires_reconciliation');
            }
            $attempt=['state'=>'IN_PROGRESS','site_uuid'=>$scope['site_uuid'],
                'job_id'=>$proof['job_id'],'subject_id'=>$proof['subject_id'],
                'source_sha256'=>$proof['source_sha256'],
                'evidence_sha256'=>$proof['evidence_sha256'],
                'attempted_at'=>gmdate('c')];
            if(!add_option($key,$attempt,'',false))
                return self::fail('prior_attempt_exists_or_unconfirmed');
            try {$result=$adapter->apply($claim,$fresh);}
            catch(Throwable $e){
                update_option($key,array_merge($attempt,['state'=>'UNKNOWN']),false);
                return self::fail('apply_exception_unknown_effect');
            }
            if(!is_array($result)||($result['success']??null)!==true){
                update_option($key,array_merge($attempt,['state'=>'UNKNOWN']),false);
                return self::fail('apply_result_uncertain');
            }
            try {$readback=$adapter->readback($claim,$fresh,$result);}
            catch(Throwable $e){$readback=false;}
            if($readback!==true){
                update_option($key,array_merge($attempt,['state'=>'UNKNOWN']),false);
                return self::fail('post_write_readback_failed');
            }
            $receipt=hash('sha256',wp_json_encode([
                'contract'=>self::CONTRACT,'intent'=>$proof['idempotency_key'],
                'result'=>$result,'site_uuid'=>$scope['site_uuid']],
                JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            update_option($key,array_merge($attempt,[
                'state'=>'VERIFIED','receipt_sha256'=>$receipt,
                'result'=>$result,'verified_at'=>gmdate('c')]),false);
            return ['contract'=>self::CONTRACT,'eligible'=>true,
                'reason'=>'VERIFIED_WRITE_AND_READBACK',
                'receipt_sha256'=>$receipt,'mutation_performed'=>true,
                'replay_allowed'=>false,'job_id'=>$proof['job_id'],
                'subject_id'=>$proof['subject_id']];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    /**
     * Explicit reconciliation of an uncertain ORIGINAL attempt. This never
     * calls apply() and never replays an external or native write. Evidence
     * and permission are reread, then the original durable attempt key is
     * promoted only after the reviewed provider's independent native readback.
     */
    public static function reconcile(array $claim,
        MAD4B_Central_Enrichment_Write_Adapter $adapter,array $native_result):array {
        if(!self::permitted())return self::fail('actor_or_site_mutation_denied');
        if(($native_result['success']??null)!==true)
            return self::fail('reconciliation_result_not_success');
        $scope=self::site_scope();
        try {$source=$adapter->source($claim);}
        catch(Throwable $e){return self::fail('reconciliation_native_source_unavailable');}
        $proof=self::verify($claim,$source,$scope);
        if(!$proof['eligible'])return $proof;
        try {$authorized=$adapter->authorize($claim,$source);}
        catch(Throwable $e){$authorized=false;}
        if($authorized!==true)return self::fail('reconciliation_original_permission_denied');
        global $wpdb;
        $key='mad4b_cwa_'.hash('sha256',$proof['idempotency_key']);
        $lock='mad4b:cwa:'.substr($key,-32);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
            return self::fail('reconciliation_operation_locked');
        try {
            $entry=get_option($key,null);
            if(!is_array($entry)||!in_array($entry['state']??'',
               ['UNKNOWN','IN_PROGRESS','VERIFIED'],true))
                return self::fail('original_uncertain_attempt_not_found');
            foreach(['site_uuid','job_id','subject_id','source_sha256','evidence_sha256'] as $field){
                if(!isset($entry[$field],$proof[$field])||
                    !hash_equals((string)$entry[$field],(string)$proof[$field]))
                    return self::fail('original_attempt_binding_mismatch');
            }
            try {$fresh=$adapter->source($claim);}
            catch(Throwable $e){return self::fail('reconciliation_source_changed');}
            $verified=self::verify($claim,$fresh,self::site_scope());
            if(!$verified['eligible'])return $verified;
            try {$same=$adapter->authorize($claim,$fresh);}
            catch(Throwable $e){$same=false;}
            if($same!==true)return self::fail('reconciliation_permission_changed');
            try {$ok=$adapter->readback($claim,$fresh,$native_result);}
            catch(Throwable $e){$ok=false;}
            if($ok!==true)return self::fail('reconciliation_readback_incomplete');
            if(($entry['state']??'')==='VERIFIED'){
                if(($entry['result']??null)!==$native_result)
                    return self::fail('previously_verified_result_changed');
                return ['contract'=>self::CONTRACT,'eligible'=>true,
                    'reason'=>'ALREADY_VERIFIED','receipt_sha256'=>$entry['receipt_sha256']??null,
                    'mutation_performed'=>false,'reconciliation_only'=>true];
            }
            $receipt=hash('sha256',wp_json_encode([
                'contract'=>self::CONTRACT,'intent'=>$proof['idempotency_key'],
                'result'=>$native_result,'site_uuid'=>$scope['site_uuid']],
                JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $changed=array_merge($entry,[
                'state'=>'VERIFIED','result'=>$native_result,
                'receipt_sha256'=>$receipt,'verified_at'=>gmdate('c'),
                'reconciled_at'=>gmdate('c'),'recovered_from'=>$entry['state']
            ]);
            if(!update_option($key,$changed,false))
                return self::fail('reconciliation_receipt_persist_failed');
            $saved=get_option($key,[]);
            if(($saved['state']??'')!=='VERIFIED' ||
               ($saved['receipt_sha256']??'')!==$receipt)
                return self::fail('reconciliation_receipt_readback_failed');
            return ['contract'=>self::CONTRACT,'eligible'=>true,
                'reason'=>'VERIFIED_ORIGINAL_WRITE_BY_READBACK',
                'receipt_sha256'=>$receipt,'mutation_performed'=>false,
                'reconciliation_only'=>true,'job_id'=>$proof['job_id'],
                'subject_id'=>$proof['subject_id']];
        }finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    /**
     * Registration-time integration flag for reviewed enrichment abilities.
     * These are read by the central audit, not an auto-grant to any tool.
     */
    public static function descriptor():array {
        return ['contract'=>self::CONTRACT,
            'required'=>['authorization','site_uuid','environment','job_id',
              'subject_id','source_sha256','source_revision','reference_id',
              'fresh_independent_evidence','quality','rights','idempotency',
              'native_readback','receipt'],
            'actor_authority_effect'=>'none',
            'generic_target_executor_enabled'=>false,
            'trusted_adapter_executor_supported'=>true,
            'read_or_discovery_grants_write'=>false,
            'central_transport_can_select_rest_write'=>true];
    }
    /**
     * The regulated adapter does the actual already-authorized operation;
     * a central wrapper cannot be used as a generic arbitrary PHP executor.
     * No caller-supplied callbacks cross an MCP/REST input boundary.
     */
}
