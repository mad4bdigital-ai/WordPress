<?php

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', $value );
}

function wp_get_environment_type() {
	return 'production';
}

final class MAD4B_SCP_Site_Profile {
	public static function wordpress_environment() { return 'production'; }
	public static function wordpress_environment_explicit() { return false; }
	public static function current_environment() { return 'staging'; }
	public static function environment_resolution() {
		return array(
			'contract' => 'mad4b.site-profile-environment-resolution.v1',
			'wordpress_environment' => 'production',
			'wordpress_environment_explicit' => false,
			'profile_environment' => 'staging',
			'profile_environment_authoritative' => true,
			'effective_environment' => 'staging',
			'effective_source' => 'site_profile',
		);
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-environment.php';

function mad4b_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: " . $message . PHP_EOL );
		exit( 1 );
	}
}

mad4b_assert( 'production' === MAD4B_SCP_Environment::wordpress(), 'raw WordPress environment must remain production evidence' );
mad4b_assert( false === MAD4B_SCP_Environment::wordpress_explicit(), 'implicit WordPress production must remain non-explicit' );
mad4b_assert( 'staging' === MAD4B_SCP_Environment::effective(), 'exact Site Profile must drive the effective environment' );
$snapshot = MAD4B_SCP_Environment::snapshot();
mad4b_assert( 'production' === $snapshot['wordpress_environment'], 'snapshot must preserve raw WordPress environment' );
mad4b_assert( 'staging' === $snapshot['profile_environment'], 'snapshot must preserve profile environment' );
mad4b_assert( true === $snapshot['profile_environment_authoritative'], 'snapshot must expose profile authority' );
mad4b_assert( 'staging' === $snapshot['effective_environment'], 'snapshot must expose effective staging' );
mad4b_assert( 'site_profile' === $snapshot['effective_source'], 'snapshot must expose effective source' );

echo "mad4b.effective-environment-runtime.v1: PASS\n";
