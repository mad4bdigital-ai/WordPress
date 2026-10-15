<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Private nonsecret drafts, with a finite per-blog slot bank and exact CAS.
 * V2 has 64 physical slots, partitioned into eight 8-slot actor buckets.
 * Hash collisions deny capacity rather than creating unbounded options.
 * Legacy random-key drafts remain owner-bound READ ONLY; they are neither
 * migrated nor swept. Expired v2 rows are reclaimed only by authorized create.
 */
final class MAD4B_SCP_CSO_Drafts {
    const CONTRACT = 'mad4b.cso.private-draft.v1';
    const TTL = 172800;
    const SLOTS = 64;
    const ACTOR_SLOTS = 8;
    const MAX_ROW_BYTES = 65536;

    private static function error( $reason ) { return MAD4B_SCP_CSO_Scope::error( $reason ); }

    private static function gate( $mutate ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'forms' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() )
            return self::error( 'DRAFT_FIRST_PARTY_REQUIRED' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        if ( $mutate && ( ! class_exists( 'MAD4B_SCP_Policy', false ) ||
            true !== MAD4B_SCP_Policy::can_mutate() ||
            ! class_exists( 'MAD4B_SCP_Operational_Integrity', false ) ) )
            return self::error( 'DRAFT_MUTATION_NOT_AUTHORIZED' );
        if ( $mutate ) {
            $checkpoint = MAD4B_SCP_Operational_Integrity::capture();
            if ( is_wp_error( $checkpoint ) ) return $checkpoint;
            $valid = MAD4B_SCP_Operational_Integrity::assert_unchanged( $checkpoint, true );
            if ( is_wp_error( $valid ) ) return $valid;
        }
        return $scope;
    }

    private static function mutation_guard( $scope ) {
        $current = self::gate( true );
        return ! is_wp_error( $current ) &&
            true === MAD4B_SCP_CSO_Scope::assert_current( $scope ) ?
            true : self::error( 'DRAFT_MUTATION_SCOPE_CHANGED' );
    }

    private static function locator( $id ) {
        if ( ! is_string( $id ) ) return false;
        if ( preg_match( '/^csod2\.([a-f0-9]{2})\.[a-f0-9]{64}$/D', $id, $match ) ) {
            $slot = hexdec( $match[1] );
            return $slot < self::SLOTS ? array(
                'key' => 'mad4b_cso_draft_slot_v2_' . $match[1],
                'slot' => $slot, 'legacy' => false ) : false;
        }
        return preg_match( '/^csod\.[a-f0-9]{64}$/D', $id ) ? array(
            'key' => 'mad4b_cso_draft_' . hash( 'sha256', $id ),
            'legacy' => true ) : false;
    }

