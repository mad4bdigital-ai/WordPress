<?php
/** No-network proof that the packaged trust anchor is unchanged and usable. */
define( 'ABSPATH', '/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-central-release-registry.php';
$method = new ReflectionMethod( 'MAD4B_SCP_Central_Release_Registry', 'pinned_public_key' );
if ( PHP_VERSION_ID < 80100 ) $method->setAccessible( true );
$key = $method->invoke( null );
$expected = 'eb7e753bc93cb0151f483e546bb8a2d09de85915a30699db4f4f179a360f7f01';
if ( ! is_string( $key ) || ! hash_equals( $expected, hash( 'sha256', $key ) )
    || false === strpos( $key, '-----BEGIN PUBLIC KEY-----' )
    || false !== strpos( $key, '-----BEGIN PRIVATE KEY-----' ) ) {
    fwrite( STDERR, "FAIL: pinned public key bytes changed or trust anchor absent\n" );
    exit( 1 );
}
$public = openssl_pkey_get_public( $key );
$details = $public ? openssl_pkey_get_details( $public ) : false;
if ( ! is_array( $details ) || (int) $details['bits'] < 2048
    || (int) $details['type'] !== OPENSSL_KEYTYPE_RSA ) {
    fwrite( STDERR, "FAIL: pinned issuer public key is not RSA >=2048\n" );
    exit( 1 );
}
echo "CENTRAL_RELEASE_PUBLIC_KEY: PASS (unchanged fingerprint, public-only RSA >=2048)\n";
