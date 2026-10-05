<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Production CSPRNG with an explicit bounded deterministic test mode.
 *
 * Test determinism is unreachable unless MAD4B_SCP_TEST_RUNTIME is explicitly
 * true. Production never derives security-sensitive bytes from a test seed.
 */
final class MAD4B_SCP_Entropy {
	const CONTRACT = 'mad4b.entropy.v1';
	private static $test_seed = null;
	private static $counters = array();

	public static function set_test_seed( $seed ) {
		if ( ! defined( 'MAD4B_SCP_TEST_RUNTIME' ) || true !== MAD4B_SCP_TEST_RUNTIME ) {
			return new WP_Error( 'mad4b_entropy_test_seed_forbidden', 'Deterministic entropy is available only in the explicit test runtime.' );
		}
		$seed = (string) $seed;
		if ( '' === $seed || strlen( $seed ) > 128 ) return new WP_Error( 'mad4b_entropy_test_seed_invalid', 'Deterministic test seed must be 1..128 bytes.' );
		self::$test_seed = hash( 'sha256', self::CONTRACT . "\0" . $seed, true );
		self::$counters = array();
		return array( 'contract'=>self::CONTRACT, 'deterministic_test_mode'=>true, 'seed_sha256'=>hash('sha256',$seed), 'authorizing'=>false );
	}

	public static function reset_test_seed() { self::$test_seed = null; self::$counters = array(); }

	public static function bytes( $purpose, $length ) {
		$purpose = sanitize_key( (string) $purpose );
		$length = (int) $length;
		if ( '' === $purpose || $length < 1 || $length > 64 ) return new WP_Error( 'mad4b_entropy_request_invalid', 'Entropy request purpose/length is invalid.' );

		if ( null !== self::$test_seed ) {
			$counter = isset( self::$counters[ $purpose ] ) ? (int) self::$counters[ $purpose ] + 1 : 1;
			self::$counters[ $purpose ] = $counter;
			$out = '';
			$block = 0;
			while ( strlen( $out ) < $length ) {
				$block++;
				$out .= hash_hmac( 'sha256', self::CONTRACT . '|' . $purpose . '|' . $counter . '|' . $block, self::$test_seed, true );
			}
			return substr( $out, 0, $length );
		}

		try {
			return random_bytes( $length );
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_entropy_unavailable', 'Cryptographically secure runtime entropy is unavailable.' );
		}
	}

	public static function hex( $purpose, $bytes ) {
		$value = self::bytes( $purpose, $bytes );
		return is_wp_error( $value ) ? $value : bin2hex( $value );
	}

	public static function status() {
		return array(
			'contract'=>self::CONTRACT,
			'deterministic_test_mode'=>null!==self::$test_seed,
			'production_source'=>'random_bytes',
			'max_request_bytes'=>64,
			'test_seed_max_bytes'=>128,
			'authorizing'=>false
		);
	}
}
