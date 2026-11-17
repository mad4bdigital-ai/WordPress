<?php
/**
 * Plugin Name: Local Lab Fluent Boards Schema Safety Guard
 * Description: Temporarily defer Fluent Boards bootstrap only when its
 *              mandatory metadata table is absent on the disposable Lab DB.
 *              The stored active_plugins option remains unchanged.
 */
defined( 'ABSPATH' ) || exit;
if ( ! function_exists( 'wp_get_environment_type' ) || wp_get_environment_type() !== 'local' ) {
    return;
}
add_filter( 'option_active_plugins', static function ( $plugins ) {
    if ( ! is_array( $plugins ) || ! in_array( 'fluent-boards/fluent-boards.php', $plugins, true ) ) {
        return $plugins;
    }
    global $wpdb;
    if ( ! $wpdb instanceof wpdb ) {
        return $plugins;
    }
    $table = $wpdb->prefix . 'fbs_metas';
    $exists = (string) $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
    );
    if ( $exists === $table ) {
        return $plugins;
    }
    return array_values( array_diff( $plugins, array( 'fluent-boards/fluent-boards.php' ) ) );
}, 1 );
