<?php
/**
 * Host-local read-only recheck for the pinned ARE Staging SSH Worker.
 * Invoke ONLY from an authenticated SSH session using WP-CLI eval-file.
 * This script does not accept executable content and never opens an OAuth session.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "DENIED:CLI_ONLY\n" );
    exit( 4 );
}
$raw = stream_get_contents( STDIN, 4097 );
$request = is_string( $raw ) && strlen( $raw ) <= 4096 ? json_decode( $raw, true ) : null;
if ( ! is_array( $request ) || array_diff( array_keys( $request ),
    array( 'session_id', 'operation', 'target_fingerprint', 'client_fingerprint', 'approval_ticket_id' ) ) ) {
    echo wp_json_encode( array( 'eligible' => false, 'reason' => 'invalid_request' ) ), PHP_EOL;
    exit( 2 );
}
foreach ( array( 'session_id', 'operation', 'target_fingerprint', 'client_fingerprint' ) as $required ) {
    if ( ! isset( $request[ $required ] ) || ! is_string( $request[ $required ] ) ) {
        echo wp_json_encode( array( 'eligible' => false, 'reason' => 'missing_required_field' ) ), PHP_EOL;
        exit( 2 );
    }
}
if ( ! in_array( $request['operation'], array( 'sql.read', 'sql.write', 'sql.schema', 'shell', 'wp-cli' ), true ) ) {
    echo wp_json_encode( array( 'eligible' => false, 'reason' => 'operation_denied' ) ), PHP_EOL;
    exit( 2 );
}
if ( ! class_exists( 'MAD4B_SCP_Operator_Sessions' ) ||
    ! method_exists( 'MAD4B_SCP_Operator_Sessions', 'worker_live_status' ) ) {
    echo wp_json_encode( array( 'eligible' => false, 'reason' => 'active_operator_runtime_missing' ) ), PHP_EOL;
    exit( 3 );
}
$verdict = MAD4B_SCP_Operator_Sessions::worker_live_status(
    $request['session_id'],
    $request['operation'],
    $request['target_fingerprint'],
    $request['client_fingerprint'],
    (string) ( $request['approval_ticket_id'] ?? '' )
);
if ( ! is_array( $verdict ) || empty( $verdict['eligible'] ) ) {
    echo wp_json_encode( array( 'eligible' => false, 'reason' => 'session_or_approval_denied' ) ), PHP_EOL;
    exit( 3 );
}
echo wp_json_encode( array(
    'eligible' => true,
    'session_id' => (string) $verdict['session_id'],
    'operation' => (string) $verdict['operation'],
    'environment' => 'staging',
    'expires_at_epoch' => (int) $verdict['expires_at_epoch'],
) ), PHP_EOL;
