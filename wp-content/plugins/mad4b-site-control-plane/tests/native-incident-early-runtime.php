<?php
/** Real child-process fatal capture before pluggable.php; no site mutation. */
if ( isset( $argv[1] ) && '--child' === $argv[1] ) {
    define( 'ABSPATH', __DIR__ . '/' );
    define( 'DAY_IN_SECONDS', 86400 );
    $GLOBALS['scenario'] = $argv[2];
    $GLOBALS['output_file'] = $argv[3];
    $GLOBALS['store'] = array();
    if ( in_array( $argv[2], array( 'early_fatal', 'readback_drift', 'writer_fail' ), true ) ) {
        define( 'AUTH_KEY', str_repeat( 'fixture-key-', 5 ) );
        define( 'AUTH_SALT', str_repeat( 'fixture-salt-', 5 ) );
    } elseif ( 'placeholder' === $argv[2] ) {
        define( 'AUTH_KEY', str_repeat( 'put your unique phrase here', 2 ) );
        define( 'AUTH_SALT', str_repeat( 'put your unique phrase here', 2 ) );
    } elseif ( 'salt_throws' === $argv[2] ) {
        function wp_salt( $context ) { throw new RuntimeException( 'fixture salt failure' ); }
    }
    function get_option( $key, $default = false ) { return $GLOBALS['store'][$key] ?? $default; }
    function update_option( $key, $value, $autoload = null ) {
        if ( 'writer_fail' === $GLOBALS['scenario'] ) return false;
        $GLOBALS['store'][$key] = 'readback_drift' === $GLOBALS['scenario'] ? array() : $value;
        file_put_contents( $GLOBALS['output_file'], json_encode( $GLOBALS['store'][$key] ) );
        return true;
    }
    function is_admin() { return false; }
    require dirname( __DIR__ ) . '/bootstrap/mad4b-native-incident-mu.php';
    MAD4B_Native_Incident_MU::boot( true );
    if ( 'early_fatal' === $argv[2] ) {
        if ( function_exists( 'wp_salt' ) ) exit( 81 );
        // Native PHP shutdown observes this real fatal, not a manually supplied
        // error_get_last() substitute. The raw marker must never be persisted.
        trigger_error( 'SYNTHETIC_PRIVATE_FATAL_DO_NOT_STORE', E_USER_ERROR );
        exit( 82 );
    }
    $result = MAD4B_Native_Incident_MU::record( array(
        'type' => E_ERROR, 'file' => __FILE__, 'message' => 'fixture private message',
    ) );
    echo json_encode( array( 'recorded' => $result ) );
    exit;
}

function early_assert( $ok, $why ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $why . "\n" ); exit( 1 ); }
}
early_assert( function_exists( 'proc_open' ), 'Child-process executor is required; no implicit skip' );
foreach ( array( 'early_fatal', 'missing_secret', 'placeholder', 'writer_fail', 'readback_drift', 'salt_throws' ) as $scenario ) {
    $file = tempnam( sys_get_temp_dir(), 'mad4b-early-' );
    early_assert( is_string( $file ), 'Temporary fixture result unavailable' );
    $pipes = array();
    $process = proc_open( array( PHP_BINARY, __FILE__, '--child', $scenario, $file ),
        array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
    early_assert( is_resource( $process ), 'Unable to execute isolated PHP fixture' );
    fclose( $pipes[0] );
    $stdout = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
    $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
    $exit = proc_close( $process );
    $stored = (string) file_get_contents( $file );
    unlink( $file );
    if ( 'early_fatal' === $scenario ) {
        $document = json_decode( $stored, true );
        early_assert( 255 === $exit && isset( $document['events'][0] ), 'Real pre-pluggable fatal not captured' );
        early_assert( E_USER_ERROR === $document['events'][0]['type'], 'Wrong native PHP error type recorded' );
        early_assert( false === strpos( $stored, 'SYNTHETIC_PRIVATE_FATAL_DO_NOT_STORE' )
            && false === strpos( $stored, __FILE__ ) && false === strpos( $stored, 'fixture-key-' ), 'Private source text or key persisted' );
    } else {
        $result = json_decode( $stdout, true );
        early_assert( 0 === $exit && is_array( $result ) && false === $result['recorded'],
            'Missing/placeholder secret, failed writer, drift or salt exception accepted: ' . $scenario );
    }
}
echo "PASS native incident early lifecycle: actual PHP fatal before pluggable, secret refusal, writer failure, readback drift, exception containment\n";
