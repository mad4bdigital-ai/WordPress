<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Independently measured MCP catalog and read-call transport health.
 * A successful tools/list must never reset consecutive tools/call failures.
 * Only timestamps/counters (never tokens, identities or arguments) persist.
 */
final class MAD4B_SCP_MCP_Peer_Health {
    const WINDOW_SECONDS = 90;
    const OPEN_SECONDS = 30;
    const FAILURE_THRESHOLD = 3;

    private static function key( array $peer, $phase ) {
        if ( ! isset( $peer['id'], $peer['endpoint'] )
            || ! in_array( $phase, array( 'catalog', 'read' ), true ) ) return '';
        return 'mad4b_native_mcp_cb_' . substr(
            hash( 'sha256', $phase . '|' . $peer['id'] . '|' . $peer['endpoint'] ), 0, 32
        );
    }
    private static function phase_status( array $peer, $phase ) {
        $key = self::key( $peer, $phase );
        if ( '' === $key || ! function_exists( 'get_transient' ) )
            return array( 'state' => 'UNAVAILABLE', 'failures' => 0 );
        $stored = get_transient( $key );
        if ( ! is_array( $stored ) )
            return array( 'state' => 'CLOSED', 'failures' => 0 );
        $now = time();
        $open_until = (int) ( $stored['open_until'] ?? 0 );
        if ( $open_until > $now )
            return array( 'state' => 'OPEN',
                'failures' => min( self::FAILURE_THRESHOLD, max( 0, (int) ( $stored['failures'] ?? 0 ) ) ),
                'retry_after_seconds' => min( self::OPEN_SECONDS, $open_until - $now ) );
        if ( (int) ( $stored['started'] ?? 0 ) + self::WINDOW_SECONDS <= $now )
            return array( 'state' => 'CLOSED', 'failures' => 0 );
        return array( 'state' => 'CLOSED',
            'failures' => min( self::FAILURE_THRESHOLD, max( 0, (int) ( $stored['failures'] ?? 0 ) ) ) );
    }
    public static function status( array $peer ) {
        $catalog = self::phase_status( $peer, 'catalog' );
        $read = self::phase_status( $peer, 'read' );
        $open = 'OPEN' === $catalog['state'] || 'OPEN' === $read['state'];
        return array( 'state' => $open ? 'OPEN' : ( 'UNAVAILABLE' === $catalog['state'] ? 'UNAVAILABLE' : 'CLOSED' ),
            'failures' => max( $catalog['failures'], $read['failures'] ),
            'catalog' => $catalog, 'read' => $read,
            'retry_after_seconds' => max( (int) ( $catalog['retry_after_seconds'] ?? 0 ),
                (int) ( $read['retry_after_seconds'] ?? 0 ) ) );
    }
    public static function check( array $peer, $phase = 'catalog' ) {
        // When discovery is unavailable a write-capable or stale read route
        // cannot bypass catalog admission. Read-call failures, however, do
        // not prevent passive catalog observation for recovery diagnostics.
        $status = self::phase_status( $peer, $phase );
        if ( 'OPEN' === $status['state'] )
            return new WP_Error( 'mad4b_mcp_peer_circuit_open',
                'Native MCP ' . $phase . ' transport circuit is open.',
                array( 'phase' => $phase,
                    'retry_after_seconds' => $status['retry_after_seconds'] ) );
        return true;
    }
    public static function transport_failed( array $peer, $phase = 'catalog' ) {
        $key = self::key( $peer, $phase );
        if ( '' === $key || ! function_exists( 'get_transient' )
            || ! function_exists( 'set_transient' ) ) return;
        $now = time();
        $stored = get_transient( $key );
        $recent = is_array( $stored )
            && (int) ( $stored['started'] ?? 0 ) + self::WINDOW_SECONDS > $now;
        $count = $recent
            ? min( self::FAILURE_THRESHOLD, max( 0, (int) ( $stored['failures'] ?? 0 ) ) + 1 ) : 1;
        set_transient( $key, array(
            'started' => $recent ? (int) $stored['started'] : $now,
            'failures' => $count,
            'open_until' => $count >= self::FAILURE_THRESHOLD ? $now + self::OPEN_SECONDS : 0,
        ), self::WINDOW_SECONDS + self::OPEN_SECONDS );
    }
    public static function transport_succeeded( array $peer, $phase = 'catalog' ) {
        $key = self::key( $peer, $phase );
        if ( '' === $key || ! function_exists( 'delete_transient' ) ) return;
        delete_transient( $key );
    }
}
