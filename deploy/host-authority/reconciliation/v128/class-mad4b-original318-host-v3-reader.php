<?php
/**
 * Original318 v3, source-only readback adapter for signed Host enrollment.
 *
 * This class never issues approvals, calls wp-cli, performs writes, checks a
 * generic request token or reclassifies a historical v2 anchor as active.
 * Trusted site record and 312/313 public keys MUST come from first-party
 * immutable runtime bindings, never caller arguments at an HTTP endpoint.
 */
if ( ! class_exists( 'MAD4B_Original318_Host_V3_Reader', false ) ) {
final class MAD4B_Original318_Host_V3_Reader {
    private const ENROLLMENT = 'mad4b.original318.host-enrollment.v3';
    private const CHECKPOINT = 'mad4b.original313.host-connection-highwater.v3';
    private const ENROLLMENT_DOMAIN = "MAD4B_HOST_ENROLLMENT_V3\n";
    private const CHECKPOINT_DOMAIN = "MAD4B_HOST_CONNECTION_HIGHWATER_V3\n";
    private const ENROLLMENT_FIELDS = array(
        'contract','company','environment','site_uuid','target_host_id',
        'ssh_principal','host_key_sha256','policy_sha256','revision','nonce',
        'issued_epoch','mode','expires_epoch','state'
    );
    private const CHECKPOINT_FIELDS = array(
        'contract','company','environment','site_uuid','revision',
        'enrollment_sha256','state','operation_id','activity_sha256',
        'activity_seq','observed_epoch'
    );

    private static function exact_fields($row, array $fields): bool {
        if ( ! is_array($row) || array_is_list($row) ) return false;
        $actual = array_keys($row);
        $expected = array_merge($fields, array('signature'));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private static function hex($value, int $size): bool {
        return is_string($value) && (bool) preg_match('/^[a-f0-9]{'.$size.'}$/D', $value);
    }

    private static function canonical(array $row, array $fields, string $domain): ?string {
        $body = array();
        foreach ($fields as $field) {
            if (!array_key_exists($field,$row)) return null;
            $body[$field]=$row[$field];
        }
        ksort($body, SORT_STRING);
        // Strict scalar signed fields; all identity strings are ASCII-only.
        foreach ($body as $value) {
            if (!is_string($value) && !is_int($value) && !is_null($value)) return null;
        }
        $encoded=json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $domain.$encoded : null;
    }

    private static function verify($row, array $fields, string $contract, string $domain, string $pub): bool {
        if ( ! function_exists('sodium_crypto_sign_verify_detached')
            || ! self::exact_fields($row,$fields)
            || ($row['contract']??null) !== $contract
            || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) return false;
        if (!is_string($row['signature'])) return false;
        $raw=base64_decode($row['signature'],true);
        if (!is_string($raw) || strlen($raw)!==SODIUM_CRYPTO_SIGN_BYTES) return false;
        $canonical=self::canonical($row,$fields,$domain);
        return is_string($canonical) && sodium_crypto_sign_verify_detached($raw,$canonical,$pub);
    }

    private static function digest(array $row, array $fields, string $domain): string {
        $canonical=self::canonical($row,$fields,$domain);
        return is_string($canonical) ? hash('sha256',$canonical) : '';
    }

    /**
     * Pure readback from signed first-party inputs. This never authorizes
     * either a generic Host mutation or an individual operation.
     */
    public static function verify_readback(
        $enrollment, $checkpoint, $site,
        $root312_public, $independent313_public, ?int $instant=null
    ): array {
        $now=$instant ?? time();
        $out=array(
            'contract'=>'mad4b.original318.host-connection-readback.v3',
            'source'=>'original318_current_wp_readback',
            'observed_epoch'=>$now,
            'readback_verified'=>false,
            'signature_verified'=>false,
            'state'=>'MISSING_OR_INVALID',
            'revision'=>0,
            'host_key_sha256'=>null,
            'enrollment_sha256'=>null,
            'operation_id'=>null,
            'activity_sha256'=>null,
            'activity_seq'=>null,
            'write_authorized'=>false,
            'grant_created'=>false,
            'execution_performed'=>false,
        );
        if (!is_array($site) || !is_string($root312_public) ||
            !is_string($independent313_public) ||
            !is_int($now) || $now<1 ||
            hash_equals($root312_public,$independent313_public) ||
            !self::verify($enrollment,self::ENROLLMENT_FIELDS,self::ENROLLMENT,
                          self::ENROLLMENT_DOMAIN,$root312_public) ||
            !self::verify($checkpoint,self::CHECKPOINT_FIELDS,self::CHECKPOINT,
                          self::CHECKPOINT_DOMAIN,$independent313_public)) return $out;
        if (!is_int($enrollment['revision']) || $enrollment['revision']<1 ||
            !is_int($enrollment['issued_epoch']) ||
            $enrollment['issued_epoch']<1 || $enrollment['issued_epoch']>$now+5 ||
            !in_array($enrollment['mode'],array('persistent','operation_scoped'),true) ||
            $enrollment['expires_epoch']!==null ||
            $enrollment['state']!=='ACTIVE' ||
            !self::hex($enrollment['nonce'],32) ||
            !self::hex($enrollment['host_key_sha256'],64) ||
            !self::hex($enrollment['policy_sha256'],64)) return $out;
        foreach (array('company','environment','site_uuid','target_host_id','ssh_principal',
                        'host_key_sha256','policy_sha256') as $field) {
            if (!is_string($enrollment[$field]) || $enrollment[$field]==='' ||
                ($site[$field]??null)!==$enrollment[$field]) return $out;
        }
        if (!is_int($checkpoint['observed_epoch']) ||
            $checkpoint['observed_epoch']<$now-30 ||
            $checkpoint['observed_epoch']>$now+5 ||
            $checkpoint['state']!=='ACTIVE' ||
            $checkpoint['revision']!==$enrollment['revision'] ||
            ($checkpoint['company']??null)!==$enrollment['company'] ||
            ($checkpoint['environment']??null)!==$enrollment['environment'] ||
            ($checkpoint['site_uuid']??null)!==$enrollment['site_uuid']) return $out;
        $digest=self::digest($enrollment,self::ENROLLMENT_FIELDS,self::ENROLLMENT_DOMAIN);
        if (!self::hex($digest,64) || !self::hex($checkpoint['enrollment_sha256'],64)
            || !hash_equals($digest,$checkpoint['enrollment_sha256'])) return $out;
        $operation=$checkpoint['operation_id'];
        if ($operation===null) {
            if ($checkpoint['activity_sha256']!==null ||
                $checkpoint['activity_seq']!==null) return $out;
        } else {
            if (!self::hex($operation,32) ||
                !self::hex($checkpoint['activity_sha256'],64) ||
                !is_int($checkpoint['activity_seq']) ||
                $checkpoint['activity_seq']<1) return $out;
        }
        $out['signature_verified']=true;
        $out['readback_verified']=true;
        $out['state']='ACTIVE';
        $out['revision']=$enrollment['revision'];
        $out['host_key_sha256']=$enrollment['host_key_sha256'];
        $out['enrollment_sha256']=$digest;
        $out['operation_id']=$operation;
        $out['activity_sha256']=$checkpoint['activity_sha256'];
        $out['activity_seq']=$checkpoint['activity_seq'];
        // Does not activate a scoped connection on the strength of a
        // checkpoint alone; verify_operation requires the original signed
        // grant and latest activity independently.
        if ($enrollment['mode']==='operation_scoped')
            $out['state']='ACTIVE';
        return $out;
    }
}
}
