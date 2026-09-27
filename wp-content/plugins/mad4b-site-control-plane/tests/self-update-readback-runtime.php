<?php
define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = (string) $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

$root = sys_get_temp_dir() . '/mad4b-self-update-readback-' . bin2hex( random_bytes( 6 ) );
$plugin_dir = $root . '/mad4b-site-control-plane';
if ( ! mkdir( $plugin_dir, 0700, true ) && ! is_dir( $plugin_dir ) ) {
	fwrite( STDERR, "unable to create self-update runtime fixture\n" );
	exit( 1 );
}

$plugin_file = $plugin_dir . '/mad4b-site-control-plane.php';
file_put_contents( $plugin_file, "<?php\n/**\n * Plugin Name: MAD4B Site Control Plane\n * Version: 0.4.0-rc.64\n */\n" );
file_put_contents(
	$plugin_dir . '/MAD4B-BUILD-PROVENANCE.json',
	json_encode(
		array(
			'contract' => 'mad4b.build-provenance.v1',
			'control_plane_version' => '0.4.0-rc.64',
			'source_commit_sha' => str_repeat( 'a', 40 ),
			'build_fingerprint' => str_repeat( 'b', 64 ),
			'package_manifest_digest' => str_repeat( 'c', 64 ),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	)
);

define( 'MAD4B_SCP_FILE', $plugin_file );
define( 'MAD4B_SCP_DIR', $plugin_dir . '/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.63' );

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php';

$target = array(
	'version' => '0.4.0-rc.64',
	'source_commit_sha' => str_repeat( 'a', 40 ),
	'build_fingerprint' => str_repeat( 'b', 64 ),
	'package_manifest_digest' => str_repeat( 'c', 64 ),
);

$verify = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'verify_installed_identity' );
$verify->setAccessible( true );
$result = $verify->invoke( null, $target );
if ( is_wp_error( $result ) || '0.4.0-rc.64' !== ( $result['version'] ?? '' ) ) {
	fwrite( STDERR, "same-request disk readback did not accept the replaced build\n" );
	exit( 2 );
}

$bad = json_decode( file_get_contents( $plugin_dir . '/MAD4B-BUILD-PROVENANCE.json' ), true );
$bad['control_plane_version'] = '0.4.0-rc.63';
file_put_contents( $plugin_dir . '/MAD4B-BUILD-PROVENANCE.json', json_encode( $bad, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
$mismatch = $verify->invoke( null, $target );
if ( ! is_wp_error( $mismatch ) || 'mad4b_self_update_installed_provenance_version_mismatch' !== $mismatch->get_error_code() ) {
	fwrite( STDERR, "provenance version mismatch did not fail closed\n" );
	exit( 3 );
}

@unlink( $plugin_dir . '/MAD4B-BUILD-PROVENANCE.json' );
@unlink( $plugin_file );
@rmdir( $plugin_dir );
@rmdir( $root );

echo "mad4b.control-plane-self-update same-request readback: PASS\n";
