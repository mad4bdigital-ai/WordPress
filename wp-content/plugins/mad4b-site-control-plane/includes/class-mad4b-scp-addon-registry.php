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
			'addon_id', 'plugin_file', 'base_provider', 'compatible_versions', 'extension_points', 'capabilities',
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
		if ( empty( $manifest['extension_points'] ) || ! is_array( $manifest['extension_points'] ) ) return new WP_Error( 'mad4b_addon_manifest_extension_points_invalid', 'MAD4B add-on manifest must declare extension points.' );
		if ( ! empty( $manifest['authority_impact']['inherits_production_authority'] ) || ! empty( $manifest['authority_impact']['adds_generic_shell'] ) || ! empty( $manifest['authority_impact']['adds_raw_sql'] ) ) return new WP_Error( 'mad4b_addon_manifest_authority_invalid', 'MAD4B add-on manifest may not inherit Production authority, generic shell, or raw SQL.' );
		if ( ! isset( $manifest['portability']['vendor_files_modified'] ) || false !== $manifest['portability']['vendor_files_modified'] ) return new WP_Error( 'mad4b_addon_manifest_vendor_patch_denied', 'MAD4B add-ons may not modify vendor files.' );
		if ( empty( $manifest['certification']['exact_provider_version_required'] ) || empty( $manifest['certification']['exact_addon_version_required'] ) ) return new WP_Error( 'mad4b_addon_manifest_exact_pair_required', 'MAD4B add-ons require exact provider/add-on pair certification.' );
		if ( empty( $manifest['failure_policy']['reconcile_before_retry_after_uncertain_write'] ) ) return new WP_Error( 'mad4b_addon_manifest_uncertain_retry_invalid', 'MAD4B add-ons must reconcile before retry after an uncertain write.' );
		if ( ! isset( $manifest['network_access']['allowed_hosts'] ) || ! is_array( $manifest['network_access']['allowed_hosts'] ) ) return new WP_Error( 'mad4b_addon_manifest_network_policy_invalid', 'MAD4B add-on network policy must explicitly declare allowed_hosts.' );
		return true;
	}

	private static function plugin_state( $plugin_file ) {
		$plugin_file = (string) $plugin_file;
		if ( '' === $plugin_file ) return array( 'installed' => false, 'active' => false, 'version' => '', 'sha256' => '' );
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		$installed = isset( $plugins[ $plugin_file ] );
		$absolute = wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_file );
		return array(
			'installed' => $installed,
			'active' => $installed && function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ),
			'network_active' => $installed && is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file ),
			'version' => $installed && ! empty( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '',
			'sha256' => is_file( $absolute ) && is_readable( $absolute ) ? hash_file( 'sha256', $absolute ) : '',
		);
	}

	private static function pair_status( array $manifest ) {
		$valid = self::validate_manifest( $manifest );
		if ( is_wp_error( $valid ) ) return array( 'state' => 'manifest_invalid', 'certified' => false, 'error_code' => $valid->get_error_code() );
		$addon = self::plugin_state( $manifest['plugin_file'] );
		$provider_id = sanitize_key( (string) $manifest['base_provider']['provider_id'] );
		$provider = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::runtime_status( $provider_id ) : array( 'status' => 'provider_contract_unavailable', 'runtime_contract_ok' => false );
		$extension_points_sha256 = self::fingerprint( $manifest['extension_points'] );
		$manifest_sha256 = self::fingerprint( $manifest );
		$provider_version = isset( $provider['installed_version'] ) ? (string) $provider['installed_version'] : '';
		$provider_profile_fingerprint = self::fingerprint( array(
			'provider' => $provider_id,
			'version' => $provider_version,
			'status' => isset( $provider['status'] ) ? (string) $provider['status'] : '',
			'runtime_contract_ok' => ! empty( $provider['runtime_contract_ok'] ),
			'certification_authority' => isset( $provider['certification_authority'] ) ? (string) $provider['certification_authority'] : '',
		) );
		$pair = array(
			'provider_id' => $provider_id,
			'provider_version' => $provider_version,
			'provider_profile_fingerprint' => $provider_profile_fingerprint,
			'addon_id' => (string) $manifest['addon_id'],
			'addon_version' => $addon['version'],
			'addon_main_file_sha256' => $addon['sha256'],
			'addon_manifest_sha256' => $manifest_sha256,
			'extension_points_sha256' => $extension_points_sha256,
			'release_ring' => sanitize_key( (string) $manifest['release_ring'] ),
			'registry_epoch' => (int) get_option( self::EPOCH_OPTION, 0 ),
		);
		$pair_fingerprint = self::fingerprint( $pair );
		$certified_pair = false;
		foreach ( (array) $manifest['certified_pairs'] as $candidate ) {
			if ( ! is_array( $candidate ) ) continue;
			if ( $provider_version !== ( isset( $candidate['provider_version'] ) ? (string) $candidate['provider_version'] : '' ) ) continue;
			if ( $addon['version'] !== ( isset( $candidate['addon_version'] ) ? (string) $candidate['addon_version'] : '' ) ) continue;
			if ( $extension_points_sha256 !== ( isset( $candidate['extension_points_sha256'] ) ? strtolower( (string) $candidate['extension_points_sha256'] ) : '' ) ) continue;
			if ( ! empty( $candidate['addon_main_file_sha256'] ) && $addon['sha256'] !== strtolower( (string) $candidate['addon_main_file_sha256'] ) ) continue;
			$certified_pair = true;
			break;
		}

		$provider_certified = ! empty( $provider['runtime_contract_ok'] ) && isset( $provider['status'] ) && 'certified' === $provider['status'];
		$state = 'pair_uncertified';
		if ( ! $addon['installed'] ) $state = 'addon_unavailable';
		elseif ( ! $addon['active'] && ! $addon['network_active'] ) $state = 'addon_inactive';
		elseif ( ! $provider_certified ) $state = 'provider_uncertified';
		elseif ( $certified_pair ) $state = 'certified';

		return array(
			'state' => $state,
			'certified' => 'certified' === $state,
			'mutating_capabilities_eligible' => 'certified' === $state,
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

	public static function status( $input = null ) {
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) return array( 'contract' => self::CONTRACT, 'ready' => false, 'state' => 'catalog_invalid', 'error_code' => $catalog->get_error_code(), 'production_authorized' => false );
		$items = array();
		$ready = true;
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
			'state' => $ready ? 'ready' : 'certification_required',
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
