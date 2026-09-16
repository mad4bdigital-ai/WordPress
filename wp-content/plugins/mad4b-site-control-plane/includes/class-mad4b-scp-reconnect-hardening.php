<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact ChatGPT reconnect hardening layered over Upgrade Continuity.
 *
 * This component never grants write, Production or Breakglass authority. It
 * narrows reconnect readiness to the exact mad4b-chatgpt registration and
 * converts an otherwise ambiguous REST 404 into bounded 503 diagnostics.
 */
final class MAD4B_SCP_Reconnect_Hardening {
	const CONTRACT = 'mad4b.reconnect-hardening.v1';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Upgrade Continuity owns the migration and governance checks; replace only
		// the reconnect projection/notice so unrelated MCP servers cannot block ChatGPT.
		remove_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Upgrade_Continuity', 'register_abilities' ), 34 );
		remove_action( 'admin_notices', array( 'MAD4B_SCP_Upgrade_Continuity', 'connection_admin_notice' ), 1 );
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ), 34 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
		add_action( 'admin_notices', array( __CLASS__, 'connection_admin_notice' ), 1 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_mcp_rest_dispatch' ), -100, 3 );
	}

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) return;
		wp_register_ability_category( 'mad4b-governance', array(
			'label' => 'MAD4B Governance',
			'description' => 'Read-only governance, enrollment and reconnect diagnostics.',
		) );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) return;
		if ( ! wp_has_ability( 'mad4b/reconnect-readiness' ) ) {
			wp_register_ability( 'mad4b/reconnect-readiness', array(
				'label' => 'MAD4B Reconnect Readiness',
				'description' => 'Inspect exact ChatGPT Site Profile, OAuth and MCP reconnect readiness without changing authority.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( __CLASS__, 'reconnect_status' ),
				'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
				'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
		if ( ! wp_has_ability( 'mad4b/governance-bootstrap-status' ) ) {
			wp_register_ability( 'mad4b/governance-bootstrap-status', array(
				'label' => 'MAD4B Governance Bootstrap Status',
				'description' => 'Distinguish governance schema readiness from append-only audit readiness without mutation.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( 'MAD4B_SCP_Upgrade_Continuity', 'governance_status' ),
				'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
				'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
	}

	public static function reconnect_status() {
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$local = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::status() : array();
		$bridge = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$registrations = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$chatgpt = isset( $registrations['mad4b-chatgpt'] ) && is_array( $registrations['mad4b-chatgpt'] ) ? $registrations['mad4b-chatgpt'] : array();
		$bridge_registration = class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ? MAD4B_SCP_MCP_Registration_Bridge::status() : array();
		$blockers = array();

		if ( empty( $profile['configured'] ) ) $blockers[] = 'site_profile_unconfigured';
		if ( empty( $profile['environment_match'] ) ) $blockers[] = 'site_profile_environment_drift';
		if ( empty( $profile['origin_match'] ) ) $blockers[] = 'site_profile_origin_drift';
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::oauth_enabled() ) $blockers[] = 'site_profile_oauth_disabled';
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::managed_runtime_enabled() ) $blockers[] = 'site_profile_managed_runtime_disabled';
		if ( empty( $local['effective'] ) ) $blockers[] = self::oauth_blocker( $local );
		if ( empty( $bridge['effective'] ) ) $blockers[] = 'oauth_resource_bridge_not_effective';
		if ( empty( $chatgpt['registered'] ) ) $blockers[] = ! empty( $chatgpt['error'] ) ? sanitize_key( (string) $chatgpt['error'] ) : 'mcp_chatgpt_not_registered';
		$blockers = array_values( array_unique( array_filter( array_map( 'sanitize_key', $blockers ) ) ) );

		return array(
			'contract' => self::CONTRACT,
			'ready' => empty( $blockers ),
			'blockers' => $blockers,
			'resource' => self::resource_identifier(),
			'oauth_authorize_url' => untrailingslashit( home_url( '/oauth/mcp/authorize' ) ),
			'site_profile' => self::bounded_profile( $profile ),
			'local_oauth_effective' => ! empty( $local['effective'] ),
			'oauth_resource_bridge_effective' => ! empty( $bridge['effective'] ),
			'chatgpt_registered' => ! empty( $chatgpt['registered'] ),
			'chatgpt_error' => isset( $chatgpt['error'] ) ? sanitize_key( (string) $chatgpt['error'] ) : '',
			'missed_rest_recovery_state' => isset( $bridge_registration['missed_rest_recovery_state'] ) ? sanitize_key( (string) $bridge_registration['missed_rest_recovery_state'] ) : '',
			'missed_rest_recovery_blocker' => isset( $bridge_registration['missed_rest_recovery_blocker'] ) ? sanitize_key( (string) $bridge_registration['missed_rest_recovery_blocker'] ) : '',
			'upgrade_recovery' => class_exists( 'MAD4B_SCP_Upgrade_Continuity' ) ? MAD4B_SCP_Upgrade_Continuity::recovery_status() : array(),
			'write_auto_enabled' => false,
			'production_authority_auto_enabled' => false,
			'breakglass_auto_enabled' => false,
		);
	}

	public static function guard_mcp_rest_dispatch( $result, $server, $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $result;
		if ( ! self::is_resource_request_path( (string) $request->get_route() ) ) return $result;
		$status = self::reconnect_status();
		if ( ! empty( $status['ready'] ) ) return $result;
		return new WP_Error( 'mad4b_mcp_reconnect_not_ready', 'MAD4B MCP reconnect is not ready.', array(
			'status' => 503,
			'blockers' => $status['blockers'],
			'contract' => self::CONTRACT,
			'resource' => self::resource_identifier(),
		) );
	}

	public static function connection_admin_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		if ( 'mad4b-control-plane-chatgpt' !== $page ) return;
		$status = self::reconnect_status();
		$recovery = class_exists( 'MAD4B_SCP_Upgrade_Continuity' ) ? MAD4B_SCP_Upgrade_Continuity::recovery_status() : array();
		if ( ! empty( $recovery['recovered'] ) ) echo '<div class="notice notice-success inline"><p>' . esc_html__( 'MAD4B recovered the previously verified read/OAuth connection for this exact non-Production Site Profile. Write authority remains disabled and requires explicit administrator re-enrollment.', 'mad4b-site-control-plane' ) . '</p></div>';
		if ( ! empty( $status['ready'] ) ) return;
		$blockers = ! empty( $status['blockers'] ) ? implode( ', ', array_map( 'sanitize_key', $status['blockers'] ) ) : 'reconnect_not_ready';
		echo '<div class="notice notice-warning inline"><p>' . esc_html( 'ChatGPT reconnect is not ready. Blockers: ' . $blockers . '.' ) . '</p></div>';
	}

	public static function is_resource_request_path( $path ) {
		$path = self::normalize_path( $path );
		$resource = wp_parse_url( self::resource_identifier(), PHP_URL_PATH );
		$resource = is_string( $resource ) ? self::normalize_path( $resource ) : '/wp-json/mcp/mad4b-chatgpt';
		if ( hash_equals( $resource, $path ) ) return true;
		$rest_root = wp_parse_url( rest_url(), PHP_URL_PATH );
		$rest_root = is_string( $rest_root ) ? self::normalize_path( $rest_root ) : '/wp-json';
		if ( '/' !== $rest_root && 0 === strpos( $resource, $rest_root . '/' ) ) return hash_equals( self::normalize_path( substr( $resource, strlen( $rest_root ) ) ), $path );
		return false;
	}

	private static function resource_identifier() {
		return class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() : untrailingslashit( rest_url( 'mcp/mad4b-chatgpt' ) );
	}

	private static function oauth_blocker( $status ) {
		if ( ! is_array( $status ) || empty( $status ) ) return 'local_oauth_status_unavailable';
		if ( empty( $status['configured'] ) ) return 'local_oauth_disabled';
		if ( empty( $status['issuer_configuration_valid'] ) ) return ! empty( $status['runtime_error'] ) ? sanitize_key( (string) $status['runtime_error'] ) : 'local_oauth_issuer_invalid';
		if ( empty( $status['oauth_store_ready'] ) ) return 'oauth_store_not_ready';
		if ( empty( $status['private_key_present'] ) ) return ! empty( $status['runtime_error'] ) ? sanitize_key( (string) $status['runtime_error'] ) : 'oauth_private_key_not_ready';
		if ( ! empty( $status['runtime_error'] ) ) return sanitize_key( (string) $status['runtime_error'] );
		return 'local_oauth_not_effective';
	}

	private static function bounded_profile( $status ) {
		if ( ! is_array( $status ) ) $status = array();
		return array(
			'configured' => ! empty( $status['configured'] ),
			'site_uuid' => isset( $status['site_uuid'] ) ? sanitize_text_field( (string) $status['site_uuid'] ) : '',
			'revision' => isset( $status['revision'] ) ? absint( $status['revision'] ) : 0,
			'environment' => isset( $status['environment'] ) ? sanitize_key( (string) $status['environment'] ) : '',
			'environment_match' => ! empty( $status['environment_match'] ),
			'origin_match' => ! empty( $status['origin_match'] ),
			'oauth_enabled' => ! empty( $status['oauth_enabled'] ),
			'write_enabled' => ! empty( $status['write_enabled'] ),
		);
	}

	private static function normalize_path( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}
}
