<?php
/** Hermetic package-wide pre-plugin OPcache convergence regression. */
define( 'ABSPATH', '/fixture/' );
$options = array();
function get_option( $name, $default = null ) {
    global $options;
    return isset( $options[ $name ] ) ? $options[ $name ] : $default;
}
function update_option( $name, $value, $autoload = false ) {
    global $options;
    if ( isset( $options[ $name ] ) && $options[ $name ] === $value ) return false;
    $options[ $name ] = $value;
    return true;
}
require dirname( __DIR__ ) . '/bootstrap/mad4b-runtime-cache-convergence-mu.php';
function verify( $ok, $label ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . "\n" ); exit( 1 ); }
}
$root = sys_get_temp_dir() . '/mad4b-runtime-cache-' . bin2hex( random_bytes( 8 ) );
mkdir( $root, 0700 );
mkdir( $root . '/includes', 0700 );
file_put_contents( $root . '/includes/first.php', "<?php echo 'first';\n" );
file_put_contents( $root . '/includes/second.php', "<?php echo 'second';\n" );
file_put_contents( $root . '/readme.txt', "test\n" );
function write_manifest( $root, $source, $corrupt = false ) {
    $rows = array(); $canonical = '';
    foreach ( array( 'includes/first.php', 'includes/second.php', 'readme.txt' ) as $path ) {
        $bytes = file_get_contents( $root . '/' . $path );
        $sha = hash( 'sha256', $bytes );
        if ( $corrupt && 'includes/second.php' === $path ) $sha = str_repeat( 'f', 64 );
        $size = strlen( $bytes );
        $rows[] = array( 'path' => $path, 'bytes' => $size, 'sha256' => $sha );
        $canonical .= $path . "\0" . $size . "\0" . $sha . "\n";
    }
    file_put_contents( $root . '/MAD4B-BUILD-PROVENANCE.json', json_encode( array(
        'contract' => 'mad4b.build-provenance.v1',
        'source_commit_sha' => $source, 'package_manifest_digest' => hash( 'sha256', $canonical ),
        'manifest_file_count' => count( $rows ), 'package_files' => $rows,
    ) ) );
}
$first = str_repeat( 'a', 40 ); $next = str_repeat( 'b', 40 );
$site = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
write_manifest( $root, $first );
$calls = array();
$invalidation = function( $path ) use ( &$calls ) { $calls[] = $path; return true; };
$accept = function( $env, $id = null ) use ( $root, $site, $invalidation ) {
    return MAD4B_SCP_Runtime_Cache_Convergence_MU::converge(
        $root, $env, null === $id ? $site : $id, $invalidation
    );
};
$one = $accept( 'staging' );
verify( 'revalidated' === $one['state'] && 2 === $one['php_invalidated']
    && true === $one['next_request_required'], 'new package invalidates exactly the PHP files' );
verify( count( $calls ) === 2, 'no wildcard or unrelated file invalidation' );
$two = $accept( 'staging' );
verify( 'current' === $two['state'] && count( $calls ) === 2,
    'subsequent request has no invalidation or write' );
verify( 'revalidated' === $accept( 'production' )['state'],
    'Production supports exactly the same verified package-only cache operation' );
verify( 'current' === $accept( 'production' )['state'],
    'Production receipt is idempotent and never grants writing' );
verify( 'blocked' === $accept( 'unknown' )['state'], 'Unknown environment remains denied' );
verify( 'revalidated' === $accept( 'staging', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' )['state'],
    'site-bound receipt does not transfer to another enrollment' );
write_manifest( $root, $next, true );
verify( 'blocked' === $accept( 'staging' )['state'],
    'malformed or tampered package fails before opcode calls and receipt update' );
write_manifest( $root, $next );
verify( 'revalidated' === $accept( 'staging' )['state'],
    'later exact source causes a fresh invalidation after integrity check' );
verify( 'current' === $accept( 'staging' )['state'], 'new release converges only once' );
$state = file_get_contents( $root . '/MAD4B-BUILD-PROVENANCE.json' );
$json = json_decode( $state, true );
$json['package_files'][1]['path'] = '../escape.php';
file_put_contents( $root . '/MAD4B-BUILD-PROVENANCE.json', json_encode( $json ) );
$options = array();
verify( 'blocked' === $accept( 'staging' )['state'], 'no traversal paths' );
foreach ( array( $root . '/includes/first.php', $root . '/includes/second.php',
    $root . '/readme.txt', $root . '/MAD4B-BUILD-PROVENANCE.json' ) as $file ) unlink( $file );
rmdir( $root . '/includes' ); rmdir( $root );
echo "PASS: site-bound manifest-verified OPcache convergence and fail-closed gates\n";
