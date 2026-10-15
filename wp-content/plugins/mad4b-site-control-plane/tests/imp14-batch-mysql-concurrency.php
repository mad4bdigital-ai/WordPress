<?php
/**
 * Real-MySQL Staging opt-in smoke test: runs two independent PHP processes
 * contending for the SAME Profile option lock.
 *
 * Example (on an authorized Staging host, PHP CLI + WordPress installed):
 * php tests/imp14-batch-mysql-concurrency.php --wp-root=/path/to/wordpress \
 *   --expected-head=<full-40-char-source-commit> \
 *   --expected-site-uuid=<enrolled-staging-site-uuid>
 * A matching bundled source-commit field is a prerequisite, NOT an independent
 * proof of installed ZIP SHA-256, provider certification or Host attestation.
 *
 * Does not write business posts or run an import. Its lock is a synthetic
 * randomly named source Profile scoped to the enrolled Staging site.
 * Explicitly never force-clears failed locks; report for recovery.
 */
if ( PHP_SAPI !== 'cli' ) { exit( 2 ); }
$args = array();
foreach ( array_slice( $argv, 1 ) as $part ) {
    if ( preg_match( '/^--([a-z-]+)=(.*)$/D', $part, $m ) )
        $args[ $m[1] ] = $m[2];
}
$expected_head = isset( $args['expected-head'] ) ? strtolower( trim( $args['expected-head'] ) ) : '';
$expected_site = isset( $args['expected-site-uuid'] ) ? strtolower( trim( $args['expected-site-uuid'] ) ) : '';
if ( ! preg_match( '/^[a-f0-9]{40}$/D', $expected_head ) ||
    ! preg_match( '/^[a-f0-9-]{36}$/D', $expected_site ) ) {
    fwrite( STDERR, "BLOCKED: --expected-head and --expected-site-uuid required\n" );
    exit( 2 );
}
$root = isset( $args['wp-root'] ) ? realpath( $args['wp-root'] ) : false;
if ( !$root || ! is_file( $root . '/wp-load.php' ) ) {
    fwrite( STDERR, "BLOCKED: explicit --wp-root required\n" ); exit( 2 );
}
require_once $root . '/wp-load.php';
// A bundled identity is not itself an independent binary or Host attestation.
$evidence_path = dirname( __DIR__ ) . '/config/functional-gap-contract-evidence.generated.json';
$evidence = is_file( $evidence_path ) && is_readable( $evidence_path )
    ? json_decode( (string) file_get_contents( $evidence_path ), true ) : null;
$installed_head = is_array( $evidence ) && isset( $evidence['source_commit_sha'] )
    ? strtolower( trim( (string) $evidence['source_commit_sha'] ) ) : '';
if ( ! preg_match( '/^[a-f0-9]{40}$/D', $installed_head ) ||
    ! hash_equals( $expected_head, $installed_head ) ) {
    fwrite( STDERR, "BLOCKED: installed source evidence differs from requested HEAD\n" );
    exit( 2 );
}
if ( ! class_exists( 'MAD4B_SCP_Batch_Atomic_Mutex' ) )
    require_once __DIR__ . '/../includes/class-mad4b-scp-batch-atomic-mutex.php';
