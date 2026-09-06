<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Owns the lifecycle of WPL Client data stored in WordPress.
 *
 * Activation is intentionally non-destructive for permanent licence/order data:
 * it removes stale runtime state, repairs structured options and clears WPL caches.
 * Uninstall is destructive and removes all plugin-owned options, transients and user meta.
 */
class WPL_Database_Maintenance {

    const STATE_VERSION = 1;

    /**
     * Runtime-only state that must never survive a fresh activation cycle.
     */
    private static $runtime_options = [
        'wpl_bg_install_job',
        'wpl_has_pending_request',
        'wpl_products_signature',
        'wpl_client_do_redirect',
        'wpl_client_do_register',
        'wpl_client_do_create_user',
    ];

    /**
     * JSON-list options which older releases may leave malformed.
     */
    private static $json_list_options = [
        'wpl_verified_order_numbers',
        'wpl_verified_wpl_ids',
    ];

    public static function activate( $network_wide = false ) {
        if ( is_multisite() && $network_wide ) {
            $site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
            foreach ( $site_ids as $site_id ) {
                switch_to_blog( (int) $site_id );
                self::repair_current_site();
                restore_current_blog();
            }
            return;
        }

        self::repair_current_site();
    }

    /**
     * Reset only disposable runtime state. Permanent serial/order data is preserved.
     */
    public static function repair_current_site() {
        self::clear_runtime_state();

        foreach ( self::$json_list_options as $option_name ) {
            self::normalize_json_list_option( $option_name );
        }

        update_option( 'wpl_db_state_version', self::STATE_VERSION, false );
    }

    /**
     * Safe to call from the existing "clear cache" action as well as activation.
     */
    public static function clear_runtime_state() {
        foreach ( self::$runtime_options as $option_name ) {
            delete_option( $option_name );
        }

        self::clear_transients();
        wp_clear_scheduled_hook( 'wpl_bg_install_cron' );
    }

    /**
     * Deactivation must stop workers and remove stale locks/jobs without deleting licences.
     */
    public static function deactivate( $network_wide = false ) {
        if ( is_multisite() && $network_wide ) {
            $site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
            foreach ( $site_ids as $site_id ) {
                switch_to_blog( (int) $site_id );
                self::deactivate_current_site();
                restore_current_blog();
            }
            return;
        }

        self::deactivate_current_site();
    }

    private static function deactivate_current_site() {
        self::clear_runtime_state();
        wp_clear_scheduled_hook( 'wpl_daily_heartbeat' );
    }

    /**
     * Full uninstall cleanup. Only plugin-owned namespaces are touched.
     */
    public static function uninstall() {
        self::delete_support_users();

        if ( is_multisite() ) {
            $site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
            foreach ( $site_ids as $site_id ) {
                switch_to_blog( (int) $site_id );
                self::purge_current_site();
                restore_current_blog();
            }
        } else {
            self::purge_current_site();
        }

        self::delete_wpl_user_meta();
        self::clear_network_transients();
    }

    private static function purge_current_site() {
        global $wpdb;

        wp_clear_scheduled_hook( 'wpl_daily_heartbeat' );
        wp_clear_scheduled_hook( 'wpl_bg_install_cron' );

        self::clear_transients();

        // Delete every option in the plugin-owned `wpl_` namespace.
        $wpl_like = $wpdb->esc_like( 'wpl_' ) . '%';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpl_like
            )
        );

        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    public static function clear_transients() {
        global $wpdb;

        $value_like   = $wpdb->esc_like( '_transient_wpl_' ) . '%';
        $timeout_like = $wpdb->esc_like( '_transient_timeout_wpl_' ) . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $value_like,
                $timeout_like
            )
        );

        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    private static function clear_network_transients() {
        if ( ! is_multisite() ) return;

        global $wpdb;
        if ( empty( $wpdb->sitemeta ) ) return;

        $value_like   = $wpdb->esc_like( '_site_transient_wpl_' ) . '%';
        $timeout_like = $wpdb->esc_like( '_site_transient_timeout_wpl_' ) . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
                $value_like,
                $timeout_like
            )
        );
    }

    private static function normalize_json_list_option( $option_name ) {
        $raw = get_option( $option_name, '[]' );

        if ( is_array( $raw ) ) {
            $list = $raw;
        } elseif ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            $list    = is_array( $decoded ) ? $decoded : [];
        } else {
            $list = [];
        }

        $normalized = [];
        foreach ( $list as $value ) {
            if ( ! is_scalar( $value ) ) continue;
            $value = sanitize_text_field( (string) $value );
            if ( $value === '' || in_array( $value, $normalized, true ) ) continue;
            $normalized[] = $value;
        }

        update_option( $option_name, wp_json_encode( array_values( $normalized ) ), false );
    }

    private static function delete_wpl_user_meta() {
        global $wpdb;

        $meta_like = $wpdb->esc_like( '_wpl_' ) . '%';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $meta_like
            )
        );
    }

    private static function delete_support_users() {
        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $support_ids = get_users( [
            'meta_key'   => '_wpl_access_user',
            'meta_value' => '1',
            'fields'     => 'ID',
            'number'     => -1,
        ] );

        if ( empty( $support_ids ) ) return;

        $admins = get_users( [
            'role'    => 'administrator',
            'fields'  => 'ID',
            'number'  => 5,
            'exclude' => array_map( 'intval', $support_ids ),
        ] );
        $reassign = ! empty( $admins ) ? (int) $admins[0] : null;

        foreach ( $support_ids as $support_id ) {
            wp_delete_user( (int) $support_id, $reassign );
        }
    }
}
