<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Request-generation fence for long-lived PHP processes.
 *
 * Ordinary PHP-FPM requests naturally start with clean statics. Long-lived
 * workers do not. This class binds request-local caches to one logical request
 * boundary and one authoritative context snapshot. It never grants authority.
 */
final class MAD4B_SCP_Request_Generation {
	const CONTRACT = 'mad4b.request-scope-generation.v1';
	private static $explicit_boundary = '';
	private static $boundary_sha256 = '';
	private static $context_sha256 = '';
	private static $context = array();
	private static $generation = 0;
	private static $reset_count = 0;

	public static function begin_long_lived_request( $token, $surface = 'long_lived_worker' ) {
		$token = trim( (string) $token );
		if ( '' === $token || strlen( $token ) > 191 ) {
			return new WP_Error( 'mad4b_request_scope_boundary_invalid', 'A bounded logical request-boundary token is required.' );
		}
		self::$explicit_boundary = hash( 'sha256', $token );
		return self::admit( $surface );
	}

	public static function clear_long_lived_boundary() {
		self::$explicit_boundary = '';
	}

	public static function admit( $surface = 'runtime' ) {
		$surface = sanitize_key( (string) $surface );
		if ( '' === $surface ) $surface = 'runtime';
		$boundary = self::boundary_sha256();
		$context = self::context_snapshot();
		$context_sha = self::digest( $context );
		if ( '' === $context_sha ) return new WP_Error( 'mad4b_request_scope_context_unavailable', 'Request context could not be canonically fingerprinted.' );

		if ( '' === self::$boundary_sha256 ) {
			self::$boundary_sha256 = $boundary;
			self::$context_sha256 = $context_sha;
			self::$context = $context;
			self::$generation = 1;
			return self::receipt( $surface, false );
		}

		if ( hash_equals( self::$boundary_sha256, $boundary ) ) {
			if ( ! hash_equals( self::$context_sha256, $context_sha ) ) {
				return new WP_Error(
					'mad4b_request_scope_context_drift',
					'Authoritative request context changed inside one logical request; use a fresh request before further governed work.',
					array(
						'changed_fields' => self::changed_fields( self::$context, $context ),
						'fresh_request_required' => true,
						'authorizing' => false,
					)
				);
			}
			return self::receipt( $surface, false );
		}

		$worker_changes = array_intersect(
			self::changed_fields( self::$context, $context ),
			array( 'blog_id', 'home', 'siteurl', 'environment', 'runtime_version', 'runtime_file_sha256' )
		);
		if ( $worker_changes ) {
			return new WP_Error(
				'mad4b_request_scope_worker_recycle_required',
				'Site/runtime identity changed across logical requests; recycle this worker before serving the new context.',
				array( 'changed_fields' => array_values( $worker_changes ), 'authorizing' => false )
			);
		}

		$safe = self::assert_transition_safe();
		if ( is_wp_error( $safe ) ) return $safe;
		$reset = self::reset_owners();
		if ( is_wp_error( $reset ) ) return $reset;

		$context = self::context_snapshot();
		$context_sha = self::digest( $context );
		if ( '' === $context_sha ) return new WP_Error( 'mad4b_request_scope_context_unavailable', 'Request context could not be re-fingerprinted after reset.' );
		self::$boundary_sha256 = $boundary;
		self::$context_sha256 = $context_sha;
		self::$context = $context;
		self::$generation++;
		self::$reset_count++;
		return self::receipt( $surface, true, $reset );
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'generation' => self::$generation,
			'boundary_sha256' => self::$boundary_sha256,
			'context_sha256' => self::$context_sha256,
			'reset_count' => self::$reset_count,
			'explicit_long_lived_boundary' => '' !== self::$explicit_boundary,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function assert_transition_safe() {
		if ( class_exists( 'MAD4B_SCP_Database_Transaction_Guard' ) ) {
			$tx = MAD4B_SCP_Database_Transaction_Guard::transaction_state();
			if ( is_wp_error( $tx ) ) return $tx;
			if ( 0 !== $tx ) return new WP_Error( 'mad4b_request_scope_transaction_active', 'A database transaction is active across the logical request boundary.' );
		}
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && method_exists( 'MAD4B_SCP_Identity_Context', 'request_scope_state' ) ) {
			$state = MAD4B_SCP_Identity_Context::request_scope_state();
			if ( ! empty( $state['approval_ticket_bound'] ) || ! empty( $state['subject_override_active'] ) ) {
				return new WP_Error( 'mad4b_request_scope_identity_active', 'Request identity overlays are still active across the logical request boundary.' );
			}
		}
		if ( class_exists( 'MAD4B_SCP_Authorization' ) && method_exists( 'MAD4B_SCP_Authorization', 'request_scope_state' ) ) {
			$state = MAD4B_SCP_Authorization::request_scope_state();
			if ( ! empty( $state['active_execution_observations'] ) ) {
				return new WP_Error( 'mad4b_request_scope_execution_active', 'An execution callback remains active across the logical request boundary.' );
			}
		}
		if ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'request_scope_state' ) ) {
			$state = MAD4B_SCP_Staging_Write_Authority::request_scope_state();
			if ( ! empty( $state['reconciling'] ) ) return new WP_Error( 'mad4b_request_scope_reconciliation_active', 'Write-authority reconciliation remains active across the logical request boundary.' );
		}
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope' ) && method_exists( 'MAD4B_SCP_MCP_Request_Scope', 'request_scope_transition_safe' ) ) {
			$safe = MAD4B_SCP_MCP_Request_Scope::request_scope_transition_safe();
			if ( is_wp_error( $safe ) ) return $safe;
		}
		return true;
	}

	private static function reset_owners() {
		$owners = array(
			array( 'MAD4B_SCP_Site_Profile', 'reset_cache' ),
			array( 'MAD4B_SCP_Schema', 'reset_request_cache' ),
			array( 'MAD4B_SCP_Agent_Registry', 'reset_request_cache' ),
			array( 'MAD4B_SCP_Operation_Registry', 'reset_request_cache' ),
			array( 'MAD4B_SCP_Policy_Resolution', 'reset_request_cache' ),
			array( 'MAD4B_SCP_MCP_Client_Profile_Registry', 'reset_request_cache' ),
			array( 'MAD4B_SCP_Servers', 'reset_request_cache' ),
			array( 'MAD4B_SCP_Staging_Write_Authority', 'reset_request_cache' ),
			array( 'MAD4B_SCP_Authorization', 'reset_request_cache' ),
			array( 'MAD4B_SCP_Abilities', 'reset_request_cache' ),
			array( 'MAD4B_SCP_Identity_Context', 'reset_request_cache' ),
			array( 'MAD4B_SCP_MCP_Request_Scope', 'reset_request_cache' ),
		);
		$reset = array();
		foreach ( $owners as $owner ) {
			if ( ! class_exists( $owner[0], false ) || ! method_exists( $owner[0], $owner[1] ) ) continue;
			$result = call_user_func( $owner );
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'mad4b_request_scope_reset_failed',
					'A request-local cache owner refused reset.',
					array( 'owner' => $owner[0], 'cause' => $result->get_error_code(), 'authorizing' => false )
				);
			}
			$reset[] = $owner[0];
		}
		return $reset;
	}

	private static function boundary_sha256() {
		if ( '' !== self::$explicit_boundary ) return self::$explicit_boundary;
		$basis = array(
			'sapi' => defined( 'PHP_SAPI' ) ? PHP_SAPI : '',
			'request_time' => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (string) $_SERVER['REQUEST_TIME_FLOAT'] : ( isset( $_SERVER['REQUEST_TIME'] ) ? (string) $_SERVER['REQUEST_TIME'] : '' ),
			'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( trim( (string) $_SERVER['REQUEST_METHOD'] ) ) : '',
			'uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
			'wp_cli' => defined( 'WP_CLI' ) && WP_CLI,
			'argv_sha256' => isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ? hash( 'sha256', implode( "\0", array_map( 'strval', $_SERVER['argv'] ) ) ) : '',
		);
		if ( '' === $basis['request_time'] ) $basis['process_id'] = function_exists( 'getmypid' ) ? (int) getmypid() : 0;
		return self::digest( $basis );
	}

	private static function context_snapshot() {
		$profile = class_exists( 'MAD4B_SCP_Site_Profile', false ) ? get_option( MAD4B_SCP_Site_Profile::OPTION, null ) : null;
		$projection = class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection', false ) ? get_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, null ) : null;
		$user_caps = array();
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( is_object( $user ) && isset( $user->allcaps ) && is_array( $user->allcaps ) ) {
				foreach ( $user->allcaps as $cap => $allowed ) if ( $allowed ) $user_caps[] = (string) $cap;
				sort( $user_caps, SORT_STRING );
			}
		}
		$runtime_sha = '';
		if ( defined( 'MAD4B_SCP_FILE' ) && is_readable( MAD4B_SCP_FILE ) ) {
			$value = hash_file( 'sha256', MAD4B_SCP_FILE );
			if ( is_string( $value ) ) $runtime_sha = strtolower( $value );
		}
		return array(
			'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'user_caps_sha256' => self::digest( $user_caps ),
			'environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
			'home' => function_exists( 'get_option' ) ? (string) get_option( 'home', '' ) : '',
			'siteurl' => function_exists( 'get_option' ) ? (string) get_option( 'siteurl', '' ) : '',
			'site_profile_sha256' => self::digest( $profile ),
			'projection_sha256' => self::digest( $projection ),
			'runtime_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'runtime_file_sha256' => $runtime_sha,
		);
	}

	private static function changed_fields( array $before, array $after ) {
		$keys = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
		sort( $keys, SORT_STRING );
		$changed = array();
		foreach ( $keys as $key ) {
			$a = array_key_exists( $key, $before ) ? self::digest( $before[ $key ] ) : '';
			$b = array_key_exists( $key, $after ) ? self::digest( $after[ $key ] ) : '';
			if ( $a !== $b ) $changed[] = (string) $key;
		}
		return $changed;
	}

	private static function receipt( $surface, $reset_performed, array $reset_owners = array() ) {
		return array(
			'contract' => self::CONTRACT,
			'generation' => self::$generation,
			'boundary_sha256' => self::$boundary_sha256,
			'context_sha256' => self::$context_sha256,
			'surface' => $surface,
			'reset_performed' => (bool) $reset_performed,
			'reset_owners' => array_values( $reset_owners ),
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function digest( $value ) {
		$canonical = self::canonicalize( $value );
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			$keys = array_keys( $value );
			$is_list = $keys === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$out = array();
				foreach ( $value as $item ) $out[] = self::canonicalize( $item );
				return $out;
			}
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) $out[ (string) $key ] = self::canonicalize( $value[ $key ] );
			return $out;
		}
		if ( is_object( $value ) ) return self::canonicalize( get_object_vars( $value ) );
		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) || null === $value ) return $value;
		if ( is_float( $value ) && is_finite( $value ) ) return $value;
		return (string) $value;
	}
}
