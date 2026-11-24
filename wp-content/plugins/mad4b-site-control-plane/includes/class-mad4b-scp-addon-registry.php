<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral registry for MAD4B-owned WordPress add-ons.
 *
 * This registry is deliberately read-only with respect to providers and add-ons.
 * It never activates a plugin, never grants authority, and never treats a
 * compatible version range as execution certification. Mutating eligibility
 * requires an exact provider/add-on pair plus runtime/provider certification.
 */
final class MAD4B_SCP_Addon_Registry {
	const CONTRACT = 'mad4b.wordpress-addon-registry.v1';
	const CATALOG_CONTRACT = 'mad4b.wordpress-addon-catalog.v1';
	const CATALOG_FILE = 'config/wordpress-addon-catalog.json';
	const ABILITY = 'mad4b/addon-registry-status';
	const EPOCH_OPTION = 'mad4b_scp_addon_registry_epoch_v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 34 );
		add_action( 'activated_plugin', array( __CLASS__, 'invalidate_plugin_lifecycle' ), 20, 2 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'invalidate_plugin_lifecycle' ), 20, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'invalidate_upgrade_lifecycle' ), 20, 2 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability(
			self::ABILITY,
			array(
				'label' => 'MAD4B Add-on Registry Status',
				'description' => 'Read-only exact-pair certification and lifecycle status for MAD4B WordPress add-ons.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'status' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	public static function invalidate_plugin_lifecycle( $plugin = '', $network_wide = false ) {
		self::bump_epoch( 'plugin_lifecycle', array( 'plugin' => (string) $plugin, 'network_wide' => (bool) $network_wide ) );
	}

	public static function invalidate_upgrade_lifecycle( $upgrader = null, $options = array() ) {
		if ( ! is_array( $options ) || 'plugin' !== ( isset( $options['type'] ) ? (string) $options['type'] : '' ) ) return;
		$plugins = isset( $options['plugins'] ) && is_array( $options['plugins'] ) ? array_values( array_map( 'strval', $options['plugins'] ) ) : array();
		if ( empty( $plugins ) && ! empty( $options['plugin'] ) ) $plugins[] = (string) $options['plugin'];
		self::bump_epoch( 'plugin_upgrade', array( 'plugins' => $plugins, 'action' => isset( $options['action'] ) ? (string) $options['action'] : '' ) );
	}

	private static function bump_epoch( $reason, array $context ) {
		$epoch = (int) get_option( self::EPOCH_OPTION, 0 ) + 1;
		update_option( self::EPOCH_OPTION, $epoch, false );
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record(
				'mad4b/addon-registry-invalidate',
				array( 'epoch' => $epoch, 'reason' => sanitize_key( (string) $reason ), 'context' => $context ),
				'ok'
			);
		}
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}

	private static function fingerprint( $value ) {
		$encoded = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private static function catalog() {
		$path = wp_normalize_path( MAD4B_SCP_DIR . self::CATALOG_FILE );
		if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_addon_catalog_missing', 'MAD4B WordPress add-on catalog is missing or unreadable.' );
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || self::CATALOG_CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) ) return new WP_Error( 'mad4b_addon_catalog_contract_invalid', 'MAD4B WordPress add-on catalog contract is invalid.' );
		if ( ! isset( $data['addons'] ) || ! is_array( $data['addons'] ) ) return new WP_Error( 'mad4b_addon_catalog_items_invalid', 'MAD4B WordPress add-on catalog items are invalid.' );
		return $data;
	}

	private static function required_manifest_fields() {
		return array(
			'addon_id', 'plugin_file', 'source_root', 'base_provider', 'compatible_versions', 'extension_points', 'capabilities',
			'data_ownership', 'authority_impact', 'rollback', 'certification', 'tests', 'portability',
			'supply_chain', 'network_access', 'multisite', 'performance_budget', 'observability', 'failure_policy',
			'release_ring', 'certified_pairs',
		);
	}

	private static function validate_manifest( array $manifest ) {
		foreach ( self::required_manifest_fields() as $field ) if ( ! array_key_exists( $field, $manifest ) ) return new WP_Error( 'mad4b_addon_manifest_field_missing', 'MAD4B add-on manifest is missing a required field.', array( 'field' => $field ) );
		$addon_id = sanitize_key( (string) $manifest['addon_id'] );
		if ( '' === $addon_id || $addon_id !== (string) $manifest['addon_id'] ) return new WP_Error( 'mad4b_addon_manifest_id_invalid', 'MAD4B add-on manifest addon_id is invalid.' );
		if ( empty( $manifest['base_provider']['provider_id'] ) ) return new WP_Error( 'mad4b_addon_manifest_provider_invalid', 'MAD4B add-on manifest base provider identity is missing.' );
		$source_root = trim( (string) $manifest['source_root'] );
		if ( '' === $source_root || 0 === strpos( $source_root, '/' ) || false !== strpos( $source_root, '..' ) ) return new WP_Error( 'mad4b_addon_manifest_source_root_invalid', 'MAD4B add-on source_root must be an explicit repository-relative path.' );
		if ( empty( $manifest['extension_points'] ) || ! is_array( $manifest['extension_points'] ) ) return new WP_Error( 'mad4b_addon_manifest_extension_points_invalid', 'MAD4B add-on manifest must declare extension points.' );
		if ( ! empty( $manifest['authority_impact']['inherits_production_authority'] ) || ! empty( $manifest['authority_impact']['adds_generic_shell'] ) || ! empty( $manifest['authority_impact']['adds_raw_sql'] ) ) return new WP_Error( 'mad4b_addon_manifest_authority_invalid', 'MAD4B add-on manifest may not inherit Production authority, generic shell, or raw SQL.' );
		if ( ! isset( $manifest['portability']['vendor_files_modified'] ) || false !== $manifest['portability']['vendor_files_modified'] ) return new WP_Error( 'mad4b_addon_manifest_vendor_patch_denied', 'MAD4B add-ons may not modify vendor files.' );
		if ( ( $manifest['certification']['exact_provider_version_required'] ?? null ) !== true
			|| ( $manifest['certification']['exact_addon_version_required'] ?? null ) !== true )
			return new WP_Error( 'mad4b_addon_manifest_exact_pair_required', 'MAD4B add-ons require explicit exact provider/add-on pair certification.' );
		if ( ( $manifest['failure_policy']['reconcile_before_retry_after_uncertain_write'] ?? null ) !== true )
			return new WP_Error( 'mad4b_addon_manifest_uncertain_retry_invalid', 'MAD4B add-ons must explicitly reconcile before retry after an uncertain write.' );
		if ( ! isset( $manifest['network_access']['allowed_hosts'] ) || ! is_array( $manifest['network_access']['allowed_hosts'] ) ) return new WP_Error( 'mad4b_addon_manifest_network_policy_invalid', 'MAD4B add-on network policy must explicitly declare allowed_hosts.' );
        if ( ! is_array( $manifest['certified_pairs'] ) ) return new WP_Error(
            'mad4b_addon_certified_pairs_invalid', 'Add-on certificates must be an array of exact provider/add-on pairs.' );
        $ring = $manifest['release_ring'];
        if ( ! is_string( $ring ) ) return new WP_Error(
            'mad4b_addon_release_ring_invalid', 'Add-on release ring must be a recognized string.' );
        if ( 'dynamic' === $ring ) {
            // A dynamic ring is not another environment. It is resolved from
            // a current enrolled site and one exact repository-pinned pair.
            $expected_policy = array(
                'mode' => 'site_bound_certified_pair',
                'requires_enrolled_site_profile' => true,
                'unknown_pair_fallback' => 'shadow',
                'production_authorized' => false,
            );
            $policy = isset( $manifest['release_policy'] ) && is_array( $manifest['release_policy'] )
                ? $manifest['release_policy'] : array();
            if ( self::canonicalize( $policy ) !== self::canonicalize( $expected_policy ) )
                return new WP_Error( 'mad4b_addon_dynamic_release_policy_invalid',
                    'Dynamic add-on ring requires the exact nonauthorizing site-bound release policy.' );
        } elseif ( ! in_array( $ring, array( 'shadow', 'canary', 'active' ), true ) ) {
            return new WP_Error( 'mad4b_addon_release_ring_invalid',
                'Add-on ring must be shadow, canary, active, or governed dynamic.' );
        }
		return true;
	}

    /**
     * Current host-owned, independently enrolled site identity.
     * Invalid origin or error-shaped observations cannot bind a dynamic ring.
     * No Site Profile is created/updated here.
     */
    private static function enrolled_site_binding() {
        $unbound = array( 'bound' => false, 'site_uuid' => '', 'profile_digest' => '' );
        $klass = 'MAD4B_SCP_Site_Profile';
        if ( ! class_exists( $klass, false ) ) return $unbound;
        foreach ( array( 'site_uuid', 'profile_digest', 'origin_enrolled', 'site_urls_match_enrollment' ) as $method )
            if ( ! is_callable( array( $klass, $method ) ) ) return $unbound;
        try {
            $uuid = $klass::site_uuid();
            $digest = $klass::profile_digest();
            if ( true !== $klass::origin_enrolled() || true !== $klass::site_urls_match_enrollment()
                || ! is_string( $uuid ) || ! is_string( $digest ) ) return $unbound;
        } catch ( \Throwable $error ) {
            return $unbound;
        }
        $uuid = strtolower( trim( $uuid ) );
        $digest = strtolower( trim( $digest ) );
        if ( 1 !== preg_match( '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $uuid )
            || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) return $unbound;
        return array( 'bound' => true, 'site_uuid' => $uuid, 'profile_digest' => $digest );
    }

	private static function plugin_state( $plugin_file ) {
		$plugin_file = (string) $plugin_file;
		if ( '' === $plugin_file ) return array( 'installed' => false, 'active' => false, 'version' => '', 'sha256' => '' );
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		if ( ! is_array( $plugins ) ) $plugins = array();
		$installed = isset( $plugins[ $plugin_file ] ) && is_array( $plugins[ $plugin_file ] );
		$absolute = wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_file );
		return array(
			'installed' => $installed,
			'active' => $installed && function_exists( 'is_plugin_active' ) && true === is_plugin_active( $plugin_file ),
			'network_active' => $installed && true === is_multisite() && function_exists( 'is_plugin_active_for_network' ) && true === is_plugin_active_for_network( $plugin_file ),
			'version' => $installed && ! empty( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '',
			'sha256' => is_file( $absolute ) && is_readable( $absolute ) ? hash_file( 'sha256', $absolute ) : '',
		);
	}

	/**
	 * Identify the first-party Control Plane by its exact installed plugin
	 * and live runtime, rather than querying the external-provider catalogue.
	 * This read-only proof is NOT an add-on pair certificate or write grant.
	 */
	private static function base_provider_runtime_status( array $manifest ) {
		$id = sanitize_key( (string) ( $manifest['base_provider']['provider_id'] ?? '' ) );
		$core_file = 'mad4b-site-control-plane/mad4b-site-control-plane.php';
		if ( 'mad4b-site-control-plane' === $id
			&& $core_file === (string) ( $manifest['base_provider']['plugin_file'] ?? '' ) ) {
			$core = self::plugin_state( $core_file );
			$active = ! empty( $core['installed'] ) && ( ! empty( $core['active'] ) || ! empty( $core['network_active'] ) );
			$bound = $active
				&& defined( 'MAD4B_SCP_VERSION' )
				&& hash_equals( (string) $core['version'], (string) MAD4B_SCP_VERSION )
				&& class_exists( 'MAD4B_SCP_Operation_Registry', false )
				&& class_exists( 'MAD4B_SCP_Site_Profile', false )
				&& class_exists( 'MAD4B_SCP_Addon_Registry', false );
			return array(
				'status' => $bound ? 'native_core_runtime_bound' : 'native_core_runtime_unavailable',
				'runtime_contract_ok' => (bool) $bound,
				'native_core_runtime_bound' => (bool) $bound,
				'installed_version' => (string) $core['version'],
				'plugin_file' => $core_file,
				'plugin_main_file_sha256' => (string) $core['sha256'],
				'certification_authority' => 'native_runtime_inventory_only',
				'production_authorized' => false,
			);
		}
		$unverified = array( 'status' => 'provider_contract_unavailable',
			'runtime_contract_ok' => false );
		if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) return $unverified;
		try {
			$observed = MAD4B_SCP_Provider_Contracts::runtime_status( $id );
		} catch ( \Throwable $error ) {
			return $unverified;
		}
		// WordPress provider source errors or unexpected scalar returns are
		// neither a certificate nor a valid runtime profile.
		return is_array( $observed ) ? $observed : $unverified;
	}

	private static function pair_status( array $manifest ) {
		$valid = self::validate_manifest( $manifest );
		if ( is_wp_error( $valid ) ) return array( 'state' => 'manifest_invalid', 'certified' => false, 'error_code' => $valid->get_error_code() );
		$addon = self::plugin_state( $manifest['plugin_file'] );
		$provider_id = sanitize_key( (string) $manifest['base_provider']['provider_id'] );
		$provider = self::base_provider_runtime_status( $manifest );
		$extension_points_sha256 = self::fingerprint( $manifest['extension_points'] );
		$manifest_sha256 = self::fingerprint( $manifest );
        // External provider observation is not a typed WordPress data store.
        // Error objects and arrays cannot become version/identity strings.
        $provider_version = is_string( $provider['installed_version'] ?? null )
            ? $provider['installed_version'] : '';
		$provider_profile_fingerprint = self::fingerprint( array(
			'provider' => $provider_id,
			'version' => $provider_version,
            'status' => is_string( $provider['status'] ?? null ) ? $provider['status'] : '',
			'runtime_contract_ok' => ( $provider['runtime_contract_ok'] ?? null ) === true,
            'certification_authority' => is_string( $provider['certification_authority'] ?? null )
                ? $provider['certification_authority'] : '',
		) );
        // Explicit legacy rings keep their existing certification semantics.
        // Dynamic rings resolve exactly one current site-bound certificate.
        $dynamic = 'dynamic' === $manifest['release_ring'];
        $site = $dynamic ? self::enrolled_site_binding() : array(
            'bound' => false, 'site_uuid' => '', 'profile_digest' => '' );
        $effective_ring = $dynamic ? 'shadow' : $manifest['release_ring'];
        $matching_certificates = 0;
        $certified_pair = false;
        foreach ( $manifest['certified_pairs'] as $candidate ) {
            if ( ! is_array( $candidate ) ) continue;
            if ( $provider_version !== ( isset( $candidate['provider_version'] ) ? (string) $candidate['provider_version'] : '' ) ) continue;
            if ( $addon['version'] !== ( isset( $candidate['addon_version'] ) ? (string) $candidate['addon_version'] : '' ) ) continue;
            if ( $extension_points_sha256 !== ( isset( $candidate['extension_points_sha256'] ) ? strtolower( (string) $candidate['extension_points_sha256'] ) : '' ) ) continue;
            $expected_main_sha = $candidate['addon_main_file_sha256'] ?? null;
            if ( ! is_string( $expected_main_sha )
                || 1 !== preg_match( '/^[a-f0-9]{64}$/D', strtolower( $expected_main_sha ) )
                || ! is_string( $addon['sha256'] )
                || ! hash_equals( strtolower( $expected_main_sha ), strtolower( $addon['sha256'] ) ) ) continue;
            if ( ! $dynamic ) {
                $certified_pair = true;
                break;
            }
            if ( ! $site['bound']
                || ! isset( $candidate['site_uuid'], $candidate['site_profile_digest'], $candidate['release_ring'] )
                || ! is_string( $candidate['site_uuid'] )
                || ! is_string( $candidate['site_profile_digest'] )
                || ! is_string( $candidate['release_ring'] ) ) continue;
            $pair_site = strtolower( trim( $candidate['site_uuid'] ) );
            $pair_profile_digest = strtolower( trim( $candidate['site_profile_digest'] ) );
            $pair_ring = $candidate['release_ring'];
            if ( ! hash_equals( $site['site_uuid'], $pair_site )
                || ! hash_equals( $site['profile_digest'], $pair_profile_digest )
                || ! in_array( $pair_ring, array( 'shadow', 'canary', 'active' ), true ) ) continue;
            ++$matching_certificates;
            // An array-order tie or duplicate cannot silently choose a ring.
            if ( 1 === $matching_certificates ) $effective_ring = $pair_ring;
            else $effective_ring = 'shadow';
        }
        if ( $dynamic ) {
            $certified_pair = 1 === $matching_certificates;
            if ( ! $certified_pair ) $effective_ring = 'shadow';
        }
        $pair = array(
            'provider_id' => $provider_id,
            'provider_version' => $provider_version,
            'provider_profile_fingerprint' => $provider_profile_fingerprint,
            'addon_id' => (string) $manifest['addon_id'],
            'addon_version' => $addon['version'],
            'addon_main_file_sha256' => $addon['sha256'],
            'addon_manifest_sha256' => $manifest_sha256,
            'extension_points_sha256' => $extension_points_sha256,
            'release_ring' => $effective_ring,
            'registry_epoch' => (int) get_option( self::EPOCH_OPTION, 0 ),
        );
        if ( $dynamic ) {
            $pair['release_policy'] = 'site_bound_certified_pair';
            $pair['site_uuid'] = $site['bound'] ? $site['site_uuid'] : '';
            $pair['site_profile_digest'] = $site['bound'] ? $site['profile_digest'] : '';
        }
        $pair_fingerprint = self::fingerprint( $pair );
		$provider_verified = ( $provider['runtime_contract_ok'] ?? null ) === true
			&& is_string( $provider['status'] ?? null )
			&& in_array( $provider['status'], array( 'certified', 'native_core_runtime_bound' ), true );
        // Inactive add-ons or uncertified providers never retain a promoted
        // dynamic ring, even if a prior exact certificate is still present.
        if ( $dynamic && ( ! $provider_verified
            || ( ! $addon['active'] && ! $addon['network_active'] ) ) ) {
            $effective_ring = 'shadow';
            $pair['release_ring'] = 'shadow';
            $pair_fingerprint = self::fingerprint( $pair );
        }
		$state = 'pair_uncertified';
		if ( ! $addon['installed'] ) $state = 'addon_unavailable';
		elseif ( ! $addon['active'] && ! $addon['network_active'] ) $state = 'addon_inactive';
		elseif ( ! $provider_verified ) $state = 'provider_uncertified';
		elseif ( $certified_pair ) $state = 'certified';

		return array(
			'state' => $state,
			'certified' => 'certified' === $state,
			'mutating_capabilities_eligible' => 'certified' === $state
                && ( ! $dynamic || 'active' === $effective_ring ),
            'release_ring_mode' => $dynamic ? 'site_bound_certified_pair' : 'legacy_explicit',
            'effective_release_ring' => $effective_ring,
            'site_profile_bound' => $dynamic && $site['bound'],
			'addon' => $addon,
			'provider' => $provider,
			'pair' => $pair,
			'pair_fingerprint' => $pair_fingerprint,
			'certification_fingerprint' => self::fingerprint( array( 'state' => $state, 'pair_fingerprint' => $pair_fingerprint, 'certified_pair' => $certified_pair ) ),
			'stale_plan_on_fingerprint_change' => true,
			'revalidation_required_after_plugin_lifecycle_change' => true,
			'production_authorized' => false,
		);
	}

	private static function manifest_by_id( $addon_id ) {
		$addon_id = sanitize_key( (string) $addon_id );
		if ( '' === $addon_id ) return new WP_Error( 'mad4b_addon_id_required', 'MAD4B add-on id is required.' );
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return $catalog;
		foreach ( $catalog['addons'] as $manifest ) {
			if ( is_array( $manifest ) && isset( $manifest['addon_id'] ) && hash_equals( $addon_id, sanitize_key( (string) $manifest['addon_id'] ) ) ) return $manifest;
		}
		return new WP_Error( 'mad4b_addon_not_cataloged', 'MAD4B add-on is not present in the governed add-on catalog.' );
	}

	public static function execution_binding( $addon_id ) {
		$manifest = self::manifest_by_id( $addon_id );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$status = self::pair_status( $manifest );
        if ( ! empty( $status['certified'] ) && empty( $status['mutating_capabilities_eligible'] ) )
            return new WP_Error( 'mad4b_addon_release_ring_not_active',
                'Site-bound add-on certificate is read-only until its effective dynamic ring is active.' );
		if ( empty( $status['certified'] ) ) return new WP_Error(
			'mad4b_addon_pair_not_certified',
			'MAD4B add-on execution binding is unavailable until the exact provider/add-on runtime pair is certified.',
			array( 'addon_id' => (string) $addon_id, 'runtime_status' => $status )
		);
		return array(
			'contract' => 'mad4b.wordpress-addon-execution-binding.v1',
			'addon_id' => (string) $addon_id,
			'pair_fingerprint' => (string) $status['pair_fingerprint'],
			'certification_fingerprint' => (string) $status['certification_fingerprint'],
			'provider_id' => isset( $status['pair']['provider_id'] ) ? (string) $status['pair']['provider_id'] : '',
			'provider_version' => isset( $status['pair']['provider_version'] ) ? (string) $status['pair']['provider_version'] : '',
			'addon_version' => isset( $status['pair']['addon_version'] ) ? (string) $status['pair']['addon_version'] : '',
			'release_ring' => isset( $status['pair']['release_ring'] ) ? (string) $status['pair']['release_ring'] : '',
			'production_authorized' => false,
		);
	}

	public static function mutation_guard( $addon_id, $expected_pair_fingerprint, $expected_certification_fingerprint ) {
		$expected_pair_fingerprint = strtolower( trim( (string) $expected_pair_fingerprint ) );
		$expected_certification_fingerprint = strtolower( trim( (string) $expected_certification_fingerprint ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_pair_fingerprint ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_certification_fingerprint ) ) {
			return new WP_Error( 'mad4b_addon_expected_fingerprint_invalid', 'MAD4B add-on mutation guard requires exact planned pair and certification fingerprints.' );
		}
		$binding = self::execution_binding( $addon_id );
		if ( is_wp_error( $binding ) ) return $binding;
		if ( ! hash_equals( $expected_pair_fingerprint, strtolower( (string) $binding['pair_fingerprint'] ) ) ) return new WP_Error(
			'mad4b_addon_pair_fingerprint_drift',
			'MAD4B add-on provider/add-on pair changed after planning; re-plan and re-approve.',
			array( 'addon_id' => (string) $addon_id, 'current_pair_fingerprint' => (string) $binding['pair_fingerprint'] )
		);
		if ( ! hash_equals( $expected_certification_fingerprint, strtolower( (string) $binding['certification_fingerprint'] ) ) ) return new WP_Error(
			'mad4b_addon_certification_fingerprint_drift',
			'MAD4B add-on certification changed after planning; re-plan and re-approve.',
			array( 'addon_id' => (string) $addon_id, 'current_certification_fingerprint' => (string) $binding['certification_fingerprint'] )
		);
		return true;
	}

	public static function status( $input = null ) {
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return array( 'contract' => self::CONTRACT, 'ready' => false, 'state' => 'catalog_invalid', 'error_code' => $catalog->get_error_code(), 'production_authorized' => false );
		$items = array();
		// An empty release catalog is not a certified registry. Historical
		// source candidates cannot authorize an add-on execution binding.
		$ready = ! empty( $catalog['addons'] );
		foreach ( $catalog['addons'] as $manifest ) {
			if ( ! is_array( $manifest ) ) { $ready = false; $items[] = array( 'state' => 'manifest_invalid', 'certified' => false ); continue; }
			$row = self::pair_status( $manifest );
			if ( empty( $row['certified'] ) ) $ready = false;
			$items[] = array_merge( array( 'addon_id' => isset( $manifest['addon_id'] ) ? (string) $manifest['addon_id'] : '' ), $row );
		}
		return array(
			'contract' => self::CONTRACT,
			'catalog_contract' => self::CATALOG_CONTRACT,
			'catalog_fingerprint' => self::fingerprint( $catalog ),
			'registry_epoch' => (int) get_option( self::EPOCH_OPTION, 0 ),
			'ready' => $ready,
			'state' => empty( $items ) ? 'no_registered_addons' : ( $ready ? 'ready' : 'certification_required' ),
			'pending_candidate_count' => isset( $catalog['pending_addon_candidates'] ) && is_array( $catalog['pending_addon_candidates'] ) ? count( $catalog['pending_addon_candidates'] ) : 0,
			'addons' => $items,
			'count' => count( $items ),
			'exact_pair_certification_required' => true,
			'catalog_certification_is_repository_authority' => true,
			'runtime_pair_revalidated_on_every_status_read' => true,
			'compatible_range_is_not_execution_authority' => true,
			'plugin_lifecycle_invalidates_plan_fingerprint' => true,
			'read_only' => true,
			'mutation_performed' => false,
			'production_authorized' => false,
		);
	}
}
