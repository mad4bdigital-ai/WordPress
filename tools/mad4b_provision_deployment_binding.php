<?php
/**
 * Provision an independent MAD4B deployment binding outside WordPress DB
 * and outside its web document root. Run ONLY in trusted PHP CLI on the
 * authorized Staging host; never expose this file over HTTP.
 *
 * php tools/mad4b_provision_deployment_binding.php /absolute/staging/public_html /absolute/private_dir
 *
 * Prints the inclusion path, NEVER the key. Does not edit wp-config.php,
 * grant Site Profile authority, overwrite existing keys or touch Production.
 */
if ( PHP_SAPI !== 'cli' || ! isset( $argv[1], $argv[2] ) || count( $argv ) !== 3 ) {
    fwrite( STDERR, "Usage: php tools/mad4b_provision_deployment_binding.php ABSOLUTE_SITE_ROOT ABSOLUTE_PRIVATE_DIR\n" );
    exit( 2 );
}
$site = realpath( $argv[1] );
$private = realpath( $argv[2] );
if ( ! is_string( $site ) || ! is_dir( $site ) || ! is_file( $site . DIRECTORY_SEPARATOR . 'wp-config.php' )
    || ! is_string( $private ) || ! is_dir( $private )
    || strpos( $private . DIRECTORY_SEPARATOR, $site . DIRECTORY_SEPARATOR ) === 0
    || strpos( $site . DIRECTORY_SEPARATOR, $private . DIRECTORY_SEPARATOR ) === 0
    || ! is_writable( $private ) ) {
    fwrite( STDERR, "Denied: existing writable private directory must be OUTSIDE both the site document root and all its ancestors.\n" );
    exit( 3 );
}
$original = $private . DIRECTORY_SEPARATOR . 'mad4b-staging-deployment-binding.php';
if ( file_exists( $original ) || is_link( $original ) ) {
    fwrite( STDERR, "Existing secret file detected; will not rotate or overwrite.\n" );
    exit( 4 );
}
$private_mode = fileperms( $private ) & 0777;
if ( $private_mode & 0007 ) {
    fwrite( STDERR, "Denied: private directory has permissions for other users. Set to 0700 or equivalent restricted access first.\n" );
    exit( 5 );
}
try {
    $key = bin2hex( random_bytes( 48 ) );
} catch ( Throwable $e ) {
    fwrite( STDERR, "Denied: secure random source unavailable.\n" );
    exit( 6 );
}
$payload = "<?php\n"
    . "// Auto-generated on authorized Staging host. NEVER commit or serve this file.\n"
    . "if ( ! defined( 'MAD4B_SCP_DEPLOYMENT_BINDING' ) ) {\n"
    . "    define( 'MAD4B_SCP_DEPLOYMENT_BINDING', " . var_export( $key, true ) . " );\n"
    . "}\n";
$handle = @fopen( $original, 'x' );
if ( false === $handle ) {
    fwrite( STDERR, "Denied: unable to create exclusive protected file.\n" );
    exit( 7 );
}
@chmod( $original, 0600 );
$actual_mode = @fileperms( $original );
if ( false === $actual_mode || ( $actual_mode & 0077 ) !== 0 ) {
    fclose( $handle );
    @unlink( $original );
    fwrite( STDERR, "Denied: 0600 file permission could not be enforced.\n" );
    exit( 8 );
}
$written = fwrite( $handle, $payload );
$flushed = fflush( $handle );
fclose( $handle );
if ( $written !== strlen( $payload ) || ! $flushed ) {
    @unlink( $original );
    fwrite( STDERR, "Denied: secure host binding file write failed.\n" );
    exit( 9 );
}
unset( $key, $payload );
echo "CREATED. The secret was not printed.\n";
echo "In the EXISTING Staging wp-config.php, BEFORE require wp-settings.php, add:\n";
echo "require_once " . var_export( $original, true ) . ";\n";
echo "Then use mad4b/deployment-binding-setup-status and -plan via owner-authenticated MCP.\n";
