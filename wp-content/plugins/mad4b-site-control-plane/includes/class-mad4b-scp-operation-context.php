<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Operation_Context {
	const CONTRACT = 'mad4b.dynamic-operation-context.v1';
	const BINDING_CONTRACT = 'dynamic-operation-binding:v1';

	public static function create( $operation_key, array $binding_basis, array $args = array() ) {
		$operation_key = trim( (string) $operation_key );
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{8,191}$/', $operation_key ) ) return new WP_Error( 'mad4b_operation_key_invalid', 'Stable operation_key is required.' );
		$binding_basis['operation_key'] = $operation_key;
		$binding = MAD4B_SCP_Canonicalization::digest( self::BINDING_CONTRACT, $binding_basis );
		if ( is_wp_error( $binding ) ) return $binding;
		$operation_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::uuid4();
		$now = time();
		$hard_seconds = isset( $args['hard_deadline_seconds'] ) ? max( 60, min( 7200, absint( $args['hard_deadline_seconds'] ) ) ) : 1800;
		return array(
			'contract' => self::CONTRACT,
			'operation_key' => $operation_key,
			'operation_id' => $operation_id,
			'operation_binding_sha256' => $binding,
			'binding_basis' => $binding_basis,
			'started_at' => gmdate( 'c', $now ),
			'heartbeat_at' => gmdate( 'c', $now ),
			'hard_deadline_at' => gmdate( 'c', $now + $hard_seconds ),
			'hard_deadline_seconds' => $hard_seconds,
		);
	}

	private static function uuid4() {
		$data = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