if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
    ! MAD4B_SCP_Site_Profile::configured() ||
    ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
    ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ||
    ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) ) {
    fwrite( STDERR, "BLOCKED: enrolled Staging Site Profile required\n" );
    exit( 2 );
}
if ( ! hash_equals( $expected_site, strtolower( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) ) {
    fwrite( STDERR, "BLOCKED: enrolled site differs from requested Site UUID\n" );
    exit( 2 );
}
if ( isset( $args['child'] ) ) {
    $slug = isset( $args['slug'] ) ? $args['slug'] : '';
    $barrier = isset( $args['barrier'] ) ? $args['barrier'] : '';
    if ( ! preg_match( '/^imp14race_[a-f0-9]{12}$/D', $slug ) ||
        ! is_file( $barrier ) || ! isset( $args['worker'] ) ) exit( 3 );
    $deadline = microtime( true ) + 8.0;
    while ( trim( (string) @file_get_contents( $barrier ) ) !== 'go' ) {
        if ( microtime( true ) > $deadline ) exit( 4 );
        usleep( 10000 );
    }
    $receipt = MAD4B_SCP_Batch_Atomic_Mutex::acquire( $slug, 'export' );
    if ( is_wp_error( $receipt ) ) {
        echo json_encode( array( 'worker' => $args['worker'],
            'winner' => false, 'error' => $receipt->get_error_code() ) ) . "\n";
        exit( 0 );
    }
    usleep( 1250000 );
    $released = MAD4B_SCP_Batch_Atomic_Mutex::release( $receipt );
    echo json_encode( array( 'worker' => $args['worker'],
        'winner' => true, 'released' => true === $released ) ) . "\n";
    exit( true === $released ? 0 : 5 );
}
if ( ! function_exists( 'proc_open' ) || ! is_string( PHP_BINARY ) ) {
    fwrite( STDERR, "BLOCKED: Staging CLI process spawning unavailable\n" ); exit( 2 );
}
$slug = 'imp14race_' . bin2hex( random_bytes( 6 ) );
$barrier = tempnam( sys_get_temp_dir(), 'mad4b_imp14_' );
if ( false === $barrier ) exit( 2 );
$jobs = array();
$command = array( PHP_BINARY, __FILE__, '--wp-root=' . $root,
    '--expected-head=' . $expected_head,
    '--expected-site-uuid=' . $expected_site,
    '--child=1', '--slug=' . $slug, '--barrier=' . $barrier );
for ( $i = 0; $i < 2; $i++ ) {
    $cmd = array_merge( $command, array( '--worker=' . $i ) );
    $pipes = array();
    $process = proc_open( $cmd, array(
        0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ),
        2 => array( 'pipe', 'w' ) ), $pipes );
    if ( ! is_resource( $process ) ) {
        fwrite( STDERR, "BLOCKED: child could not launch\n" );
        @unlink( $barrier ); exit( 2 );
    }
    fclose( $pipes[0] );
    $jobs[] = array( 'process' => $process, 'pipes' => $pipes );
}
file_put_contents( $barrier, 'go' );
$results = array(); $failed = false;
foreach ( $jobs as $job ) {
    $stdout = stream_get_contents( $job['pipes'][1] );
    $stderr = stream_get_contents( $job['pipes'][2] );
    fclose( $job['pipes'][1] ); fclose( $job['pipes'][2] );
    $exit = proc_close( $job['process'] );
    $row = json_decode( trim( $stdout ), true );
    if ( $exit !== 0 || !is_array( $row ) || $stderr !== '' ) $failed = true;
    $results[] = array( 'exit' => $exit, 'result' => $row,
        'stderr_sha256' => hash( 'sha256', $stderr ) );
}
@unlink( $barrier );
$winners = 0; $losers = 0;
foreach ( $results as $item ) {
    if ( isset( $item['result']['winner'] ) && $item['result']['winner'] === true &&
        $item['result']['released'] === true ) $winners++;
    elseif ( isset( $item['result']['error'] ) &&
        $item['result']['error'] === 'mad4b_batch_mutation_locked' ) $losers++;
}
$observed = MAD4B_SCP_Batch_Atomic_Mutex::observe( $slug );
$released = !is_wp_error( $observed ) && !$observed['held'];
$pass = !$failed && $winners === 1 && $losers === 1 && $released;
echo json_encode( array( 'contract' => 'mad4b.imp14-mysql-concurrency.v1',
    'result' => $pass ? 'PASS' : 'FAIL', 'workers' => 2,
    'winners' => $winners, 'contenders_refused' => $losers,
    'lock_released' => $released, 'staging_only' => true,
    'source_manifest_head_matches_requested' => true,
    'host_attestation_certified' => false,
    'plugin_filesystem_hash_verified' => false,
    'site_identity_matches_requested' => true,
    'business_post_writes' => 0 ) ) . "\n";
exit( $pass ? 0 : 1 );
