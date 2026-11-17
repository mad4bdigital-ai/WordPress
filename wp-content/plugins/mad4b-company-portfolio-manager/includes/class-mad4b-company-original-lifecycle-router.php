<?php
defined('ABSPATH') || exit;

/**
 * Read-only route selection atop the ORIGINAL MAD4B providers and Broker.
 * A status is never a Job, Grant, 312 invocation, or 313 certificate.
 */
final class MAD4B_Company_Original_Lifecycle_Router_V1 {
    const CONTRACT = 'mad4b.original-lifecycle-routing.v1';

    public static function select(array $e):array {
        $job = isset($e['job_id']) ? (string)$e['job_id'] : '';
        $has_job = $job !== '';
        $receipt = ($e['native_execution_receipt'] ?? false) === true;
        $post = ($e['independent_postcondition_verified'] ?? false) === true;
        $cert = ($e['original_313_verified'] ?? false) === true;
        $operation = (string)($e['operation'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9._-]{2,63}$/D', $operation)
            || ($has_job && !preg_match('/^[a-f0-9]{32}$/D',$job))
            || ($receipt && !$has_job)
            || ($post && !$receipt)
            || ($cert && (!$post || !$has_job)))
            return self::result($operation,$job,'NO_AUTHORIZED_ROUTE',
                'INVALID_OR_INCONSISTENT_EVIDENCE','inspect_original_scope',false);
        if ($cert)
            return self::result($operation,$job,'RECEIPT_ONLY_RECONCILIATION',
                'INDEPENDENT_313_VERIFIED','no_action',true);
        if ($receipt)
            return self::result($operation,$job,'RECEIPT_ONLY_RECONCILIATION',
                $post ? 'POSTEXECUTION_RECEIPT_ONLY_RECOVERY_REQUIRED'
                      : 'INDEPENDENT_READBACK_REQUIRED',
                $post ? 'original_owner_historical_receipt_only_recovery'
                      : 'independent_readback_only',false);
        if ($has_job)
            return self::result($operation,$job,
                ($e['signed_claim_eligible'] ?? false) === true
                    ? 'ORIGINAL_BROKER_305_306_312_313' : 'RECEIPT_ONLY_RECONCILIATION',
                ($e['signed_claim_eligible'] ?? false) === true
                    ? 'ORIGINAL_SIGNED_JOB_ACTIVE' : 'ORIGINAL_JOB_EXPIRED_RECONCILE_NO_REEXECUTION',
                ($e['signed_claim_eligible'] ?? false) === true
                    ? 'original_312_once' : 'inspect_historical_host_evidence_no_new_execution',false);
        if (($e['same_host'] ?? false) === true
            && ($e['verified_direct_authority'] ?? false) === true
            && ($e['requires_original_broker'] ?? false) !== true)
            return self::result($operation,'','DIRECT_NATIVE',
                'DIRECT_NATIVE_AUTHORITY_REQUIRED_AT_EXECUTION',
                'call_existing_native_executor_with_live_permission_check',false);
        if (($e['original_broker_ready'] ?? false) === true
            && ($e['registered_operation'] ?? false) === true)
            return self::result($operation,'','ORIGINAL_BROKER_305_306_312_313',
                'ORIGINAL_305_OWNER_SUBMISSION_REQUIRED','check_existing_job_before_owner_submit_original_305',false);
        return self::result($operation,'','NO_AUTHORIZED_ROUTE',
            'OPERATION_AUTHORITY_NOT_READY','inspect_operation_capability',false);
    }

    private static function result(string $operation,string $job,string $route,
        string $stage,string $next,bool $certified):array {
        return array('contract'=>self::CONTRACT,'operation'=>$operation,
            'job_id'=>$job?:null,'route'=>$route,'stage'=>$stage,
            'next_action'=>$next,'allow_reexecution'=>false,
            'original_313_certified'=>$certified,
            'authorizes_execution'=>false,'adds_grants'=>false);
    }

