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
        }
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
