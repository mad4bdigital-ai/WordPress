<?php
/**
 * Additive Original318 v3 WordPress read-only connector for manually
 * installed, independently signed new enrollments.
 *
 * This file is quarantined in deploy/ and is NOT auto-loaded from Git.
 * When selected, install together with class-mad4b-original318-host-v3-reader.php
 * under a verified site-specific MU-plugin root, without replacing legacy v2.
 */
if (!defined('ABSPATH')) { exit; }
// Never load a partial, modified or symlinked runtime bundle.
$reader_file=__DIR__ . '/class-mad4b-original318-host-v3-reader.php';
if (!is_file($reader_file) || is_link($reader_file) || !is_readable($reader_file)
    || !hash_equals('fc865e27306cf5c40a184e847b7bd863fd93beb25a5602c41cb721f64bc024e6',
       (string)hash_file('sha256',$reader_file))) return;
require_once $reader_file;
if (!class_exists('MAD4B_Original318_Host_V3_Readback', false)) {
final class MAD4B_Original318_Host_V3_Readback {
    private const PREFIX_ENROLLMENT = 'mad4b_host_v3_signed_enrollment_';
    private const PREFIX_CHECKPOINT = 'mad4b_host_v3_signed_checkpoint_';

    private static function denied(string $company, string $environment, string $reason): array {
        return array(
            'contract'=>'mad4b.original318.host-connection-readback.v3',
            'source'=>'original318_current_wp_readback',
            'company'=>$company,
            'environment'=>$environment,
            'state'=>'MISSING_OR_INVALID',
            'reason'=>$reason,
            'readback_verified'=>false,
            'signature_verified'=>false,
            'connection_verified'=>false,
            'write_authorized'=>false,
            'grant_created'=>false,
            'execution_performed'=>false,
        );
    }

    public static function current(string $company, string $environment): array {
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
            || !in_array($environment,array('staging','production'),true))
            return self::denied($company,$environment,'SITE_SCOPE_INVALID');
        // Only Root-deployed trusted PHP constants, never an admin option or
        // a remote CLI supplied key. No private keys are available to WP.
        if (!defined('MAD4B_HOST_V3_PINNED_BINDINGS')
            || !defined('MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64')
            || !defined('MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64'))
            return self::denied($company,$environment,'ROOT_TRUST_BINDING_NOT_INSTALLED');
        $all=constant('MAD4B_HOST_V3_PINNED_BINDINGS');
        $scope=$company.':'.$environment;
        $pinned=is_array($all)&&isset($all[$scope])?$all[$scope]:null;
        if (!is_array($pinned)
            || array_keys($pinned)!==array(
                'company','environment','site_uuid','target_host_id','ssh_principal',
                'host_key_sha256','policy_sha256','origin'
            )
            || ($pinned['company']??null)!==$company
            || ($pinned['environment']??null)!==$environment
            || !function_exists('home_url')
            || ($pinned['origin']??null)!==untrailingslashit(home_url('/')))
            return self::denied($company,$environment,'PINNED_SITE_IDENTITY_INVALID');
        // The Central host itself is not a Company Environment Registry
        // target. It has a separate first-party Site Profile with its own
        // enrolled deployment-binding digest. Exact immutable Root pins
        // remain the authority; the WordPress profile is a cross-check only.
        if ($company==='mad4b-central' && $environment==='staging') {
            $profile=get_option('mad4b_scp_site_profile_v2',false);
            if (!is_array($profile)
                || ($profile['contract']??null)!=='mad4b.site-profile.v2'
                || ($profile['version']??null)!==2
                || ($profile['environment']??null)!=='staging'
                || ($profile['site_uuid']??null)!==$pinned['site_uuid']
                || ($profile['canonical_origin']??null)!==$pinned['origin']
                || !isset($profile['deployment_binding_digest'])
                || !is_string($profile['deployment_binding_digest'])
                || !preg_match('/^[a-f0-9]{64}$/D',$profile['deployment_binding_digest'])
                || !defined('MAD4B_HOST_V3_CENTRAL_DEPLOYMENT_BINDING_SHA256')
                || !is_string(constant('MAD4B_HOST_V3_CENTRAL_DEPLOYMENT_BINDING_SHA256'))
                || !hash_equals(
                    (string)constant('MAD4B_HOST_V3_CENTRAL_DEPLOYMENT_BINDING_SHA256'),
                    $profile['deployment_binding_digest']))
                return self::denied($company,$environment,'CENTRAL_SITE_PROFILE_BINDING_DRIFT');
        } else {
            // Company sites use their separately enrolled company registry.
            if (!class_exists('MAD4B_Company_Environment_Registry_V2',false))
                return self::denied($company,$environment,'CENTRAL_SITE_REGISTRY_NOT_READY');
            $registered=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
            if (!is_array($registered)
                || ($registered['company_id']??null)!==$company
                || ($registered['environment']??null)!==$environment
                || ($registered['site_uuid']??null)!==$pinned['site_uuid']
                || ($registered['canonical_origin']??null)!==$pinned['origin'])
                return self::denied($company,$environment,'ENROLLED_COMPANY_SITE_DRIFT');
        }
        $pub312=base64_decode((string)constant('MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64'),true);
        $pub313=base64_decode((string)constant('MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64'),true);
        if (!is_string($pub312)||strlen($pub312)!==32 ||
            !is_string($pub313)||strlen($pub313)!==32 ||
            hash_equals($pub312,$pub313))
            return self::denied($company,$environment,'INDEPENDENT_PUBLIC_KEYS_INVALID');
        $signed=get_option(self::PREFIX_ENROLLMENT.$company.'_'.$environment,false);
        $checkpoint=get_option(self::PREFIX_CHECKPOINT.$company.'_'.$environment,false);
        unset($pinned['origin']);
        $proof=MAD4B_Original318_Host_V3_Reader::verify_readback(
            $signed,$checkpoint,$pinned,$pub312,$pub313,time());
        $proof['company']=$company;
        $proof['environment']=$environment;
        $proof['connection_verified']=($proof['readback_verified']??false)===true
            && is_array($signed)
            && ($signed['mode']??'')==='persistent';
        return $proof;
    }

    public static function cli($args, $assoc): void {
        $command=(string)($args[0]??'');
        if ($command!=='current'
            || array_keys($assoc)!==array('company','environment'))
            WP_CLI::error('EXACT_HOST_V3_CURRENT_SCOPE_REQUIRED');
        $row=self::current((string)$assoc['company'],(string)$assoc['environment']);
        WP_CLI::line(wp_json_encode($row,JSON_UNESCAPED_SLASHES));
        if (($row['readback_verified']??false)!==true) WP_CLI::halt(2);
    }

    public static function boot(): void {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b-host-v3',array(__CLASS__,'cli'));
    }
}
MAD4B_Original318_Host_V3_Readback::boot();
}
