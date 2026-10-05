<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact MCP protocol behavior certified for the packaged MCP Adapter.
 *
 * Client/vendor identity is deliberately absent from negotiation. The protocol
 * revision and exact packaged Adapter version are the compatibility authority;
 * neither creates WordPress/MAD4B execution authority.
 */
final class MAD4B_SCP_MCP_Protocol_Profile {
	const CONTRACT = 'mad4b.mcp-protocol-profile.v1';
	const CATALOG_CONTRACT = 'mad4b.mcp-protocol-profile-catalog.v1';
	const CERTIFIED_ADAPTER_VERSION_SOURCE = 'runtime_release_policy.target_adapter_version';
	const MAX_FEATURES = 8;
	private static $catalog = null;

	public static function reset_for_tests() { self::$catalog = null; }

	public static function supported_versions() {
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return array();
		return array_values( $catalog['supported_protocol_versions'] );
	}

	public static function profile( $version ) {
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return $catalog;
		$version = trim( (string) $version );
		if ( '' === $version || ! isset( $catalog['profiles'][ $version ] ) ) {
			return new WP_Error(
				'mad4b_mcp_protocol_version_uncertified',
				'Requested MCP protocol revision is not certified for the exact packaged Adapter.',
				array(
					'requested_protocol_version' => $version,
					'certified_protocol_versions' => array_values( $catalog['supported_protocol_versions'] ),
					'authority_effect' => 'none',
				)
			);
		}
		$profile = $catalog['profiles'][ $version ];
		$profile['protocol_version'] = $version;
		$profile['contract'] = self::CONTRACT;
		$profile['adapter_version'] = (string) $catalog['certified_adapter']['version'];
		$profile['authorizing'] = false;
		return $profile;
	}

	public static function negotiate( $requested_version, array $client_features = array() ) {
		$status = self::status();
		if ( empty( $status['ready'] ) ) {
			return new WP_Error( 'mad4b_mcp_protocol_profile_unready', 'MCP protocol profile is not ready for the exact packaged Adapter.', $status );
		}
		if ( count( $client_features ) > self::MAX_FEATURES ) {
			return new WP_Error( 'mad4b_mcp_protocol_feature_budget_exceeded', 'Client protocol feature claims exceed the bounded negotiation surface.' );
		}
		$allowed = array( 'tools_list_refresh', 'reconnect', 'projection_revision_echo', 'client_caches_tool_definitions' );
		foreach ( $client_features as $feature => $value ) {
			$feature = sanitize_key( (string) $feature );
			if ( ! in_array( $feature, $allowed, true ) || ! is_bool( $value ) ) {
				return new WP_Error(
					'mad4b_mcp_protocol_feature_uncertified',
					'Unknown or malformed MCP protocol feature claims fail closed until separately certified.',
					array( 'feature' => $feature, 'authority_effect' => 'none' )
				);
			}
		}
		$profile = self::profile( $requested_version );
		if ( is_wp_error( $profile ) ) return $profile;
		return array(
			'contract' => self::CONTRACT,
			'protocol_version' => (string) $profile['protocol_version'],
			'adapter_version' => (string) $status['certified_adapter_version'],
			'lifecycle' => (string) $profile['lifecycle'],
			'server_capabilities' => array( 'tools' => array( 'listChanged' => false ) ),
			'tools_list_pagination' => (string) $profile['tools_list_pagination'],
			'refresh_semantics' => (string) $profile['refresh_semantics'],
			'unknown_feature_policy' => 'deny',
			'client_features' => $client_features,
			'fallback_protocol_negotiation_used' => false,
			'client_vendor_authoritative' => false,
			'authority_effect' => 'none',
			'authorizing' => false,
		);
	}

