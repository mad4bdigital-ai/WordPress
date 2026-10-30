<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Exact RC2 local-owner signing authority. Staging only; no external CA claim.
 *
 * This verifier does not enroll keys at runtime or grant install permission.
 * Its trust root and signed evidence are immutable, reviewed source files.
 */
final class MAD4B_SCP_Local_DFSB_Trust {
    const VERSION = '0.4.0-alpha.15-rc.2';
    const SOURCE = 'd9af6ae623f7400b8f76f457f59d5f68ed258add';
    const PACKAGE = '9cbbed5817ab06b4a8205a8592344b5788cb20269350aadb6d4f0f35033ad1b6';
    const HOST = 'tours.dev.mad4b.com';

    public static function blockers(array $row): array {
        $errors = array();
        if (!function_exists('sodium_crypto_sign_verify_detached')) $errors[]='local_owner_ed25519_unavailable';
        if (!function_exists('wp_get_environment_type') ||
            'staging' !== wp_get_environment_type() ||
            !class_exists('MAD4B_SCP_Site_Profile') ||
            !MAD4B_SCP_Site_Profile::origin_enrolled() ||
            'staging' !== MAD4B_SCP_Site_Profile::current_environment() ||
            !function_exists('home_url') ||
            self::HOST !== strtolower((string)parse_url(home_url('/'), PHP_URL_HOST))) {
            $errors[] = 'local_owner_exact_staging_site_required';
        }
        foreach (array(
            'id'=>'dynamic-filter-seo-bridge',
            'provider_id'=>'dynamic-filter-seo-bridge',
            'plugin_file'=>'dynamic-filter-seo-bridge/dynamic-filter-seo-bridge.php',
            'repository'=>'mad4bdigital-ai/WordPress',
            'source_commit_sha'=>self::SOURCE,
            'candidate_source_commit_sha'=>self::SOURCE,
            'artifact_sha256'=>self::PACKAGE,
            'artifact_version'=>self::VERSION,
            'candidate_artifact_version'=>self::VERSION,
            'review_state'=>'source_reviewed_local_owner_signed',
            'package_certification'=>'local_owner_signed'
        ) as $name=>$expected) {
            if (!isset($row[$name]) || !is_string($row[$name]) ||
                !hash_equals($expected, $row[$name])) $errors[]='local_owner_source_registry_mismatch';
        }
        if ($errors) return array_values(array_unique($errors));
        $root = rtrim(MAD4B_SCP_DIR, '/\\').'/config/';
        $policy = self::jsonFile($root.'dfsb-local-rc2-trust.json');
        if (!$policy || ($policy['contract']??'')!=='mad4b.dfsb.local-owner-trust.v1'
            || ($policy['mode']??'')!=='local_owner_signed'
            || ($policy['enrollment']??'')!=='source_review_required'
            || ($policy['staging_only']??false)!==true
            || ($policy['site_host']??'')!==self::HOST
            || ($policy['source_sha']??'')!==self::SOURCE
            || ($policy['version']??'')!==self::VERSION
            || ($policy['package_sha256']??'')!==self::PACKAGE) {
            return array('local_owner_trust_policy_invalid');
        }
        $key = (string)($policy['public_key_hex']??'');
        $decoded = ctype_xdigit($key) && strlen($key)===64 ? hex2bin($key) : false;
        if (!is_string($decoded) || strlen($decoded)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            !hash_equals((string)($policy['public_key_sha256']??''),hash('sha256',$decoded))) {
            return array('local_owner_trusted_key_invalid');
        }
        $archive = self::signedPayload($root.'dfsb-local-rc2-archive-signature.json',$key);
        $receipt = self::signedPayload($root.'dfsb-local-rc2-receipt-signature.json',$key);
        if (!$archive || !$receipt) return array('local_owner_signature_invalid');
        foreach (array(
            'contract'=>'mad4b.dfsb.local-owner-archive-signature.v1',
            'scope'=>'staging_only', 'site_host'=>self::HOST,
            'source_commit_sha'=>self::SOURCE,
            'plugin_version'=>self::VERSION,
            'artifact_sha256'=>self::PACKAGE,
            'signer_public_key_sha256'=>$policy['public_key_sha256'],
            'owner_github_login'=>'mad4bdigital-ai'
        ) as $k=>$v) {
            if (!isset($archive[$k]) || $archive[$k]!==$v) return array('local_owner_archive_subject_mismatch');
        }
        foreach (array(
            'contract'=>'mad4b.dfsb.local-owner-detached-receipt-signature.v1',
            'environment'=>'staging','site_scope'=>self::HOST,
            'source_sha'=>self::SOURCE,'package_sha256'=>self::PACKAGE,
            'local_key_public_sha256'=>$policy['public_key_sha256'],
            'owner_login'=>'mad4bdigital-ai',
        ) as $k=>$v) {
            if (!isset($receipt[$k]) || $receipt[$k]!==$v) return array('local_owner_receipt_subject_mismatch');
        }
        if (($archive['staging_install_authorized']??null)!==false ||
            ($archive['production_install_authorized']??null)!==false ||
            ($archive['independent_provider_certification']??null)!==false ||
            ($receipt['operational_provider_certified']??null)!==false) {
            return array('local_owner_signature_authority_overclaim');
        }
        $file=$root.'dfsb-local-rc2-provenance.txt';
        if (!is_file($file) || !is_readable($file) ||
            !hash_equals((string)($receipt['receipt_sha256']??''),strtolower((string)hash_file('sha256',$file)))) {
            return array('local_owner_detached_receipt_invalid');
        }
        $contents=file_get_contents($file);
        if (!is_string($contents) || strlen($contents)>4096 ||
            !str_contains($contents,'git_sha='.self::SOURCE."\n") ||
            !str_contains($contents,'plugin_version='.self::VERSION."\n") ||
            !str_contains($contents,'package_sha256='.self::PACKAGE."\n")) {
            return array('local_owner_detached_receipt_subject_mismatch');
        }
        return array();
    }

    private static function jsonFile(string $file): array {
        if (!is_readable($file) || filesize($file)>16384) return array();
        $x=json_decode((string)file_get_contents($file),true);
        return is_array($x)?$x:array();
    }
    private static function signedPayload(string $file,string $key): array {
        $envelope=self::jsonFile($file);
        if (!$envelope || !isset($envelope['payload']) || !is_array($envelope['payload'])
            || ($envelope['public_key_hex']??'')!==$key) return array();
        $signature=(string)($envelope['signature_hex']??'');
        if (strlen($signature)!==128 || !ctype_xdigit($signature)) return array();
        $payload=$envelope['payload'];
        ksort($payload,SORT_STRING);
        $canonical=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($canonical) || !hash_equals((string)($envelope['payload_sha256']??''),hash('sha256',$canonical)) ||
            !sodium_crypto_sign_verify_detached(hex2bin($signature),$canonical,hex2bin($key))) return array();
        return $payload;
    }
}