    private static function table( $key ) {
        global $wpdb;
        if ( ! is_string( $key ) ||
            ! preg_match( '/^mad4b_cso_draft_(?:slot_v2_[a-f0-9]{2}|[a-f0-9]{64})$/D', $key ) ||
            ! is_object( $wpdb ) || ! function_exists( 'get_current_blog_id' ) ||
            ! method_exists( $wpdb, 'get_blog_prefix' ) ||
            ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ||
            ! method_exists( $wpdb, 'query' ) )
            return self::error( 'DRAFT_STORAGE_UNAVAILABLE' );
        $blog = get_current_blog_id();
        $table = $wpdb->get_blog_prefix( $blog ) . 'options';
        return is_int( $blog ) && $blog > 0 &&
            preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) &&
            isset( $wpdb->options ) && $wpdb->options === $table ?
            $table : self::error( 'DRAFT_STORAGE_UNAVAILABLE' );
    }

    private static function raw( $key ) {
        global $wpdb;
        $table = self::table( $key );
        if ( is_wp_error( $table ) ) return $table;
        $raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT CASE WHEN OCTET_LENGTH(option_value) <= 65536 THEN option_value ELSE '' END FROM `{$table}` WHERE option_name = %s", $key ) );
        return empty( $wpdb->last_error ) ? $raw : self::error( 'DRAFT_READ_UNCERTAIN' );
    }

    private static function plain_tree( $value, &$nodes, $depth = 0 ) {
        if ( ++$nodes > 4096 || $depth > 16 ) return false;
        if ( is_array( $value ) ) {
            if ( count( $value ) > 128 ) return false;
            foreach ( $value as $key => $item ) {
                if ( ! is_int( $key ) && ! is_string( $key ) ) return false;
                if ( ! self::plain_tree( $item, $nodes, $depth + 1 ) ) return false;
            }
            return true;
        }
        return is_null( $value ) || is_string( $value ) || is_bool( $value ) ||
            is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
    }

    private static function decode( $raw ) {
        if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_ROW_BYTES )
            return self::error( 'DRAFT_CORRUPT_OR_OVERSIZE' );
        // Bound bytes BEFORE deserialization and never instantiate stored objects.
        $sealed = @unserialize( $raw, array( 'allowed_classes' => false, 'max_depth' => 18 ) );
        $nodes = 0;
        if ( ! is_array( $sealed ) || ! self::plain_tree( $sealed, $nodes ) ||
            true !== MAD4B_SCP_CSO_Scope::bounded( $sealed ) )
            return self::error( 'DRAFT_CORRUPT_OR_OVERSIZE' );
        $record = MAD4B_SCP_CSO_Scope::unseal( $sealed, self::CONTRACT );
        if ( is_wp_error( $record ) || ! is_array( $record ) ) return self::error( 'DRAFT_CORRUPT' );
        $location = self::locator( $record['id'] ?? null );
        $keys = array( 'contract','id','scope_sha256','actor_sha256','form','values','revision','expires_at' );
        if ( $location && ! $location['legacy'] )
            $keys = array_merge( $keys, array( 'storage_version','slot','owner_sha256','created_at','updated_at' ) );
        if ( ! $location || array_diff( array_keys( $record ), $keys ) ||
            count( $record ) !== count( $keys ) ||
            ( $record['contract'] ?? '' ) !== self::CONTRACT ||
            ! is_string( $record['scope_sha256'] ?? null ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $record['scope_sha256'] ) ||
            ! is_string( $record['actor_sha256'] ?? null ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $record['actor_sha256'] ) ||
            ! is_int( $record['revision'] ?? null ) || $record['revision'] < 1 ||
            ! is_int( $record['expires_at'] ?? null ) ||
            ! is_array( $record['form'] ?? null ) ||
            count( $record['form'] ) !== 2 ||
            array_diff( array_keys( $record['form'] ), array( 'ability_name','expected_descriptor_sha256' ) ) ||
            ! is_string( $record['form']['ability_name'] ) ||
            ! is_string( $record['form']['expected_descriptor_sha256'] ) ||
            ! is_array( $record['values'] ?? null ) ||
            true !== MAD4B_SCP_CSO_Scope::safe_data( $record['values'] ) ||
            true !== MAD4B_SCP_CSO_Scope::bounded( $record['values'] ) )
            return self::error( 'DRAFT_RECORD_INVALID' );
        if ( ! $location['legacy'] && (
            ( $record['storage_version'] ?? 0 ) !== 2 ||
            ( $record['slot'] ?? -1 ) !== $location['slot'] ||
            ! is_string( $record['owner_sha256'] ?? null ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $record['owner_sha256'] ) ||
            (int) floor( $record['slot'] / self::ACTOR_SLOTS ) !== hexdec( substr( $record['owner_sha256'], 0, 2 ) ) % 8 ||
            ! is_int( $record['created_at'] ?? null ) ||
            ! is_int( $record['updated_at'] ?? null ) ||
            $record['created_at'] < 1 || $record['created_at'] > $record['updated_at'] ||
            $record['updated_at'] > time() + 60 ||
            $record['expires_at'] !== $record['updated_at'] + self::TTL ) )
            return self::error( 'DRAFT_RECORD_INVALID' );
        return array( 'record' => $record, 'sealed' => $sealed, 'location' => $location );
    }

    private static function owner() {
        if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'get_current_blog_id' ) )
            return self::error( 'DRAFT_OWNER_UNVERIFIED' );
        $user = get_current_user_id(); $blog = get_current_blog_id();
        return is_int( $user ) && $user > 0 && is_int( $blog ) && $blog > 0 ?
            hash( 'sha256', $blog . ':' . $user ) : self::error( 'DRAFT_OWNER_UNVERIFIED' );
    }

    private static function fields( $form, $values ) {
        if ( ! is_array( $form ) || ! is_array( $values ) ||
            ! isset( $form['ability_name'], $form['expected_descriptor_sha256'] ) ||
            array_diff( array_keys( $form ), array( 'ability_name', 'expected_descriptor_sha256' ) ) ||
            true !== MAD4B_SCP_CSO_Scope::safe_data( $values ) ||
            true !== MAD4B_SCP_CSO_Scope::bounded( $values ) )
            return self::error( 'DRAFT_VALUES_INVALID' );
        $valid = MAD4B_SCP_CSO_Forms::validate( $form, $values );
        return is_wp_error( $valid ) || true !== ( $valid['valid'] ?? false ) ?
            self::error( 'DRAFT_DESCRIPTOR_OR_FIELDS_INVALID' ) : true;
    }

    private static function read( $id, $scope, $allow_expired = false ) {
        $location = self::locator( $id );
        if ( ! $location ) return self::error( 'DRAFT_IDENTIFIER_INVALID' );
        $raw = self::raw( $location['key'] );
        if ( is_wp_error( $raw ) ) return $raw;
        if ( null === $raw ) return self::error( 'DRAFT_NOT_FOUND' );
        $loaded = self::decode( $raw );
        if ( is_wp_error( $loaded ) ) return $loaded;
        $record = $loaded['record'];
        if ( $record['id'] !== $id || $loaded['location'] !== $location ||
            ! hash_equals( $record['scope_sha256'], MAD4B_SCP_CSO_Scope::digest( $scope ) ) ||
            ! hash_equals( $record['actor_sha256'], (string) ( $scope['actor_sha256'] ?? '' ) ) )
            return self::error( 'DRAFT_OWNERSHIP_OR_RECORD_INVALID' );
        if ( ! $location['legacy'] ) {
            $owner = self::owner();
            if ( is_wp_error( $owner ) || ! hash_equals( $record['owner_sha256'], $owner ) )
                return self::error( 'DRAFT_OWNERSHIP_OR_RECORD_INVALID' );
        }
        if ( ! $allow_expired && $record['expires_at'] <= time() ) return self::error( 'DRAFT_EXPIRED' );
        $loaded['raw'] = $raw; $loaded['key'] = $location['key'];
        return $loaded;
    }

    private static function project( $record, $include_values, $legacy = false ) {
        $result = array( 'contract' => self::CONTRACT,
            'id' => $record['id'], 'revision' => $record['revision'],
            'expires_at' => gmdate( 'c', $record['expires_at'] ),
            'saved' => true, 'site_mutation_performed' => false,
            'approval_issued' => false, 'secret_values_allowed' => false,
            'legacy_read_only' => $legacy, 'requires_recreate_for_edit' => $legacy );
        if ( $include_values ) { $result['form'] = $record['form']; $result['values'] = $record['values']; }
        return $result;
    }

    private static function persist( $key, $previous_raw, $sealed, $delete, $scope ) {
        global $wpdb;
        $table = self::table( $key );
        if ( is_wp_error( $table ) ) return $table;
        $guard = self::mutation_guard( $scope );
        if ( is_wp_error( $guard ) ) return $guard;
        $raw = $delete ? null : maybe_serialize( $sealed );
        if ( ! $delete && ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_ROW_BYTES ) )
            return self::error( 'DRAFT_RECORD_OVERSIZE' );
        if ( null === $previous_raw ) {
            $sql = "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')";
            $args = array( $key, $raw );
        } elseif ( $delete ) {
            $sql = "DELETE FROM `{$table}` WHERE option_name = %s AND BINARY option_value = BINARY %s";
            $args = array( $key, $previous_raw );
        } else {
            $sql = "UPDATE `{$table}` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s";
            $args = array( $raw, $key, $previous_raw );
        }
        $errors = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
        try { $affected = $wpdb->query( $wpdb->prepare( $sql, $args ) ); }
        finally { if ( is_bool( $errors ) ) $wpdb->suppress_errors( $errors ); }
        wp_cache_delete( $key, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        if ( 1 !== $affected || ! empty( $wpdb->last_error ) )
            return self::error( 0 === $affected ? 'DRAFT_REVISION_CONFLICT' : 'DRAFT_PERSISTENCE_UNCERTAIN' );
        $readback = self::raw( $key );
        if ( is_wp_error( $readback ) ||
            ( $delete ? null !== $readback : ! is_string( $readback ) || ! hash_equals( $raw, $readback ) ) ||
            true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'DRAFT_PERSISTENCE_UNCERTAIN' );
        return true;
    }

    public static function create( $form, $values ) {
        $scope = self::gate( true );
        if ( is_wp_error( $scope ) ) return $scope;
        $valid = self::fields( $form, $values );
        if ( is_wp_error( $valid ) ) return $valid;
        $owner = self::owner();
        if ( is_wp_error( $owner ) ) return $owner;
        $first = ( hexdec( substr( $owner, 0, 2 ) ) % 8 ) * self::ACTOR_SLOTS;
        for ( $slot = $first; $slot < $first + self::ACTOR_SLOTS; $slot++ ) {
            $slot_hex = sprintf( '%02x', $slot );
            $key = 'mad4b_cso_draft_slot_v2_' . $slot_hex;
            $previous = self::raw( $key );
            if ( is_wp_error( $previous ) ) return $previous;
            if ( null !== $previous ) {
                $old = self::decode( $previous );
                // Corrupt/foreign/unexpired rows are never overwritten for quota.
                if ( is_wp_error( $old ) || $old['location']['legacy'] ||
                    $old['location']['key'] !== $key || $old['record']['expires_at'] > time() ) continue;
            }
            try { $id = 'csod2.' . $slot_hex . '.' . bin2hex( random_bytes( 32 ) ); }
            catch ( \Throwable $e ) { return self::error( 'DRAFT_RANDOM_UNAVAILABLE' ); }
            $now = time();
            $record = array( 'contract' => self::CONTRACT, 'id' => $id,
                'scope_sha256' => MAD4B_SCP_CSO_Scope::digest( $scope ),
                'actor_sha256' => $scope['actor_sha256'],
                'form' => array( 'ability_name' => $form['ability_name'],
                    'expected_descriptor_sha256' => $form['expected_descriptor_sha256'] ),
                'values' => $values, 'revision' => 1, 'expires_at' => $now + self::TTL,
                'storage_version' => 2, 'slot' => $slot, 'owner_sha256' => $owner,
                'created_at' => $now, 'updated_at' => $now );
            $sealed = MAD4B_SCP_CSO_Scope::seal( $record, self::CONTRACT );
            if ( is_wp_error( $sealed ) ) return $sealed;
            $saved = self::persist( $key, $previous, $sealed, false, $scope );
            if ( is_wp_error( $saved ) ) return $saved;
            return self::project( $record, false );
        }
        return self::error( 'DRAFT_ACTOR_OR_SITE_CAPACITY_REACHED' );
    }

    public static function load( $id ) {
        $scope = self::gate( false );
        if ( is_wp_error( $scope ) ) return $scope;
        $loaded = self::read( $id, $scope );
        if ( is_wp_error( $loaded ) ) return $loaded;
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'DRAFT_LOAD_SCOPE_CHANGED' );
        return self::project( $loaded['record'], true, $loaded['location']['legacy'] );
    }

    public static function save( $id, $revision, $form, $values ) {
        $scope = self::gate( true );
        if ( is_wp_error( $scope ) ) return $scope;
        if ( ! is_int( $revision ) || $revision < 1 ) return self::error( 'DRAFT_REVISION_INVALID' );
        $valid = self::fields( $form, $values );
        if ( is_wp_error( $valid ) ) return $valid;
        $loaded = self::read( $id, $scope );
        if ( is_wp_error( $loaded ) ) return $loaded;
        if ( $loaded['location']['legacy'] ) return self::error( 'DRAFT_LEGACY_READ_ONLY' );
        if ( $revision !== $loaded['record']['revision'] ) return self::error( 'DRAFT_REVISION_CONFLICT' );
        $next = $loaded['record'];
        $next['form'] = array( 'ability_name' => $form['ability_name'],
            'expected_descriptor_sha256' => $form['expected_descriptor_sha256'] );
        $next['values'] = $values; $next['revision']++;
        $next['updated_at'] = time(); $next['expires_at'] = $next['updated_at'] + self::TTL;
        $sealed = MAD4B_SCP_CSO_Scope::seal( $next, self::CONTRACT );
        if ( is_wp_error( $sealed ) ) return $sealed;
        $saved = self::persist( $loaded['key'], $loaded['raw'], $sealed, false, $scope );
        return is_wp_error( $saved ) ? $saved : self::project( $next, false );
    }

    public static function delete( $id, $revision ) {
        $scope = self::gate( true );
        if ( is_wp_error( $scope ) ) return $scope;
        $loaded = self::read( $id, $scope, true );
        if ( is_wp_error( $loaded ) ) return $loaded;
        if ( $loaded['location']['legacy'] ) return self::error( 'DRAFT_LEGACY_READ_ONLY' );
        if ( ! is_int( $revision ) || $loaded['record']['revision'] !== $revision )
            return self::error( 'DRAFT_REVISION_CONFLICT' );
        $deleted = self::persist( $loaded['key'], $loaded['raw'], null, true, $scope );
        return is_wp_error( $deleted ) ? $deleted : array(
            'contract' => self::CONTRACT, 'deleted' => true,
            'site_mutation_performed' => false, 'id' => $id );
    }
}
