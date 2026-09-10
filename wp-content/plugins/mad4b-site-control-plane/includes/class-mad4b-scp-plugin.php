<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Plugin {
	private static $booted = false;
	private static $schema_error = null;

	public static function activate() {
		MAD4B_SCP_Staging_OAuth_Autoconfig::bootstrap();
		MAD4B_SCP_Skill_Autoconfig::bootstrap();
		MAD4B_SCP_Staging_Write_Authority::bootstrap();
		$schema = MAD4B_SCP_Schema::install_or_upgrade();
		if ( is_wp_error( $schema ) ) self::$schema_error = $schema;
		update_option( 'mad4b_scp_version', MAD4B_SCP_VERSION, false );
		if ( false === get_option( MAD4B_SCP_Audit::LEGACY_OPTION, false ) ) add_option( MAD4B_SCP_Audit::LEGACY_OPTION, array(), '', false );
		if ( ! is_wp_error( self::$schema_error ) ) {
			$audit = MAD4B_SCP_Audit::ensure_head_initialized();
			if ( is_wp_error( $audit ) ) self::$schema_error = $audit;
		}
		if ( ! is_wp_error( self::$schema_error ) ) MAD4B_SCP_Skill_Seeder::bootstrap();
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Staging is zero-touch by default: bind a safe WordPress administrator
		// subject before Local OAuth key/store/transport components inspect config.
		// Production and explicit operator OAuth configuration remain fail-closed.
		MAD4B_SCP_Staging_OAuth_Autoconfig::bootstrap();
		MAD4B_SCP_Skill_Autoconfig::bootstrap();
		MAD4B_SCP_Staging_Write_Authority::bootstrap();
		MAD4B_SCP_MCP_Provider_Isolation::boot();
		self::bind_local_oauth_subject_compatibility();
		MAD4B_SCP_Local_OAuth_Key_Path_Policy::boot();
		MAD4B_SCP_Local_OAuth_Init_Lock::boot();
		MAD4B_SCP_Local_OAuth_Loopback_Guard::boot();
		MAD4B_SCP_Local_OAuth_Server::boot();

		// OAuth is an optional remote authentication layer over mad4b-read, not a
		// prerequisite for the WordPress-authenticated local MCP transport. Delay
		// bearer interception until init priority 3: Local OAuth finishes key/store
		// bootstrap at priority 1 and releases its init lock at priority 2 first.
		// A configured-but-ineffective OAuth authority therefore cannot take down
		// the local WordPress-authenticated read transport.
		add_action( 'init', array( __CLASS__, 'boot_oauth_transport_if_effective' ), 3 );
		MAD4B_SCP_MCP_Client_Compatibility::boot();

		if ( ! MAD4B_SCP_Schema::is_ready() || (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) < MAD4B_SCP_Schema::VERSION ) {
			$schema = MAD4B_SCP_Schema::install_or_upgrade();
			if ( is_wp_error( $schema ) ) self::$schema_error = $schema;
		}
		if ( false === get_option( MAD4B_SCP_Audit::LEGACY_OPTION, false ) ) add_option( MAD4B_SCP_Audit::LEGACY_OPTION, array(), '', false );
		if ( ! is_wp_error( self::$schema_error ) ) {
			$audit = MAD4B_SCP_Audit::ensure_head_initialized();
			if ( is_wp_error( $audit ) ) self::$schema_error = $audit;
		}
		if ( ! is_wp_error( self::$schema_error ) ) MAD4B_SCP_Skill_Seeder::bootstrap();
		if ( is_wp_error( self::$schema_error ) ) add_action( 'admin_notices', array( __CLASS__, 'schema_notice' ) );

		MAD4B_SCP_Admin_UI::boot();
		MAD4B_SCP_Connection_Admin_UI::boot();
		MAD4B_SCP_ChatGPT_Connection_Admin_UI::boot();
		MAD4B_SCP_Adapter_Coverage_Admin_UI::boot();
		MAD4B_SCP_Runtime_Components_Admin_UI::boot();
		MAD4B_SCP_Skill_Resource_Writer::boot();
		MAD4B_SCP_Skills_Admin_UI::boot();
		MAD4B_SCP_Local_OAuth_Browser_Canary::boot();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'abilities_notice' ) );
			return;
		}

		MAD4B_SCP_Connection_Ability::boot();
		MAD4B_SCP_Governed_Ability_Overrides::boot();
		MAD4B_SCP_Staging_Write_Authority::boot();

		// Reconciliation used to run on every wp-admin request. That created
		// unnecessary database/audit churn and made unrelated admin screens pay for
		// the governed write plane. Preserve automatic reconciliation on the
		// Abilities lifecycle, but scope admin reconciliation to MAD4B screens only.
		remove_action( 'admin_init', array( 'MAD4B_SCP_Staging_Write_Authority', 'reconcile' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'reconcile_authority_on_mad4b_admin' ), 20 );

		MAD4B_SCP_Staging_Write_Planning_Guard::boot();
		MAD4B_SCP_REST_Compatibility::boot();
		MAD4B_SCP_Write_Runtime_Certification::boot();

		// Runtime certification is intentionally expensive: it reconciles authority,
		// inspects all write mounts and executes the in-process WPML compatibility
		// probe. Never run that work merely because an arbitrary wp-admin or REST
		// request happened. It remains explicit on the MAD4B Certification tab.
		remove_action( 'mcp_adapter_init', array( 'MAD4B_SCP_Write_Runtime_Certification', 'observe' ), 110 );
		remove_action( 'admin_init', array( 'MAD4B_SCP_Write_Runtime_Certification', 'observe' ), 110 );
		add_action( 'admin_init', array( __CLASS__, 'observe_write_certification_on_certification_page' ), 110 );

		MAD4B_SCP_Governance_Abilities::boot();
		MAD4B_SCP_Skill_Abilities::boot();
		MAD4B_SCP_Skills_Adapter::boot();
		MAD4B_SCP_Skill_Runtime_Certification::boot();

		// Ability + MCP server hooks are bound at plugin-file load by the bridge so
		// a lazy registry cannot consume its one-shot init action before this
		// plugins_loaded callback. Keep this idempotent call as a lifecycle guard.
		MAD4B_SCP_MCP_Registration_Bridge::boot_early();

		if ( class_exists( 'WP\\MCP\\Core\\McpAdapter' ) ) {
			add_action( 'admin_init', array( __CLASS__, 'prime_admin_mcp_runtime' ), 1 );
		} else {
			add_action( 'admin_notices', array( __CLASS__, 'mcp_notice' ) );
		}
	}

	public static function boot_oauth_transport_if_effective() {
		if ( ! self::oauth_transport_enabled() || ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return;
		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true === constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) {
			if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Key_Path_Policy' ) || ! MAD4B_SCP_Local_OAuth_Key_Path_Policy::transport_ready() ) return;
		}
		$status = MAD4B_SCP_OAuth_Resource_Bridge::status();
		if ( ! is_array( $status ) || empty( $status['effective'] ) ) return;

		MAD4B_SCP_OAuth_Request_Context_Guard::boot();
		MAD4B_SCP_OAuth_JWT_Header_Guard::boot();
		MAD4B_SCP_OAuth_Resource_Bridge::boot();
		MAD4B_SCP_OAuth_Outbound_Budget_Guard::boot();
		MAD4B_SCP_OAuth_Subject_Gate::boot();
		MAD4B_SCP_OAuth_Challenge_Alignment::boot();
	}

	private static function oauth_transport_enabled() {
		return defined( 'MAD4B_MCP_OAUTH_ENABLED' ) && true === constant( 'MAD4B_MCP_OAUTH_ENABLED' );
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
	 * Keep automatic write-authority reconciliation off unrelated wp-admin pages.
	 * Remote/MCP requests still reconcile through the Abilities lifecycle.
	 */
	public static function reconcile_authority_on_mad4b_admin() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.
		if ( 0 !== strpos( $page, 'mad4b-control-plane' ) ) return;
		MAD4B_SCP_Staging_Write_Authority::reconcile();
	}

	/**
	 * Certification is an explicit evidence collection operation. Restrict the
	 * expensive WPML/REST probe and certification audit write to its own tab.
	 */
	public static function observe_write_certification_on_certification_page() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.
		if ( 'mad4b-control-plane-connection' !== $page || 'certification' !== $tab ) return;
		MAD4B_SCP_Write_Runtime_Certification::observe();
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
			// Readiness used to capture registration_status() before the lazy REST
			// lifecycle had completed. Reconcile the bounded exact-Staging rescue now,
			// before any Connection_Status snapshot is rendered.
			if ( class_exists( 'MAD4B_SCP_MCP_Registration_Rescue' ) ) {
				MAD4B_SCP_MCP_Registration_Rescue::reconcile( 'admin_connection_prime' );
			}
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
