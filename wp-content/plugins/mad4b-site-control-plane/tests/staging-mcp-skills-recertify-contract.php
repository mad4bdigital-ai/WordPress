<?php
/* Hermetic fail-closed contract test for the explicit Staging MCP recertification repair. */
require_once dirname( __DIR__ ) . '/tools/staging-mcp-skills-recertify.php';
$sha = str_repeat( 'a', 40 );
$valid = array(
    'environment' => 'staging', 'current_sha' => $sha, 'expected_sha' => $sha,
    'origin' => 'https://staging.example.test', 'expected_origin' => 'https://staging.example.test',
    'skills_live_ready' => true, 'skills_build_provenance_ready' => true, 'audit_ready' => true,
);
$cases = array(
    array( array( 'environment' => 'production' ), 'non_staging_denied' ),
    array( array( 'expected_sha' => str_repeat( 'b', 40 ) ), 'exact_build_mismatch' ),
    array( array( 'current_sha' => '' ), 'exact_build_mismatch' ),
    array( array( 'expected_origin' => 'https://other.example.test' ), 'origin_mismatch' ),
    array( array( 'skills_live_ready' => false ), 'live_skills_not_ready' ),
    array( array( 'skills_build_provenance_ready' => false ), 'live_skills_not_ready' ),
    array( array( 'audit_ready' => false ), 'audit_storage_not_ready' ),
);
foreach ( $cases as $case ) {
    $actual = MAD4B_Staging_MCP_Skills_Recertify::preflight( array_merge( $valid, $case[0] ) );
    if ( $actual !== $case[1] ) throw new RuntimeException( 'Unsafe preflight result: ' . $actual );
}
if ( 'eligible' !== MAD4B_Staging_MCP_Skills_Recertify::preflight( $valid ) )
    throw new RuntimeException( 'Eligible Staging build unexpectedly blocked.' );

define( 'WP_CLI', true );
define( 'ABSPATH', '/var/tmp/' );
class WP_CLI {
    static $lines = array();
    static function line( $s ) { self::$lines[] = $s; }
    static function error( $s ) { throw new RuntimeException( $s ); }
}
class MAD4B_SCP_Site_Profile {
    static function current_environment() { return 'staging'; }
}
class MAD4B_SCP_Runtime_Convergence {
    const CHECKPOINT_OPTION = 'mad4b_scp_runtime_convergence_v1';
    static function restart_grace_status() { return array( 'current_identity' => array( 'source_commit_sha' => str_repeat( 'a', 40 ) ) ); }
}
class MAD4B_SCP_Skill_Runtime_Certification {
    static $observed = 0;
    static $current = true;
    static function current_status() { return array( 'ready' => true, 'checks' => array( 'build_provenance_identity_ready' => true ) ); }
    static function persisted_status() { return array( 'ready' => true, 'build_identity_current' => self::$current ); }
    static function observe( $explicit = false ) { self::$observed++; self::$current = true; return self::persisted_status(); }
}
class MAD4B_SCP_Audit { static function storage_status() { return array( 'ready' => true ); } }
function home_url( $path = '/' ) { return 'https://staging.example.test/'; }
function get_option( $name, $default = array() ) { return array( 'state' => 'pending_manual_resume', 'target_identity' => 'unchanged' ); }
$backup = tempnam( sys_get_temp_dir(), 'mad4b-recertify-test-' );
file_put_contents( $backup, str_repeat( 'x', 80 ) );
putenv( 'MAD4B_STAGING_MCP_RECERTIFY=CERTIFY CURRENT STAGING SKILLS' );
putenv( 'MAD4B_STAGING_EXPECTED_SHA=' . $sha );
putenv( 'MAD4B_STAGING_EXPECTED_ORIGIN=https://staging.example.test' );
putenv( 'MAD4B_STAGING_CERT_BACKUP_PATH=' . $backup );
try {
    MAD4B_Staging_MCP_Skills_Recertify::execute();
    if ( 0 !== MAD4B_SCP_Skill_Runtime_Certification::$observed ) throw new RuntimeException( 'Fresh certificate unexpectedly changed.' );
    MAD4B_SCP_Skill_Runtime_Certification::$current = false;
    MAD4B_Staging_MCP_Skills_Recertify::execute();
    if ( 1 !== MAD4B_SCP_Skill_Runtime_Certification::$observed ) throw new RuntimeException( 'Stale certification did not run once.' );
    putenv( 'MAD4B_STAGING_EXPECTED_SHA=' . str_repeat( 'b', 40 ) );
    try { MAD4B_Staging_MCP_Skills_Recertify::execute(); throw new RuntimeException( 'Incorrect build unexpectedly accepted.' ); }
    catch ( RuntimeException $e ) {
        if ( 'exact_build_mismatch' !== $e->getMessage() ) throw $e;
    }
} finally { unlink( $backup ); }
echo "PASS: STAGING_MCP_SKILLS_RECERTIFY_CONTRACT (8 gates + idempotence + exact build + checkpoint preservation)\n";
