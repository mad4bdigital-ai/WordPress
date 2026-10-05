<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Authorization_Target_Fingerprint {
	const CONTRACT = 'mad4b.authorization-target.v1';
	const MAX_CANONICAL_BYTES = 65536;
	const MAX_DEPTH = 8;

	public static function fingerprint( $ability_name, $provider, $input, array $agent = array(), array $identity = array() ) {
		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider ) $provider = 'core';
		$filtered = apply_filters( 'mad4b_scp_authorization_target_fingerprint', '', (string) $ability_name, $provider, $input, $agent, $identity );
		if ( is_string( $filtered ) && '' !== trim( $filtered ) ) return substr( trim( $filtered ), 0, 191 );

		$normalized = self::canonicalize( $input, 0 );
		if ( is_wp_error( $normalized ) ) return '';
		$resource_set = class_exists( 'MAD4B_SCP_Resource_Constraint_Set' )
			? MAD4B_SCP_Resource_Constraint_Set::compile( $ability_name, $provider, is_array( $input ) ? $input : array() )
			: array();
		if ( is_wp_error( $resource_set ) ) return '';
		$payload = array(
			'contract' => self::CONTRACT,
			'ability' => (string) $ability_name,
			'provider' => $provider,
			'resource_set_sha256' => isset( $resource_set['resource_set_sha256'] ) ? (string) $resource_set['resource_set_sha256'] : '',
			'input' => $normalized,
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || strlen( $json ) > self::MAX_CANONICAL_BYTES ) return '';
		return hash( 'sha256', $json );
	}

	public static function canonicalize( $value, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) return new WP_Error( 'mad4b_target_fingerprint_too_deep', 'Mutation target input exceeds the maximum canonical nesting depth.' );
		if ( is_array( $value ) ) {
			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$out = array();
				foreach ( $value as $item ) {
					$normalized = self::canonicalize( $item, $depth + 1 );
					if ( is_wp_error( $normalized ) ) return $normalized;
					$out[] = $normalized;
				}
				return $out;
			}
			$keys = array_keys( $value );
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) {
				if ( ! is_string( $key ) && ! is_int( $key ) ) return new WP_Error( 'mad4b_target_fingerprint_invalid_key', 'Mutation target input contains an unsupported object key.' );
				$normalized = self::canonicalize( $value[ $key ], $depth + 1 );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$out[ (string) $key ] = $normalized;
			}
			return $out;
		}
		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) || null === $value ) return $value;
		if ( is_float( $value ) && is_finite( $value ) ) return $value;
		return new WP_Error( 'mad4b_target_fingerprint_invalid_value', 'Mutation target input contains an unsupported value type.' );
	}
}
