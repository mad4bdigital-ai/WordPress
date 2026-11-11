<?php
/**
 * Plugin Name: MAD4B Broker General Shell Completion Receipt
 * Description: Ingest cryptographically signed Broker execution/readback evidence; never accepts commands or issues grants.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Broker_General_Shell_Receipt_V1 {
    const CONTRACT = 'mad4b.broker-general-shell-completion.v1';
    const PREFIX = 'mad4b_broker_general_shell_receipt_v1_';
    const NONCE_PREFIX = 'mad4b_broker_general_shell_nonce_v1_';
    const HOST = 'srv2030908';
    const FIELDS = array('contract','host_id','job_id','target_id','grant_id',
        'transport_id','company','site_uuid','origin','environment','operation',
        'command_sha256','readback_sha256','expected_readback_sha256',
        'state','command_exit_code','readback_exit_code','issued','expires','nonce');

    private static function fail($reason) {
        return array('contract'=>self::CONTRACT,'verified'=>false,
            'reason'=>$reason,'central_completion_recorded'=>false);
    }

    public static function signed_text(array $data) {
        if (array_keys($data) !== array_merge(self::FIELDS, array('signature')))
            return false;
        $parts = array();
        foreach (self::FIELDS as $name) {
            if (!array_key_exists($name, $data)) return false;
            $value = $data[$name];
            if (!is_string($value) && !is_int($value)) return false;
            $parts[] = (string) $value;
        }
        return implode("\n", $parts);
    }

    public static function verify_and_record(array $data) {
        if (!class_exists('MAD4B_Broker_Exact_SSH_Authority_V1',false)
            || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
            || !class_exists('MAD4B_Central_SSH_Command_Intake_V1',false))
            return self::fail('ORIGINAL_CORE_COMPONENTS_MISSING');
        $body = self::signed_text($data);
        if (!is_string($body)) return self::fail('RECEIPT_SHAPE_INVALID');
        $job = $data['job_id']; $target = $data['target_id'];
        $now = time();
        if ($data['contract'] !== self::CONTRACT || $data['host_id'] !== self::HOST
            || !preg_match('/^[a-f0-9]{32}$/D', $job)
            || !preg_match('/^[a-f0-9]{32}$/D', $data['grant_id'])
            || !preg_match('/^[a-f0-9]{32}$/D', $data['transport_id'])
            || !preg_match('/^[a-f0-9]{64}$/D', $data['command_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $data['readback_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $data['expected_readback_sha256'])
            || !preg_match('/^[a-f0-9]{32}$/D', $data['nonce'])
            || !in_array($data['state'], array('VERIFIED','FAILED_REQUIRES_RECONCILIATION'),true)
            || !is_int($data['command_exit_code']) || !is_int($data['readback_exit_code'])
            || !is_int($data['issued']) || !is_int($data['expires'])
            || $data['issued'] < $now - 120 || $data['issued'] > $now + 10
            || $data['expires'] < $now || $data['expires'] > $data['issued'] + 120)
            return self::fail('RECEIPT_FIELDS_INVALID_OR_EXPIRED');
        if ($data['operation'] !== 'maintenance.shell'
            || ($data['state'] === 'VERIFIED'
                && ($data['command_exit_code'] !== 0 || $data['readback_exit_code'] !== 0
                    || !hash_equals($data['expected_readback_sha256'], $data['readback_sha256']))))
            return self::fail('READBACK_RESULT_INVALID');
        $sig = base64_decode($data['signature'],true);
        $pub = base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
        if (!is_string($sig) || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
            || !is_string($pub) || strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($sig, $body, $pub))
            return self::fail('BROKER_SIGNATURE_INVALID');
        // Independent 312 readback must still prove the original *unconsumed*
        // exact job, grant, company, site, transport, target and command.
        $auth = MAD4B_Broker_Exact_SSH_Authority_V1::evaluate($job,$target,$data['command_sha256']);
        if (($auth['verified']??false)!==true || ($auth['eligible']??false)!==true)
            return self::fail('CENTRAL_GRANT_OR_CLAIM_REVOKED');
        foreach (array('grant_id','transport_id','company','site_uuid','origin','environment','operation') as $key) {
            if (($auth[$key]??null)!==$data[$key]) return self::fail('EXACT_SCOPE_CHANGED_'.$key);
        }
        if (($auth['target_id']??null)!==$target || ($auth['job_id']??null)!==$job)
            return self::fail('JOB_OR_TARGET_CHANGED');
        $job_key = MAD4B_Central_VPS_Broker_V1::JOB.$job;
        $receipt_key = self::PREFIX.$job;
        $nonce_key = self::NONCE_PREFIX.$data['nonce'];
        $payload_sha256 = hash('sha256',$body);
        // Crash-safe completion: when the signed receipt was durably stored
        // but the original 306 job update failed, the *same exact signed*
        // payload may finish the original job. This never repeats host Shell.
        $nonce_owner = get_option($nonce_key,null);
        if ($nonce_owner !== null) {
            $saved = get_option($receipt_key,false);
            if ($nonce_owner !== $job || !is_array($saved)
                || ($saved['nonce']??null) !== $data['nonce']
                || ($saved['signed_payload_sha256']??null) !== $payload_sha256
                || ($saved['state']??null) !== $data['state']
                || ($saved['grant_id']??null) !== $data['grant_id']
                || ($saved['command_sha256']??null) !== $data['command_sha256'])
                return self::fail('NONCE_REPLAY');
            // A completed job is never accepted a second time, even with
            // identical, correctly signed evidence.
            $still_pending = get_option($job_key,false);
            if (!is_array($still_pending)
                || ($still_pending['state']??null) !== 'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
                || ($still_pending['executed']??null) !== false)
                return self::fail('NONCE_REPLAY');
            return self::finalize_original_job($job_key,$job,$data,true);
        }
        // Reject missing/already-finished original job BEFORE allocating a
        // nonce or receipt. Never manufacture a completion for an absent job.
        $pending = get_option($job_key,false);
        if (!is_array($pending)
            || ($pending['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
            || ($pending['executed']??null)!==false)
            return self::fail('ORIGINAL_JOB_NOT_PENDING_RECONCILE_MANUALLY');
        if (!add_option($nonce_key,$job,'',false))
            return self::fail('NONCE_REPLAY');
        $record = array('contract'=>self::CONTRACT,'state'=>$data['state'],
            'job_id'=>$job,'target_id'=>$target,'grant_id'=>$data['grant_id'],
            'command_sha256'=>$data['command_sha256'],
            'readback_sha256'=>$data['readback_sha256'],
            'nonce'=>$data['nonce'],'signed_payload_sha256'=>$payload_sha256,
            'recorded_at'=>gmdate('c'),'broker_signature_verified'=>true,
            'original_core_scope_verified'=>true);
        if (!add_option($receipt_key,$record,'',false))
            return self::fail('JOB_RECEIPT_REPLAY');
        return self::finalize_original_job($job_key,$job,$data,false);
    }

    private static function finalize_original_job($job_key,$job,array $data,$recovery) {
        $row = get_option($job_key,false);
        if (!is_array($row) || ($row['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
            || ($row['executed']??null)!==false)
            return self::fail('ORIGINAL_JOB_NOT_PENDING_RECONCILE_MANUALLY');
        $row['state'] = $data['state']==='VERIFIED'
            ? 'GENERAL_SHELL_EXECUTION_READBACK_VERIFIED' : 'GENERAL_SHELL_REQUIRES_RECONCILIATION';
        $row['executed'] = true;
        $row['independent_host_permission_verified'] = true;
        $row['independent_readback_verified'] = $data['state']==='VERIFIED';
        $row['receipt_contract'] = self::CONTRACT;
        if (!update_option($job_key,$row,false))
            return self::fail('JOB_UPDATE_UNCERTAIN_RECONCILE_MANUALLY');
        // Readback is mandatory even after update_option reports success.
        $confirmed = get_option($job_key,false);
        if (!is_array($confirmed) || ($confirmed['state']??null)!==$row['state']
            || ($confirmed['executed']??null)!==true
            || ($confirmed['receipt_contract']??null)!==self::CONTRACT)
            return self::fail('JOB_READBACK_UNCERTAIN_RECONCILE_MANUALLY');
        return array('contract'=>self::CONTRACT,'verified'=>true,
            'central_completion_recorded'=>true,'job_id'=>$job,
            'state'=>$row['state'],'readback_verified'=>$row['independent_readback_verified'],
            'reconciled_from_stored_receipt'=>$recovery);
    }

    public static function cli($args,$assoc) {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')
            || count($args)!==1 || $args[0]!=='receipt'
            || !is_array($assoc) || array_keys($assoc)!==array('payload'))
            WP_CLI::error('SIGNED_BROKER_RECEIPT_REQUIRED');
        $raw = base64_decode((string)$assoc['payload'],true);
        if (!is_string($raw) || strlen($raw)>6000) WP_CLI::error('INVALID_RECEIPT_PAYLOAD');
        $data = json_decode($raw,true);
        if (!is_array($data)) WP_CLI::error('INVALID_RECEIPT_JSON');
        $r = self::verify_and_record($data);
        if (($r['verified']??false)!==true) WP_CLI::error($r['reason']);
        WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
    }

    public static function boot() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b broker-general-shell',array(__CLASS__,'cli'));
    }
}
MAD4B_Broker_General_Shell_Receipt_V1::boot();
