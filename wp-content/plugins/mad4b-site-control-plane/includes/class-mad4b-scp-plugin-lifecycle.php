<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic plugin lifecycle preflight.
 *
 * This class never activates or deactivates a plugin. Core mutation abilities
 * consume its plan so dependency checks, optimistic state fingerprints and
 * lifecycle policy are identical for humans, Skills and workflow providers.
 */
final class MAD4B_SCP_Plugin_Lifecycle {
	const CONTRACT = 'mad4b.plugin-lifecycle-plan.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 33 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( 'mad4b/plugin-lifecycle-plan' ) ) return;
		wp_register_ability(
			'mad4b/plugin-lifecycle-plan',
			array(
				'label' => 'Plan Plugin Lifecycle Change',
				'description' => 'Read-only dependency-aware preflight for governed plugin activation or deactivation.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'plan' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'plugin' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
						'desired_active' => array( 'type' => 'boolean' ),
						'expected_state_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
						'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
					),
					'required' => array( 'plugin', 'desired_active', 'reason' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	public static function plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$plugin = isset( $input['plugin'] ) ? self::normalize_plugin_file( $input['plugin'] ) : '';
		$desired = ! empty( $input['desired_active'] );
		$reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';
		$expected_state_sha = isset( $input['expected_state_sha256'] ) ? strtolower( trim( (string) $input['expected_state_sha256'] ) ) : '';

		$snapshot = self::snapshot( $plugin );
		if ( is_wp_error( $snapshot ) ) return $snapshot;

		$operation = $desired ? 'activate' : 'deactivate';
		$blockers = array();
		$required_dependencies = self::required_dependencies( $plugin, $snapshot['headers'] );
		$active_dependents = self::active_dependents( $plugin );

		if ( '' !== $expected_state_sha && ! hash_equals( $expected_state_sha, $snapshot['state_sha256'] ) ) $blockers[] = 'plugin_state_changed_since_review';
		if ( $desired === (bool) $snapshot['active'] ) $blockers[] = $desired ? 'already_active' : 'already_inactive';
		if ( ! self::lifecycle_gate_enabled() ) $blockers[] = 'plugin_lifecycle_global_gate_disabled';
		if ( ! MAD4B_SCP_Policy::plugin_lifecycle_allowed( $plugin, $operation ) ) $blockers[] = 'plugin_not_allowlisted_for_operation';

		if ( ! $desired && self::is_protected( $plugin, $snapshot['headers'] ) ) $blockers[] = 'protected_control_plane_dependency';
		if ( $desired ) {
			foreach ( $required_dependencies as $dependency ) {
				if ( empty( $dependency['installed'] ) ) $blockers[] = 'required_dependency_missing:' . $dependency['slug'];
				elseif ( empty( $dependency['active'] ) ) $blockers[] = 'required_dependency_inactive:' . $dependency['slug'];
			}
		} else {
			foreach ( $active_dependents as $dependent ) $blockers[] = 'active_dependent:' . $dependent['plugin'];
		}

		$blockers = array_values( array_unique( $blockers ) );
		sort( $blockers, SORT_STRING );

		$plan = array(
			'contract' => self::CONTRACT,
			'plugin' => $plugin,
			'plugin_name' => $snapshot['name'],
			'plugin_version' => $snapshot['version'],
			'operation' => $operation,
			'desired_active' => $desired,
			'current_active' => (bool) $snapshot['active'],
			'current_network_active' => (bool) $snapshot['network_active'],
			'plugin_main_file_sha256' => $snapshot['plugin_main_file_sha256'],
			'state_sha256' => $snapshot['state_sha256'],
			'expected_state_sha256' => $expected_state_sha,
			'required_dependencies' => $required_dependencies,
			'active_dependents' => $active_dependents,
			'protected' => self::is_protected( $plugin, $snapshot['headers'] ),
			'global_gate_enabled' => self::lifecycle_gate_enabled(),
			'allowlisted' => MAD4B_SCP_Policy::plugin_lifecycle_allowed( $plugin, $operation ),
			'eligible' => empty( $blockers ),
			'blockers' => $blockers,
			'reason' => $reason,
			'mutation_performed' => false,
			'authority_created' => false,
		);
		$encoded = wp_json_encode( self::canonicalize( $plan ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) return new WP_Error( 'mad4b_plugin_lifecycle_plan_encoding_failed', 'Unable to encode plugin lifecycle plan.' );
		$plan['plan_sha256'] = hash( 'sha256', $encoded );
		return $plan;
	}

	public static function mutation_preflight( $plugin, $desired_active, array $input = array() ) {
		$plan_input = array(
			'plugin' => $plugin,
			'desired_active' => (bool) $desired_active,
			'reason' => isset( $input['reason'] ) ? (string) $input['reason'] : 'governed plugin lifecycle mutation',
		);
		if ( ! empty( $input['expected_state_sha256'] ) ) $plan_input['expected_state_sha256'] = (string) $input['expected_state_sha256'];
		$plan = self::plan( $plan_input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $plan['eligible'] ) ) {
			return new WP_Error(
				'mad4b_plugin_lifecycle_preflight_blocked',
				'Plugin lifecycle preflight blocked the requested mutation.',
				array( 'blockers' => $plan['blockers'], 'state_sha256' => $plan['state_sha256'], 'plan_sha256' => $plan['plan_sha256'] )
			);
		}
		return $plan;
	}

	public static function verify_state( $plugin, $desired_active ) {
		$snapshot = self::snapshot( $plugin );
		if ( is_wp_error( $snapshot ) ) return $snapshot;
		if ( (bool) $snapshot['active'] !== (bool) $desired_active ) {
			return new WP_Error(
				'mad4b_plugin_lifecycle_readback_mismatch',
				'Plugin lifecycle readback does not match the requested state.',
				array( 'current_active' => (bool) $snapshot['active'], 'state_sha256' => $snapshot['state_sha256'] )
			);
		}
		return $snapshot;
	}

	public static function snapshot( $plugin ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = self::normalize_plugin_file( $plugin );
		$plugins = get_plugins();
		if ( '' === $plugin || ! isset( $plugins[ $plugin ] ) ) return new WP_Error( 'mad4b_plugin_missing', 'Plugin is not installed.' );

		$headers = is_array( $plugins[ $plugin ] ) ? $plugins[ $plugin ] : array();
		$path = wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin );
		$file_sha = is_file( $path ) && is_readable( $path ) ? hash_file( 'sha256', $path ) : '';
		$active = is_plugin_active( $plugin );
		$network_active = is_multisite() ? is_plugin_active_for_network( $plugin ) : false;
		$state_payload = array(
			'plugin' => $plugin,
			'version' => isset( $headers['Version'] ) ? (string) $headers['Version'] : '',
			'active' => (bool) $active,
			'network_active' => (bool) $network_active,
			'main_file_sha256' => $file_sha,
			'requires_plugins' => isset( $headers['RequiresPlugins'] ) ? (string) $headers['RequiresPlugins'] : '',
		);
		return array(
			'plugin' => $plugin,
			'name' => isset( $headers['Name'] ) ? (string) $headers['Name'] : $plugin,
			'version' => isset( $headers['Version'] ) ? (string) $headers['Version'] : '',
			'active' => (bool) $active,
			'network_active' => (bool) $network_active,
			'plugin_main_file_sha256' => $file_sha,
			'state_sha256' => hash( 'sha256', wp_json_encode( self::canonicalize( $state_payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'headers' => $headers,
		);
	}

	private static function required_dependencies( $plugin, array $headers ) {
		$raw = isset( $headers['RequiresPlugins'] ) ? (string) $headers['RequiresPlugins'] : '';
		$slugs = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw ) ) ) );
		$result = array();
		foreach ( array_values( array_unique( $slugs ) ) as $slug ) {
			$matches = self::plugin_files_for_slug( $slug );
			$active = false;
			foreach ( $matches as $match ) if ( is_plugin_active( $match ) ) { $active = true; break; }
			$result[] = array( 'slug' => $slug, 'installed' => ! empty( $matches ), 'active' => $active, 'plugin_files' => $matches );
		}
		return $result;
	}

	private static function active_dependents( $plugin ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$target_slug = self::plugin_slug( $plugin );
		$result = array();
		foreach ( get_plugins() as $file => $headers ) {
			if ( $file === $plugin || ! is_plugin_active( $file ) ) continue;
			$raw = is_array( $headers ) && isset( $headers['RequiresPlugins'] ) ? (string) $headers['RequiresPlugins'] : '';
			$dependencies = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw ) ) ) );
			if ( in_array( $target_slug, $dependencies, true ) ) {
				$result[] = array(
					'plugin' => $file,
					'name' => is_array( $headers ) && isset( $headers['Name'] ) ? (string) $headers['Name'] : $file,
					'requires_slug' => $target_slug,
				);
			}
		}
		usort( $result, static function ( $a, $b ) { return strcmp( $a['plugin'], $b['plugin'] ); } );
		return $result;
	}

	private static function plugin_files_for_slug( $slug ) {
		$files = array();
		foreach ( get_plugins() as $file => $headers ) {
			if ( self::plugin_slug( $file ) === $slug ) $files[] = $file;
		}
		sort( $files, SORT_STRING );
		return $files;
	}

	private static function plugin_slug( $plugin ) {
		$plugin = self::normalize_plugin_file( $plugin );
		$dir = dirname( $plugin );
		if ( '.' !== $dir && '' !== $dir ) return sanitize_key( basename( $dir ) );
		return sanitize_key( basename( $plugin, '.php' ) );
	}

	private static function normalize_plugin_file( $plugin ) {
		$plugin = wp_normalize_path( sanitize_text_field( (string) $plugin ) );
		$plugin = ltrim( $plugin, '/' );
		return false !== strpos( $plugin, '..' ) ? '' : $plugin;
	}

	private static function is_protected( $plugin, array $headers ) {
		$self = plugin_basename( MAD4B_SCP_FILE );
		$name = isset( $headers['Name'] ) ? strtolower( (string) $headers['Name'] ) : '';
		$text_domain = isset( $headers['TextDomain'] ) ? strtolower( (string) $headers['TextDomain'] ) : '';
		$is_mcp_adapter = 0 === strpos( strtolower( $plugin ), 'mcp-adapter/' ) || 'mcp-adapter' === $text_domain || false !== strpos( $name, 'mcp adapter' );
		return $plugin === $self || $is_mcp_adapter || MAD4B_SCP_Policy::plugin_lifecycle_protected( $plugin );
	}

	private static function lifecycle_gate_enabled() {
		return defined( 'MAD4B_MCP_PLUGIN_LIFECYCLE_ENABLED' ) && true === MAD4B_MCP_PLUGIN_LIFECYCLE_ENABLED;
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			$out = array();
			foreach ( $value as $item ) $out[] = self::canonicalize( $item );
			return $out;
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
