<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Plugin {
	private static $booted = false;
	private static $schema_error = null;

	public static function activate() {
		$schema = MAD4B_SCP_Schema::install_or_upgrade();
		if ( is_wp_error( $schema ) ) self::$schema_error = $schema;
		update_option( 'mad4b_scp_version', MAD4B_SCP_VERSION, false );
		if ( false === get_option( MAD4B_SCP_Audit::LEGACY_OPTION, false ) ) add_option( MAD4B_SCP_Audit::LEGACY_OPTION, array(), '', false );
		if ( ! is_wp_error( self::$schema_error ) ) {
			$audit = MAD4B_SCP_Audit::ensure_head_initialized();
			if ( is_wp_error( $audit ) ) self::$schema_error = $audit;
		}
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		MAD4B_SCP_MCP_Provider_Isolation::boot();
		self::bind_local_oauth_subject_compatibility();
		MAD4B_SCP_Local_OAuth_Loopback_Guard::boot();
		MAD4B_SCP_Local_OAuth_Server::boot();
		MAD4B_SCP_OAuth_Resource_Bridge::boot();
		MAD4B_SCP_OAuth_Subject_Gate::boot();
		MAD4B_SCP_MCP_Client_Compatibility::boot();
		MAD4B_SCP_OAuth_Challenge_Alignment::boot();

		if ( ! MAD4B_SCP_Schema::is_ready() || (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) < MAD4B_SCP_Schema::VERSION ) {
			$schema = MAD4B_SCP_Schema::install_or_upgrade();
			if ( is_wp_error( $schema ) ) self::$schema_error = $schema;
		}
		if ( false === get_option( MAD4B_SCP_Audit::LEGACY_OPTION, false ) ) add_option( MAD4B_SCP_Audit::LEGACY_OPTION, array(), '', false );
		if ( ! is_wp_error( self::$schema_error ) ) {
			$audit = MAD4B_SCP_Audit::ensure_head_initialized();
			if ( is_wp_error( $audit ) ) self::$schema_error = $audit;
		}
		if ( is_wp_error( self::$schema_error ) ) add_action( 'admin_notices', array( __CLASS__, 'schema_notice' ) );

		MAD4B_SCP_Admin_UI::boot();
		MAD4B_SCP_Connection_Admin_UI::boot();
		MAD4B_SCP_Adapter_Coverage_Admin_UI::boot();
		MAD4B_SCP_Runtime_Components_Admin_UI::boot();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'abilities_notice' ) );
			return;
		}

		MAD4B_SCP_Connection_Ability::boot();
		MAD4B_SCP_Governed_Ability_Overrides::boot();
		MAD4B_SCP_Governance_Abilities::boot();

		$abilities = new MAD4B_SCP_Abilities();
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();

		add_action( 'wp_abilities_api_categories_init', array( $abilities, 'register_categories' ) );
		add_action( 'wp_abilities_api_categories_init', array( $registry, 'register_categories' ), 20 );
		add_action( 'wp_abilities_api_init', array( $abilities, 'register_abilities' ) );
		add_action( 'wp_abilities_api_init', array( $registry, 'register_abilities' ), 20 );

		if ( class_exists( 'WP\\MCP\\Core\\McpAdapter' ) ) {
			$servers = new MAD4B_SCP_Servers();
			add_action( 'mcp_adapter_init', array( $servers, 'register_servers' ) );
			add_action( 'admin_init', array( __CLASS__, 'prime_admin_mcp_runtime' ), 1 );
		} else {
			add_action( 'admin_notices', array( __CLASS__, 'mcp_notice' ) );
		}
	}

	/**
	 * Keep issuer-bound subject configuration as the single source of truth while
	 * preserving Local OAuth's legacy constant contract. This creates no new
	 * authority: the alias is defined only for the exact local issuer and only
	 * when the legacy constant is absent. Missing/invalid local bindings remain
	 * fail-closed.
	 */
	private static function bind_local_oauth_subject_compatibility() {
		if ( defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) return;
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) || true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) return;
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) return;
		$bindings = constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' );
		if ( ! is_array( $bindings ) ) return;
		$local_issuer = rtrim( (string) MAD4B_SCP_Local_OAuth_Server::issuer(), '/' );
		if ( '' === $local_issuer ) return;
		foreach ( $bindings as $issuer => $subjects ) {
			if ( ! is_string( $issuer ) || ! hash_equals( $local_issuer, rtrim( trim( $issuer ), '/' ) ) ) continue;
			$items = is_array( $subjects ) ? $subjects : preg_split( '/[\s,]+/', (string) $subjects );
			$bounded = array();
			foreach ( is_array( $items ) ? array_slice( $items, 0, 500 ) : array() as $subject ) {
				if ( ! is_string( $subject ) ) continue;
				$subject = trim( $subject );
				if ( '' === $subject || strlen( $subject ) > 512 || preg_match( '/[\s,]/', $subject ) ) continue;
				if ( 0 !== strpos( $subject, 'user:' ) && 0 !== strpos( $subject, 'tenant:' ) ) continue;
				$bounded[] = $subject;
			}
			$bounded = array_values( array_unique( $bounded ) );
			if ( ! empty( $bounded ) ) define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS', $bounded );
			return;
		}
	}

	/**
	 * The official Adapter initializes on rest_api_init. Control-plane admin pages
	 * are ordinary wp-admin requests, so prime the in-memory REST/MCP registry
	 * locally before any readiness snapshot is rendered. This performs no HTTP
	 * request and creates no credentials or persistent authority.
	 */
	public static function prime_admin_mcp_runtime() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page bootstrap.
		if ( 0 !== strpos( $page, 'mad4b-control-plane' ) ) return;
		if ( ! function_exists( 'rest_get_server' ) ) return;
		try {
			rest_get_server();
		} catch ( Throwable $e ) {
			// Readiness surfaces remain fail-closed and will report the unavailable registry.
		}
	}

	public static function schema_notice() {
		if ( current_user_can( 'manage_options' ) && is_wp_error( self::$schema_error ) ) echo '<div class="notice notice-error"><p>' . esc_html__( 'MAD4B Site Control Plane governance schema is unavailable. Mutation remains fail-closed until the schema is repaired.', 'mad4b-site-control-plane' ) . '</p></div>';
	}
	public static function abilities_notice() {
		if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-error"><p>' . esc_html__( 'MAD4B Site Control Plane requires the WordPress Abilities API (WordPress 6.9+).', 'mad4b-site-control-plane' ) . '</p></div>';
	}
	public static function mcp_notice() {
		if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-warning"><p>' . esc_html__( 'MAD4B Site Control Plane abilities are registered, but the official WordPress MCP Adapter is not active. Install and activate mcp-adapter to expose MCP servers.', 'mad4b-site-control-plane' ) . '</p></div>';
	}
}
