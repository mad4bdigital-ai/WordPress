<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Canonicalization {
	const CONTRACT = 'mad4b.dynamic-canonicalization.v1';

	public static function canonical_json( $value ) {
		$normalized = self::normalize( $value );
		if ( is_wp_error( $normalized ) ) return $normalized;
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? new WP_Error( 'mad4b_canonical_json_failed', 'Unable to encode canonical JSON.' ) : (string) $json;
	}

	public static function digest( $contract, $value ) {
		$contract = trim( (string) $contract );
		if ( '' === $contract || strlen( $contract ) > 191 ) return new WP_Error( 'mad4b_canonical_contract_invalid', 'Canonical hash contract is invalid.' );
		$json = self::canonical_json( $value );
		if ( is_wp_error( $json ) ) return $json;
		return hash( 'sha256', 'mad4b:' . $contract . "\n" . $json );
	}

	private static function normalize( $value ) {
		if ( is_float( $value ) ) return new WP_Error( 'mad4b_canonical_float_denied', 'Floating-point values require an explicit field normalization contract.' );
		if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) return $value;
		if ( is_object( $value ) ) $value = get_object_vars( $value );
		if ( ! is_array( $value ) ) return new WP_Error( 'mad4b_canonical_type_unsupported', 'Canonicalization supports JSON-compatible values only.' );
		if ( self::is_list( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$normalized = self::normalize( $item );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$out[] = $normalized;
			}
			return $out;
		}
		$keys = array_map( 'strval', array_keys( $value ) );
		sort( $keys, SORT_STRING );
		$out = array();
		foreach ( $keys as $key ) {
			$normalized = self::normalize( $value[ $key ] );
			if ( is_wp_error( $normalized ) ) return $normalized;
			$out[ $key ] = $normalized;
		}
		return $out;
	}

	private static function is_list( array $value ) {
		$expected = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $expected ) return false;
			$expected++;
		}
		return true;
	}
}
