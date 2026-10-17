<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded diagnostic service used by the legacy Ability facade. */
final class MAD4B_SCP_Site_Info_Reader {
    public static function read() {
        global $wpdb, $wp_version;
        // Database introspection must never break a basic MCP identity read.
        $db_server_info = 'unavailable';
        try {
            if ( is_object( $wpdb ) && is_callable( array( $wpdb, 'db_server_info' ) ) )
                $db_server_info = (string) $wpdb->db_server_info();
            elseif ( is_object( $wpdb ) && is_callable( array( $wpdb, 'db_version' ) ) )
                $db_server_info = (string) $wpdb->db_version();
        } catch ( Throwable $error ) {
            $db_server_info = 'unavailable';
        }
        return array( 'site' => array(
            'wordpress_version' => isset( $wp_version ) ? (string) $wp_version : 'unknown',
            'php_version' => PHP_VERSION,
            'db_server_info' => $db_server_info,
            'site_url' => site_url(), 'home_url' => home_url(), 'is_multisite' => is_multisite(),
            'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
            'control_plane' => MAD4B_SCP_VERSION,
            'mcp_adapter' => class_exists( 'WP\\MCP\\Core\\McpAdapter' ),
            'breakglass' => MAD4B_SCP_Policy::can_breakglass(),
        ) );
    }
}
