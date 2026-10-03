<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Machine-readable error contract normalization.
 *
 * Human messages are diagnostic only. The WP_Error code remains canonical and
 * this layer only appends non-authorizing response metadata.
 */
final class MAD4B_SCP_Error_Contract_Registry {
	const REGISTRY_CONTRACT = 'mad4b.error-reason-code-registry.v1';
	const RESPONSE_CONTRACT = 'mad4b.error-response.v1';
	private static $booted = false;
	private static $registry = null;
	private static $registry_sha256 = '';

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_filter' ) ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_ability_args' ), 195, 2 );
	}

	public static function registry() {
		if ( is_array( self::$registry ) ) return self::$registry;
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'config/error-reason-codes.json' : '';
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'mad4b_error_registry_unavailable', 'Machine-readable error registry is unavailable.' );
		}
		$raw = file_get_contents( $path );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data )
			|| self::REGISTRY_CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' )
			|| 1 !== (int) ( isset( $data['schema_version'] ) ? $data['schema_version'] : 0 )
			|| self::RESPONSE_CONTRACT !== ( isset( $data['response_contract'] ) ? (string) $data['response_contract'] : '' )
			|| ! isset( $data['families'] ) || ! is_array( $data['families'] ) ) {
			return new WP_Error( 'mad4b_error_registry_invalid', 'Machine-readable error registry is invalid.' );
		}
		self::$registry = $data;
		self::$registry_sha256 = hash( 'sha256', (string) $raw );
		return self::$registry;
	}

	public static function status() {
		$registry = self::registry();
		if ( is_wp_error( $registry ) ) return $registry;
		return array(
			'contract' => self::REGISTRY_CONTRACT,
			'response_contract' => self::RESPONSE_CONTRACT,
			'schema_version' => (int) $registry['schema_version'],
			'registry_sha256' => self::$registry_sha256,
			'exact_code_count' => isset( $registry['exact_codes'] ) && is_array( $registry['exact_codes'] ) ? count( $registry['exact_codes'] ) : 0,
			'family_count' => count( $registry['families'] ),
			'message_is_contractual' => false,
			'authorizing' => false,
		);
	}

	public static function classify( $code ) {
		$registry = self::registry();
		if ( is_wp_error( $registry ) ) return $registry;
		$code = sanitize_key( (string) $code );
		$exact = isset( $registry['exact_codes'] ) && is_array( $registry['exact_codes'] ) ? $registry['exact_codes'] : array();
		if ( isset( $exact[ $code ] ) && is_array( $exact[ $code ] ) ) {
			return array_merge(
				array( 'reason_code' => $code, 'registered_exact' => true ),
				$exact[ $code ]
			);
		}
		$best = null;
		$best_len = -1;
		foreach ( $registry['families'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['prefix'] ) ) continue;
			$prefix = (string) $row['prefix'];
			if ( 0 === strpos( $code, $prefix ) && strlen( $prefix ) > $best_len ) {
				$best = $row;
				$best_len = strlen( $prefix );
			}
		}
		if ( ! is_array( $best ) ) {
			return array(
				'reason_code' => $code,
				'registered_exact' => false,
				'family' => 'unregistered',
				'retry_class' => 'inspect_state',
				'client_action' => 'inspect_machine_readable_error_contract',
			);
		}
		$best['reason_code'] = $code;
		$best['registered_exact'] = false;
		unset( $best['prefix'] );
		return $best;
	}

	public static function enrich_wp_error( $error ) {
		if ( ! is_wp_error( $error ) ) return $error;
		$code = sanitize_key( (string) $error->get_error_code() );
		$class = self::classify( $code );
		if ( is_wp_error( $class ) ) return $error;
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = null === $data || false === $data || '' === $data ? array() : array( 'legacy_error_data' => $data );
		}
		$registry = self::registry();
		if ( is_wp_error( $registry ) ) return $error;
		$data['mad4b_error'] = array(
			'contract' => self::RESPONSE_CONTRACT,
			'schema_version' => (int) $registry['schema_version'],
			'registry_sha256' => self::$registry_sha256,
			'reason_code' => $code,
			'family' => isset( $class['family'] ) ? (string) $class['family'] : 'unregistered',
			'retry_class' => isset( $class['retry_class'] ) ? (string) $class['retry_class'] : 'inspect_state',
			'client_action' => isset( $class['client_action'] ) ? (string) $class['client_action'] : 'inspect_machine_readable_error_contract',
			'registered_exact' => ! empty( $class['registered_exact'] ),
			'message_is_contractual' => false,
			'authorizing' => false,
		);
		if ( ! array_key_exists( 'reason_code', $data ) ) $data['reason_code'] = $code;
		if ( method_exists( $error, 'add_data' ) ) {
			$error->add_data( $data, $code );
			return $error;
		}
		return new WP_Error( $code, $error->get_error_message(), $data );
	}

	public static function wrap_ability_args( $args, $name ) {
		if ( ! is_array( $args ) ) return $args;
		foreach ( array( 'permission_callback', 'execute_callback' ) as $field ) {
			if ( empty( $args[ $field ] ) || ! is_callable( $args[ $field ] ) ) continue;
			$original = $args[ $field ];
			$wrapped = static function ( $input = null ) use ( $original ) {
				return MAD4B_SCP_Error_Contract_Registry::enrich_wp_error( call_user_func( $original, $input ) );
			};
			if ( 'execute_callback' === $field
				&& class_exists( 'MAD4B_SCP_Authorization' )
				&& method_exists( 'MAD4B_SCP_Authorization', 'propagate_trusted_execution_boundary' ) ) {
				MAD4B_SCP_Authorization::propagate_trusted_execution_boundary( (string) $name, $wrapped, $original );
			}
			$args[ $field ] = $wrapped;
		}
		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_error_contract'] = self::RESPONSE_CONTRACT;
		return $args;
	}
}
