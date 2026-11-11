<?php
/**
 * MAD4B Dynamic 313 Proof Verifier v1 (source-only candidate).
 *
 * No command routing, Job creation, Grants, receipt persistence or public REST.
 * The caller MUST obtain the enrolled registry record and original 306/312
 * Job from separate first-party services. Never pass caller-supplied records
 * as authority. Legacy 313 remains unchanged until governed enrollment.
 */
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') { exit; }

final class MAD4B_Dynamic_313_Proof_V1 {
    const CONTRACT = 'mad4b.dynamic-313-independent-proof.v1';
    const KEYS = [
        'contract','job_id','operation_id','provider_version','evidence_schema',
        'company','environment','site_uuid','origin','target_id',
        'command_sha256','plan_sha256','evidence_sha256','payload',
        'issued_epoch','expires_epoch','nonce','signature'
    ];

    private static function deny($why) {
        return ['verified'=>false,'reason'=>$why,'original_313_certified'=>false];
    }

    private static function canonical($data) {
        if (is_array($data)) {
            if (array_is_list($data)) {
                return array_map([__CLASS__, 'canonical'], $data);
            }
            ksort($data, SORT_STRING);
            foreach ($data as $key=>$value) {
                $data[$key] = self::canonical($value);
            }
        }
        return $data;
    }

    private static function encode($value) {
        $encoded = json_encode(self::canonical($value),
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new RuntimeException('CANONICAL_JSON_INVALID');
        }
        return $encoded;
    }

    private static function schema_check(array $payload, array $schema) {
        if (($schema['contract'] ?? '') !== 'mad4b.dynamic-evidence-schema.v1'
            || !is_array($schema['fields'] ?? null)
            || !is_string($schema['success_state'] ?? null)
            || !isset($schema['fields']['result_status'])) {
            return false;
        }
        $fields = $schema['fields'];
        $actual = array_keys($payload);
        $expected = array_keys($fields);
        sort($actual); sort($expected);
        if ($actual !== $expected) {
            return false;
        }
        foreach ($fields as $field=>$type) {
            $value = $payload[$field];
            $valid = match ($type) {
                'integer' => is_int($value) && $value >= 0,
                'boolean' => is_bool($value),
                'sha256' => is_string($value) &&
                    preg_match('/^[a-f0-9]{64}$/D', $value) === 1,
                'string' => is_string($value) && strlen($value) > 0 &&
                    strlen($value) <= 200,
                default => false,
            };
            if (!$valid) return false;
        }
        return $payload['result_status'] === $schema['success_state'];
    }

