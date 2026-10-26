<?php
/** Exact Staging attachment transfer grant policy; no wildcard or Production grants. */
define( 'ABSPATH', '/test/' );
function sanitize_key( $x ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $x ) ); }
class MAD4B_SCP_Site_Profile {
    public static $environment = 'staging';
    public static function current_environment() { return self::$environment; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-grant-reconciliation.php';
function verify( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL ' . $message . PHP_EOL ); exit( 1 ); }
}
$ability = 'mad4b/attachment-transfer';
foreach ( array( 'staging', 'local', 'production' ) as $environment ) {
    MAD4B_SCP_Site_Profile::$environment = $environment;
    $allowed = MAD4B_SCP_Staging_Write_Grant_Reconciliation::allowed_ability_providers();
    verify( 'staging' === $environment ? ( $allowed[ $ability ] ?? null ) === 'core'
        : ! isset( $allowed[ $ability ] ), 'exact transfer grant policy: ' . $environment );
    verify( ! isset( $allowed['*'] ) && ! isset( $allowed['mad4b/*'] ), 'no wildcard grants: ' . $environment );
}
echo "PASS: exact attachment transfer policy restricted to Staging
";
