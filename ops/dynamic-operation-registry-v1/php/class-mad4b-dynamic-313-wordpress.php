<?php
/**
 * MAD4B first-party Dynamic 313 proof check and status (read-only).
 *
 * This module CANNOT ingest or finalize original 313 receipts, issue a
 * Broker Job, issue a Grant or mark Production ready for execution.
 * It is deliberately an additive read-only verifier.
 */
defined('ABSPATH') || exit;

require_once __DIR__.'/class-mad4b-dynamic-313-verifier.php';

final class MAD4B_Dynamic_WP_313_Readback_V1 {
    const CONTRACT='mad4b.dynamic-313-wordpress.v1';
    const PROOF_PREFIX='mad4b_dynamic_313_signed_evidence_v1_';

    private static function trusted():array {
        $file=__DIR__.'/config.php';
        $data=is_file($file) ? require $file : null;
        if (!is_array($data) ||
            ($data['contract']??'')!=='mad4b.dynamic-313-first-party-config.v1' ||
            !is_bool($data['original_313_finalizer_connected']??null) ||
            (($data['original_313_finalizer_connected']??false)===true &&
             (!class_exists('MAD4B_Native_WPCLI_Receipt_Verifier_V1',false) ||
              !is_callable(['MAD4B_Native_WPCLI_Receipt_Verifier_V1','supports_dynamic_v4']) ||
              !MAD4B_Native_WPCLI_Receipt_Verifier_V1::supports_dynamic_v4())) ||
            !is_array($data['trusted']??null) ||
            ($data['trusted']['first_party_enrolled']??null)!==true) {
            return [];
        }
        return $data['trusted'];
    }

    public static function enrolled_schema_id():string {
        $trusted=self::trusted();
        return (string)($trusted['provider']['evidence_schema']??'');
    }

    public static function status():array {
        $trusted=self::trusted();
        return [
            'contract'=>self::CONTRACT,
            'first_party_proof_enrolled'=>count($trusted)>0,
            'original_313_finalizer_connected'=>
                count($trusted)>0 &&
                ($config=is_file(__DIR__.'/config.php') ? require __DIR__.'/config.php' : []) &&
                is_array($config) &&
                ($config['original_313_finalizer_connected']??null)===true &&
                class_exists('MAD4B_Native_WPCLI_Receipt_Verifier_V1',false) &&
                MAD4B_Native_WPCLI_Receipt_Verifier_V1::supports_dynamic_v4(),
            'independent_verifier_ready'=>
                isset($trusted['independent_verifier_public_key_b64']) &&
                is_string($trusted['independent_verifier_public_key_b64']),
            'dynamic_proof_ingest_ready'=>count($trusted)>0,
            // End-to-end original host receipt writer and policy must also
            // be installed and independently verified before 306 dispatch.
            'operational_ready'=>false,
            'operation_id'=>$trusted['provider']['operation_id']??null,
            'job_issued'=>false,
            'grant_issued'=>false,
            'original_313_receipt_issued'=>false,
            'production_mutated'=>false,
        ];
    }

