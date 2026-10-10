<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Per-site/Profile atomic mutex for MAD4B's OWN import source operations.
 *
 * Do not use add_option() as a mutex: modern WP uses a duplicate-key UPDATE
 * path and may mutate a competing holder's option value. Reserve via one
 * INSERT IGNORE, require exactly one inserted row, reread the raw DB value,
 * and release only by token-constrained atomic DELETE.
 *
 * Not a fence for WordPress editors, WP All Import or remote Sheet writers.
 */
final class MAD4B_SCP_Batch_Atomic_Mutex {
    private static function err( $code, $message ) {
        return new WP_Error( $code, $message );
    }
    private static function db() {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ||
            ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) ||
            ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->options ) ||
            ! method_exists( $wpdb, 'prepare' ) ||
            ! method_exists( $wpdb, 'query' ) ||
            ! method_exists( $wpdb, 'get_var' ) )
            return self::err( 'mad4b_batch_mutex_db_unavailable',
                'An exact WordPress SQL options store is required for atomic reservation.' );
        return $wpdb;
    }
    public static function key( $slug ) {
        return 'mad4b_batch_mutex_' . hash( 'sha256',
            MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug );
    }
    private static function read( $db, $key ) {
        $sql = $db->prepare(
            "SELECT option_value FROM `{$db->options}` WHERE option_name = %s LIMIT 1",
            $key );
        return $db->get_var( $sql );
    }
    private static function cache_clear( $key ) {
        if ( function_exists( 'wp_cache_delete' ) )
            wp_cache_delete( $key, 'options' );
    }
    public static function observe( $slug ) {
        $db = self::db();
        if ( is_wp_error( $db ) ) return $db;
        $key = self::key( $slug );
        $raw = self::read( $db, $key );
        if ( null === $raw ) return array( 'held' => false, 'operation' => null,
            'started_at' => null, 'sha256' => null );
        if ( ! is_string( $raw ) ||
            ! preg_match( '/^[a-f0-9]{32}\\|(begin|append|approve|archive|export)\\|[0-9]{10}$/D', $raw ) )
            return self::err( 'mad4b_batch_mutex_corrupt',
                'Mutex storage is unrecognized. Manual investigation required.' );
        $parts = explode( '|', $raw, 3 );
        return array( 'held' => true, 'operation' => $parts[1],
            'started_at' => (int) $parts[2],
            'sha256' => hash( 'sha256', $raw ) );
    }
    public static function acquire( $slug, $operation ) {
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', (string) $slug ) ||
            ! in_array( $operation, array(
                'begin', 'append', 'approve', 'archive', 'export' ), true ) )
            return self::err( 'mad4b_batch_mutex_scope_invalid',
                'Exact source operation/Profile scope required.' );
        $db = self::db();
        if ( is_wp_error( $db ) ) return $db;
        $key = self::key( $slug );
        $value = bin2hex( random_bytes( 16 ) ) . '|' .
            $operation . '|' . (string) time();
        // An INSERT IGNORE with a UNIQUE option_name constraint returns
        // 1 for the sole winner and 0 for any contender, without overwriting.
        $inserted = $db->query( $db->prepare(
            "INSERT IGNORE INTO `{$db->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $key, $value, 'off' ) );
        if ( 1 !== $inserted )
            return self::err( 'mad4b_batch_mutation_locked',
                'Atomic Profile reservation was not obtained; another worker or failed reservation may remain.' );
        self::cache_clear( $key );
        $observed = self::read( $db, $key );
        if ( ! is_string( $observed ) ||
            ! hash_equals( $value, $observed ) )
            return self::err( 'mad4b_batch_lock_readback_failed',
                'Atomic lock owner cannot be independently verified. Recovery required.' );
        return array( 'option_key' => $key, 'value' => $value );
    }
    public static function release( $lease ) {
        if ( ! is_array( $lease ) ||
            ! isset( $lease['option_key'], $lease['value'] ) ||
            ! is_string( $lease['option_key'] ) ||
            ! is_string( $lease['value'] ) ||
            ! preg_match( '/^mad4b_batch_mutex_[a-f0-9]{64}$/D',
                $lease['option_key'] ) )
            return self::err( 'mad4b_batch_mutex_release_invalid',
                'An exact reservation receipt is required to release its lock.' );
        $db = self::db();
        if ( is_wp_error( $db ) ) return $db;
        $key = $lease['option_key'];
        $value = $lease['value'];
        $deleted = $db->query( $db->prepare(
            "DELETE FROM `{$db->options}` WHERE option_name = %s AND BINARY option_value = BINARY %s LIMIT 1",
            $key, $value ) );
        if ( 1 !== $deleted )
            return self::err( 'mad4b_batch_lock_stolen',
                'Atomic lock release refused because owner token is absent or changed.' );
        self::cache_clear( $key );
        if ( null !== self::read( $db, $key ) )
            return self::err( 'mad4b_batch_unlock_unverified',
                'Mutex release could not be confirmed from exact SQL readback.' );
        return true;
    }
}