    public static function status(string $company,string $environment,string $operation,
        string $job=''):array {
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
            || !in_array($environment,array('staging','production'),true)
            || !class_exists('MAD4B_Company_Environment_Registry_V2',false))
            return self::select(array('operation'=>$operation,'job_id'=>'bad'));
        $target = MAD4B_Company_Environment_Registry_V2::target($company,$environment);
        if (!is_array($target)||empty($target['site_uuid'])||empty($target['canonical_origin']))
            return self::select(array('operation'=>$operation,'job_id'=>'bad'));
        $facts=array('operation'=>$operation,'requires_original_broker'=>true);
        $registry='MAD4B_Original_Native_Operation_Providers_V1';
        if (class_exists($registry,false) && !in_array($operation,
            array('company.remote.wpcli.full','maintenance.shell'),true)) {
            $provider=$registry::profile($operation);
            $facts['registered_operation']=is_array($provider)
                && ($provider['operation']??null)===$operation
                && ($provider['authority_family']??null)==='original_305';
            $facts['original_broker_ready']=$facts['registered_operation']
                && $registry::legacy_admitted($operation,'wordpress','wpcli.argv.json.v1');
        }
        // Only local, authenticated WordPress READ operations may prefer Direct.
        // Never infer direct Write authority from shell_access or a Profile flag.
        if (!$job && !empty($facts['registered_operation']) && str_ends_with($operation,'.read')
            && untrailingslashit(home_url('/'))===$target['canonical_origin']
            && current_user_can('manage_options')) {
            $facts['same_host']=true;
            $facts['verified_direct_authority']=true;
            $facts['requires_original_broker']=false;
        }
        if ($job !== '') {
            if (!preg_match('/^[a-f0-9]{32}$/D',$job)
                || !class_exists('MAD4B_Central_VPS_Broker_V1',false))
                return self::select(array('operation'=>$operation,'job_id'=>'bad'));
            $row=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$job,false);
            if (!is_array($row) || ($row['job_id']??null)!==$job
                || ($row['company']??null)!==$company
                || ($row['environment']??null)!==$environment
                || ($row['site_uuid']??null)!==$target['site_uuid']
                || ($row['canonical_origin']??null)!==$target['canonical_origin'])
                return self::select(array('operation'=>$operation,'job_id'=>'bad'));
            $facts['job_id']=$job;
            // Use ORIGINAL 312 authority, not merely a date on the Job row.
            // This is only status discovery; no Grant or new Claim is issued.
            $facts['signed_claim_eligible']=false;
            if (($row['state']??'')==='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
                && ($row['executed']??null)===false
                && (int)($row['expires_epoch']??0)>time()
                && class_exists('MAD4B_Broker_Exact_SSH_Authority_V1',false)
                && is_callable(array('MAD4B_Broker_Exact_SSH_Authority_V1','evaluate'))) {
                $proof=MAD4B_Broker_Exact_SSH_Authority_V1::evaluate(
                    $job,(string)($row['target_host_id']??''),
                    (string)($row['command_sha256']??''));
                $facts['signed_claim_eligible']=is_array($proof)
                    && ($proof['eligible']??false)===true
                    && ($proof['verified']??false)===true
                    && ($proof['site_uuid']??null)===$target['site_uuid']
                    && ($proof['company']??null)===$company
                    && ($proof['environment']??null)===$environment;
            }
            // A pending original job alone NEVER proves the host receipt.
            // Original 313-certified rows can be presented as complete.
            $completed=($row['state']??'')==='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
                && ($row['executed']??false)===true
                && ($row['independent_readback_verified']??false)===true
                && ($row['receipt_contract']??'')==='mad4b.broker-wpcli-native-finish.v1';
            if ($completed) {
                $facts['native_execution_receipt']=true;
                $facts['independent_postcondition_verified']=true;
                $facts['original_313_verified']=true;
            }
        }
        return self::select($facts);
    }

    public static function cli($args,$assoc):void {
        if (($args[0]??'')!=='status' || count($args)!==1
            || array_diff(array_keys($assoc),array('company','environment','operation','job'))
            || !current_user_can('manage_options'))
            WP_CLI::error('CURRENT_OWNER_AND_READONLY_LIFECYCLE_ARGS_REQUIRED');
        WP_CLI::line(wp_json_encode(self::status(
            (string)($assoc['company']??''),(string)($assoc['environment']??''),
            (string)($assoc['operation']??''),(string)($assoc['job']??''))));
    }
    public static function boot():void {
        if (defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b lifecycle-route',array(__CLASS__,'cli'));
    }
}
MAD4B_Company_Original_Lifecycle_Router_V1::boot();
