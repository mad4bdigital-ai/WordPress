<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Recursive evidence/diagnostic redaction with bounded traversal.
 *
 * This class never authorizes anything and must not be used to transform the
 * actual provider return value consumed by business logic. It protects evidence,
 * audit, observability and structured error metadata only.
 */
final class MAD4B_SCP_Structural_Redaction {
	const CONTRACT = 'mad4b.structural-redaction.v1';
	const MAX_DEPTH = 8;
	const MAX_NODES = 256;
	const MAX_STRING_BYTES = 2048;

	public static function redact( $value, $context = 'metadata' ) {
		$stats = array( 'visited'=>0, 'redacted'=>0, 'truncated'=>0, 'sensitive_keys'=>0, 'sensitive_values'=>0 );
		$clean = self::walk( $value, 0, $stats, sanitize_key( (string) $context ) );
		return $clean;
	}

	public static function classify( $value, $context = 'metadata' ) {
		$stats = array( 'visited'=>0, 'redacted'=>0, 'truncated'=>0, 'sensitive_keys'=>0, 'sensitive_values'=>0 );
		$clean = self::walk( $value, 0, $stats, sanitize_key( (string) $context ) );
		$classification = $stats['redacted'] > 0 ? 'sensitive_redacted' : ( $stats['truncated'] > 0 ? 'bounded_truncated' : 'public_bounded' );
		return array(
			'contract'=>self::CONTRACT,'context'=>sanitize_key((string)$context),'classification'=>$classification,
			'value'=>$clean,'stats'=>$stats,'authorizing'=>false
		);
	}

	public static function sensitive_key( $key ) {
		$key = self::key_fingerprint( $key );
		if ( '' === $key ) return false;
		$patterns = array(
			'authorization','authheader','bearer','accesstoken','refreshtoken','idtoken','token',
			'secret','password','passwd','pwd','credential','credentials','apikey','privatekey',
			'clientsecret','consumersecret','accesskey','sessionid','sessiontoken','cookie','setcookie',
			'webhooksecret','signingsecret','nonce','jwt','rawpayload','requestbody','responsebody'
		);
		foreach ( $patterns as $needle ) if ( false !== strpos( $key, $needle ) ) return true;
		return false;
	}

	public static function sensitive_scalar( $value ) {
		if ( ! is_string( $value ) ) return false;
		$v = trim( $value );
		if ( '' === $v ) return false;
		if ( preg_match( '/^Bearer\s+[A-Za-z0-9._~+\/-]+=*$/i', $v ) ) return true;
		if ( preg_match( '/^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $v ) ) return true;
		if ( preg_match( '/^-{5}BEGIN (?:RSA )?PRIVATE KEY-{5}/', $v ) ) return true;
		if ( preg_match( '/^(?:sk|rk|pk|ghp|github_pat|xox[baprs])-[_A-Za-z0-9-]{16,}$/', $v ) ) return true;
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://[^/@\s:]+:[^/@\s]+@#i', $v ) ) return true;
		if ( preg_match( '/\bAKIA[0-9A-Z]{16}\b/', $v ) ) return true;
		return false;
	}

	private static function walk( $value, $depth, array &$stats, $context ) {
		$stats['visited']++;
		if ( $stats['visited'] > self::MAX_NODES || $depth > self::MAX_DEPTH ) {
			$stats['truncated']++;
			return '[TRUNCATED]';
		}
		if ( is_object( $value ) ) $value = get_object_vars( $value );
		if ( is_array( $value ) ) {
			$out = array(); $count = 0;
			foreach ( $value as $key => $item ) {
				if ( $count >= self::MAX_NODES ) { $out['_truncated'] = true; $stats['truncated']++; break; }
				$key_string = is_string( $key ) ? $key : (string) $key;
				if ( self::sensitive_key( $key_string ) ) {
					$out[ $key ] = '[REDACTED]'; $stats['redacted']++; $stats['sensitive_keys']++; $count++; continue;
				}
				$out[ $key ] = self::walk( $item, $depth + 1, $stats, $context ); $count++;
			}
			return $out;
		}
		if ( is_resource( $value ) ) { $stats['redacted']++; return '[REDACTED]'; }
		if ( is_string( $value ) ) {
			if ( self::sensitive_scalar( $value ) ) { $stats['redacted']++; $stats['sensitive_values']++; return '[REDACTED]'; }
			if ( strlen( $value ) > self::MAX_STRING_BYTES ) { $stats['truncated']++; return substr( $value, 0, self::MAX_STRING_BYTES ) . '…[TRUNCATED]'; }
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) return $value;
		$stats['redacted']++;
		return '[REDACTED]';
	}

	private static function key_fingerprint( $key ) {
		$key = strtolower( (string) $key );
		$key = preg_replace( '/[^a-z0-9]+/', '', $key );
		return is_string( $key ) ? $key : '';
	}
}
