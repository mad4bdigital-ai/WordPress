<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-admin-navigation-manifest.php';

/**
 * Admin-menu shell for WordPress native plugin-management GET/HEAD only.
 * No governance boot, schema work, providers, OAuth, writes or network I/O.
 * The selected MAD4B page loads the full Control Plane on its own request.
 */
final class MAD4B_SCP_Native_Plugin_Admin_Menu {
    const PARENT = 'mad4b-control-plane';

    public static function boot() {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 10 );
            self::register_plugin_row();
            add_action( 'admin_notices', array( __CLASS__, 'render_setup_result_notice' ), 10 );
        }
    }

    public static function register_plugin_row() {
        if ( ! function_exists( 'add_filter' ) || ! function_exists( 'plugin_basename' ) ) return;
        add_filter( 'plugin_action_links_' . plugin_basename( MAD4B_SCP_FILE ),
            array( __CLASS__, 'render_setup_action' ), 20, 1 );
    }

    public static function render_setup_action( $links ) {
        $links = is_array( $links ) ? $links : array();
        if ( ! is_admin() || ! current_user_can( 'manage_options' )
            || ! current_user_can( 'activate_plugins' ) ) return $links;
        $environment = function_exists( 'wp_get_environment_type' )
            ? wp_get_environment_type() : '';
        if ( ! in_array( $environment, array( 'staging', 'production' ), true ) )
            return $links;
        $links['mad4b_host_setup'] = self::setup_form_markup( $environment );
        return $links;
    }

    private static function setup_form_markup( $environment ) {
        // Native Plugins GET is a lightweight, read-only navigation surface.
        // Do not nest a form inside WordPress core's bulk-actions form.
        $production = 'production' === $environment;
        $anchor = $production ? '#mad4b-production-deployment-identity'
            : '#mad4b-staging-deployment-identity';
        $label = $production ? 'Inspect Production host binding'
            : 'Complete Staging host setup';
        return '<a href="' . esc_url( admin_url(
            'admin.php?page=mad4b-operational-remediation' . $anchor ) ) . '">'
            . esc_html__( $label, 'mad4b-site-control-plane' ) . '</a>';
    }

    public static function render_setup_result_notice() {
        // Redirect arguments are only a navigation hint, never an authority
        // receipt. The dedicated page performs independent binding readback.
        if ( ! is_admin() || ! current_user_can( 'manage_options' )
            || ! isset( $_GET['mad4b_native_host_setup'] ) ) return;
        echo '<div class="notice notice-info"><p>'
            . esc_html__( 'Staging setup request returned. Open Operational Remediation to verify the current binding independently.', 'mad4b-site-control-plane' )
            . '</p></div>';
    }

    public static function register_menu() {
        add_menu_page(
            __( 'MAD4B Control Plane', 'mad4b-site-control-plane' ),
            __( 'MAD4B Control Plane', 'mad4b-site-control-plane' ),
            'manage_options',
            self::PARENT,
            array( __CLASS__, 'render_only_after_normal_boot' ),
            'dashicons-shield-alt',
            80
        );
        // These entries only restore navigation: full page loading happens
        // in a separate, normally bootstrapped admin.php request.
        foreach ( MAD4B_SCP_Admin_Navigation_Manifest::menu_rows() as $slug => $row ) {
            add_submenu_page(
                self::PARENT,
                $row['label'],
                $row['label'],
                $row['capability'],
                $slug,
                array( __CLASS__, 'render_only_after_normal_boot' )
            );
        }
    }

    public static function render_only_after_normal_boot() {
        // A native plugin-management read must never instantiate the Control
        // Plane runtime merely by dispatching an unexpected menu callback.
        wp_die( esc_html__( 'Open this page from MAD4B Control Plane in the WordPress admin menu.', 'mad4b-site-control-plane' ) );
    }
}
