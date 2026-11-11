<?php
/**
 * Plugin Name: MAD4B Staging Vault-Backed REST Probe
 * Version: 0.1.0
 * Reuses the existing encrypted vault. No credentials are exported.
 */
defined('ABSPATH') || exit;
final class MAD4B_Staging_Vault_REST_Probe {
    public static function permitted(): bool {
        return wp_get_environment_type() === 'staging'
            && home_url('/') === 'https://tours.dev.mad4b.com/'
            && current_user_can('manage_options');
    }
    private static function verified_binding(string $id): bool {
        $known = MAD4B_Central_Company_REST_V1::directory()[$id] ?? null;
        $registry = get_option('mad4b_tourism_company_site_registry_v1', []);
        $bound = is_array($registry) ? ($registry[$id] ?? null) : null;
        if (!is_array($known) || !is_array($bound)) return false;
        if (($bound['registration_mode'] ?? '') !== 'p256_signed_site_profile_v4'
            || ($bound['company_code'] ?? '') !== $id
            || ($bound['canonical_origin'] ?? '') !== ($known['url'] ?? '')
            || empty($bound['host_binding_verified'])
            || !wp_is_uuid((string) ($bound['company_site_uuid'] ?? ''))
            || !wp_is_uuid((string) ($bound['native_site_profile_uuid'] ?? ''))) return false;
        $stamp = strtotime((string)($bound['identity_verified_at'] ?? ''));
        return $stamp !== false && $stamp <= time() && time() - $stamp < 86400 * 7;
    }
    public static function probe(WP_REST_Request $request) {
        if (!self::permitted()) return new WP_Error('mad4b_probe_forbidden', 'Staging admin required', ['status'=>403]);
        $id = (string) $request['company'];
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D', $id))
            return new WP_Error('mad4b_probe_invalid_company', 'Invalid company ID', ['status'=>400]);
        if (!self::verified_binding($id))
            return new WP_Error('mad4b_probe_signed_profile_required', 'Recent V4 site identity binding required', ['status'=>409]);
        $q = new WP_REST_Request('POST', '/mad4b-central/v1/simple-connections/'.$id.'/test');
        $q->set_param('company', $id);
        $result = MAD4B_Central_Simple_Connections_V1::test_saved($q);
        if (is_wp_error($result)) return $result;
        $data = $result->get_data();
        if (empty($data['connected']))
            return new WP_Error('mad4b_probe_not_authenticated', 'REST connection not authenticated', ['status'=>409]);
        return new WP_REST_Response([
            'contract'=>'mad4b.portfolio.signed-rest-vault-probe.v1',
            'company_id'=>$id, 'state'=>'REST_REFERENCE_AUTHENTICATED',
            'remote_read_authenticated'=>true,
            'remote_write_certified'=>false,
            'credentials_exported'=>false,
            'target_site_uuid_verified'=>true
        ], 200);
    }
    public static function boot(): void {
        if (!class_exists('MAD4B_Central_Simple_Connections_V1',false)
            || !class_exists('MAD4B_Central_Company_REST_V1',false)) return;
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b-central/v1','/signed-rest-vault-probe/(?P<company>[a-z0-9-]+)',[
                'methods'=>WP_REST_Server::CREATABLE,
                'permission_callback'=>[__CLASS__,'permitted'],
                'callback'=>[__CLASS__,'probe']
            ]);
        });
    }
}
MAD4B_Staging_Vault_REST_Probe::boot();
