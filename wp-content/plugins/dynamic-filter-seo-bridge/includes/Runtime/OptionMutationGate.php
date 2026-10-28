<?php
namespace DynamicFilterSEOBridge\Runtime;

/**
 * Shared ETG option mutex. Fail closed if an interrupted request leaves a stale
 * reservation; an operator must explicitly reconcile it. No unsafe auto-steal.
 * This serializes the ETG Admin and MAD4B MCP writers of the same WP option.
 */
final class OptionMutationGate {
    private static $held = array();
    private static $retained = array();
    private const ALLOWED = array('etg_dfsb_settings', 'etg_dfsb_dynamic_content_slots', 'etg_indexflow_routes');

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
        self::$held[$key] = $token;
        register_shutdown_function(static function () use ($lease): void {
            // Preserve the lock after fatal execution: state may be modified
            // while its journal was never finalized.
            $last = function_exists('error_get_last') ? error_get_last() : null;
            if (is_array($last) && in_array((int)($last['type'] ?? 0),
                array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR), true)) return;
            self::release($lease);
        });
        return $lease;
    }

    /** Keep a recoverably uncertain mutation fenced across normal PHP cleanup. */
    public static function retain(array $lease): bool {
        if(empty($lease['key'])||empty($lease['token'])||empty($lease['resource'])
            ||!in_array($lease['resource'],self::ALLOWED,true))return false;
        $key='mad4b_etg_lock_'.substr(hash('sha256','option|'.$lease['resource']),0,36);
        if(!hash_equals($key,(string)$lease['key'])||!isset(self::$held[$key])
            ||!hash_equals(self::$held[$key],(string)$lease['token']))return false;
        self::$retained[$key]=(string)$lease['token'];
        return true;
    }

    public static function release(array $lease): bool {
        global $wpdb;
        if (empty($lease['key']) || empty($lease['token']) || empty($lease['resource'])
            || !in_array($lease['resource'], self::ALLOWED, true)
            || !isset($wpdb) || !is_object($wpdb) || !isset($wpdb->options)
            || !method_exists($wpdb, 'delete') || !function_exists('wp_cache_delete')) return false;
        $key = 'mad4b_etg_lock_' . substr(hash('sha256', 'option|' . $lease['resource']), 0, 36);
        if (!hash_equals($key, (string)$lease['key'])) return false;
        if(isset(self::$retained[$key])&&hash_equals(self::$retained[$key],(string)$lease['token']))return false;
        // Compare-and-delete in one SQL statement. A get_option -> delete_option
        // sequence can delete another holder after a token handover.
        $deleted = $wpdb->delete($wpdb->options,
            array('option_name' => $key, 'option_value' => (string)$lease['token']),
            array('%s', '%s'));
        if (1 !== $deleted) return false;
        unset(self::$held[$key],self::$retained[$key]);
        // Direct SQL bypasses the normal delete_option cache maintenance.
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
        return true;
    }
}
