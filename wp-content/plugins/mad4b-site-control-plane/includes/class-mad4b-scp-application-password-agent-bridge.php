<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Maps WordPress-verified application credentials to distinct, grant-bound identities. */
final class MAD4B_SCP_Application_Password_Agent_Bridge {
    private static $verified = null;
    public static function boot() {
        add_action('application_password_did_authenticate', [__CLASS__, 'authenticated'], 10, 2);
        add_filter('mad4b_scp_authenticated_subject_context', [__CLASS__, 'context'], 40);
    }
    public static function authenticated($user, $item) {
        self::$verified = null;
        if (!($user instanceof WP_User) || empty($item['uuid']) || !is_string($item['uuid'])) return;
        if (!defined('REST_REQUEST') || !REST_REQUEST || !is_ssl() && wp_get_environment_type() !== 'local') return;
        if (!class_exists('MAD4B_SCP_Site_Profile') || !MAD4B_SCP_Site_Profile::origin_enrolled()) return;
        if (!MAD4B_SCP_Site_Profile::user_is_enrolled((int)$user->ID)) return;
        $site_uuid = (string) MAD4B_SCP_Site_Profile::site_uuid();
        if (!$site_uuid) return;
        self::$verified = [
            'authenticated' => true,
            'subject_type' => 'wp_app_password',
            'subject_fingerprint' => hash('sha256', 'wp_app_password' . "\0" . $site_uuid . "\0" . (int)$user->ID . "\0" . $item['uuid']),
            'auth_method' => 'wp_application_password',
            'wp_user_id' => (int)$user->ID,
            'origin' => 'wordpress_application_password',
        ];
    }
    public static function verified_for_mcp_request($request) {
        if (wp_get_environment_type() !== 'local' || !self::$verified || !is_object($request) || !method_exists($request, 'get_route')) return false;
        if (('/' . trim((string)$request->get_route(), '/')) !== '/mcp/mad4b-chatgpt') return false;
        $authorization = method_exists($request, 'get_header') ? (string)$request->get_header('authorization') : '';
        if (!preg_match('/^Basic [A-Za-z0-9+\/=]+$/D', trim($authorization))) return false;
        if ((int)get_current_user_id() !== (int)self::$verified['wp_user_id']) return false;
        if (!class_exists('MAD4B_SCP_Agent_Registry')) return false;
        $agent = MAD4B_SCP_Agent_Registry::resolve_agent(self::$verified);
        if (is_wp_error($agent) || !is_array($agent)) return false;
        $grant = MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'], 'mad4b-write', 'mad4b/context-ai-review', 'core');
        return is_array($grant) && isset($grant['effect']) && 'allow' === $grant['effect'];
    }
    public static function context($context) {
        if (!self::$verified || !is_array($context) || !empty($context['authenticated'])) return $context;
        foreach(self::$verified as $k=>$v) $context[$k]=$v;
        return $context;
    }
}
MAD4B_SCP_Application_Password_Agent_Bridge::boot();
