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
    /**
     * Insert an immutable MAD4B source receipt exactly once.
     * add_option() can update an existing option under an insertion race.
     * This helper instead requires one new SQL row and exact byte readback.
     */
    public static function insert_immutable( $name, $value ) {
        if ( ! is_string( $name ) ||
            ! preg_match( '/^mad4b_(?:activity_import_review|activity_import_archive|import_approval)_[a-f0-9]{64}$/D', $name ) ||
            ! is_array( $value ) || ! function_exists( 'maybe_serialize' ) )
            return self::err( 'mad4b_import_immutable_input_invalid',
                'An exact generated source receipt and WordPress serialization are required.' );
        $db = self::db();
        if ( is_wp_error( $db ) ) return $db;
        $raw = maybe_serialize( $value );
        if ( ! is_string( $raw ) || strlen( $raw ) > 3000000 )
            return self::err( 'mad4b_import_immutable_size_denied',
                'Immutable review receipt is not a bounded string.' );
        $inserted = $db->query( $db->prepare(
            "INSERT IGNORE INTO `{$db->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $name, $raw, 'off' ) );
        if ( 1 !== $inserted )
            return self::err( 'mad4b_import_immutable_exists_or_failed',
                'Source receipt already exists or exact insert could not be verified.' );
        self::cache_clear( $name );
        if ( ! hash_equals( $raw, (string) self::read( $db, $name ) ) )
            return self::err( 'mad4b_import_immutable_readback_failed',
                'Atomic immutable receipt could not be independently verified.' );
        if ( function_exists( 'wp_cache_get' ) &&
            function_exists( 'wp_cache_set' ) ) {
            $notoptions = wp_cache_get( 'notoptions', 'options' );
            if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
                unset( $notoptions[ $name ] );
                wp_cache_set( 'notoptions', $notoptions, 'options' );
            }
        }
        return true;
    }
    /**
     * One-time HMAC nonce marker, issued by the validated sender only.
     * A simultaneous replay MUST lose without replacing its first marker.
     */
    public static function reserve_signed_nonce( $key, $issued_at ) {
        if ( ! is_string( $key ) ||
            ! preg_match( '/^mad4b_import_nonce_[a-f0-9]{64}$/D', $key ) ||
            ! is_int( $issued_at ) || $issued_at < 1 )
            return self::err( 'mad4b_import_nonce_invalid',
                'Only a validated bounded signed-source nonce can be reserved.' );
        $db = self::db();
        if ( is_wp_error( $db ) ) return $db;
        $raw = (string) $issued_at;
        $inserted = $db->query( $db->prepare(
            "INSERT IGNORE INTO `{$db->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $key, $raw, 'off' ) );
        if ( 1 !== $inserted )
            return self::err( 'mad4b_import_webhook_replay',
                'This signed source nonce already exists or cannot be reserved.' );
        self::cache_clear( $key );
        if ( ! hash_equals( $raw, (string) self::read( $db, $key ) ) )
            return self::err( 'mad4b_import_nonce_readback_failed',
                'The signed nonce was not independently confirmed.' );
        if ( function_exists( 'wp_cache_get' ) &&
            function_exists( 'wp_cache_set' ) ) {
            $notoptions = wp_cache_get( 'notoptions', 'options' );
            if ( is_array( $notoptions ) && isset( $notoptions[ $key ] ) ) {
                unset( $notoptions[ $key ] );
                wp_cache_set( 'notoptions', $notoptions, 'options' );
            }
        }
        return true;
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
