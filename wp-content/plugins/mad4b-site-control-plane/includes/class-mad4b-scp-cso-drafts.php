<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Private, nonsecret, revisioned drafts. Fixed-name WordPress options are used
 * only as an ownership-bound journal; arbitrary option keys are never accepted.
 * There is NO automatic content, provider or plugin-setting mutation.
 */
final class MAD4B_SCP_CSO_Drafts {
    const CONTRACT = 'mad4b.cso.private-draft.v1';
    const TTL = 172800;

    private static function error( $reason ) {
        return MAD4B_SCP_CSO_Scope::error( $reason );
    }

    private static function gate( $mutate ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'forms' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() )
            return self::error( 'DRAFT_FIRST_PARTY_REQUIRED' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        if ( $mutate && ( ! class_exists( 'MAD4B_SCP_Policy', false ) ||
            ! MAD4B_SCP_Policy::can_mutate() ||
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

    private static function identifier( $id ) {
        return is_string( $id ) && 1 === preg_match( '/^csod\.[a-f0-9]{64}$/D', $id );
    }

    private static function key( $id ) {
        return 'mad4b_cso_draft_' . hash( 'sha256', $id );
    }

    private static function fields( $form, $values ) {
        if ( ! is_array( $form ) || ! is_array( $values ) ||
            ! isset( $form['ability_name'], $form['expected_descriptor_sha256'] ) ||
            array_diff( array_keys( $form ), array( 'ability_name', 'expected_descriptor_sha256' ) ) ||
            ! MAD4B_SCP_CSO_Scope::safe_data( $values ) ||
            ! MAD4B_SCP_CSO_Scope::bounded( $values ) )
            return self::error( 'DRAFT_VALUES_INVALID' );
        $valid = MAD4B_SCP_CSO_Forms::validate( $form, $values );
        return is_wp_error( $valid ) || empty( $valid['valid'] ) ?
            self::error( 'DRAFT_DESCRIPTOR_OR_FIELDS_INVALID' ) : true;
    }

    private static function read( $id, $scope ) {
        global $wpdb;
        if ( ! self::identifier( $id ) || ! is_object( $wpdb ) ||
            ! isset( $wpdb->options ) )
            return self::error( 'DRAFT_STORAGE_UNAVAILABLE' );
        $key = self::key( $id );
        $raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
        if ( null === $raw || ! empty( $wpdb->last_error ) )
            return self::error( 'DRAFT_NOT_FOUND' );
        $sealed = maybe_unserialize( $raw );
        if ( ! is_array( $sealed ) ) return self::error( 'DRAFT_CORRUPT' );
        $record = MAD4B_SCP_CSO_Scope::unseal( $sealed, self::CONTRACT );
        if ( is_wp_error( $record ) || ! is_array( $record ) ||
            ( $record['contract'] ?? null ) !== self::CONTRACT ||
            ( $record['id'] ?? null ) !== $id ||
            ! isset( $record['scope_sha256'], $record['actor_sha256'], $record['revision'] ) ||
            ! hash_equals( (string) $record['scope_sha256'],
                MAD4B_SCP_CSO_Scope::digest( $scope ) ) ||
            ! hash_equals( (string) $record['actor_sha256'],
                (string) ( $scope['actor_sha256'] ?? '' ) ) ||
            ! is_int( $record['revision'] ) || $record['revision'] < 1 ||
            ! is_int( $record['expires_at'] ?? null ) )
            return self::error( 'DRAFT_OWNERSHIP_OR_RECORD_INVALID' );
        if ( $record['expires_at'] <= time() ) return self::error( 'DRAFT_EXPIRED' );
        return array( 'record' => $record, 'sealed' => $sealed, 'key' => $key );
    }

    private static function project( $record, $include_values ) {
        $result = array( 'contract' => self::CONTRACT,
            'id' => $record['id'], 'revision' => $record['revision'],
            'expires_at' => gmdate( 'c', $record['expires_at'] ),
            'saved' => true, 'site_mutation_performed' => false,
            'approval_issued' => false, 'secret_values_allowed' => false );
        if ( $include_values ) {
            $result['form'] = $record['form'];
            $result['values'] = $record['values'];
        }
        return $result;
    }

    private static function update( $key, $old, $new, $delete ) {
        global $wpdb;
        $statement = $delete ?
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s" :
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s";
        $args = $delete ?
            array( $key, maybe_serialize( $old ) ) :
            array( maybe_serialize( $new ), $key, maybe_serialize( $old ) );
        $affected = $wpdb->query( $wpdb->prepare( $statement, $args ) );
        wp_cache_delete( $key, 'options' );
        return 1 === $affected && empty( $wpdb->last_error ) ?
            true : self::error( 'DRAFT_REVISION_CONFLICT' );
    }

    public static function create( $form, $values ) {
        $scope = self::gate( true );
        if ( is_wp_error( $scope ) ) return $scope;
        $valid = self::fields( $form, $values );
        if ( is_wp_error( $valid ) ) return $valid;
        try { $id = 'csod.' . bin2hex( random_bytes( 32 ) ); }
        catch ( \Throwable $e ) { return self::error( 'DRAFT_RANDOM_UNAVAILABLE' ); }
        $record = array( 'contract' => self::CONTRACT, 'id' => $id,
            'scope_sha256' => MAD4B_SCP_CSO_Scope::digest( $scope ),
            'actor_sha256' => $scope['actor_sha256'],
            'form' => $form, 'values' => $values, 'revision' => 1,
            'expires_at' => time() + self::TTL );
        $sealed = MAD4B_SCP_CSO_Scope::seal( $record, self::CONTRACT );
        if ( is_wp_error( $sealed ) ) return $sealed;
        if ( ! add_option( self::key( $id ), $sealed, '', false ) )
            return self::error( 'DRAFT_CREATE_CONFLICT' );
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'DRAFT_SAVE_SCOPE_UNCERTAIN' );
        return self::project( $record, false );
    }

    public static function load( $id ) {
        $scope = self::gate( false );
        if ( is_wp_error( $scope ) ) return $scope;
        $loaded = self::read( $id, $scope );
        if ( is_wp_error( $loaded ) ) return $loaded;
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'DRAFT_LOAD_SCOPE_CHANGED' );
        return self::project( $loaded['record'], true );
    }

    public static function save( $id, $revision, $form, $values ) {
        $scope = self::gate( true );
        if ( is_wp_error( $scope ) ) return $scope;
        if ( ! is_int( $revision ) || $revision < 1 ) return self::error( 'DRAFT_REVISION_INVALID' );
        $valid = self::fields( $form, $values );
        if ( is_wp_error( $valid ) ) return $valid;
        $loaded = self::read( $id, $scope );
        if ( is_wp_error( $loaded ) ) return $loaded;
        if ( $revision !== $loaded['record']['revision'] )
            return self::error( 'DRAFT_REVISION_CONFLICT' );
        $next = $loaded['record'];
        $next['form'] = $form;
        $next['values'] = $values;
        $next['revision']++;
        $next['expires_at'] = time() + self::TTL;
        $sealed = MAD4B_SCP_CSO_Scope::seal( $next, self::CONTRACT );
        if ( is_wp_error( $sealed ) ) return $sealed;
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'DRAFT_SAVE_SCOPE_CHANGED' );
        $saved = self::update( $loaded['key'], $loaded['sealed'], $sealed, false );
        if ( is_wp_error( $saved ) ) return $saved;
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'DRAFT_SAVE_SCOPE_UNCERTAIN' );
        return self::project( $next, false );
    }

    public static function delete( $id, $revision ) {
        $scope = self::gate( true );
        if ( is_wp_error( $scope ) ) return $scope;
        $loaded = self::read( $id, $scope );
        if ( is_wp_error( $loaded ) ) return $loaded;
        if ( ! is_int( $revision ) || $loaded['record']['revision'] !== $revision )
            return self::error( 'DRAFT_REVISION_CONFLICT' );
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return self::error( 'DRAFT_DELETE_SCOPE_CHANGED' );
        $deleted = self::update( $loaded['key'], $loaded['sealed'], null, true );
        return is_wp_error( $deleted ) ? $deleted : array(
            'contract' => self::CONTRACT, 'deleted' => true,
            'site_mutation_performed' => false, 'id' => $id );
    }
}
