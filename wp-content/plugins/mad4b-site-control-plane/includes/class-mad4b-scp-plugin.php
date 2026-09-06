<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Plugin {

	private static $booted = false;

	public static function activate() {
		update_option( 'mad4b_scp_version', MAD4B_SCP_VERSION, false );
		if ( false === get_option( 'mad4b_scp_audit_log', false ) ) {
			add_option( 'mad4b_scp_audit_log', array(), '', false );
		}
	}

	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'abilities_notice' ) );
			return;
		}

		$abilities = new MAD4B_SCP_Abilities();
		$registry  = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();

		add_action( 'wp_abilities_api_categories_init', array( $abilities, 'register_categories' ) );
		add_action( 'wp_abilities_api_categories_init', array( $registry, 'register_categories' ), 20 );
		add_action( 'wp_abilities_api_init', array( $abilities, 'register_abilities' ) );
		add_action( 'wp_abilities_api_init', array( $registry, 'register_abilities' ), 20 );

		if ( class_exists( 'WP\\MCP\\Core\\McpAdapter' ) ) {
			$servers = new MAD4B_SCP_Servers();
			add_action( 'mcp_adapter_init', array( $servers, 'register_servers' ) );
		} else {
			add_action( 'admin_notices', array( __CLASS__, 'mcp_notice' ) );
		}
	}

	public static function abilities_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'MAD4B Site Control Plane requires the WordPress Abilities API (WordPress 6.9+).', 'mad4b-site-control-plane' ) . '</p></div>';
		}
	}

	public static function mcp_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'MAD4B Site Control Plane abilities are registered, but the official WordPress MCP Adapter is not active. Install and activate mcp-adapter to expose MCP servers.', 'mad4b-site-control-plane' ) . '</p></div>';
		}
	}
}
