<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Signed preparation evidence, never a ticket, grant, idempotency key or approval. */
final class MAD4B_SCP_Preparation_Receipt {
	const CONTRACT = 'mad4b.preparation-receipt.v1';
	const TTL = 300;
	const MAX_BYTES = 4096;
	private static function key() { return hash_hmac( 'sha256', self::CONTRACT, wp_salt( 'auth' ), true ); }
	private static function failure() { return new WP_Error( 'mad4b_preparation_receipt_invalid', 'Preparation changed or expired. Prepare the capability again; live authority is still required.' ); }
	public static function issue( array $row ) {
		if ( empty( $row['execution_eligible'] ) || empty( $row['descriptor_sha256'] ) ) return '';
		$now = time();
		$payload = array(
			'contract' => self::CONTRACT,
			'ability_name' => $row['ability_name'],
			'descriptor_sha256' => $row['descriptor_sha256'],
			'authority_scope_sha256' => MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(),
			'issued_at' => $now,
			'expires_at' => $now + self::TTL,
		);
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) ) return '';
		$body = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		$token = $body . '.' . hash_hmac( 'sha256', $body, self::key() );
		return strlen( $token ) <= self::MAX_BYTES ? $token : '';
	}
	public static function verify( $token, $name ) {
		if ( ! is_string( $token ) || strlen( $token ) > self::MAX_BYTES || 1 !== preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $parts ) ) return self::failure();
		if ( ! hash_equals( hash_hmac( 'sha256', $parts[1], self::key() ), $parts[2] ) ) return self::failure();
		$json = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
		$p = is_string( $json ) ? json_decode( $json, true, 8 ) : null;
		$now = time();
		if ( ! is_array( $p ) || ( $p['contract'] ?? '' ) !== self::CONTRACT || ( $p['ability_name'] ?? '' ) !== $name || ! isset( $p['issued_at'], $p['expires_at'] ) || ! is_int( $p['issued_at'] ) || ! is_int( $p['expires_at'] ) || $p['issued_at'] > $now || $p['expires_at'] <= $now || $p['expires_at'] - $p['issued_at'] !== self::TTL ) return self::failure();
		foreach ( array( 'descriptor_sha256', 'authority_scope_sha256' ) as $key ) if ( ! isset( $p[$key] ) || ! is_string( $p[$key] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $p[$key] ) ) return self::failure();
		if ( ! hash_equals( MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(), $p['authority_scope_sha256'] ) ) return self::failure();
		$row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $name );
		if ( is_wp_error( $row ) || empty( $row['execution_eligible'] ) || ! hash_equals( $row['descriptor_sha256'], $p['descriptor_sha256'] ) ) return self::failure();
		return true;
	}
}
