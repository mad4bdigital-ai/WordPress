<?php
/** Site-enrolled, feature-bound Skills catalog status (read-only). */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
class MAD4B_SCP_Site_Profile {
    public static $enrolled = false, $urls = false, $feature = false;
    public static function origin_enrolled() { return self::$enrolled; }
    public static function site_urls_match_enrollment() { return self::$urls; }
    public static function skills_enabled() { return self::$feature; }
}
function apply_filters( $filter, $value ) { return $value; }
function wp_normalize_path( $path ) { return $path; }
function untrailingslashit( $path ) { return rtrim( $path, '/\\' ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-skill-registry.php';
function assert_it( $ok, $label ) { if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); } }
assert_it( ! MAD4B_SCP_Skill_Registry::catalog_enabled(), 'Unenrolled site must not expose company catalog' );
MAD4B_SCP_Site_Profile::$enrolled = true;
assert_it( ! MAD4B_SCP_Skill_Registry::catalog_enabled(), 'Mismatched home/site URL blocks catalog' );
MAD4B_SCP_Site_Profile::$urls = true;
// The enrolled company catalog is always readable. The editor and scripts
// remain separate environment-gated capabilities, never granted by reading.
assert_it( MAD4B_SCP_Skill_Registry::catalog_enabled(), 'Enrolled company read catalog remains available when Skill authoring is opted out' );
MAD4B_SCP_Site_Profile::$feature = true;
assert_it( MAD4B_SCP_Skill_Registry::catalog_enabled(), 'Enrolled, matching, opted-in company catalog can read' );
$status = MAD4B_SCP_Skill_Registry::status();
assert_it( ! empty( $status['catalog_enabled'] ), 'Catalog status must be reported' );
assert_it( empty( $status['editor_enabled'] ), 'Catalog is not authoring permission' );
assert_it( empty( $status['scripts_editor_enabled'] ), 'Catalog is not scripts authoring permission' );
MAD4B_SCP_Site_Profile::$urls = false;
assert_it( empty( MAD4B_SCP_Skill_Registry::status()['catalog_enabled'] ), 'Lost site identity must immediately stop read catalog' );
echo "PASS: Live source profile-bound read-only Skills catalog (7 guards)\n";
