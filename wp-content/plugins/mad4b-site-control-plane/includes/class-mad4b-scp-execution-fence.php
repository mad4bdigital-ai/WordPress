<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Request-local idempotence fence for governed mutation execution.
 *
 * The fence never grants mutation authority and never changes approval state.
 * It only prevents the exact same ticket/target from entering the execution
 * callback more than once inside one logical request. Independent requests
 * continue through the canonical approval replay checks.
 */
final class MAD4B_SCP_Execution_Fence {
	const CONTRACT = 'mad4b.same-request-execution-fence.v1';

	private static $booted = false;
	private static $entries = array();

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'wrap_governed_write' ), 200, 2 );
	}

	public static function wrap_governed_write( $args, $name ) {
		if ( ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) return $args;
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return $args;
		if ( empty( $mcp['mad4b_execution_boundary'] ) || ! empty( $mcp['mad4b_same_request_execution_fence'] ) ) return $args;

		$declared_server = self::declared_server( $args );
		$original_execute = $args['execute_callback'];
		$original_permission = isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ? $args['permission_callback'] : null;

		if ( $original_permission ) {
			$args['permission_callback'] = static function ( $input = null ) use ( $original_permission, $name, $declared_server ) {
				$key = MAD4B_SCP_Execution_Fence::key_for( $name, $declared_server, $input );
				if ( '' !== $key && isset( MAD4B_SCP_Execution_Fence::$entries[ $key ] ) ) {
					$entry = MAD4B_SCP_Execution_Fence::$entries[ $key ];
					if ( 'in_progress' === $entry['state'] ) {
						return new WP_Error( 'mad4b_execution_reentry_denied', 'The exact governed mutation is already executing in this request.' );
					}
					if ( 'completed' === $entry['state'] ) return true;
				}
				return call_user_func( $original_permission, $input );
			};
		}

		$args['execute_callback'] = static function ( $input = null ) use ( $original_execute, $name, $declared_server ) {
			$key = MAD4B_SCP_Execution_Fence::key_for( $name, $declared_server, $input );
			if ( '' === $key ) return call_user_func( $original_execute, $input );

			if ( isset( MAD4B_SCP_Execution_Fence::$entries[ $key ] ) ) {
				$entry = MAD4B_SCP_Execution_Fence::$entries[ $key ];
				if ( 'in_progress' === $entry['state'] ) {
					return new WP_Error( 'mad4b_execution_reentry_denied', 'The exact governed mutation is already executing in this request.' );
				}
				if ( 'completed' === $entry['state'] && array_key_exists( 'result', $entry ) ) return $entry['result'];
			}

			MAD4B_SCP_Execution_Fence::$entries[ $key ] = array( 'state' => 'in_progress' );
			try {
				$result = call_user_func( $original_execute, $input );
			} catch ( Throwable $throwable ) {
				MAD4B_SCP_Execution_Fence::$entries[ $key ] = array(
					'state' => 'completed',
					'result' => new WP_Error( 'mad4b_execution_exception', 'Governed mutation threw before a reusable result was available.' ),
				);
				throw $throwable;
			}
			MAD4B_SCP_Execution_Fence::$entries[ $key ] = array( 'state' => 'completed', 'result' => $result );
			return $result;
		};

		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
		$args['meta']['mcp']['mad4b_same_request_execution_fence'] = self::CONTRACT;
		return $args;
	}

	public static function key_for( $ability_name, $declared_server, $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Authorization' ) ) return '';
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) || ! is_array( $identity ) ) return '';
		$request_id = isset( $identity['request_id'] ) ? trim( (string) $identity['request_id'] ) : '';
		if ( '' === $request_id ) return '';

		$input_ticket = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $input ) : '';
		$identity_ticket = isset( $identity['approval_ticket_id'] ) ? strtolower( trim( (string) $identity['approval_ticket_id'] ) ) : '';
		$ticket_id = '' !== $input_ticket ? $input_ticket : $identity_ticket;
		if ( '' === $ticket_id || ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) ) return '';

		$provider = MAD4B_SCP_Authorization::provider_for_execution( $ability_name, $declared_server );
		if ( is_wp_error( $provider ) ) return '';
		$authorization_input = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::authorization_input( $input ) : $input;
		$agent = array();
		if ( class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$resolved = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
			if ( is_array( $resolved ) ) $agent = $resolved;
		}
		$target = MAD4B_SCP_Authorization::target_fingerprint( $ability_name, $provider, $authorization_input, $agent, $identity );
		if ( '' === $target ) return '';

		$payload = array(
			'contract' => self::CONTRACT,
			'request_id' => substr( $request_id, 0, 64 ),
			'approval_ticket_id' => $ticket_id,
			'ability' => (string) $ability_name,
			'provider' => (string) $provider,
			'target_fingerprint' => $target,
		);
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? '' : hash( 'sha256', $encoded );
	}

	private static function declared_server( array $args ) {
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		$surface = isset( $mcp['surface'] ) ? sanitize_key( (string) $mcp['surface'] ) : '';
		if ( in_array( $surface, array( 'content', 'admin', 'breakglass' ), true ) ) return 'mad4b-' . $surface;
		$category = isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : '';
		if ( in_array( $category, array( 'mad4b-content', 'mad4b-admin', 'mad4b-breakglass' ), true ) ) return $category;
		return 'mad4b-write';
	}
}

require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-acceptance-reconciler.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-portable-snapshot-attestation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-snapshot-finalizer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-wpml-acceptance-finalizer.php';

if ( function_exists( 'add_filter' ) ) MAD4B_SCP_Execution_Fence::boot();
