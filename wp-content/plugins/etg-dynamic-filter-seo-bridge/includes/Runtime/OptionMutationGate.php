<?php
namespace ETG\DynamicFilterSEOBridge\Runtime;

/**
 * Shared ETG option mutex. Fail closed if an interrupted request leaves a stale
 * reservation; an operator must explicitly reconcile it. No unsafe auto-steal.
 * This serializes the ETG Admin and MAD4B MCP writers of the same WP option.
 */
final class OptionMutationGate {
    private const ALLOWED = array('etg_dfsb_settings', 'etg_dfsb_dynamic_content_slots');

    public static function acquire(string $option) {
        if (!in_array($option, self::ALLOWED, true)) {
            return new \WP_Error('etg_dfsb_option_scope_denied', 'Unrecognized ETG option lock resource.');
        }
        if (!function_exists('add_option') || !function_exists('wp_generate_uuid4')) {
            return new \WP_Error('etg_dfsb_option_lock_unavailable', 'Required WordPress lock functions are missing.');
        }
        $key = 'mad4b_etg_lock_' . substr(hash('sha256', 'option|' . $option), 0, 36);
        $token = wp_generate_uuid4();
        if (!add_option($key, $token, '', 'no')) {
            return new \WP_Error('mad4b_etg_target_locked', 'ETG option is already locked; prior lease requires reconciliation.');
        }
        $lease = array('key' => $key, 'token' => $token, 'resource' => $option);
        register_shutdown_function(static function () use ($lease): void {
            self::release($lease);
        });
        return $lease;
    }

    public static function release(array $lease): bool {
        if (empty($lease['key']) || empty($lease['token']) || empty($lease['resource'])
            || !in_array($lease['resource'], self::ALLOWED, true)
            || !function_exists('get_option') || !function_exists('delete_option')) return false;
        $key = 'mad4b_etg_lock_' . substr(hash('sha256', 'option|' . $lease['resource']), 0, 36);
        if (!hash_equals($key, (string)$lease['key'])) return false;
        $actual = get_option($key, '');
        if (!is_string($actual) || !hash_equals((string)$lease['token'], $actual)) return false;
        return (bool)delete_option($key);
    }
}
