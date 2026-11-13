<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Typed actors for reversible adapter operations: no synthetic Agent records. */
final class MAD4B_SCP_Mutation_Principal {
    const WP_USER = 'wordpress_user';
    const NHI = 'independent_nhi';

    public static function allowed_native_abilities() {
        return array(
            'mad4b/content-create-post',
            'mad4b/taxonomy-create-term',
            'mad4b/taxonomy-set-object-terms',
        );
    }

    public static function fingerprint( $site_uuid, $user_id ) {
        return hash( 'sha256', 'mad4b:wp-user:v1|' . (string) $site_uuid . '|' . (int) $user_id );
    }

    public static function schema_ready() {
        global $wpdb;
        if ( ! class_exists( 'MAD4B_SCP_Schema' ) ) return false;
        $tables = MAD4B_SCP_Schema::tables();
        $table = $tables['mutations'];
        $rows = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A );
        if ( ! is_array( $rows ) ) return false;
        $fields = array();
        foreach ( $rows as $row ) $fields[ $row['Field'] ] = $row;
        return isset( $fields['principal_type'], $fields['agent_id'], $fields['wp_user_id'] )
            && 'YES' === (string) $fields['agent_id']['Null'];
    }

    public static function resolve( $identity, $ability, $input = array() ) {
        if ( ! is_array( $identity ) ) return new WP_Error( 'mad4b_principal_identity_invalid', 'Verified mutation identity is required.' );
        $user = class_exists( 'MAD4B_SCP_Delegation_Mode' )
            ? MAD4B_SCP_Delegation_Mode::verified_wordpress_user( $identity ) : null;
        if ( $user ) {
            if ( ! in_array( $ability, self::allowed_native_abilities(), true ) )
                return new WP_Error( 'mad4b_principal_native_ability_unmapped', 'This adapter has no native WordPress principal mapping.' );
            if ( ! MAD4B_SCP_Policy::can_mutate() )
                return new WP_Error( 'mad4b_principal_write_denied', 'Site mutation policy denied the WordPress actor.' );
            $cap = MAD4B_SCP_Delegation_Mode::authorize_ability( $identity, $ability, $input );
            if ( is_wp_error( $cap ) || true !== $cap )
                return is_wp_error( $cap ) ? $cap : new WP_Error( 'mad4b_principal_cap_denied', 'WordPress permission denied.' );
            if ( ! self::schema_ready() )
                return new WP_Error( 'mad4b_principal_journal_schema_unready', 'Typed mutation journal migration has not been verified.' );
            $site_uuid = MAD4B_SCP_Site_Profile::site_uuid();
            if ( '' === (string) $site_uuid )
                return new WP_Error( 'mad4b_principal_site_unbound', 'WordPress site identity is unavailable.' );
            return array(
                'principal_type' => self::WP_USER,
                'agent_id' => null,
                'wp_user_id' => (int) $user->ID,
                'subject_type' => self::WP_USER,
                'subject_fingerprint' => self::fingerprint( $site_uuid, $user->ID ),
                'actor' => array( 'principal_type' => self::WP_USER, 'wp_user_id' => (int) $user->ID ),
            );
        }
        $agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
        if ( is_wp_error( $agent ) ) return $agent;
        return array(
            'principal_type' => self::NHI,
            'agent_id' => (int) $agent['id'],
            'wp_user_id' => get_current_user_id(),
            'subject_type' => (string) ( $identity['subject_type'] ?? '' ),
            'subject_fingerprint' => (string) ( $identity['subject_fingerprint'] ?? '' ),
            'actor' => $agent,
        );
    }

    public static function owner_for_undo( $identity, array $record, $adapter, array $target ) {
        $type = (string) ( $record['principal_type'] ?? self::NHI );
        if ( '' === $type ) $type = self::NHI; // pre-migration rows
        if ( self::NHI === $type ) {
            $agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
            if ( is_wp_error( $agent ) ) return $agent;
            if ( (int) $agent['id'] !== (int) $record['agent_id']
                && ! apply_filters( 'mad4b_scp_allow_cross_agent_undo', false, $record, $agent ) )
                return new WP_Error( 'mad4b_undo_agent_denied', 'Different NHI agent cannot undo this mutation.' );
            return array(
                'principal_type' => self::NHI, 'agent_id' => (int) $agent['id'],
                'wp_user_id' => get_current_user_id(),
                'subject_type' => (string) ( $identity['subject_type'] ?? '' ),
                'subject_fingerprint' => (string) ( $identity['subject_fingerprint'] ?? '' ),
                'actor' => $agent,
            );
        }
        if ( self::WP_USER !== $type ) return new WP_Error( 'mad4b_undo_principal_unknown', 'Unknown recorded principal.' );
        $ability = (string) ( $record['ability_name'] ?? '' );
        if ( ! in_array( $ability, self::allowed_native_abilities(), true ) )
            return new WP_Error( 'mad4b_undo_native_ability_unmapped', 'Native undo is not enabled for this adapter.' );
        $user = MAD4B_SCP_Delegation_Mode::verified_wordpress_user( $identity );
        if ( ! $user || (int) $record['wp_user_id'] !== (int) $user->ID )
            return new WP_Error( 'mad4b_undo_wordpress_owner_denied', 'Only the authenticated original WordPress user may undo this write.' );
        $site_uuid = MAD4B_SCP_Site_Profile::site_uuid();
        $expected = self::fingerprint( $site_uuid, $user->ID );
        if ( ! $site_uuid || ! hash_equals( $expected, (string) ( $record['subject_fingerprint'] ?? '' ) ) )
            return new WP_Error( 'mad4b_undo_wordpress_site_denied', 'WordPress actor is bound to a different site identity.' );
        if ( ! MAD4B_SCP_Policy::can_mutate() )
            return new WP_Error( 'mad4b_undo_wordpress_write_denied', 'Site mutation gate denied undo.' );
        if ( 'mad4b/content-create-post' === $ability ) {
            $state = $adapter->read_reversible_state( $ability, $target );
            if ( is_wp_error( $state ) ) return $state;
            $id = absint( $state['post_id'] ?? 0 );
            if ( ! $id || ! user_can( $user, 'delete_post', $id ) )
                return new WP_Error( 'mad4b_undo_wordpress_post_denied', 'User cannot delete the created post.' );
        } elseif ( 'mad4b/taxonomy-create-term' === $ability ) {
            $taxonomy = sanitize_key( (string) ( $target['taxonomy'] ?? '' ) );
            $object = $taxonomy ? get_taxonomy( $taxonomy ) : null;
            if ( ! $object || ! user_can( $user, $object->cap->manage_terms ) )
                return new WP_Error( 'mad4b_undo_wordpress_taxonomy_denied', 'User cannot manage this taxonomy.' );
        } elseif ( 'mad4b/taxonomy-set-object-terms' === $ability ) {
            $taxonomy = sanitize_key( (string) ( $target['taxonomy'] ?? '' ) );
            $object = $taxonomy ? get_taxonomy( $taxonomy ) : null;
            $id = absint( $target['object_id'] ?? 0 );
            if ( ! $id || ! $object || ! user_can( $user, 'edit_post', $id )
                || ! user_can( $user, $object->cap->assign_terms ) )
                return new WP_Error( 'mad4b_undo_wordpress_object_denied', 'WordPress user cannot change these object terms.' );
        }
        return array(
            'principal_type' => self::WP_USER, 'agent_id' => null,
            'wp_user_id' => (int) $user->ID, 'subject_type' => self::WP_USER,
            'subject_fingerprint' => $expected,
            'actor' => array( 'principal_type' => self::WP_USER, 'wp_user_id' => (int) $user->ID ),
        );
    }
}
