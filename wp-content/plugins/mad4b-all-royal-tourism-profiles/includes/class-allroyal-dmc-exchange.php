<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** All Royal-specific DMC catalog import/export settings and plans. */
final class MAD4B_All_Royal_DMC_Exchange {
    const CONTRACT = 'allroyal.dmc-exchange.v1';
    const POLICY_META = '_allroyal_dmc_exchange_policy';
    const FIELDS = array( 'post_title', 'post_excerpt', 'post_content' );
    private static function err( $c, $msg ) { return new WP_Error( $c, $msg ); }

    private static function policy( $id ) {
        if ( 'dmcs' !== get_post_type( $id ) ) return self::err( 'allroyal_dmc_not_found', 'DMC profile not registered.' );
        $policy = get_post_meta( $id, self::POLICY_META, true );
        if ( ! is_array( $policy ) ) return self::err( 'allroyal_dmc_policy_missing', 'A configured DMC exchange policy is required.' );
        return $policy;
    }
    private static function digest( $v ) { return hash( 'sha256', wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }

    public static function configure( $input = array() ) {
        if ( ! MAD4B_All_Royal_Tourism_Profiles::can_write() )
            return self::err( 'allroyal_dmc_admin_required', 'Site-scoped Staging administrator required.' );
        $id = isset( $input['dmc_post_id'] ) ? absint( $input['dmc_post_id'] ) : 0;
        if ( 'dmcs' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) )
            return self::err( 'allroyal_dmc_target_missing', 'DMC profile not available.' );
        $old = get_post_meta( $id, self::POLICY_META, true );
        if ( ! is_array( $old ) ) $old = array();
        $expected = isset( $input['expected_sha256'] ) ? (string) $input['expected_sha256'] : '';
        if ( empty( $input['confirmed'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ||
            ! hash_equals( self::digest( $old ), $expected ) )
            return self::err( 'allroyal_dmc_policy_stale', 'Exact policy revision/confirmation required.' );
        $new = isset( $input['policy'] ) && is_array( $input['policy'] ) ? $input['policy'] : array();
        if ( array_diff( array_keys( $new ), array( 'directions', 'post_types', 'fields', 'enabled' ) ) )
            return self::err( 'allroyal_dmc_policy_unknown_field', 'Unknown DMC policy fields are not allowed.' );
        $directions = isset( $new['directions'] ) ? $new['directions'] : array();
        $types = isset( $new['post_types'] ) ? $new['post_types'] : array();
        $fields = isset( $new['fields'] ) ? $new['fields'] : array();
        if ( ! is_array( $directions ) || ! is_array( $types ) || ! is_array( $fields )
            || !$types || count( $types ) > 12 || count( $directions ) > 2
            || array_diff( $directions, array( 'import', 'export' ) )
            || array_diff( $fields, self::FIELDS ) )
            return self::err( 'allroyal_dmc_policy_invalid', 'Invalid DMC directions/post types/field mapping.' );
        foreach ( $types as $type ) {
            if ( ! is_string( $type ) || 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $type ) || ! post_type_exists( $type ) )
                return self::err( 'allroyal_dmc_post_type_invalid', 'Mapped tourism product type must already be registered.' );
        }
        $new = array( 'directions' => array_values( array_unique( $directions ) ),
            'post_types' => array_values( array_unique( $types ) ),
            'fields' => array_values( array_unique( $fields ) ),
            'enabled' => ! empty( $new['enabled'] ) );
        update_post_meta( $id, self::POLICY_META, $new );
        $back = get_post_meta( $id, self::POLICY_META, true );
        if ( ! is_array( $back ) || ! hash_equals( self::digest( $new ), self::digest( $back ) ) )
            return self::err( 'allroyal_dmc_policy_readback_failed', 'DMC exchange policy update did not match readback.' );
        return array( 'contract' => self::CONTRACT, 'policy_sha256' => self::digest( $new ),
            'dmc_post_id' => $id, 'updated' => true, 'mutation_performed' => true );
    }
    public static function plan( $input = array() ) {
        if ( ! MAD4B_All_Royal_Tourism_Profiles::can_read() )
            return self::err( 'allroyal_dmc_read_denied', 'Site-scoped DMC inspection denied.' );
        $id = isset( $input['dmc_post_id'] ) ? absint( $input['dmc_post_id'] ) : 0;
        $mode = isset( $input['direction'] ) ? (string) $input['direction'] : '';
        $type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
        $policy = self::policy( $id );
        if ( is_wp_error( $policy ) ) return $policy;
        if ( ! in_array( $mode, array( 'import', 'export' ), true )
            || empty( $policy['enabled'] ) || ! in_array( $mode, isset( $policy['directions'] ) ? $policy['directions'] : array(), true )
            || ! in_array( $type, isset( $policy['post_types'] ) ? $policy['post_types'] : array(), true ) )
            return self::err( 'allroyal_dmc_direction_or_mapping_denied', 'DMC exchange mode or product mapping not enabled.' );
        $obj = get_post_type_object( $type );
        if ( ! $obj ) return self::err( 'allroyal_dmc_product_type_missing', 'Destination product CPT does not exist.' );
        $fields = isset( $policy['fields'] ) && is_array( $policy['fields'] ) ? $policy['fields'] : array();
        if ( array_diff( $fields, self::FIELDS ) )
            return self::err( 'allroyal_dmc_fields_unsafe', 'DMC mapped fields exceed safe allowlist.' );
        $cap = 'import' === $mode ? ( isset( $obj->cap->create_posts ) ? $obj->cap->create_posts : 'edit_posts' )
            : ( isset( $obj->cap->edit_posts ) ? $obj->cap->edit_posts : 'edit_posts' );
        return array( 'contract' => self::CONTRACT, 'dmc_post_id' => $id,
            'direction' => $mode, 'post_type' => $type,
            'policy_sha256' => self::digest( $policy ),
            'mapped_fields' => $fields,
            'taxonomies' => array_values( (array) get_object_taxonomies( $type ) ),
            'wordpress_permission' => current_user_can( $cap ),
            'requires_separate_content_mutation_approval' => true,
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function import_prepare( $input = array() ) {
        $plan = self::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( 'import' !== $plan['direction'] || ! $plan['wordpress_permission'] )
            return self::err( 'allroyal_dmc_import_denied', 'DMC import planning denied.' );
        $items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array();
        if ( !$items || count( $items ) > 20 ) return self::err( 'allroyal_dmc_import_count', 'One to twenty records required.' );
        $seen = array(); $out = array();
        foreach ( $items as $item ) {
            $key = isset( $item['external_id'] ) ? (string) $item['external_id'] : '';
            if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $key ) || isset( $seen[ $key ] ) )
                return self::err( 'allroyal_dmc_external_id_invalid', 'External record IDs must be unique and bounded.' );
            $seen[ $key ] = true;
            $record = array( 'post_status' => 'draft' );
            foreach ( $plan['mapped_fields'] as $field ) if ( isset( $item[ $field ] ) && is_string( $item[ $field ] ) ) {
                $limit = 'post_title' === $field ? 1000 : ( 'post_excerpt' === $field ? 262144 : 2097152 );
                if ( strlen( $item[ $field ] ) > $limit ) return self::err( 'allroyal_dmc_field_size', 'Mapped field too large.' );
                $record[ $field ] = $item[ $field ];
            }
            if ( empty( $record['post_title'] ) ) return self::err( 'allroyal_dmc_import_title_missing', 'DMC import requires mapped title.' );
            $out[] = array( 'mode' => 'create', 'post_type' => $plan['post_type'],
                'post' => $record, 'operation_key' => 'ardmc-' . hash( 'sha256', $plan['dmc_post_id'] . '|' . $key ),
                'next_ability' => 'mad4b/content-orchestration-plan', 'external_id' => $key );
        }
        return array( 'contract' => self::CONTRACT, 'draft_candidates' => $out,
            'requires_exact_governed_content_write' => true, 'read_only' => true, 'mutation_performed' => false );
    }
    public static function export_preview( $input = array() ) {
        $plan = self::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( 'export' !== $plan['direction'] || ! $plan['wordpress_permission'] )
            return self::err( 'allroyal_dmc_export_denied', 'DMC export denied.' );
        $limit = isset( $input['limit'] ) ? max( 1, min( 20, (int) $input['limit'] ) ) : 10;
        $offset = isset( $input['offset'] ) ? max( 0, min( 100000, (int) $input['offset'] ) ) : 0;
        $posts = get_posts( array( 'post_type' => $plan['post_type'],
            'post_status' => 'publish', 'posts_per_page' => $limit, 'offset' => $offset,
            'orderby' => 'ID', 'order' => 'ASC' ) );
        if ( ! is_array( $posts ) ) return self::err( 'allroyal_dmc_export_read_failed', 'CPT query failed.' );
        $rows = array();
        foreach ( $posts as $post ) {
            if ( ! isset( $post->ID ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) continue;
            $row = array( 'post_id' => (int) $post->ID );
            foreach ( $plan['mapped_fields'] as $field ) $row[ $field ] = isset( $post->$field ) ? (string) $post->$field : '';
            $rows[] = $row;
        }
        return array( 'contract' => self::CONTRACT, 'items' => $rows, 'count' => count( $rows ),
            'data_sent' => false, 'read_only' => true, 'mutation_performed' => false );
    }
}
