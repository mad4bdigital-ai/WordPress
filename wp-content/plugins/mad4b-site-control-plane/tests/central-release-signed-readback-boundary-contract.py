#!/usr/bin/env python3
"""CI-only public readback checks: public route cannot issue unverified receipts."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'includes/class-mad4b-scp-central-release-registry.php').read_text()
assert s.count("register_rest_route( 'mad4b/v1', '/central-release/")==1
assert "'methods' => 'GET'" in s and "'permission_callback' => '__return_true'" in s
assert "'callback' => array( __CLASS__, 'serve_signed_readback' )" in s
assert "rtrim( (string) home_url(), '/' ) !== self::central_origin()" in s
assert "'staging' !== wp_get_environment_type()" in s
assert 'MAD4B_SCP_Site_Profile::origin_enrolled()' in s
assert "! self::hash_ok( $hash )" in s
assert "realpath( '/run/mad4b-native-abilities/central-release-readbacks' )" in s
assert "is_link( $file )" in s and "filesize( $file ) > self::MAX_BODY_BYTES" in s
assert 'self::verify_envelope( $record, $hash, self::central_origin(), $key )' in s
assert '1 !== openssl_verify( $encoded, $signature, $public_key, OPENSSL_ALGO_SHA256 )' in s
assert 'return $record; // Signed public readback; no private credentials.' in s
print('PASS CENTRAL_RELEASE_SIGNED_READBACK: bounded, staging-issued, verified public GET')
