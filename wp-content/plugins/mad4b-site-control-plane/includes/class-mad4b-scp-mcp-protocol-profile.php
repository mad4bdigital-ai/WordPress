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
		$status = self::status();
		return ! empty( $status['ready'] ) ? (array) ( $status['effective_protocol_versions'] ?? array() ) : array();
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
		$current_status = self::status();
		if ( empty( $current_status['ready'] )
			|| ! in_array( $version, (array) ( $current_status['effective_protocol_versions'] ?? array() ), true ) ) {
			return new WP_Error( 'mad4b_mcp_protocol_revision_unavailable_in_loaded_runtime',
				'Requested protocol revision is not implemented by the effective loaded MCP runtime.',
				array( 'requested_protocol_version' => $version,
					'available_protocol_versions' => (array) ( $current_status['effective_protocol_versions'] ?? array() ),
					'authorizing' => false ) );
		}
		$profile['adapter_version'] = ! empty( $current_status['ready'] ) ? (string) $current_status['runtime_adapter_version'] : (string) $catalog['certified_adapter']['version'];
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
			'adapter_version' => (string) $status['runtime_adapter_version'],
			'certified_adapter_target_version' => (string) $status['certified_adapter_version'],
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

    /**
     * PERMANENT FORWARD-COMPATIBILITY RULE:
     * Negotiate against the protocol revisions implemented by PHP's ACTUAL
     * loaded MCP negotiator, not the version string on the plugin header.
     * Other plugins (notably Rank Math) may supply an older WP\MCP namespace.
     * In that case retain shared revisions, disable only unavailable revisions,
     * and report the class provenance. A new protocol revision is not
     * automatically accepted until a matching runtime schema is present.
     */
    public static function runtime_protocol_support( array $advertised ) {
        $name = 'WP\\MCP\\Core\\McpVersionNegotiator';
        $effective = array();
        $origin = 'class_unavailable';
        try {
            if ( class_exists( $name ) ) {
                $reflection = new ReflectionClass( $name );
                $file = $reflection->getFileName();
                $origin = is_string( $file ) && '' !== $file
                    ? wp_normalize_path( $file ) : 'unknown_source';
                $reported = array();
                foreach ( array( 'SUPPORTED_PROTOCOL_VERSIONS', 'LEGACY_PROTOCOL_VERSIONS' ) as $const ) {
                    if ( $reflection->hasConstant( $const ) ) {
                        $values = $reflection->getConstant( $const );
                        if ( is_array( $values ) ) $reported = array_merge( $reported, $values );
                    }
                }
                foreach ( $reported as $revision ) {
                    if ( is_string( $revision )
                        && preg_match( '/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D', $revision ) ) {
                        $effective[ $revision ] = true;
                    }
                }
            }
        } catch ( Throwable $error ) {
            $effective = array();
            $origin = 'runtime_protocol_inspection_error';
        }
        $available = array_values( array_filter( $advertised, static function ( $revision ) use ( $effective ) {
            return is_string( $revision ) && isset( $effective[ $revision ] );
        } ) );
        $missing = array_values( array_diff( $advertised, $available ) );
        $expected_root = defined( 'WP_PLUGIN_DIR' )
            ? trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) . 'mcp-adapter/'
            : '';
        return array(
            'available' => $available,
            'unavailable' => $missing,
            'runtime_class_origin' => $origin,
            'runtime_class_from_standalone_adapter' => '' !== $expected_root
                && 0 === strpos( $origin, $expected_root ),
            'runtime_protocol_proven' => ! empty( $available ),
            'authorizing' => false,
        );
    }

    /**
     * PERMANENT BEHAVIOR CONTRACT:
     * A packaged Adapter version label is not the MCP transport protocol.
     * A known Provider Contract with an identical transport_compatibility
     * definition is usable for protocol negotiation, without minting grants,
     * accepting unknown revisions, or bypassing feature admission.
     *
     * This is read-only evidence. Write admission always remains with
     * Staging_Write_Authority and the active identity/ability checks.
     */
    public static function adapter_transport_compatibility( $runtime_version, $certified_version ) {
        $runtime_version = trim( (string) $runtime_version );
        $certified_version = trim( (string) $certified_version );
        $same = '' !== $runtime_version && '' !== $certified_version
            && hash_equals( $certified_version, $runtime_version );
        $known = class_exists( 'MAD4B_SCP_Provider_Contracts' );
        $source = $known
            ? MAD4B_SCP_Provider_Contracts::get_for_version( 'mcp_adapter', $runtime_version )
            : array();
        $baseline = $known
            ? MAD4B_SCP_Provider_Contracts::get_for_version( 'mcp_adapter', $certified_version )
            : array();
        $a = is_array( $source ) ? ( $source['transport_compatibility'] ?? null ) : null;
        $b = is_array( $baseline ) ? ( $baseline['transport_compatibility'] ?? null ) : null;
        $transport = is_array( $a ) && ! empty( $a ) &&
            is_array( $b ) && ! empty( $b ) && $a === $b;
        $dynamic_layout = array();
        $dynamic_ready = false;
        // A FUTURE version does not need a pre-added static version record.
        // Only the ACTUALLY INSTALLED Adapter may take this dynamic branch:
        // local core layout + plugin identity + runtime-reported protocol
        // support must all validate. This is neither a certificate nor a grant.
        $installed = $known
            ? (string) MAD4B_SCP_Provider_Contracts::installed_version( 'mcp_adapter' ) : '';
        if ( ! $same && ! $transport && $runtime_version !== ''
            && hash_equals( $installed, $runtime_version )
            && class_exists( 'MAD4B_SCP_MCP_Adapter_Dynamic_Integrity' ) ) {
            $base_contract = MAD4B_SCP_Provider_Contracts::get( 'mcp_adapter' );
            $plugin_file = is_array( $base_contract )
                ? (string) ( $base_contract['plugin_file'] ?? '' ) : '';
            $dynamic_layout = MAD4B_SCP_MCP_Adapter_Dynamic_Integrity::inspect(
                $plugin_file, $runtime_version, false
            );
            $dynamic_ready = is_array( $dynamic_layout )
                && ! empty( $dynamic_layout['ready'] )
                && ( ! empty( $dynamic_layout['layout_checked'] )
                    || ! empty( $dynamic_layout['official_package_verified'] ) );
        }
        $ready = $same || $transport || $dynamic_ready;
        return array(
            'protocol_compatible' => $ready,
            'exact_adapter_version_match' => $same,
            'registered_transport_contract_match' => $transport,
            'dynamic_installed_layout_verified' => $dynamic_ready,
            'dynamic_official_package_verified' => $dynamic_ready
                && ! empty( $dynamic_layout['official_package_verified'] ),
            'classification' => $same ? 'exact_certified_adapter'
                : ( $transport ? 'known_transport_compatible_version'
                    : ( $dynamic_ready ? 'future_installed_version_layout_compatible'
                        : 'not_installed_or_transport_unverified' ) ),
            'version_certification_warning' => $ready && ! $same,
            'write_authority_effect' => 'none',
            'authorizing' => false,
        );
    }

    /**
     * PERMANENT MCP SCOPE CONTRACT:
     * Rank Math is allowed to load its own embedded WP\MCP classes on
     * unrelated WordPress requests. Those classes must not be reported as
     * the negotiated runtime for a MAD4B MCP endpoint.
     *
     * The existing scoped MU bootstrap pins the canonical Adapter on actual
     * authorized MCP routes and explicit CLI MCP tests. This method ONLY
     * observes its request-local evidence; it never creates a scope, enables
     * MCP, changes an autoloader or grants write authority.
     */
    public static function request_scope_evidence() {
        $mu = isset( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] )
            && is_array( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] )
                ? $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] : array();
        $scoped = ! empty( $mu['request_requires_mcp_runtime'] )
            && ! empty( $mu['eligible'] )
            && empty( $mu['request_scope_bypassed'] );
        $canonical = $scoped
            && ! empty( $mu['canonical_symbols_pinned'] )
            && ! empty( $mu['critical_class_set_pinned'] )
            && ! empty( $mu['runtime_from_official_plugin'] )
            && empty( $mu['runtime_preclaimed'] )
            && empty( $mu['transaction_pending'] );
        return array(
            'contract' => 'mad4b.mcp-canonical-request-scope.v1',
            'canonical_mcp_request' => $scoped,
            'canonical_bootstrap_verified' => $canonical,
            'source' => $scoped ? 'early_mu_bootstrap' : 'non_mcp_request',
            'bootstrap_state' => isset( $mu['state'] )
                ? sanitize_key( (string) $mu['state'] ) : 'not_observed',
            'scope_bypassed' => ! $scoped,
            'authorizing' => false,
            'mutation_performed' => false,
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
		$compatibility = self::adapter_transport_compatibility( $runtime_version, $certified_version );
		$protocol_ready = ! empty( $compatibility['protocol_compatible'] );
		$exact_version_match = ! empty( $compatibility['exact_adapter_version_match'] );
		$runtime_proof = self::runtime_protocol_support( array_values( $catalog['supported_protocol_versions'] ) );
		$scope = self::request_scope_evidence();
		// Do not turn a valid Rank Math-owned ordinary WP request into a
		// false MAD4B MCP outage. Conversely, a scoped MCP request MUST NOT
		// report ready unless the original early loader pinned its class set.
		// The installed provider can serve a subset of the catalog while an older
		// MCP class has already been loaded by another plugin. Keep READ alive
		// on the overlapping revisions; no unsupported version negotiation.
		$protocol_ready = $protocol_ready && ! empty( $runtime_proof['runtime_protocol_proven'] );
		$scoped_ready = ! $scope['canonical_mcp_request']
			? null
			: ( $protocol_ready
				&& $scope['canonical_bootstrap_verified']
				&& ! empty( $runtime_proof['runtime_class_from_standalone_adapter'] ) );
		if ( $scope['canonical_mcp_request'] ) $protocol_ready = true === $scoped_ready;
		// Keep the release-certified identity separate from the installed version.
		// A compatible legacy Adapter is a warning, not a new certification.
		$effective_version = $certified_version;
		$successor = isset( $catalog['successor_certification'] ) && is_array( $catalog['successor_certification'] )
			? $catalog['successor_certification'] : array();
		return array(
			'contract' => self::CONTRACT,
			'catalog_contract' => self::CATALOG_CONTRACT,
			'ready' => $protocol_ready,
			'blocker' => $protocol_ready ? '' : 'adapter_transport_incompatible_or_unverified',
			'certified_adapter_version' => $effective_version,
			'runtime_adapter_version' => $runtime_version,
			'adapter_version_match' => $exact_version_match,
			'transport_compatible' => $protocol_ready,
			'protocol_runtime_evidence' => $runtime_proof['runtime_protocol_proven'],
			'request_scope' => $scope,
			'canonical_mcp_runtime_evaluated' => $scope['canonical_mcp_request'],
			'canonical_mcp_ready' => $scoped_ready,
			'provider_embedded_runtime_is_not_mad4b_mcp_failure' => ! $scope['canonical_mcp_request'],
			'unsupported_revision_is_request_scope_specific' => true,
			'effective_protocol_versions' => $runtime_proof['available'],
			'unavailable_protocol_versions' => $runtime_proof['unavailable'],
			'runtime_negotiator_class_source' => $runtime_proof['runtime_class_origin'],
			'runtime_negotiator_is_standalone' => $runtime_proof['runtime_class_from_standalone_adapter'],
			'partial_protocol_compatibility' => $protocol_ready && ! empty( $runtime_proof['unavailable']),
			'compatibility_classification' => $compatibility['classification'],
			'version_certification_warning' => $compatibility['version_certification_warning'],
			'certification_warning_code' => ! empty( $compatibility['version_certification_warning'] )
                ? ( ! empty( $compatibility['dynamic_installed_layout_verified'] )
                    ? 'future_version_layout_verified_protocol_runtime_limited'
                    : 'known_adapter_version_differs_from_certified_target' ) : '',
			'certified_protocol_versions' => array_values( $catalog['supported_protocol_versions'] ),
			'tools_list_changed_supported' => false,
			'unknown_protocol_policy' => 'deny',
			'unknown_feature_policy' => 'deny',
			'fallback_protocol_negotiation_allowed_by_mad4b' => false,
			'successor_certification_state' => $protocol_ready && ! $exact_version_match ? 'matching_known_transport_profile' : ( isset( $successor['state'] ) ? (string) $successor['state'] : 'not_certified' ),
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
