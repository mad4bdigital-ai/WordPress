<?php
/**
 * On-demand Host Runner identity proof from ONE existing enrolled trust root.
 * No site-local secret, stored clone marker, alternative registry or authorization.
 * The host adapter must return an independently signed proof for this request's
 * nonce. Until that adapter is actually connected, verification remains BLOCKED.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Host_Identity_Live {
	const CONTRACT = 'mad4b.host-identity-live.v1';
	const PAYLOAD = 'mad4b.host-identity-challenge-proof.v1';
	const PROVIDER_FILTER = 'mad4b_scp_enrolled_host_identity_attestation';

	private static function canonical( $payload ) {
		if ( ! is_array( $payload ) ) return array();
		ksort( $payload, SORT_STRING );
		return $payload;
	}

	private static function pinned_public() {
		$b64 = defined( 'MAD4B_SCP_HOST_ENVIRONMENT_RECEIPT_PUBLIC_KEY_B64' )
			? (string) constant( 'MAD4B_SCP_HOST_ENVIRONMENT_RECEIPT_PUBLIC_KEY_B64' ) : '';
		$key = base64_decode( $b64, true );
		return is_string( $key ) && 32 === strlen( $key ) ? $key : '';
	}

	/** Immutable challenge shape: Host Runner attests this request, not saved options. */
	public static function challenge( array $site, $nonce ) {
		if ( ! is_string( $nonce ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $nonce ) ) return array();
		$uuid = (string) ( $site['site_uuid'] ?? '' );
		$origin = (string) ( $site['canonical_origin'] ?? '' );
		$digest = (string) ( $site['profile_digest'] ?? '' );
		$rev = (int) ( $site['revision'] ?? 0 );
		if ( empty( $site['configured'] ) || empty( $site['authority_ready'] )
			|| empty( $site['origin_match'] ) || empty( $site['environment_match'] )
			|| empty( $site['wordpress_environment_explicit'] )
			|| 'staging' !== (string) ( $site['configured_environment'] ?? '' )
			|| 'staging' !== (string) ( $site['wordpress_environment'] ?? '' )
			|| 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $uuid )
			|| 1 !== preg_match( '/^https:\/\/[a-z0-9.-]+(?::[0-9]{2,5})?(?:\/[A-Za-z0-9._~%-]+)*\/?$/D', $origin )
			|| $rev < 1 || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) return array();
		return array(
			'contract' => 'mad4b.host-identity-challenge.v1',
			'nonce' => $nonce,
			'site_uuid' => $uuid,
			'origin' => $origin,
			'environment' => 'staging',
			'profile_revision' => $rev,
			'profile_digest' => $digest,
		);
	}

	/**
	 * Verify a single already-enrolled Host Runner signature. All values are
	 * compared with the challenge and its pinned Host signing key, never a key
	 * returned by MCP/WordPress DB/provider payload.
	 */
	public static function verify( array $challenge, $response, $now = null ) {
		if ( empty( $challenge ) || ! is_array( $response ) ) return false;
		$public = self::pinned_public();
		if ( '' === $public || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) return false;
		$payload = isset( $response['payload'] ) && is_array( $response['payload'] ) ? $response['payload'] : array();
		$signature = base64_decode( (string) ( $response['signature_b64'] ?? '' ), true );
		$allowed = array( 'contract', 'nonce_sha256', 'site_uuid', 'origin', 'environment',
			'profile_revision', 'profile_digest', 'runner_profile_id', 'target_fingerprint',
			'issued_at', 'expires_at' );
		$actual = array_keys( $payload );
		sort( $actual, SORT_STRING );
		$expected = $allowed;
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || count( $response ) !== 4
			|| ! isset( $response['contract'], $response['algorithm'], $response['payload'], $response['signature_b64'] )
			|| self::CONTRACT !== (string) $response['contract']
			|| 'Ed25519' !== (string) $response['algorithm']
			|| self::PAYLOAD !== (string) ( $payload['contract'] ?? '' )
			|| ! is_string( $signature ) || 64 !== strlen( $signature ) ) return false;
		foreach ( array( 'site_uuid', 'origin', 'environment', 'profile_revision', 'profile_digest' ) as $field ) {
			if ( ! isset( $challenge[ $field ], $payload[ $field ] )
				|| $challenge[ $field ] !== $payload[ $field ] ) return false;
		}
		if ( ! hash_equals( hash( 'sha256', (string) ( $challenge['nonce'] ?? '' ) ), (string) ( $payload['nonce_sha256'] ?? '' ) )
			|| 1 !== preg_match( '/^[A-Za-z0-9._-]{3,120}$/D', (string) ( $payload['runner_profile_id'] ?? '' ) )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $payload['target_fingerprint'] ?? '' ) ) return false;
		$now = null === $now ? time() : (int) $now;
		$issued = $payload['issued_at'] ?? null;
		$expires = $payload['expires_at'] ?? null;
		if ( ! is_int( $issued ) || ! is_int( $expires )
			|| $issued > $now + 5 || $issued < $now - 60
			|| $expires < $now || $expires > $issued + 60 || $expires <= $issued ) return false;
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( self::canonical( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( self::canonical( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) && sodium_crypto_sign_verify_detached( $signature, $json, $public );
	}

	/**
	 * The one configured local Host transport. It cannot choose a key, host,
	 * URL, command, PHP callback or extra identity source from WordPress data.
	 * The signed reply is still independently verified by verify().
	 */
	private static function local_host_transport( array $challenge ) {
		$path = defined( 'MAD4B_SCP_HOST_IDENTITY_SOCKET' )
			? (string) constant( 'MAD4B_SCP_HOST_IDENTITY_SOCKET' ) : '';
		if ( '' === $path ) return null;
		// Host-owned socket under a dedicated OS run directory, not webroot
		// and not a path supplied through MCP, DB, browser or Site Profile.
		if ( 1 !== preg_match( '#^/(?:var/run|run)/mad4b-host-runner/[a-zA-Z0-9._-]{1,80}\\.sock$#D', $path )
			|| is_link( $path ) || is_link( dirname( $path ) )
			|| ! function_exists( 'stream_socket_client' )
			|| ! is_dir( dirname( $path ) ) || 'socket' !== @filetype( $path ) ) return null;
		$dir_perms = @fileperms( dirname( $path ) );
		$socket_perms = @fileperms( $path );
		if ( ! is_int( $dir_perms ) || ! is_int( $socket_perms )
			|| ( $dir_perms & 0022 ) || ( $socket_perms & 0007 ) ) return null;
		$message = function_exists( 'wp_json_encode' )
			? wp_json_encode( $challenge, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $challenge, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $message ) || strlen( $message ) > 3072 ) return null;
		$errno = 0;
		$errstr = '';
		$stream = @stream_socket_client( 'unix://' . $path, $errno, $errstr, 0.6, STREAM_CLIENT_CONNECT );
		if ( ! is_resource( $stream ) ) return null;
		stream_set_timeout( $stream, 1 );
		$sent = @fwrite( $stream, $message . "\n" );
		if ( ! is_int( $sent ) || $sent !== strlen( $message ) + 1 ) { fclose( $stream ); return null; }
		$line = @fgets( $stream, 8193 );
		$extra = is_string( $line ) && strlen( $line ) >= 8192;
		fclose( $stream );
		if ( ! is_string( $line ) || $extra || '' === trim( $line ) ) return null;
		$result = json_decode( $line, true );
		return is_array( $result ) ? $result : null;
	}

	/** Read-only dynamic capture. A missing signed response is not enrollment. */
	public static function observe( array $site ) {
		$out = array(
			'contract' => self::CONTRACT, 'state' => 'host_identity_not_proven',
			'verified' => false, 'source' => 'existing_enrolled_host_runner',
			'transport' => defined( 'MAD4B_SCP_HOST_IDENTITY_SOCKET' ) ? 'enrolled_local_unix_socket' : 'enrolled_host_adapter',
			'one_authoritative_source' => true, 'new_secret_required' => false,
			'copied_site_profile_is_insufficient' => true,
			'host_private_signer_required' => true,
			'read_only' => true, 'authorizing' => false, 'mutation_performed' => false,
			'production_authorized' => false, 'legacy_host_operations_automatically_unlocked' => false,
		);
		if ( ! function_exists( 'random_bytes' )
			|| ( ! defined( 'MAD4B_SCP_HOST_IDENTITY_SOCKET' ) && ! function_exists( 'apply_filters' ) )
			|| '' === self::pinned_public() || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$out['state'] = 'host_attestation_trust_or_adapter_unavailable';
			return $out;
		}
		try { $nonce = bin2hex( random_bytes( 32 ) ); }
		catch ( Exception $e ) { $out['state'] = 'host_challenge_entropy_unavailable'; return $out; }
		$challenge = self::challenge( $site, $nonce );
		if ( empty( $challenge ) ) { $out['state'] = 'exact_staging_identity_required'; return $out; }
		// Only a pre-enrolled trusted Host adapter may satisfy this hook.
		// This code NEVER calls remote URLs or accepts a caller-chosen trust key.
		// Socket is authoritative when enrolled. No fallback to another
		// connector/filter if it is configured but temporarily unavailable.
		if ( defined( 'MAD4B_SCP_HOST_IDENTITY_SOCKET' ) ) {
			$evidence = self::local_host_transport( $challenge );
		} else {
			// Compatibility adapter for an already trusted enrolled Host
			// provider. Never a second signer or a source of public keys.
			try { $evidence = apply_filters( self::PROVIDER_FILTER, null, $challenge ); }
			catch ( Throwable $error ) { $evidence = null; }
		}
		if ( ! self::verify( $challenge, $evidence ) ) {
			$out['state'] = 'fresh_host_signature_missing_or_invalid';
			return $out;
		}
		$out['state'] = 'fresh_host_identity_verified';
		$out['verified'] = true;
		return $out;
	}
}
