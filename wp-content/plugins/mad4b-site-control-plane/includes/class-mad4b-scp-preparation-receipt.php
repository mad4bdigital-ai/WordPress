<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'MAD4B_SCP_Entropy' ) ) require_once __DIR__ . '/class-mad4b-scp-entropy.php';
if ( ! class_exists( 'MAD4B_SCP_Time_Policy' ) ) require_once __DIR__ . '/class-mad4b-scp-time-policy.php';
/** Signed preparation evidence, never a ticket, grant, idempotency key or approval. */
final class MAD4B_SCP_Preparation_Receipt {
	const CONTRACT = 'mad4b.preparation-receipt.v1';
	const CRYPTO_PURPOSE = 'preparation_receipt';
	const TTL = 300;
	const MAX_BYTES = 4096;

	private static function failure() { return new WP_Error( 'mad4b_preparation_receipt_invalid', 'Preparation changed or expired. Prepare the capability again; live authority is still required.' ); }

	public static function issue( array $row ) {
		if ( ! class_exists( 'MAD4B_SCP_Abuse_Budget' ) ) return new WP_Error( 'mad4b_abuse_budget_unavailable', 'Preparation abuse-budget runtime is unavailable.' );
		$budget = MAD4B_SCP_Abuse_Budget::admit( 'prepare', $row );
		if ( is_wp_error( $budget ) ) return $budget;
		if ( empty( $row['execution_eligible'] ) || empty( $row['descriptor_sha256'] ) ) return '';
		if ( ! class_exists( 'MAD4B_SCP_Crypto_Profile' ) ) return new WP_Error( 'mad4b_preparation_crypto_unavailable', 'Versioned preparation receipt cryptography is unavailable.' );

		$now = self::now_epoch();
		$nonce = MAD4B_SCP_Entropy::hex( 'preparation_receipt_nonce', 16 );
		if ( is_wp_error( $nonce ) ) return new WP_Error( 'mad4b_preparation_entropy_unavailable', 'Preparation receipt entropy is unavailable.' );

		$payload = array(
			'contract' => self::CONTRACT,
			'nonce' => $nonce,
			'ability_name' => $row['ability_name'],
			'descriptor_sha256' => $row['descriptor_sha256'],
			'authority_scope_sha256' => MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(),
			'issued_at' => $now,
			'expires_at' => $now + self::ttl(),
		);
		if ( class_exists( 'MAD4B_SCP_Replay_Policy' ) ) {
			$replay_policy_sha256 = MAD4B_SCP_Replay_Policy::policy_sha256();
			if ( '' === $replay_policy_sha256 ) return new WP_Error( 'mad4b_preparation_replay_policy_unavailable', 'Preparation replay policy digest is unavailable.' );
			$payload['replay_policy_sha256'] = $replay_policy_sha256;
		}

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_preparation_receipt_encode_failed', 'Preparation receipt payload could not be encoded.' );
		$body = self::b64url_encode( $json );
		$digest = hash( 'sha256', $body );
		$signature = MAD4B_SCP_Crypto_Profile::sign_digest_for_purpose( self::CRYPTO_PURPOSE, $digest );
		if ( is_wp_error( $signature ) ) return $signature;
		$signature_json = wp_json_encode( $signature, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $signature_json ) ) return new WP_Error( 'mad4b_preparation_signature_encode_failed', 'Preparation receipt signature could not be encoded.' );
		$token = $body . '.' . self::b64url_encode( $signature_json );
		return strlen( $token ) <= self::MAX_BYTES ? $token : new WP_Error( 'mad4b_preparation_receipt_oversized', 'Preparation receipt exceeds its bounded transport size.' );
	}

	public static function verify( $token, $name ) {
		$claims = self::claims( $token, $name );
		return is_wp_error( $claims ) ? $claims : true;
	}

	public static function claims( $token, $name ) {
		if ( ! is_string( $token ) || strlen( $token ) > self::MAX_BYTES || 1 !== preg_match( '/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/D', $token, $parts ) ) return self::failure();
		if ( ! class_exists( 'MAD4B_SCP_Crypto_Profile' ) ) return self::failure();

		$signature_json = self::b64url_decode( $parts[2] );
		$signature = is_string( $signature_json ) ? json_decode( $signature_json, true, 16 ) : null;
		if ( ! is_array( $signature ) ) return self::failure();
		$digest = hash( 'sha256', $parts[1] );
		$verified = MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose( $signature, $digest, self::CRYPTO_PURPOSE );
		if ( is_wp_error( $verified ) ) return self::failure();

		$json = self::b64url_decode( $parts[1] );
		$p = is_string( $json ) ? json_decode( $json, true, 8 ) : null;
		$now = self::now_epoch();
		$issued_time = is_array( $p ) && isset( $p['issued_at'] ) && class_exists( 'MAD4B_SCP_Time_Policy' ) ? MAD4B_SCP_Time_Policy::assert_timestamp( 'preparation_receipt', (int) $p['issued_at'], self::ttl() ) : true;
		if ( is_wp_error( $issued_time ) || ! is_array( $p ) || ( $p['contract'] ?? '' ) !== self::CONTRACT || ( $p['ability_name'] ?? '' ) !== $name || ! isset( $p['nonce'] ) || ! is_string( $p['nonce'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $p['nonce'] ) || ! isset( $p['issued_at'], $p['expires_at'] ) || ! is_int( $p['issued_at'] ) || ! is_int( $p['expires_at'] ) || $p['issued_at'] > $now || $p['expires_at'] <= $now || $p['expires_at'] - $p['issued_at'] !== self::ttl() ) return self::failure();
		foreach ( array( 'descriptor_sha256', 'authority_scope_sha256' ) as $key ) if ( ! isset( $p[$key] ) || ! is_string( $p[$key] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $p[$key] ) ) return self::failure();
		if ( ! hash_equals( MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(), $p['authority_scope_sha256'] ) ) return self::failure();
		if ( class_exists( 'MAD4B_SCP_Replay_Policy' ) ) {
			$current_replay_policy_sha256 = MAD4B_SCP_Replay_Policy::policy_sha256();
			if ( '' === $current_replay_policy_sha256
				|| empty( $p['replay_policy_sha256'] )
				|| ! is_string( $p['replay_policy_sha256'] )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $p['replay_policy_sha256'] )
				|| ! hash_equals( $current_replay_policy_sha256, $p['replay_policy_sha256'] ) ) return self::failure();
		}
		$row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $name );
		if ( is_wp_error( $row ) || empty( $row['execution_eligible'] ) || ! hash_equals( $row['descriptor_sha256'], $p['descriptor_sha256'] ) ) return self::failure();
		$p['signature_profile'] = isset( $signature['profile_id'] ) ? (string) $signature['profile_id'] : '';
		$p['signature_kid'] = isset( $signature['kid'] ) ? (string) $signature['kid'] : '';
		return $p;
	}

	private static function b64url_encode( $value ) { return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' ); }
	private static function b64url_decode( $value ) {
		if ( ! is_string( $value ) || '' === $value || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) return false;
		$padding = strlen( $value ) % 4;
		if ( $padding ) $value .= str_repeat( '=', 4 - $padding );
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}
	private static function now_epoch(){return (int) MAD4B_SCP_Time_Policy::now_epoch();}
	private static function ttl(){if(class_exists('MAD4B_SCP_Time_Policy')){$v=MAD4B_SCP_Time_Policy::bounded_ttl('preparation_receipt',self::TTL);if(!is_wp_error($v))return(int)$v;}return self::TTL;}
}
