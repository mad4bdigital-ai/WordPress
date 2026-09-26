<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Installs governance-preserving ability overrides without forking WordPress Abilities.
 */
final class MAD4B_SCP_Governed_Ability_Overrides {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'filter_registration_args' ), 50, 2 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_governance_abilities' ), 30 );
	}

	public static function filter_registration_args( $args, $name ) {
		if ( ! is_array( $args ) ) return $args;
		$name = (string) $name;

		if ( 'mad4b/content-update-post' === $name ) {
			$args['execute_callback'] = array( __CLASS__, 'content_update_post' );
			$args['description'] = 'Update one WordPress post through the governed reversible mutation envelope with optimistic state validation and read-after-write verification.';
			if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
			if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
			$args['meta']['mcp']['mad4b_reversible_contract'] = 'mad4b.rollback.post.v1';
		}

		if ( 'mad4b/diagnostics-health' === $name ) {
			$args['execute_callback'] = array( __CLASS__, 'diagnostics_health_readonly' );
			$args['description'] = 'Inspect runtime health without creating backup directories, changing permissions, or preparing mutation state.';
			if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
			if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
			$args['meta']['mcp']['mad4b_observational_contract'] = 'mad4b.readonly-diagnostics.v1';
		}

		if ( in_array( $name, self::remote_oauth_sensitive_read_abilities(), true ) && isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ) {
			$original_permission = $args['permission_callback'];
			$args['permission_callback'] = static function ( $input = null ) use ( $original_permission, $name ) {
				$granted = call_user_func( $original_permission, $input );
				if ( is_wp_error( $granted ) || ! $granted ) return $granted;
				return MAD4B_SCP_Governed_Ability_Overrides::can_remote_oauth_read_ability( $name );
			};
			if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
			if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
			$args['meta']['mcp']['mad4b_remote_oauth_sensitive_read'] = true;
			$args['meta']['mcp']['mad4b_remote_oauth_default'] = 'deny';
		}
		return $args;
	}

	public static function remote_oauth_sensitive_read_abilities() {
		$abilities = array(
			'mad4b/filesystem-list',
			'mad4b/filesystem-read',
			'mad4b/database-list-tables',
			'mad4b/database-describe-table',
			'mad4b/database-select',
		);
		$filtered = apply_filters( 'mad4b_scp_remote_oauth_sensitive_read_abilities', $abilities );
		if ( ! is_array( $filtered ) ) return $abilities;
		$result = array();
		foreach ( array_slice( $filtered, 0, 200 ) as $ability ) {
			if ( is_string( $ability ) && '' !== trim( $ability ) && strlen( $ability ) <= 191 ) $result[] = trim( $ability );
		}
		return array_values( array_unique( $result ) );
	}

	public static function remote_oauth_sensitive_read_allowlist() {
		if ( ! defined( 'MAD4B_MCP_OAUTH_REMOTE_READ_ALLOWLIST' ) ) return array();
		$value = constant( 'MAD4B_MCP_OAUTH_REMOTE_READ_ALLOWLIST' );
		if ( ! is_array( $value ) ) return array();
		$restricted = self::remote_oauth_sensitive_read_abilities();
		$allowed = array();
		foreach ( array_slice( $value, 0, 200 ) as $ability ) {
			if ( is_string( $ability ) && in_array( trim( $ability ), $restricted, true ) ) $allowed[] = trim( $ability );
		}
		return array_values( array_unique( $allowed ) );
	}

	public static function can_remote_oauth_read_ability( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( ! in_array( $ability_name, self::remote_oauth_sensitive_read_abilities(), true ) ) return true;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return true;
		if ( in_array( $ability_name, self::remote_oauth_sensitive_read_allowlist(), true ) ) return true;
		return new WP_Error(
			'mad4b_remote_oauth_sensitive_read_denied',
			'Generic filesystem/database introspection is denied to remote OAuth bearers by default. Explicitly allowlist the exact read ability only when the target environment requires it.'
		);
	}

	public static function remote_oauth_read_policy_status() {
		return array(
			'contract' => 'mad4b.remote-oauth-read-policy.v1',
			'default' => 'deny_sensitive_generic_introspection',
			'restricted_abilities' => self::remote_oauth_sensitive_read_abilities(),
			'explicit_allowlist' => self::remote_oauth_sensitive_read_allowlist(),
			'local_wordpress_session_affected' => false,
			'authorization_source' => 'verified_oauth_bearer_context_plus_exact_ability',
		);
	}

	public static function diagnostics_health_readonly() {
		global $wpdb;
		$uploads = wp_upload_dir( null, false );
		$backup = self::backup_root_readonly_status();
		return array(
			'status' => 'ok',
			'contract' => 'mad4b.readonly-diagnostics.v1',
			'checks' => array(
				'database' => '1' === (string) $wpdb->get_var( 'SELECT 1' ),
				'abilities_api' => function_exists( 'wp_register_ability' ),
				'mcp_adapter' => class_exists( 'WP\\MCP\\Core\\McpAdapter' ),
				'wp_content_write' => is_writable( WP_CONTENT_DIR ),
				'plugins_write' => is_writable( WP_PLUGIN_DIR ),
				'uploads_write' => isset( $uploads['basedir'] ) ? is_writable( $uploads['basedir'] ) : false,
				'protected_backup_root' => ! empty( $backup['ready'] ),
				'breakglass_enabled' => MAD4B_SCP_Policy::can_breakglass(),
			),
			'backup_root' => $backup,
			'observational_only' => true,
			'prepares_backup_root' => false,
		);
	}

	private static function backup_root_readonly_status() {
		$path = (string) MAD4B_SCP_Policy::backup_root();
		$exists = '' !== $path && is_dir( $path );
		$resolved = $exists ? realpath( $path ) : false;
		$candidate = false !== $resolved ? $resolved : '';
		if ( '' === $candidate && '' !== $path ) {
			$parent = realpath( dirname( $path ) );
			if ( false !== $parent ) $candidate = $parent . DIRECTORY_SEPARATOR . basename( $path );
		}
		$safe_location = '' !== $candidate;
		$normalized = str_replace( '\\', '/', (string) $candidate );
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $web_root ) {
			$web = realpath( $web_root );
			if ( false === $web ) continue;
			$web = rtrim( str_replace( '\\', '/', $web ), '/' );
			if ( $normalized === $web || 0 === strpos( $normalized, $web . '/' ) ) $safe_location = false;
		}
		return array(
			'configured' => '' !== $path,
			'exists' => $exists,
			'writable' => $exists && is_writable( $path ),
			'safe_location' => $safe_location,
			'ready' => $exists && is_writable( $path ) && $safe_location,
			'requires_preparation' => ! $exists,
			'path_disclosed' => false,
		);
	}

	public static function content_update_post( $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Mutation_Manager' ) ) return new WP_Error( 'mad4b_mutation_manager_unavailable', 'Reversible mutation manager is unavailable.' );
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_mutation_input_invalid', 'Post mutation input must be an object.' );
		return MAD4B_SCP_Mutation_Manager::execute_post_update( $input );
	}

	public static function register_governance_abilities() {
		if ( ! wp_has_ability( 'mad4b/mutation-get' ) ) {
			wp_register_ability(
				'mad4b/mutation-get',
				array(
					'label' => 'Get Mutation Evidence',
					'description' => 'Read bounded governance evidence for one MAD4B mutation without returning rollback payload contents.',
					'category' => 'mad4b-admin',
					'execute_callback' => array( __CLASS__, 'mutation_get' ),
					'permission_callback' => array( __CLASS__, 'can_inspect_mutation' ),
					'input_schema' => self::schema( array( 'mutation_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 64 ) ), array( 'mutation_id' ) ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( true, false, true ),
				)
			);
		}

		if ( ! wp_has_ability( 'mad4b/mutation-undo' ) ) {
			wp_register_ability(
				'mad4b/mutation-undo',
				array(
					'label' => 'Undo Verified Mutation',
					'description' => 'Restore a certified core or adapter reversible mutation only when the current target still matches the recorded after-state.',
					'category' => 'mad4b-admin',
					'execute_callback' => array( __CLASS__, 'mutation_undo' ),
					'permission_callback' => array( __CLASS__, 'can_undo_mutation' ),
					'input_schema' => self::schema(
						array(
							'mutation_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 64 ),
							'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
						),
						array( 'mutation_id', 'reason' )
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::meta( false, true, false ),
				)
			);
		}
	}

	public static function can_inspect_mutation( $input = null ) { return current_user_can( 'manage_options' ); }

	public static function can_undo_mutation( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return false;
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'Mutation/NHI authority is required for undo.' );
		if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );
		$authorization = MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
		return is_wp_error( $authorization ) ? $authorization : true;
	}

	public static function mutation_get( $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Mutation_Manager' ) ) return new WP_Error( 'mad4b_mutation_manager_unavailable', 'Reversible mutation manager is unavailable.' );
		$record = MAD4B_SCP_Mutation_Manager::get( isset( $input['mutation_id'] ) ? (string) $input['mutation_id'] : '' );
		if ( ! $record ) return new WP_Error( 'mad4b_mutation_missing', 'Mutation record was not found.' );
		unset( $record['rollback_payload'], $record['rollback_payload_sha256'] );
		if ( isset( $record['subject_fingerprint'] ) ) $record['subject_fingerprint'] = substr( (string) $record['subject_fingerprint'], 0, 16 );
		return array( 'mutation' => $record );
	}

	public static function mutation_undo( $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Mutation_Manager' ) ) return new WP_Error( 'mad4b_mutation_manager_unavailable', 'Reversible mutation manager is unavailable.' );
		$mutation_id = isset( $input['mutation_id'] ) ? (string) $input['mutation_id'] : '';
		$reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';
		MAD4B_SCP_Audit::record( 'mad4b/mutation-undo-intent', array( 'mutation_id' => $mutation_id, 'reason' => $reason ) );
		$record = MAD4B_SCP_Mutation_Manager::get( $mutation_id );
		if ( ! $record ) return new WP_Error( 'mad4b_mutation_missing', 'Mutation record was not found.' );
		if ( 'mad4b/content-update-post' === $record['ability_name'] && 'post' === $record['target_type'] ) {
			$result = MAD4B_SCP_Mutation_Manager::undo_post_mutation( $mutation_id );
		} elseif ( class_exists( 'MAD4B_SCP_Reversible_Adapter_Mutations' ) && MAD4B_SCP_Reversible_Adapter_Mutations::can_undo_record( $record ) ) {
			$result = MAD4B_SCP_Reversible_Adapter_Mutations::undo( $mutation_id );
		} else {
			$result = new WP_Error( 'mad4b_undo_not_supported', 'This mutation does not use a currently registered certified rollback contract.' );
		}
		if ( ! is_wp_error( $result ) && is_array( $result ) ) $result['reason'] = $reason;
		return $result;
	}

	private static function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}

	private static function meta( $readonly, $destructive, $idempotent ) {
		return array(
			'public' => false,
			'show_in_rest' => false,
			'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
			'annotations' => array( 'readonly' => (bool) $readonly, 'destructive' => $readonly ? (bool) $destructive : true, 'idempotent' => (bool) $idempotent ),
		);
	}
}