	public static function projection_refresh( $protocol_version, $known_projection_revision = null, $client_caches_tools = false ) {
		$profile = self::profile( $protocol_version );
		if ( is_wp_error( $profile ) ) return $profile;
		$current = class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) && method_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection', 'projection_revision' )
			? (int) MAD4B_SCP_ChatGPT_Tool_Projection::projection_revision()
			: 0;
		$known = null;
		if ( null !== $known_projection_revision && '' !== $known_projection_revision ) {
			if ( ! is_int( $known_projection_revision ) && ! ctype_digit( (string) $known_projection_revision ) ) {
				return new WP_Error( 'mad4b_mcp_projection_revision_invalid', 'Known projection revision must be a non-negative integer.' );
			}
			$known = max( 0, (int) $known_projection_revision );
		}
		$tracked = null !== $known;
		$stale = $tracked && $known !== $current;
		$refresh_required = ! $tracked || $stale;
		$action = 'none';
		if ( $refresh_required ) $action = $client_caches_tools ? 'reconnect_then_tools_list' : 'tools_list';
		return array(
			'contract' => self::CONTRACT,
			'protocol_version' => (string) $profile['protocol_version'],
			'current_projection_revision' => $current,
			'known_projection_revision' => $known,
			'projection_revision_tracked' => $tracked,
			'stale_projection' => $stale,
			'refresh_required' => $refresh_required,
			'refresh_action' => $action,
			'tools_list_changed_supported' => false,
			'push_notification_expected' => false,
			'reconnect_only_when_client_caches_tools' => true,
			'fixed_dispatch_correctness_independent' => true,
			'authority_effect' => 'none',
			'authorizing' => false,
		);
	}

	public static function status() {
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) {
			return array(
				'contract' => self::CONTRACT,
				'ready' => false,
				'blocker' => $catalog->get_error_code(),
				'certified_protocol_versions' => array(),
				'authority_effect' => 'none',
				'authorizing' => false,
			);
		}
		$runtime_version = class_exists( 'MAD4B_SCP_Provider_Contracts' )
			? (string) MAD4B_SCP_Provider_Contracts::installed_version( 'mcp_adapter' )
			: '';
		$certified_version = isset( $catalog['certified_adapter']['version'] ) ? (string) $catalog['certified_adapter']['version'] : '';
		$version_match = '' !== $runtime_version && '' !== $certified_version && hash_equals( $certified_version, $runtime_version );
		$successor = isset( $catalog['successor_certification'] ) && is_array( $catalog['successor_certification'] )
			? $catalog['successor_certification'] : array();
		return array(
			'contract' => self::CONTRACT,
			'catalog_contract' => self::CATALOG_CONTRACT,
			'ready' => $version_match,
			'blocker' => $version_match ? '' : 'adapter_version_uncertified',
			'certified_adapter_version' => $certified_version,
			'runtime_adapter_version' => $runtime_version,
			'adapter_version_match' => $version_match,
			'certified_protocol_versions' => array_values( $catalog['supported_protocol_versions'] ),
			'tools_list_changed_supported' => false,
			'unknown_protocol_policy' => 'deny',
			'unknown_feature_policy' => 'deny',
			'fallback_protocol_negotiation_allowed_by_mad4b' => false,
			'successor_certification_state' => isset( $successor['state'] ) ? (string) $successor['state'] : 'not_certified',
			'successor_auto_adopt' => ! empty( $successor['auto_adopt'] ),
			'successor_dual_protocol_regression_required' => ! empty( $successor['dual_protocol_regression_required'] ),
			'authority_effect' => 'none',
			'authorizing' => false,
		);
	}

	private static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		$path = dirname( __DIR__ ) . '/config/mcp-protocol-profiles.json';
		if ( ! is_readable( $path ) ) return self::$catalog = new WP_Error( 'mad4b_mcp_protocol_catalog_missing', 'MCP protocol profile catalog is unavailable.' );
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || self::CATALOG_CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) ) {
			return self::$catalog = new WP_Error( 'mad4b_mcp_protocol_catalog_invalid', 'MCP protocol profile catalog contract is invalid.' );
		}
		$adapter = isset( $data['certified_adapter'] ) && is_array( $data['certified_adapter'] ) ? $data['certified_adapter'] : array();
		$release_policy_path = dirname( __DIR__ ) . '/config/runtime-release-policy.json';
		$provider_profiles_path = dirname( __DIR__ ) . '/config/certified-provider-profiles.json';
		$release_policy = is_readable( $release_policy_path ) ? json_decode( (string) file_get_contents( $release_policy_path ), true ) : null;
		$provider_profiles = is_readable( $provider_profiles_path ) ? json_decode( (string) file_get_contents( $provider_profiles_path ), true ) : null;
		$target_adapter_version = is_array( $release_policy ) && 'mad4b.runtime-release-policy.v1' === ( isset( $release_policy['contract'] ) ? (string) $release_policy['contract'] : '' )
			? trim( (string) ( isset( $release_policy['target_adapter_version'] ) ? $release_policy['target_adapter_version'] : '' ) ) : '';
		$catalog_adapter_version = isset( $adapter['version'] ) ? trim( (string) $adapter['version'] ) : '';
		if ( '' === $target_adapter_version
			|| '' === $catalog_adapter_version
			|| ! hash_equals( $target_adapter_version, $catalog_adapter_version )
			|| self::CERTIFIED_ADAPTER_VERSION_SOURCE !== ( isset( $adapter['version_source'] ) ? (string) $adapter['version_source'] : '' )
			|| empty( $adapter['exact_package_governed'] ) ) {
			return self::$catalog = new WP_Error( 'mad4b_mcp_protocol_adapter_binding_invalid', 'MCP protocol catalog is not bound to the canonical runtime release Adapter target.' );
		}
		$provider_profile = is_array( $provider_profiles )
			&& isset( $provider_profiles['providers']['mcp_adapter'][ $target_adapter_version ] )
			&& is_array( $provider_profiles['providers']['mcp_adapter'][ $target_adapter_version ] )
			? $provider_profiles['providers']['mcp_adapter'][ $target_adapter_version ] : array();
		$compatibility = isset( $provider_profile['transport_compatibility'] ) && is_array( $provider_profile['transport_compatibility'] )
			? $provider_profile['transport_compatibility'] : array();
		$legacy_versions = isset( $compatibility['legacy_session_revisions'] ) && is_array( $compatibility['legacy_session_revisions'] )
			? array_values( array_map( 'strval', $compatibility['legacy_session_revisions'] ) ) : array();
		$modern_versions = isset( $compatibility['modern_per_request_revisions'] ) && is_array( $compatibility['modern_per_request_revisions'] )
			? array_values( array_map( 'strval', $compatibility['modern_per_request_revisions'] ) ) : array();
		$expected_versions = array_merge( $modern_versions, array_reverse( $legacy_versions ) );
		$versions = isset( $data['supported_protocol_versions'] ) && is_array( $data['supported_protocol_versions'] ) ? array_values( $data['supported_protocol_versions'] ) : array();
		$profiles = isset( $data['profiles'] ) && is_array( $data['profiles'] ) ? $data['profiles'] : array();
		if ( empty( $expected_versions ) || $versions !== $expected_versions || count( $versions ) > 8 ) {
			return self::$catalog = new WP_Error( 'mad4b_mcp_protocol_versions_invalid', 'Certified MCP protocol versions drifted from the exact Adapter provider profile.' );
		}
		$seen = array();
		foreach ( $versions as $version ) {
			$version = trim( (string) $version );
			if ( 1 !== preg_match( '/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D', $version ) || isset( $seen[ $version ] ) || ! isset( $profiles[ $version ] ) || ! is_array( $profiles[ $version ] ) ) {
				return self::$catalog = new WP_Error( 'mad4b_mcp_protocol_versions_invalid', 'Certified MCP protocol version set is incomplete or duplicated.' );
			}
			$profile = $profiles[ $version ];
			$expected_lifecycle = in_array( $version, $modern_versions, true ) ? 'per_request_revision' : 'initialize_session';
			if ( $expected_lifecycle !== ( isset( $profile['lifecycle'] ) ? (string) $profile['lifecycle'] : '' )
				|| false !== ( isset( $profile['tools_list_changed'] ) ? (bool) $profile['tools_list_changed'] : true )
				|| 'none' !== ( isset( $profile['tools_list_pagination'] ) ? (string) $profile['tools_list_pagination'] : '' )
				|| 'pull_tools_list_then_reconnect_if_cached' !== ( isset( $profile['refresh_semantics'] ) ? (string) $profile['refresh_semantics'] : '' )
				|| 'deny' !== ( isset( $profile['unknown_feature_policy'] ) ? (string) $profile['unknown_feature_policy'] : '' )
				|| 'none' !== ( isset( $profile['authority_effect'] ) ? (string) $profile['authority_effect'] : '' ) ) {
				return self::$catalog = new WP_Error( 'mad4b_mcp_protocol_profile_invalid', 'Certified MCP protocol profile contains unsupported behavior.', array( 'protocol_version' => $version ) );
			}
			$seen[ $version ] = true;
		}
		$data['supported_protocol_versions'] = array_values( array_keys( $seen ) );
		return self::$catalog = $data;
	}
}
