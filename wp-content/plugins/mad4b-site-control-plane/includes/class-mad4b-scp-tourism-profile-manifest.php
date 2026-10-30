<?php
defined('ABSPATH') || exit;

/**
 * Central provisioning intent for the native MAD4B Site Profile v2.
 * A manifest is not a Site Profile: only the target WordPress installation
 * can enroll its own canonical origin, UUID, deployment binding and audit.
 */
final class MAD4B_SCP_Tourism_Profile_Manifest {
    const CONTRACT = 'mad4b.tourism-company-profile-intent.v1';
    const NATIVE_CONTRACT = 'mad4b.site-profile.v2';
    const META = '_mad4b_launch_site_profile_manifest';

    public static function compose(array $intake, $resource_uuid) {
        $company = (string)($intake['company_code'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D', $company) ||
            !preg_match('/^[a-zA-Z0-9-]{8,65}$/D', (string)$resource_uuid)) {
            return new WP_Error('mad4b_profile_intent_invalid','Company or resource identity invalid.');
        }
        return array(
            'contract'=>self::CONTRACT,
            'native_contract'=>self::NATIVE_CONTRACT,
            'company_code'=>$company,
            'brand_name'=>$intake['brand_name'],
            'environment'=>'production',
            'expected_origin'=>'https://'.$company.'.mad4b.com',
            'requested_domain'=>$intake['domain'],
            'resource_uuid'=>$resource_uuid,
            'intake_digest'=>hash('sha256',wp_json_encode($intake)),
            'contact'=>array('email'=>$intake['email'],'phone_e164'=>$intake['phone_e164']),
            'presentation'=>array(
                'logo_url'=>$intake['logo_url'],
                'palette'=>$intake['colors'],
                'field_sources'=>$intake['field_sources'] ?? array(),
                'customizable_after_launch'=>true,
            ),
            'native_site_uuid'=>null,
            'native_revision'=>null,
            'native_profile_digest'=>null,
            'native_enrollment_verified'=>false,
            'write_authority_granted'=>false,
            'public_live'=>false,
            'state'=>'pending_target_enrollment',
        );
    }

    public static function matches($manifest, array $intake, $resource_uuid) {
        if (!is_array($manifest)) return false;
        $expected = self::compose($intake, $resource_uuid);
        if (is_wp_error($expected)) return false;
        foreach (array('contract','native_contract','company_code','environment',
            'expected_origin','resource_uuid','intake_digest','state') as $field) {
            if (($manifest[$field] ?? null) !== $expected[$field]) return false;
        }
        return empty($manifest['native_enrollment_verified']) &&
            empty($manifest['write_authority_granted']) &&
            ($manifest['native_site_uuid'] ?? null) === null;
    }

    public static function preview($manifest) {
        if (!is_array($manifest)) return array(
            'state'=>'not_prepared', 'native_contract'=>self::NATIVE_CONTRACT,
            'native_enrollment_verified'=>false,'public_live'=>false
        );
        return array(
            'state'=>$manifest['state'] ?? 'invalid',
            'native_contract'=>self::NATIVE_CONTRACT,
            'company_code'=>$manifest['company_code'] ?? '',
            'expected_origin'=>$manifest['expected_origin'] ?? '',
            'coolify_resource_uuid'=>$manifest['resource_uuid'] ?? '',
            'intake_digest'=>$manifest['intake_digest'] ?? '',
            'native_enrollment_verified'=>false,
            'public_live'=>false,
        );
    }
}
