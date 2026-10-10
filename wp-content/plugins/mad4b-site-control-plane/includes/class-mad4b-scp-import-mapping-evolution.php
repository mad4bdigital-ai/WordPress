<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP10 Dynamic Mapping Mutation/Maturation — read-only Schema Drift plan.
 * A proposed rename, type conversion or provider adapter never changes the
 * approved field mapping. Only a subsequent governed Profile revision could.
 */
final class MAD4B_SCP_Import_Mapping_Evolution {
    const CONTRACT = 'mad4b.import-mapping-evolution.v1';
    private static function err( $code, $text ) {
        return new WP_Error( $code, $text );
    }
    private static function digest( $data ) {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    private static function canonical( $name ) {
        return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) );
    }
    private static function classify( $header, $policy ) {
        if ( $header === $policy['identity_field'] ||
            $header === ( isset( $policy['destination_identity_meta_key'] ) ?
                $policy['destination_identity_meta_key'] : '' ) )
            return 'identity_critical';
        if ( in_array( $header, (array) $policy['price_fields'], true ) ||
            $header === $policy['currency_field'] )
            return 'commercial_critical';
        if ( in_array( $header, array(
            'period_start_field' => isset( $policy['period_start_field'] ) ?
                $policy['period_start_field'] : '',
            'period_end_field' => isset( $policy['period_end_field'] ) ?
                $policy['period_end_field'] : ''
        ), true ) ) return 'temporal_critical';
        if ( 0 === strpos( $header, '_wpml_' ) ) return 'translation_critical';
        if ( in_array( $header, (array) $policy['required_relationships'], true ) )
            return 'relationship_critical';
        if ( in_array( $header, (array) $policy['required_columns'], true ) )
            return 'required_field';
        return 'ordinary';
    }
    private static function type_profile( $rows, $field ) {
        $seen = array( 'empty' => 0, 'number' => 0, 'iso_date' => 0,
            'boolean' => 0, 'text' => 0, 'nested' => 0 );
        foreach ( array_slice( $rows, 0, 100 ) as $row ) {
            $value = isset( $row[ $field ] ) ? $row[ $field ] : '';
            if ( ! is_scalar( $value ) && null !== $value ) $kind = 'nested';
            elseif ( '' === (string) $value ) $kind = 'empty';
            elseif ( is_bool( $value ) ) $kind = 'boolean';
            elseif ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/D', (string) $value ) )
                $kind = 'iso_date';
            elseif ( preg_match( '/^-?\\d+(?:\\.\\d+)?$/D', (string) $value ) )
                $kind = 'number';
            else $kind = 'text';
            $seen[ $kind ]++;
        }
        return $seen;
    }
    private static function requirements( $risk ) {
        if ( in_array( $risk, array( 'identity_critical',
            'commercial_critical', 'translation_critical',
            'temporal_critical', 'relationship_critical' ), true ) )
            return array( 'exact_profile_revision_approval',
                'human_domain_owner_review', 'staging_sample_and_negative_tests',
                'independent_postwrite_provider_readback' );
        return array( 'exact_profile_revision_approval',
            'staging_sample_and_negative_tests', 'independent_postwrite_readback' );
    }
    public static function plan( $input = array() ) {
        if ( ! is_array( $input ) || ! current_user_can( 'manage_options' ) )
            return self::err( 'mad4b_mapping_plan_denied',
                'An enrolled Staging administrator is required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $sha = isset( $input['snapshot_sha256'] ) ?
            (string) $input['snapshot_sha256'] : '';
        $header_only = array_key_exists( 'observed_headers', $input );
        $expected_authority = isset( $input['expected_profile_authority_sha256'] ) ?
            (string) $input['expected_profile_authority_sha256'] : '';
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) ||
            array_diff( array_keys( $input ), array( 'profile_slug',
                'snapshot_sha256', 'observed_headers',
                'expected_profile_authority_sha256' ) ) ||
            ( $header_only && ( '' !== $sha ||
                ! preg_match( '/^[a-f0-9]{64}$/D', $expected_authority ) ) ) ||
            ( !$header_only && ( ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) ||
                '' !== $expected_authority ) ) )
            return self::err( 'mad4b_mapping_plan_input_invalid',
                'Use either exact immutable snapshot or observed header names bound to the Profile authority.' );
        $loaded = null;
        if ( !$header_only ) {
            if ( ! class_exists( 'MAD4B_SCP_Activity_Import_Snapshot' ) )
                return self::err( 'mad4b_mapping_snapshot_unavailable',
                    'Encrypted Staging source facility unavailable.' );
            $loaded = MAD4B_SCP_Activity_Import_Snapshot::raw_snapshot( $slug, $sha );
            if ( is_wp_error( $loaded ) ) return $loaded;
        }
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) )
            return self::err( 'mad4b_mapping_profile_missing',
                'Exact approved Profile is not active.' );
        $contract = MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile );
        $policy = isset( $contract['validation'] ) ? $contract['validation'] : array();
        if ( !$policy || empty( $policy['field_mapping'] ) )
            return self::err( 'mad4b_mapping_policy_not_configured',
                'Approved site-owned field mapping is required.' );
        if ( ( $header_only && ! hash_equals(
                (string) $profile['authority_sha256'], $expected_authority ) ) ||
            ( !$header_only && (
                (string) $loaded['receipt']['plan']['profile_revision'] !==
                    (string) $profile['revision'] ||
                ! hash_equals( (string) $loaded['receipt']['plan']['authority_sha256'],
                    (string) $profile['authority_sha256'] ) ||
                ! hash_equals( (string) $loaded['receipt']['plan']['policy_sha256'],
                    MAD4B_SCP_Activity_Import_Authority::digest( $policy ) ) ) ) )
            return self::err( 'mad4b_mapping_profile_drift',
                'Exact Profile authority changed. A fresh mapping review is required.' );
        $columns = $header_only ? $input['observed_headers'] :
            $loaded['input']['headers'];
        $rows = $header_only ? array() : $loaded['input']['rows'];
        if ( ! is_array( $columns ) || !$columns || count( $columns ) > 80 ||
            count( $columns ) !== count( array_unique( $columns ) ) )
            return self::err( 'mad4b_mapping_headers_invalid',
                'Source column identity is ambiguous or out of bounds.' );
        foreach ( $columns as $column ) {
            if ( ! is_string( $column ) ||
                ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $column ) )
                return self::err( 'mad4b_mapping_header_unsupported',
                    'A source column name must be a bounded valid identifier.' );
        }
        $mapped = $policy['field_mapping'];
        $observed = array_fill_keys( $columns, true );
        $missing = array_values( array_diff( array_keys( $mapped ), $columns ) );
        $extra = array_values( array_diff( $columns, array_keys( $mapped ) ) );
        $canonical = array();
        foreach ( $extra as $col )
            $canonical[ self::canonical( $col ) ][] = $col;
        $suggestions = array();
        $blocking = array();
        $assigned = array();
        foreach ( $missing as $old ) {
            $risk = self::classify( $old, $policy );
            $key = self::canonical( $old );
            $candidates = isset( $canonical[ $key ] ) ?
                $canonical[ $key ] : array();
            if ( count( $candidates ) === 1 &&
                ! isset( $assigned[ $candidates[0] ] ) ) {
                $new = $candidates[0];
                $assigned[ $new ] = true;
                $suggestions[] = array(
                    'change' => 'candidate_source_column_rename',
                    'old_source_column' => $old,
                    'proposed_source_column' => $new,
                    'unchanged_destination' => $mapped[ $old ],
                    'evidence' => 'normalized_header_name_match_only',
                    'confidence_class' => 'lexical_candidate_not_semantic_proof',
                    'risk' => $risk, 'requires' => self::requirements( $risk ),
                    'transformation_auto_authorized' => false
                );
            } else {
                $blocking[] = array( 'field' => $old,
                    'issue' => $candidates ?
                        'ambiguous_column_rename_candidates' : 'mapped_source_column_missing',
                    'risk' => $risk, 'requires' => self::requirements( $risk ) );
            }
        }
        $unused = array_values( array_filter( $extra,
            static function( $column ) use ( $assigned ) {
                return ! isset( $assigned[ $column ] );
            } ) );
        $unmapped = array();
        foreach ( $unused as $col ) {
            $unmapped[] = array( 'field' => $col,
                'risk' => self::classify( $col, $policy ),
                'type_observation' => self::type_profile( $rows, $col ),
                'policy' => 'quarantine_until_explicit_profile_mapping' );
        }
        $allowed_meta = array_fill_keys( (array) $profile['meta_keys'], true );
        $invalid_destinations = array();
        foreach ( $mapped as $source => $destination )
            if ( ! isset( $allowed_meta[ $destination ] ) &&
                0 !== strpos( $destination, '_wpml_import_' ) )
                $invalid_destinations[] = array(
                    'source_field' => $source,
                    'destination_field' => $destination,
                    'issue' => 'destination_no_longer_allowlisted' );
        $critical_required = array();
        foreach ( array_merge( (array) $policy['required_columns'],
            array( $policy['identity_field'] ), (array) $policy['price_fields'],
            (array) $policy['required_relationships'] ) as $col ) {
            if ( ! isset( $observed[ $col ] ) ) $critical_required[ $col ] = true;
        }
        if ( ! empty( $policy['currency_field'] ) &&
            ! isset( $observed[ $policy['currency_field'] ] ) )
            $critical_required[ $policy['currency_field'] ] = true;
        if ( ! empty( $policy['period_start_field'] ) &&
            ( ! isset( $observed[ $policy['period_start_field'] ] ) ||
              ! isset( $observed[ $policy['period_end_field'] ] ) ) )
            $critical_required['date_interval'] = true;
        $uncertified = array(
            'google_sheets_multi_editor_cas',
            'third_party_wp_all_import_transaction_fence',
            'jetengine_cct_and_relation_adapter',
            'wpml_complete_translation_link_readback',
            'external_provider_compensating_rollback',
            'approved_source_rights_and_brand_core'
        );
        $report = array(
            'contract' => self::CONTRACT,
            'profile_slug' => $slug, 'snapshot_sha256' => $sha,
            'source_evidence_kind' => $header_only ?
                'header_only_unstaged' : 'encrypted_exact_snapshot',
            'header_only_is_not_accepted_import_data' => $header_only,
            'profile_revision' => $profile['revision'],
            'profile_authority_sha256' => $profile['authority_sha256'],
            'policy_sha256' => MAD4B_SCP_Activity_Import_Authority::digest( $policy ),
            'observed_header_sha256' => self::digest( $columns ),
            'mapped_source_columns_missing' => $missing,
            'rename_suggestions' => $suggestions,
            'unmapped_source_columns' => $unmapped,
            'invalid_destinations' => $invalid_destinations,
            'critical_columns_missing' => array_keys( $critical_required ),
            'unresolved_conflicts' => $blocking,
            'maturation' => array(
                'stage' => 'drift_observed_only',
                'future_stages' => array( 'proposal_reviewed',
                    'migration_simulated', 'profile_revision_approved',
                    'provider_readback_certified' ),
                'current_approved_mapping_unchanged' => true,
                'candidate_never_applied_automatically' => true
            ),
            'safe_to_reuse_old_mapping' => !$missing && !$invalid_destinations &&
                !$critical_required && !$blocking,
            'requires_new_governed_profile_revision' =>
                (bool) ( $missing || $invalid_destinations || $critical_required || $suggestions ),
            'uncertified_provider_gates' => $uncertified,
            'wpml_link_readback_ability' => 'mad4b/business-activity-import-wpml-readback',
            'staging_manual_approval_required' => true,
            'mapping_mutation_authorized' => false,
            'third_party_write_authorized' => false,
            'ready_for_production' => false,
            'read_only' => true, 'mutation_performed' => false
        );
        $report['plan_sha256'] = self::digest( $report );
        return $report;
    }
}
