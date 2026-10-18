<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded native MCP circuit breaker. Stores only counters and timestamps,
 * never tokens, request bodies, response contents or authorization records.
 */
final class MAD4B_SCP_MCP_Peer_Health {
    const WINDOW_SECONDS = 90;
    const OPEN_SECONDS = 30;
    const FAILURE_THRESHOLD = 3;

    private static function key( array $peer ) {
        if ( ! isset( $peer['id'], $peer['endpoint'] ) ) return '';
        return 'mad4b_native_mcp_cb_' . substr(
            hash( 'sha256', $peer['id'] . '|' . $peer['endpoint'] ), 0, 32
        );
    }
    public static function status( array $peer ) {
        $key = self::key( $peer );
        if ( '' === $key || ! function_exists( 'get_transient' ) )
            return array( 'state' => 'UNAVAILABLE', 'failures' => 0 );
        $stored = get_transient( $key );
        if ( ! is_array( $stored ) )
            return array( 'state' => 'CLOSED', 'failures' => 0 );
        $now = time();
        $open_until = (int) ( $stored['open_until'] ?? 0 );
        if ( $open_until > $now )
            return array( 'state' => 'OPEN', 'failures' => min( 3, max( 0, (int) ( $stored['failures'] ?? 0 ) ) ),
                'retry_after_seconds' => min( 30, $open_until - $now ) );
        $started = (int) ( $stored['started'] ?? 0 );
        if ( $started <= 0 || $started + self::WINDOW_SECONDS <= $now )
            return array( 'state' => 'CLOSED', 'failures' => 0 );
        return array( 'state' => 'CLOSED', 'failures' => min( 3, max( 0, (int) ( $stored['failures'] ?? 0 ) ) ) );
    }
    public static function check( array $peer ) {
        $state = self::status( $peer );
        if ( 'OPEN' === $state['state'] )
            return new WP_Error( 'mad4b_mcp_peer_circuit_open',
                'Provider MCP circuit is temporarily open; choose an independently reviewed local fallback.',
                array( 'retry_after_seconds' => $state['retry_after_seconds'] ) );
        return true;
    }
    public static function transport_failed( array $peer ) {
        $key = self::key( $peer );
        if ( '' === $key || ! function_exists( 'get_transient' )
            || ! function_exists( 'set_transient' ) ) return;
        $now = time();
        $stored = get_transient( $key );
        $recent = is_array( $stored ) && (int) ( $stored['started'] ?? 0 ) + self::WINDOW_SECONDS > $now;
        $count = $recent ? min( self::FAILURE_THRESHOLD, max( 0, (int) ( $stored['failures'] ?? 0 ) ) + 1 ) : 1;
        $started = $recent ? (int) $stored['started'] : $now;
        set_transient( $key, array(
            'started' => $started,
            'failures' => $count,
            'open_until' => $count >= self::FAILURE_THRESHOLD ? $now + self::OPEN_SECONDS : 0,
        ), self::WINDOW_SECONDS + self::OPEN_SECONDS );
    }
    public static function transport_succeeded( array $peer ) {
        $key = self::key( $peer );
        if ( '' === $key || ! function_exists( 'get_transient' )
            || ! function_exists( 'delete_transient' ) ) return;
        if ( false !== get_transient( $key ) ) delete_transient( $key );
    }
}