    public static function check_exact(array $proof):array {
        if (!self::trusted()) {
            return ['verified'=>false,'reason'=>'FIRST_PARTY_DYNAMIC_REGISTRY_ABSENT'];
        }
        if (!class_exists('MAD4B_Central_VPS_Broker_V1',false) ||
            !class_exists('MAD4B_Broker_Remote_SSH_Receipt_V1',false) ||
            !class_exists('MAD4B_Broker_Exact_SSH_Authority_V1',false)) {
            return ['verified'=>false,'reason'=>'ORIGINAL_306_312_313_UNAVAILABLE'];
        }
        $jobid=$proof['job_id']??null;
        $target=$proof['target_id']??null;
        $digest=$proof['command_sha256']??null;
        if (!is_string($jobid) || !preg_match('/^[a-f0-9]{32}$/D',$jobid) ||
            !is_string($target) || !preg_match('/^[a-z0-9-]{3,64}$/D',$target) ||
            !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D',$digest)) {
            return ['verified'=>false,'reason'=>'DYNAMIC_PROOF_IDENTITY_INVALID'];
        }
        $original=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$jobid,false);
        $pending=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_PREFIX.$jobid,false);
        if (!is_array($original) || !is_array($pending) ||
            ($original['job_id']??null)!==$jobid ||
            ($pending['job_id']??null)!==$jobid ||
            ($pending['state']??null)!=='PENDING_INDEPENDENT_NATIVE_READBACK' ||
            ($pending['command_sha256']??null)!==$digest ||
            ($pending['target_id']??null)!==$target) {
            return ['verified'=>false,'reason'=>'ORIGINAL_313_PENDING_PROOF_MISSING'];
        }
        // Original Job stores canonical_origin, not an arbitrary user URL.
        $original['origin']=$original['canonical_origin']??null;
        $authority=MAD4B_Broker_Exact_SSH_Authority_V1::evaluate(
            $jobid,$target,$digest);
        $result=MAD4B_Dynamic_313_Proof_V1::verify(
            $proof,self::trusted(),$original,$authority);
        if (($result['verified']??false)!==true) return $result;
        $signed=$proof['payload'];
        foreach(['grant_id','transport_id','output_sha256',
                 'readback_sha256'] as $field) {
            if (($signed[$field]??null)!==($pending[$field]??null))
                return ['verified'=>false,
                    'reason'=>'DYNAMIC_313_PENDING_SCOPE_MISMATCH_'.$field];
        }
        if (($signed['expected_readback_sha256']??null)!==
                ($pending['readback_sha256']??null))
            return ['verified'=>false,'reason'=>'DYNAMIC_313_POSTCONDITION_CHANGED'];
        // Only original Central 313 finish can issue the final receipt.
        return $result+[
            'original_313_receipt_issued'=>false,
            'proof_persisted'=>false
        ];
    }

    // Persist an independently signed proof only after obtaining fresh
    // ORIGINAL 306/312 authority and a pending original 313 receipt. This
    // storage does NOT finalize a Job or sign an Original receipt.
    public static function ingest_exact(array $proof):array {
        $checked=self::check_exact($proof);
        if (($checked['verified']??false)!==true)
            return $checked;
        $job=(string)($checked['job_id']??'');
        $key=self::PROOF_PREFIX.$job;
        if (!add_option($key,$proof,'',false))
            return ['verified'=>false,'reason'=>'DYNAMIC_313_PROOF_ALREADY_STORED'];
        $read=get_option($key,false);
        if (!is_array($read) || $read!==$proof)
            return ['verified'=>false,'reason'=>'DYNAMIC_313_PROOF_READBACK_UNCERTAIN'];
        return ['verified'=>true,'job_id'=>$job,'operation_id'=>$checked['operation_id'],
                'state'=>'INDEPENDENT_DYNAMIC_EVIDENCE_STORED',
                'original_313_certified'=>false,'original_313_receipt_issued'=>false];
    }

    // For the actual Original 313 verifier, NEVER accept caller-provided
    // evidence. Re-fetch and re-check the independently signed proof.
    public static function recorded_exact_for_original_313(string $jobid):array {
        if (!preg_match('/^[a-f0-9]{32}$/D',$jobid))
            return ['verified'=>false,'reason'=>'DYNAMIC_313_JOB_ID_INVALID'];
        $row=get_option(self::PROOF_PREFIX.$jobid,false);
        if (!is_array($row) || ($row['job_id']??null)!==$jobid)
            return ['verified'=>false,'reason'=>'DYNAMIC_313_SIGNED_EVIDENCE_NOT_STORED'];
        $check=self::check_exact($row);
        if (($check['verified']??false)!==true) return $check;
        return ['verified'=>true,'job_id'=>$jobid,'operation_id'=>$check['operation_id'],
                'proof'=>$row];
    }

    public static function cli($args,$assoc):void {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI') ||
            !current_user_can('manage_options')) {
            WP_CLI::error('CENTRAL_WPCLI_MANAGER_REQUIRED');
        }
        if (($args[0]??'')==='status' && !$assoc) {
            WP_CLI::line(wp_json_encode(self::status()));
            return;
        }
        if (!in_array(($args[0]??''),['check','ingest'],true)
            || array_keys($assoc)!==['payload']) {
            WP_CLI::error('READ_ONLY_STATUS_OR_EXACT_SIGNED_PROOF_REQUIRED');
        }
        $blob=base64_decode((string)$assoc['payload'],true);
        if (!is_string($blob) || strlen($blob)>12000) {
            WP_CLI::error('DYNAMIC_PROOF_PAYLOAD_INVALID');
        }
        $proof=json_decode($blob,true);
        $result=is_array($proof)
            ? (($args[0]==='ingest')?self::ingest_exact($proof):self::check_exact($proof))
            : ['verified'=>false,'reason'=>'DYNAMIC_PROOF_JSON_INVALID'];
        if (($result['verified']??false)!==true) {
            WP_CLI::error((string)($result['reason']??'DYNAMIC_PROOF_DENIED'));
        }
        WP_CLI::line(wp_json_encode($result));
    }

    public static function boot():void {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('mad4b dynamic-313-proof',[__CLASS__,'cli']);
        }
    }
}
MAD4B_Dynamic_WP_313_Readback_V1::boot();
