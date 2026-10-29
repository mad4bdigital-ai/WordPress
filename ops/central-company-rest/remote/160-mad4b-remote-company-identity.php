<?php
/**
 * Plugin Name: MAD4B Remote Company REST Identity (MU)
 * Description: Read-only exact-host HMAC identity handshake for a centrally managed company. No generic data, user, order or database exports.
 * Version: 0.1.0
 *
 * Filesystem bootstrap only: installing this file does not create accounts, secrets,
 * scheduled jobs, or enable arbitrary remote commands.
 */
if (!defined('ABSPATH')) { exit; }

final class MAD4B_Remote_Company_Identity_V1 {
    const MAP = [
        'allroyalegypt.com' => 'all-royal-egypt',
        'egypttourgates.com' => 'egypt-tour-gates',
        'tourism.almallahgroup-mg.com' => 'tourism-almallahgroup',
    ];
    const OPTION = 'mad4b_company_remote_rest_secret_v1';
    const ROUTE = '/mad4b-company/v1/identity';
    public static function own_host(): string {
        return strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
    }
    public static function company_id(): string {
        return self::MAP[self::own_host()] ?? '';
    }
    public static function allowed($request) {
        if (!($request instanceof WP_REST_Request)) {
            return new WP_Error('mad4b_remote_request_invalid', 'Invalid request.', ['status' => 403]);
        }
        $host=self::own_host();
        $company=self::company_id();
        if (!$company || !in_array($host,array_keys(self::MAP),true)) {
            return new WP_Error('mad4b_remote_not_enrolled', 'Site not enrolled.', ['status' => 403]);
        }
        $secret=get_option(self::OPTION, '');
        if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D',$secret)) {
            return new WP_Error('mad4b_remote_unpaired', 'Remote site is not paired.', ['status' => 403]);
        }
        $claimed=(string)$request->get_header('x-mad4b-company');
        $stamp=(string)$request->get_header('x-mad4b-time');
        $nonce=(string)$request->get_header('x-mad4b-nonce');
        $signature=(string)$request->get_header('x-mad4b-signature');
        if ($claimed !== $company ||
            !preg_match('/^[0-9]{10}$/D',$stamp) ||
            abs(time()-(int)$stamp)>120 ||
            !preg_match('/^[a-f0-9]{32}$/D',$nonce) ||
            !preg_match('/^[a-f0-9]{64}$/D',$signature) ||
            $request->get_method()!=='GET' ||
            $request->get_route()!==self::ROUTE) {
            return new WP_Error('mad4b_remote_auth_invalid', 'Invalid signature claims.', ['status' => 403]);
        }
        $body="GET\n".self::ROUTE."\n".$company."\n".$stamp."\n".$nonce;
        $expected=hash_hmac('sha256',$body,$secret);
        if (!hash_equals($expected,$signature)) {
            return new WP_Error('mad4b_remote_auth_invalid', 'Signature verification failed.', ['status' => 403]);
        }
        $replay='mad4b_remote_replay_'.hash('sha256',$company.'|'.$stamp.'|'.$nonce);
        if (get_transient($replay)) {
            return new WP_Error('mad4b_remote_replay', 'Repeated challenge.', ['status' => 403]);
        }
        set_transient($replay,1,180);
        return true;
    }
    public static function identity() {
        $host=self::own_host();
        $controlPlane='mad4b-site-control-plane/mad4b-site-control-plane.php';
        $pluginState=(array)get_option('active_plugins',[]);
        $response = new WP_REST_Response([
            'contract' => 'mad4b.company.remote-rest-identity.v1',
            'company_id' => self::company_id(),
            'canonical_host' => $host,
            'site_root' => 'https://'.$host,
            'rest_authenticated' => true,
            'permission_scope' => 'company_identity.read',
            'control_plane_active' => in_array($controlPlane,$pluginState,true),
            'content_sync_active' => false,
            'catalog_write_enabled' => false,
            'pricing_mutation_enabled' => false,
            'orders_exported' => false,
            'media_copied' => false,
        ],200);
        $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
    public static function boot(): void {
        add_action('rest_api_init',static function() {
            if (!self::company_id()) { return; }
            register_rest_route('mad4b-company/v1','/identity',[
                'methods'=>WP_REST_Server::READABLE,
                'callback'=>[__CLASS__,'identity'],
                'permission_callback'=>[__CLASS__,'allowed'],
            ]);
        });
    }
}
MAD4B_Remote_Company_Identity_V1::boot();
