<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP10 independent WPML link readback. A source group key is NEVER
 * presumed equal to WPML's internal integer trid. Nothing is written.
 */
final class MAD4B_SCP_Import_WPML_Readback {
    const CONTRACT = 'mad4b.import-wpml-readback.v1';
    private static function error( $code, $detail ) {
        return new WP_Error( $code, $detail );
    }
    public static function plan( $input = array() ) {
        if ( ! is_array( $input ) || ! current_user_can( 'manage_options' ) )
            return self::error( 'mad4b_wpml_audit_denied',
                'Staging administrator required for independently verified WPML links.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $sha = isset( $input['snapshot_sha256'] ) ? (string) $input['snapshot_sha256'] : '';
        $group_index = isset( $input['group_index'] ) ? $input['group_index'] : 0;
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) ||
            ! is_int( $group_index ) || $group_index < 0 || $group_index >= 500 )
            return self::error( 'mad4b_wpml_audit_input_invalid',
                'Exact source snapshot and bounded translation-group index required.' );
        if ( ! ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ) ) ||
            ! function_exists( 'apply_filters' ) ||
            ! function_exists( 'has_filter' ) ||
            false === has_filter( 'wpml_element_trid' ) ||
            false === has_filter( 'wpml_element_language_details' ) ||
            false === has_filter( 'wpml_get_element_translations' ) )
            return self::error( 'mad4b_wpml_provider_not_available',
                'A compatible, active WPML language-link provider is required.' );
        $loaded = MAD4B_SCP_Activity_Import_Snapshot::raw_snapshot( $slug, $sha );
        if ( is_wp_error( $loaded ) ) return $loaded;
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) ||
            empty( $profile['post_type'] ) )
            return self::error( 'mad4b_wpml_profile_invalid', 'Approved post Profile required.' );
        $policy = MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile );
        $validation = isset( $policy['validation'] ) ? $policy['validation'] : array();
        $identity = isset( $validation['identity_field'] ) ?
            $validation['identity_field'] : '';
        $meta_key = isset( $validation['destination_identity_meta_key'] ) ?
            $validation['destination_identity_meta_key'] : '';
        $group_col = '_wpml_import_translation_group';
        $lang_col = '_wpml_import_language_code';
        $headers = isset( $loaded['input']['headers'] ) ?
            $loaded['input']['headers'] : array();
        if ( !$identity || !$meta_key || ! in_array( $meta_key,
            (array) $profile['meta_keys'], true ) ||
            ! in_array( $group_col, $headers, true ) ||
            ! in_array( $lang_col, $headers, true ) )
            return self::error( 'mad4b_wpml_identity_unbound',
                'Source identity and WPML group/language columns must be explicitly known.' );
        $fresh = MAD4B_SCP_Activity_Import_Review::plan( $loaded['input'] );
        if ( is_wp_error( $fresh ) ||
            ! hash_equals( (string) $loaded['receipt']['plan']['plan_sha256'],
                is_wp_error( $fresh ) ? '' : (string) $fresh['plan_sha256'] ) ||
            (string) $profile['revision'] !==
                (string) $loaded['receipt']['plan']['profile_revision'] ||
            ! hash_equals( (string) $profile['authority_sha256'],
                (string) $loaded['receipt']['plan']['authority_sha256'] ) )
            return self::error( 'mad4b_wpml_source_stale',
                'Encrypted source no longer matches the exact governed Profile.' );
        $groups = array();
        foreach ( $loaded['input']['rows'] as $row ) {
            $group = (string) $row[ $group_col ];
            if ( ! isset( $groups[ $group ] ) ) $groups[ $group ] = array();
            $groups[ $group ][] = $row;
        }
        $keys = array_keys( $groups );
        if ( $group_index >= count( $keys ) )
            return self::error( 'mad4b_wpml_group_out_of_range',
                'Translation-group index is beyond the reviewed source.' );
        $group_key = $keys[ $group_index ];
        $rows = $groups[ $group_key ];
        if ( count( $rows ) > 10 )
            return self::error( 'mad4b_wpml_group_too_large',
                'Readback is limited to ten source records in one translation group.' );
        $trids = array(); $expected_by_lang = array();
        $issues = array(); $found = 0;
        $element_type = 'post_' . $profile['post_type'];
        foreach ( $rows as $row ) {
            $source_identity = (string) $row[ $identity ];
            $lang = (string) $row[ $lang_col ];
            if ( isset( $expected_by_lang[ $lang ] ) ) {
                $issues[] = 'duplicate_language_in_import_group'; continue;
            }
            $posts = get_posts( array(
                'post_type' => $profile['post_type'],
                'post_status' => array( 'publish','draft','pending','private','future','trash','inherit' ),
                'meta_key' => $meta_key, 'meta_value' => $source_identity,
                'meta_compare' => '=', 'numberposts' => 2,
                'fields' => 'ids', 'suppress_filters' => true
            ) );
            if ( ! is_array( $posts ) || count( $posts ) !== 1 ) {
                $issues[] = 'missing_or_ambiguous_external_identity'; continue;
            }
            $post_id = (int) $posts[0];
            if ( (string) get_post_meta( $post_id, $meta_key, true ) !==
                $source_identity ) {
                $issues[] = 'database_collation_identity_mismatch'; continue;
            }
            $trid = apply_filters( 'wpml_element_trid', null, $post_id,
                $element_type );
            $details = apply_filters( 'wpml_element_language_details', null,
                array( 'element_id' => $post_id,
                    'element_type' => $profile['post_type'] ) );
            if ( ! is_numeric( $trid ) || (int) $trid < 1 ||
                ! is_object( $details ) ||
                ! isset( $details->trid, $details->language_code ) ||
                (int) $details->trid !== (int) $trid ||
                (string) $details->language_code !== $lang ) {
                $issues[] = 'wpml_trid_or_language_mismatch'; continue;
            }
            $expected_by_lang[ $lang ] = $post_id;
            $trids[ (int) $trid ] = true;
            $found++;
        }
        if ( count( $trids ) !== 1 )
            $issues[] = 'source_translations_not_in_same_trid';
        if ( count( $trids ) === 1 ) {
            $trid = (int) key( $trids );
            $translations = apply_filters( 'wpml_get_element_translations',
                null, $trid, $element_type );
            if ( ! is_array( $translations ) )
                $issues[] = 'wpml_translations_readback_missing';
            else {
                foreach ( $expected_by_lang as $language => $post_id ) {
                    if ( ! isset( $translations[ $language ] ) ||
                        ! is_object( $translations[ $language ] ) ||
                        ! isset( $translations[ $language ]->element_id ) ||
                        (int) $translations[ $language ]->element_id !== $post_id )
                        $issues[] = 'wpml_translation_member_mismatch';
                }
                if ( ! empty( $validation['require_complete_wpml_groups'] ) ) {
                    foreach ( (array) $validation['wpml_languages'] as $required ) {
                        if ( ! isset( $expected_by_lang[ $required ],
                            $translations[ $required ] ) )
                            $issues[] = 'wpml_required_language_not_linked';
                    }
                }
            }
        }
        $issues = array_values( array_unique( $issues ) );
        $complete = count( $rows ) === $found && !$issues;
        return array(
            'contract' => self::CONTRACT,
            'profile_slug' => $slug,
            'snapshot_sha256' => $sha,
            'group_index' => $group_index,
            'group_key_sha256' => hash( 'sha256', $group_key ),
            'total_groups' => count( $keys ),
            'next_group_index' => $group_index + 1 < count( $keys ) ?
                $group_index + 1 : null,
            'source_group_rows' => count( $rows ),
            'resolved_posts' => $found,
            'independent_wpml_link_audit_passed_for_this_group' => $complete,
            'issues' => $issues,
            'provider_version_certified' => false,
            'all_groups_audited' => count( $keys ) === 1 && $complete,
            'jetengine_relations_verified' => false,
            'source_provenance_verified' => false,
            'external_import_execution_authorized' => false,
            'production_promotion_authorized' => false,
            'read_only' => true, 'mutation_performed' => false
        );
    }
}
