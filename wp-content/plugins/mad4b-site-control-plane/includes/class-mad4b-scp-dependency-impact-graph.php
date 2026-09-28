<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only dependency and certification impact projection.
 *
 * Combines WordPress RequiresPlugins edges with governed provider and add-on
 * certification state. It never mutates plugins or recertifies providers.
 */
final class MAD4B_SCP_Dependency_Impact_Graph {
	const CONTRACT = 'mad4b.dependency-impact-graph.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 33 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/dependency-impact' ) ) ) return;
		wp_register_ability( 'mad4b/dependency-impact', array(
			'label' => 'Inspect Plugin Dependency Impact',
			'description' => 'Read-only graph of WordPress plugin dependents and governed certification impact for a target plugin/provider.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'inspect' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'plugin' => array( 'type' => 'string', 'maxLength' => 191 ),
					'provider_id' => array( 'type' => 'string', 'maxLength' => 64 ),
				),
				'additionalProperties' => false,
			),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	private static function normalize_plugin( $plugin ) {
		$plugin = ltrim( wp_normalize_path( sanitize_text_field( (string) $plugin ) ), '/' );
		return false !== strpos( $plugin, '..' ) ? '' : $plugin;
	}

	private static function slug( $plugin ) {
		$plugin = self::normalize_plugin( $plugin );
		$dir = dirname( $plugin );
		return sanitize_key( '.' !== $dir && '' !== $dir ? basename( $dir ) : basename( $plugin, '.php' ) );
	}

	private static function infer_provider_id( $plugin ) {
		$plugin = self::normalize_plugin( $plugin );
		if ( '' === $plugin ) return '';
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'config/certified-providers.json' : '';
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) return '';
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$providers = is_array( $data ) && isset( $data['providers'] ) && is_array( $data['providers'] ) ? $data['providers'] : array();
		foreach ( $providers as $provider_id => $row ) {
			if ( ! is_array( $row ) || empty( $row['plugin_file'] ) ) continue;
			if ( $plugin === self::normalize_plugin( (string) $row['plugin_file'] ) ) return sanitize_key( (string) $provider_id );
		}
		return '';
	}

	public static function inspect( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$plugin = self::normalize_plugin( isset( $input['plugin'] ) ? $input['plugin'] : '' );
		$provider_id = sanitize_key( isset( $input['provider_id'] ) ? (string) $input['provider_id'] : '' );
		$provider_inferred = false;
		if ( '' === $provider_id && '' !== $plugin ) {
			$provider_id = self::infer_provider_id( $plugin );
			$provider_inferred = '' !== $provider_id;
		}
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		$dependents = array();
		$requires = array();
		if ( '' !== $plugin && isset( $plugins[ $plugin ] ) ) {
			$headers = is_array( $plugins[ $plugin ] ) ? $plugins[ $plugin ] : array();
			$raw = isset( $headers['RequiresPlugins'] ) ? (string) $headers['RequiresPlugins'] : '';
			$requires = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw ) ) ) ) ) );
			$target_slug = self::slug( $plugin );
			foreach ( $plugins as $file => $candidate_headers ) {
				if ( $file === $plugin ) continue;
				$raw_candidate = is_array( $candidate_headers ) && isset( $candidate_headers['RequiresPlugins'] ) ? (string) $candidate_headers['RequiresPlugins'] : '';
				$deps = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw_candidate ) ) ) );
				if ( in_array( $target_slug, $deps, true ) ) $dependents[] = array(
					'plugin' => $file,
					'name' => isset( $candidate_headers['Name'] ) ? sanitize_text_field( (string) $candidate_headers['Name'] ) : $file,
					'active' => function_exists( 'is_plugin_active' ) ? (bool) is_plugin_active( $file ) : null,
				);
			}
		}

		$provider_status = array();
		if ( '' !== $provider_id && class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) {
			$provider_status = MAD4B_SCP_Provider_Contracts::runtime_status( $provider_id );
			if ( ! is_array( $provider_status ) ) $provider_status = array();
		}
		$addon_status = class_exists( 'MAD4B_SCP_Addon_Registry' ) ? MAD4B_SCP_Addon_Registry::status() : array();
		$affected_addons = array();
		foreach ( isset( $addon_status['items'] ) && is_array( $addon_status['items'] ) ? $addon_status['items'] : array() as $row ) {
			if ( ! is_array( $row ) ) continue;
			$row_provider = isset( $row['pair']['provider_id'] ) ? sanitize_key( (string) $row['pair']['provider_id'] ) : '';
			if ( '' !== $provider_id && $row_provider === $provider_id ) $affected_addons[] = array(
				'addon_id' => isset( $row['addon_id'] ) ? (string) $row['addon_id'] : '',
				'state' => isset( $row['state'] ) ? (string) $row['state'] : '',
				'certified' => ! empty( $row['certified'] ),
				'pair_fingerprint' => isset( $row['pair_fingerprint'] ) ? (string) $row['pair_fingerprint'] : '',
			);
		}

		$impact_reasons = array();
		if ( $dependents ) $impact_reasons[] = 'wordpress_dependents_present';
		if ( $affected_addons ) $impact_reasons[] = 'certified_addon_pairs_require_revalidation';
		if ( '' !== $provider_id && ! empty( $provider_status ) ) $impact_reasons[] = 'provider_runtime_contract_may_require_recertification';

		return array(
			'contract' => self::CONTRACT,
			'plugin' => $plugin,
			'provider_id' => $provider_id,
			'provider_id_inferred' => $provider_inferred,
			'wordpress_requires' => $requires,
			'wordpress_dependents' => $dependents,
			'provider_status' => $provider_status,
			'affected_addons' => $affected_addons,
			'certification_revalidation_required' => ! empty( $affected_addons ) || ( '' !== $provider_id && ! empty( $provider_status ) ),
			'impact_reasons' => $impact_reasons,
			'mutation_performed' => false,
			'authority_created' => false,
		);
	}
}
