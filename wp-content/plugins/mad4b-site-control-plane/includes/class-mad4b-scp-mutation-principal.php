<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Journal actor contract. Native WordPress users are never assigned fake NHI ids.
 * An unrecognized Ability must retain its prior independent-NHI execution path.
 */
final class MAD4B_SCP_Mutation_Principal {
    const CONTRACT = 'mad4b.mutation-principal.v1';
    const WP_USER = 'wordpress_user';
    const NHI = 'independent_nhi';

    public static function wp_fingerprint( $site_uuid, $user_id ) {
        return hash( 'sha256', 'mad4b:wordpress_user:' . (string) $site_uuid . ':' . (int) $user_id );
    }

    public static function native_ability( $ability ) {
        if ( ! class_exists( 'MAD4B_SCP_Delegation_Mode' )
            || ! method_exists( 'MAD4B_SCP_Delegation_Mode', 'native_adapter_abilities' ) ) return false;
        return in_array( (string) $ability, MAD4B_SCP_Delegation_Mode::native_adapter_abilities(), true );
    }

    public static function resolve( $identity, $ability, $input = array() ) {
        if ( is_wp_error( $identity ) || ! is_array( $identity ) )
            return new WP_Error( 'mad4b_principal_identity_unverified', 'Mutation identity is unavailable.' );
        $user = class_exists( 'MAD4B_SCP_Delegation_Mode' )
            ? MAD4B_SCP_Delegation_Mode::verified_wordpress_user( $identity ) : null;
        if ( $user ) {
            if ( ! self::native_ability( $ability ) )
                return new WP_Error( 'mad4b_principal_ability_unmapped', 'The requested adapter Ability has no native WordPress permission mapping.' );
            if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_mutate() )
                return new WP_Error( 'mad4b_principal_site_write_denied', 'Site write gate denied the operation.' );
            $allowed = MAD4B_SCP_Delegation_Mode::authorize_ability( $identity, $ability, $input );
            if ( is_wp_error( $allowed ) || true !== $allowed )
                return is_wp_error( $allowed ) ? $allowed : new WP_Error( 'mad4b_principal_capability_denied', 'WordPress capability denied.' );
            $site_uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '';
            if ( '' === $site_uuid ) return new WP_Error( 'mad4b_principal_site_unbound', 'Site identity is unavailable.' );
            return array(
                'principal_type' => self::WP_USER,
                'agent_id' => null,
                'wp_user_id' => (int) $user->ID,
                'subject_type' => self::WP_USER,
                'subject_fingerprint' => self::wp_fingerprint( $site_uuid, $user->ID ),
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

    /** Exact owner must authorize the undo; legacy NHI journal semantics remain. */
    public static function owner_for_undo( $identity, array $record, array $target, $adapter ) {
        $type = isset( $record['principal_type'] ) && '' !== $record['principal_type']
            ? (string) $record['principal_type'] : self::NHI;
        if ( self::WP_USER !== $type ) {
            if ( self::NHI !== $type ) return new WP_Error( 'mad4b_undo_principal_invalid', 'Unknown recorded principal type.' );
            $agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
            if ( is_wp_error( $agent ) ) return $agent;
            if ( (int) $agent['id'] !== (int) $record['agent_id']
                && ! apply_filters( 'mad4b_scp_allow_cross_agent_undo', false, $record, $agent ) )
                return new WP_Error( 'mad4b_undo_agent_denied', 'Another NHI agent cannot undo this mutation.' );
            return self::resolve( $identity, (string) $record['ability_name'] );
        }
        if ( ! self::native_ability( $record['ability_name'] ) )
            return new WP_Error( 'mad4b_undo_principal_ability_unmapped', 'This adapter has no native WordPress undo mapping.' );
        $user = class_exists( 'MAD4B_SCP_Delegation_Mode' )
            ? MAD4B_SCP_Delegation_Mode::verified_wordpress_user( $identity ) : null;
        if ( ! $user || (int) $record['wp_user_id'] !== (int) $user->ID )
            return new WP_Error( 'mad4b_undo_wordpress_user_denied', 'Only the original authenticated WordPress user can undo this mutation.' );
        $site_uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '';
        if ( ! $site_uuid || ! hash_equals(
            self::wp_fingerprint( $site_uuid, $user->ID ),
            (string) ( $record['subject_fingerprint'] ?? '' ) ) )
            return new WP_Error( 'mad4b_undo_wordpress_site_denied', 'Recorded WordPress actor belongs to a different site identity.' );
        if ( ! MAD4B_SCP_Policy::can_mutate() )
            return new WP_Error( 'mad4b_undo_wordpress_write_denied', 'WordPress write gate denied undo.' );
        $ability = (string) $record['ability_name'];
        if ( 'mad4b/content-create-post' === $ability ) {
            $state = $adapter->read_reversible_state( $ability, $target );
            if ( is_wp_error( $state ) ) return $state;
            $id = isset( $state['post_id'] ) ? absint( $state['post_id'] ) : 0;
            if ( $id > 0 && ! user_can( $user, 'delete_post', $id ) )
                return new WP_Error( 'mad4b_undo_wordpress_post_denied', 'WordPress user cannot delete the created post.' );
        } elseif ( 'mad4b/taxonomy-create-term' === $ability ) {
            $taxonomy = isset( $target['taxonomy'] ) ? sanitize_key( $target['taxonomy'] ) : '';
            $tax = $taxonomy ? get_taxonomy( $taxonomy ) : null;
            if ( ! $tax || ! user_can( $user, $tax->cap->manage_terms ) )
                return new WP_Error( 'mad4b_undo_wordpress_term_denied', 'WordPress user cannot manage this taxonomy.' );
        } elseif ( 'mad4b/taxonomy-set-object-terms' === $ability ) {
            $id = isset( $target['object_id'] ) ? absint( $target['object_id'] ) : 0;
            $taxonomy = isset( $target['taxonomy'] ) ? sanitize_key( $target['taxonomy'] ) : '';
            $tax = $taxonomy ? get_taxonomy( $taxonomy ) : null;
            if ( ! $id || ! $tax || ! user_can( $user, 'edit_post', $id )
                || ! user_can( $user, $tax->cap->assign_terms ) )
                return new WP_Error( 'mad4b_undo_wordpress_assignment_denied', 'WordPress user cannot edit this object or assign its taxonomy terms.' );
        } else {
            return new WP_Error( 'mad4b_undo_wordpress_mapping_missing', 'No native WordPress undo policy for this Ability.' );
        }
        return array(
            'principal_type' => self::WP_USER, 'agent_id' => null,
            'wp_user_id' => (int) $user->ID, 'subject_type' => self::WP_USER,
            'subject_fingerprint' => self::wp_fingerprint( $site_uuid, $user->ID ),
            'actor' => array( 'principal_type' => self::WP_USER, 'wp_user_id' => (int) $user->ID ),
        );
    }

    /** Used before any native journal write. Migration is explicit and fail-closed. */
    public static function journal_schema_ready() {
        global $wpdb;
        if ( ! class_exists( 'MAD4B_SCP_Schema' ) ) return false;
        $table = MAD4B_SCP_Schema::tables()['mutations'];
        $rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        if ( ! is_array( $rows ) ) return false;
        $columns = array();
        foreach ( $rows as $row ) $columns[ $row['Field'] ] = $row;
        return isset( $columns['principal_type'], $columns['agent_id'], $columns['wp_user_id'] )
            && 'YES' === (string) $columns['agent_id']['Null'];
    }
}
