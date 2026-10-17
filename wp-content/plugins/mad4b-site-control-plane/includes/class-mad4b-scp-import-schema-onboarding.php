<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP12: read-only, low-dependency onboarding for a WordPress site that has
 * no Content Experience Profile. Never infer JetEngine CCT table schemas.
 */
final class MAD4B_SCP_Import_Schema_Onboarding {
    const CONTRACT = 'mad4b.import-schema-onboarding.v1';
    private static function err( $code, $message ) {
        return new WP_Error( $code, $message );
    }
    private static function digest( $value ) {
        $json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    private static function normal( $key ) {
        return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $key ) );
    }
    public static function plan( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) ||
            ! MAD4B_SCP_Site_Profile::configured() ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
            ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ||
            ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) )
            return self::err( 'mad4b_schema_onboarding_denied',
                'Exact enrolled Staging administrator required.' );
        if ( array_diff( array_keys( $input ), array(
            'post_type', 'observed_headers' ) ) )
            return self::err( 'mad4b_schema_onboarding_untrusted_options',
                'Source cannot supply Meta schema or execution authority.' );
        $type = isset( $input['post_type'] ) ?
            (string) $input['post_type'] : '';
        $headers = isset( $input['observed_headers'] ) ?
            $input['observed_headers'] : array();
        if ( ! is_array( $headers ) || count( $headers ) > 80 ||
            count( $headers ) !== count( array_unique( $headers ) ) )
            return self::err( 'mad4b_schema_onboarding_headers_invalid',
                'Headers must be unique and limited to 80 source fields.' );
        foreach ( $headers as $header )
            if ( ! is_string( $header ) ||
                ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $header ) )
                return self::err( 'mad4b_schema_onboarding_header_unsafe',
                    'Source field is not a supported bounded identifier.' );
        if ( '' === $type ) {
            $types = get_post_types( array(), 'objects' );
            if ( ! is_array( $types ) )
                return self::err( 'mad4b_schema_onboarding_registry_missing',
                    'WordPress content registry is unavailable.' );
            $result = array();
            foreach ( $types as $name => $object ) {
                if ( count( $result ) >= 60 ) break;
                if ( in_array( $name, array( 'attachment',
                    'revision', 'nav_menu_item' ), true ) ) continue;
                if ( ! is_object( $object ) ) continue;
                $result[] = array(
                    'post_type' => $name,
                    'label' => isset( $object->label ) ?
                        (string) $object->label : $name,
                    'public' => ! empty( $object->public ),
                    'editable_by_current_operator' => isset( $object->cap->edit_posts ) &&
                        current_user_can( $object->cap->edit_posts ),
                    'import_destination_approved' => false
                );
            }
            return array(
                'contract' => self::CONTRACT,
                'mode' => 'discover_post_types',
                'available_post_types' => $result,
                'total_returned' => count( $result ),
                'choose_exact_post_type_before_mapping' => true,
                'cct_table_introspection_proven' => false,
                'read_only' => true, 'mutation_performed' => false
            );
        }
        if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,39}$/D', $type ) ||
            ! post_type_exists( $type ) ||
            in_array( $type, array( 'attachment',
                'revision', 'nav_menu_item' ), true ) )
            return self::err( 'mad4b_schema_onboarding_type_invalid',
                'Choose one registered non-internal WordPress post type.' );
        $object = get_post_type_object( $type );
        if ( ! is_object( $object ) ||
            ! isset( $object->cap->edit_posts ) ||
            ! current_user_can( $object->cap->edit_posts ) )
            return self::err( 'mad4b_schema_onboarding_type_capability',
                'The current administrator cannot edit this content type.' );
        $registry = function_exists( 'get_registered_meta_keys' ) ?
            get_registered_meta_keys( 'post', $type ) : array();
        if ( ! is_array( $registry ) ) $registry = array();
        $safe = array(); $excluded = 0;
        foreach ( $registry as $key => $args ) {
            if ( ! is_string( $key ) || ! is_array( $args ) ||
                empty( $args['show_in_rest'] ) ||
                ( function_exists( 'is_protected_meta' ) &&
                    is_protected_meta( $key, 'post' ) ) ||
                ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $key ) ) {
                $excluded++; continue;
            }
            if ( count( $safe ) >= 128 ) { $excluded++; continue; }
            $safe[] = array(
                'meta_key' => $key, 'registered_type' =>
                    isset( $args['type'] ) ? (string) $args['type'] : 'unknown',
                'rest_schema_visible' => true,
                'requires_profile_approval' => true );
        }
        usort( $safe, static function( $a, $b ) {
            return strcmp( $a['meta_key'], $b['meta_key'] );
        } );
        $names = array_column( $safe, 'meta_key' );
        $taxes = function_exists( 'get_object_taxonomies' ) ?
            get_object_taxonomies( $type, 'objects' ) : array();
        if ( ! is_array( $taxes ) ) $taxes = array();
        $taxonomy = array();
        foreach ( $taxes as $name => $tax ) {
            if ( count( $taxonomy ) >= 32 ) break;
            if ( ! is_object( $tax ) ) continue;
            $taxonomy[] = array( 'taxonomy' => (string) $name,
                'hierarchical' => ! empty( $tax->hierarchical ),
                'assign_allowed' => isset( $tax->cap->assign_terms ) &&
                    current_user_can( $tax->cap->assign_terms ),
                'mapping_approved' => false );
        }
        $suggestions = array();
        foreach ( $headers as $source ) {
            $matches = array_values( array_filter( $names,
                static function( $candidate ) use ( $source ) {
                    return self::normal( $candidate ) ===
                        self::normal( $source );
                } ) );
            if ( count( $matches ) === 1 ) {
                $suggestions[] = array(
                    'source_column' => $source,
                    'candidate_meta_key' => $matches[0],
                    'match_type' => 'lexical_only_not_business_meaning',
                    'approved' => false );
            }
        }
        $jet = class_exists( 'Jet_Engine' ) ||
            function_exists( 'jet_engine' );
        $report = array(
            'contract' => self::CONTRACT,
            'mode' => 'inspect_wordpress_post_type',
            'post_type' => $type,
            'post_type_label' => isset( $object->label ) ?
                (string) $object->label : $type,
            'registered_rest_meta_candidates' => $safe,
            'excluded_meta_key_count' => $excluded,
            'taxonomies' => $taxonomy,
            'observed_source_header_sha256' => self::digest( $headers ),
            'lexical_only_candidates' => $suggestions,
            'jetengine_runtime_detected' => $jet,
            'jetengine_cct_definition_certified' => false,
            'jetengine_relations_certified' => false,
            'site_meta_allowlist_approved' => false,
            'source_identity_field_approved' => false,
            'field_mapping_approved' => false,
            'wordPress_profile_created' => false,
            'next_steps' => array(
                'Confirm source rights and external stable ID',
                'Review and approve exact destination field types and Meta allowlist',
                'For JetEngine CCT, use an independently certified native CCT schema driver',
                'Plan and approve a Content Experience Profile revision',
                'Run mapping maturation and exact encrypted source review on Staging'
            ),
            'read_only' => true, 'mutation_performed' => false
        );
        $report['schema_sha256'] = self::digest( $report );
        return $report;
    }
}