    /**
     * Pure independent verifier, not an authority minting interface.
     * $trusted is first-party registry state, $originalJob/$original312 must
     * have been independently fetched from the Central Broker.
     */
    public static function verify(array $proof, array $trusted,
                                  array $originalJob, array $original312,
                                  ?int $now = null) {
        $clock = $now ?? time();
        $keys = array_keys($proof);
        $expected = self::KEYS;
        sort($keys); sort($expected);
        if ($keys !== $expected) return self::deny('DYNAMIC_PROOF_SCHEMA_INVALID');
        if (($trusted['registry_contract'] ?? '') !==
                'mad4b.dynamic-operation-index.v1'
            || ($trusted['first_party_enrolled'] ?? null) !== true
            || !is_array($trusted['provider'] ?? null)
            || !is_array($trusted['evidence_schema'] ?? null)) {
            return self::deny('FIRST_PARTY_REGISTRY_NOT_ENROLLED');
        }
        $m = $trusted['provider'];
        $schema = $trusted['evidence_schema'];
        if (($proof['contract'] ?? null) !== self::CONTRACT
            || !is_int($proof['provider_version'])
            || $proof['provider_version'] !== ($m['version'] ?? null)
            || $proof['operation_id'] !== ($m['operation_id'] ?? null)
            || $proof['evidence_schema'] !== ($m['evidence_schema'] ?? null)
            || $proof['evidence_schema'] !== ($schema['schema_id'] ?? null)
            || ($m['command']['bound_operation_id'] ?? null) !==
                $proof['operation_id']) {
            return self::deny('PROVIDER_BINDING_CHANGED');
        }
        $nowLimit = $proof['issued_epoch'];
        $expires = $proof['expires_epoch'];
        if (!is_int($nowLimit) || !is_int($expires)
            || $nowLimit < $clock-120 || $nowLimit > $clock+10
            || $expires <= $clock || $expires > $nowLimit+120
            || !is_string($proof['job_id'])
            || !preg_match('/^[a-f0-9]{32}$/D',$proof['job_id'])
            || !is_string($proof['nonce'])
            || !preg_match('/^[a-f0-9]{32}$/D',$proof['nonce'])
            || !is_string($proof['plan_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D',$proof['plan_sha256'])
            || !is_string($proof['site_uuid'])
            || !preg_match('/^[a-f0-9-]{36}$/D',$proof['site_uuid'])
            || !is_string($proof['origin'])
            || !str_starts_with($proof['origin'],'https://')) {
            return self::deny('DYNAMIC_PROOF_SCOPE_OR_TIME_INVALID');
        }
        $command = $m['command'];
        if (($command['format'] ?? '') !== 'wpcli.argv.json.v1'
            || ($command['mode'] ?? '') !== 'plan_sha256'
            || !is_array($command['prefix'] ?? null)
            || !is_string($command['flag'] ?? null)) {
            return self::deny('REGISTRY_COMMAND_SCHEMA_INVALID');
        }
        $argv = array_merge($command['prefix'],
            [$command['flag'].$proof['plan_sha256']]);
        if (!hash_equals(hash('sha256',self::encode($argv)),
                (string)$proof['command_sha256'])) {
            return self::deny('DYNAMIC_COMMAND_HASH_CHANGED');
        }
        if (!is_array($proof['payload'])
            || !self::schema_check($proof['payload'],$schema)
            || !hash_equals(hash('sha256',self::encode($proof['payload'])),
                (string)$proof['evidence_sha256'])) {
            return self::deny('DYNAMIC_EVIDENCE_MISMATCH');
        }
        $fields=$proof['payload'];
        if (($fields['grant_id']??null)!==($original312['grant_id']??null)
            || ($fields['transport_id']??null)!==($original312['transport_id']??null)
            || ($fields['expected_readback_sha256']??null)!==
                ($fields['readback_sha256']??null)
            || ($fields['production_mutation']??null)!==
                ($proof['environment']==='production')) {
            return self::deny('DYNAMIC_313_ORIGINAL_RECEIPT_BINDING_MISMATCH');
        }
        if (($originalJob['job_id'] ?? null) !== $proof['job_id']
            || ($originalJob['target_host_id'] ?? null) !== $proof['target_id']
            || ($originalJob['command_sha256'] ?? null) !==
                $proof['command_sha256']
            || ($originalJob['operation'] ?? null) !== 'company.remote.wpcli.full'
            || ($originalJob['job_type'] ?? null) !== 'signed_vps_wpcli'
            || ($originalJob['target_scope'] ?? null) !== 'wordpress'
            || ($original312['contract'] ?? null) !==
                'mad4b.broker-exact-ssh-authority.v1'
            || ($original312['verified'] ?? null) !== true
            || ($original312['eligible'] ?? null) !== true
            || ($original312['owner_enrolled_wpcli'] ?? null) !== true
            || ($original312['central_scope_only'] ?? null) !== true
            || ($original312['shell_executed'] ?? null) !== false
            || ($original312['expires_epoch'] ?? 0) < $clock+20) {
            return self::deny('ORIGINAL_312_CLAIM_INVALID');
        }
        foreach (['job_id','company','environment','site_uuid','origin',
                  'command_sha256'] as $key) {
            if (($originalJob[$key] ?? null) !== ($proof[$key] ?? null)
                || ($original312[$key] ?? null) !== ($proof[$key] ?? null)) {
                return self::deny('ORIGINAL_306_312_SCOPE_CHANGED_'.$key);
            }
        }
        if (($original312['target_id'] ?? null) !== $proof['target_id']) {
            return self::deny('ORIGINAL_312_HOST_CHANGED');
        }
        $public = base64_decode(
            (string)($trusted['independent_verifier_public_key_b64'] ?? ''),true);
        $signature = base64_decode((string)$proof['signature'],true);
        $data = $proof; unset($data['signature']);
        if (!is_string($public)
            || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached(
                $signature, self::encode($data), $public)) {
            return self::deny('INDEPENDENT_313_SIGNATURE_INVALID');
        }
        return [
            'verified'=>true,
            'operation_id'=>$proof['operation_id'],
            'job_id'=>$proof['job_id'],
            'evidence_sha256'=>$proof['evidence_sha256'],
            'original_313_certified'=>false,
            'central_313_receipt_created'=>false,
        ];
    }
}
