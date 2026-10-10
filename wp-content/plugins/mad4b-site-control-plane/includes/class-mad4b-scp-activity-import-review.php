<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP01: bounded spreadsheet/import preflight and human conflict review.
 * Deliberately NOT a silent WP All Import executor or a Sheets CAS writer.
 */
final class MAD4B_SCP_Activity_Import_Review {
    const CONTRACT = 'mad4b.activity-import-review.v1';
    const MAX_ROWS = 500;
    const MAX_COLUMNS = 80;
    const MAX_ISSUES = 200;
    private static function error( $code, $message ) { return new WP_Error( $code, $message ); }
    private static function digest( $v ) {
        $json = wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    private static function enrolled() {
        return class_exists( 'MAD4B_SCP_Site_Profile' ) &&
            MAD4B_SCP_Site_Profile::configured() &&
            MAD4B_SCP_Site_Profile::origin_enrolled() &&
            MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
    }
    private static function option_key( $profile_slug ) {
        return 'mad4b_activity_import_review_' . hash( 'sha256',
            MAD4B_SCP_Site_Profile::site_uuid() . '|' . $profile_slug );
    }
    public static function brand_core_plan( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() )
            return self::error( 'mad4b_brand_acceptance_denied', 'Enrolled administrator required.' );
        if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ||
            ! method_exists( 'MAD4B_SCP_Context_Authority', 'brand_core_coverage' ) )
            return self::error( 'mad4b_brand_context_missing', 'Exact Context Authority runtime unavailable.' );
        $coverage = MAD4B_SCP_Context_Authority::brand_core_coverage();
        $queue = MAD4B_SCP_Context_Authority::review_queue();
        return array( 'contract' => 'mad4b.brand-core-acceptance-plan.v1',
            'required' => array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' ),
            'ready' => ! empty( $coverage['ready'] ),
            'coverage' => $coverage,
            'review_queue' => $queue,
            'automated_approval_performed' => false,
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function capabilities( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() )
            return self::error( 'mad4b_import_authority_denied', 'Enrolled administrator required.' );
        $mode_catalog = class_exists( 'MAD4B_SCP_Activity_Import_Modes' )
            ? MAD4B_SCP_Activity_Import_Modes::catalog() : null;
        return array(
            'contract' => self::CONTRACT, 'read_only' => true,
            'mode_catalog' => is_array( $mode_catalog ) ? $mode_catalog : null,
            'wp_all_import_detected' => class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' ),
            'available_sources' => is_array( $mode_catalog ) ?
                array_values( array_unique( array_map( static function ( $m ) { return $m['source']; },
                    array_values( array_filter( $mode_catalog['modes'],
                        static function ( $m ) { return ! empty( $m['review_intake_implemented'] ) && ! empty( $m['detected'] ); } ) ) ) ) ) : array(),
            'available_destinations' => array( 'review_inbox' ),
            'candidate_destinations' => array( 'wp_all_import_existing_template', 'governed_content_experience_profile' ),
            'wp_all_import_execution_certified' => false,
            'google_sheets_atomic_cas_certified' => false,
            'generic_importer_options' => array(
                'unique_identifier', 'match_existing', 'create', 'update', 'delete',
                'update_only_selected_fields', 'custom_fields', 'taxonomies',
                'relations', 'images', 'wpml_translation_group', 'schedule',
                'batch_limit', 'skip_unchanged', 'dry_run', 'error_policy', 'rollback_policy'
            ),
            'max_preview_rows' => self::MAX_ROWS,
            'staging_only' => true
        );
    }
    /**
     * Read-only WP All Import options handoff. The upstream plugin owns its
     * wizard/run engine; no unverified internal PMXI_Import_Record mutation.
     */
    public static function wp_all_import_plan( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() )
            return self::error( 'mad4b_wpai_plan_denied', 'Enrolled administrator required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) ||
            ! MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile ) )
            return self::error( 'mad4b_wpai_profile_not_enabled', 'Enabled Profile Activity facet required.' );
        $site_policy = MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile );
        if ( empty( $site_policy['validation']['identity_field'] ) )
            return self::error( 'mad4b_wpai_validation_missing', 'Approved import validation must exist.' );
        $import_id = isset( $input['import_id'] ) ? (int) $input['import_id'] : 0;
        $unique = isset( $input['unique_identifier'] ) ? (string) $input['unique_identifier'] : '';
        $mode = isset( $input['mode'] ) ? (string) $input['mode'] : '';
        if ( $import_id < 1 || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $unique ) ||
            ! in_array( $mode, array( 'create_new', 'match_existing', 'update_existing' ), true ) )
            return self::error( 'mad4b_wpai_handoff_invalid', 'Exact installed import ID, stable unique key and explicit import mode required.' );
        if ( $unique !== (string) $site_policy['validation']['identity_field'] )
            return self::error( 'mad4b_wpai_identity_mismatch',
                'WP All Import unique identifier must match approved source identity.');
        $allowed_sections = array(
            'source', 'sheet', 'mapping', 'match', 'records', 'post', 'custom_fields',
            'taxonomies', 'relationships', 'media', 'wpml', 'pricing',
            'scheduling', 'batch', 'errors', 'rollback', 'review'
        );
        $options = isset( $input['provider_options'] ) ? $input['provider_options'] : array();
        if ( ! is_array( $options ) || count( $options ) > 18 ||
            array_diff( array_keys( $options ), $allowed_sections ) )
            return self::error( 'mad4b_wpai_option_section_invalid', 'Unsupported import option section; inspect installed plugin before adding keys.' );
        foreach ( $options as $name => $value ) {
            if ( ! is_array( $value ) || count( $value ) > 100 )
                return self::error( 'mad4b_wpai_option_unbounded', 'Each option section requires bounded structured values.' );
        }
        $permitted_meta = array_fill_keys( (array) $profile['meta_keys'], true );
        $update_fields = isset( $input['update_fields'] ) ? $input['update_fields'] : array();
        if ( ! is_array( $update_fields ) || count( $update_fields ) > 80 )
            return self::error( 'mad4b_wpai_update_fields_invalid', 'Explicit bounded updated-field allowlist required.' );
        $approved_fields = array_fill_keys(
            array_values( $site_policy['validation']['field_mapping'] ), true );
        foreach ( $update_fields as $field ) {
            if ( ! is_string( $field ) || ! isset( $permitted_meta[ $field ] ) ||
                ! isset( $approved_fields[ $field ] ) )
                return self::error( 'mad4b_wpai_update_not_allowed', 'Only parent Profile-allowlisted Meta fields may be updated.' );
        }
        $requested_delete = ! empty( $input['delete_missing'] );
        $requested_publish = ! empty( $input['publish_immediately'] );
        $contract = array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'profile_slug' => $slug, 'profile_revision' => $profile['revision'],
            'profile_authority_sha256' => $profile['authority_sha256'],
            'validation_sha256' => MAD4B_SCP_Activity_Import_Authority::digest(
                $site_policy['validation'] ),
            'engine' => 'wp_all_import', 'import_id' => $import_id,
            'unique_identifier' => $unique, 'mode' => $mode,
            'update_fields' => $update_fields, 'provider_options' => $options,
            'requested_delete' => $requested_delete,
            'requested_publish' => $requested_publish );
        return array( 'contract' => 'mad4b.wp-all-import-handoff-plan.v1',
            'plan_sha256' => self::digest( $contract ),
            'engine_detected' => class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' ),
            'source_bound' => $contract, 'option_sections' => $allowed_sections,
            'requires_site_job_readback' => true, 'requires_native_wpai_wizard_or_certified_adapter' => true,
            'requires_governed_approval' => true,
            'blocked_effects' => array_values( array_filter( array(
                $requested_delete ? 'delete_missing_requires_separate_explicit_release_policy' : null,
                $requested_publish ? 'publish_requires_separate_commercial_release' : null
            ) ) ),
            'ready_for_import_execution' => false, 'read_only' => true,
            'mutation_performed' => false );
    }
    /** Bounded, strict, UTC-only period parsing (no locale guessing). */
    private static function parse_period( $value, $format ) {
        if ( ! is_scalar( $value ) ) return false;
        $value = (string) $value;
        if ( 'unix_seconds' === $format ) {
            if ( ! preg_match( '/^\\d{1,10}$/D', $value ) ) return false;
            return (int) $value;
        }
        if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/D', $value ) ) return false;
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
        if ( false === $date || $date->format( 'Y-m-d' ) !== $value )
            return false;
        return $date->setTime( 23, 59, 59 )->getTimestamp();
    }
    private static function inspect( $input, $issue_offset = 0 ) {
        if ( ! is_array( $input ) ) return self::error( 'mad4b_import_payload_invalid', 'Object required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) ||
            ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) )
            return self::error( 'mad4b_import_profile_invalid', 'Exact Experience Profile required.' );
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) ||
            ! MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile ) )
            return self::error( 'mad4b_import_profile_not_enabled', 'Enabled Activity facet required.' );
        $policy_result = MAD4B_SCP_Activity_Import_Authority::resolve( $profile, $input );
        if ( is_wp_error( $policy_result ) ) return $policy_result;
        $policy = $policy_result['policy'];
        $rows = isset( $input['rows'] ) ? $input['rows'] : array();
        $headers = isset( $input['headers'] ) ? $input['headers'] : array();
        if ( ! is_array( $rows ) || ! is_array( $headers ) ||
            count( $rows ) < 1 || count( $rows ) > min( self::MAX_ROWS, $policy['max_rows'] ) ||
            count( $headers ) < 1 ||
            count( $headers ) > self::MAX_COLUMNS )
            return self::error( 'mad4b_import_bounds', 'Bounded spreadsheet preview required.' );
        $seen = array();
        foreach ( $headers as $h ) {
            if ( ! is_string( $h ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $h ) ||
                isset( $seen[ $h ] ) )
                return self::error( 'mad4b_import_column_invalid', 'Column labels must be unique safe identifiers.' );
            $seen[ $h ] = true;
        }
        $identity = $policy['identity_field'];
        if ( ! isset( $seen[ $identity ] ) )
            return self::error( 'mad4b_import_identity_missing', 'Explicit unique identifier field required.' );
        $mapping = $policy['field_mapping'];
        if ( ! is_array( $mapping ) || count( $mapping ) > self::MAX_COLUMNS )
            return self::error( 'mad4b_import_mapping_invalid', 'Bounded field mapping required.' );
        $allow = array_fill_keys( (array) $profile['meta_keys'], true );
        $allowed_wpml = array(
            '_wpml_import_language_code', '_wpml_import_source_language_code',
            '_wpml_import_translation_group', '_wpml_import_after_process_post_status'
        );
        foreach ( $mapping as $from => $to ) {
            if ( ! isset( $seen[ $from ] ) || ! is_string( $to ) ||
                ( ! isset( $allow[ $to ] ) && ! in_array( $to, $allowed_wpml, true ) ) )
                return self::error( 'mad4b_import_field_not_allowed', 'Mapping target must be declared by exact parent profile or governed WPML metadata.' );
        }
        $allowed_currencies = $policy['allowed_currencies'];
        if ( ! is_array( $allowed_currencies ) || count( $allowed_currencies ) > 20 )
            return self::error( 'mad4b_import_currency_policy_invalid', 'Explicit bounded currency allowlist required.' );
        $allowed_currencies = array_values( array_unique( array_map( 'strval', $allowed_currencies ) ) );
        if ( ! empty( $policy['currency_field'] ) && empty( $allowed_currencies ) )
            return self::error( 'mad4b_import_currency_allowlist_required',
                'Commercial currency fields require an explicit site-approved currency list.' );
        foreach ( $allowed_currencies as $currency ) {
            if ( ! preg_match( '/^[A-Z]{3}$/D', $currency ) )
                return self::error( 'mad4b_import_currency_code_invalid',
                    'Approved currency codes must use the exact three-letter uppercase contract.' );
        }
        $price_policy = $policy['price_tier_policy'];
        if ( ! in_array( $price_policy, array( 'none', 'review_monotonic' ), true ) )
            return self::error( 'mad4b_import_price_policy_invalid', 'Only configured price review policy is permitted.' );
        $flag_expired = $policy['review_past_intervals'];
        $period_start_key = isset( $policy['period_start_field'] ) ?
            $policy['period_start_field'] : '';
        $period_end_key = isset( $policy['period_end_field'] ) ?
            $policy['period_end_field'] : '';
        $period_format = isset( $policy['period_format'] ) ?
            $policy['period_format'] : 'iso_date';
        $currency_key = $policy['currency_field'];
        $price_fields = $policy['price_fields'];
        $issues = array(); $issue_total = 0; $issue_counts = array();
        $block_count = 0; $review_count = 0;
        $ids = array(); $groups = array(); $group_languages = array(); $row_hashes = array();
        foreach ( $rows as $i => $row ) {
            if ( ! is_array( $row ) || array_diff( array_keys( $row ), $headers ) ||
                array_diff( $headers, array_keys( $row ) ) )
                return self::error( 'mad4b_import_row_shape', 'All rows must contain precisely the declared columns.' );
            foreach ( $row as $value ) {
                if ( ! is_scalar( $value ) && null !== $value )
                    return self::error( 'mad4b_import_value_type', 'Nested structures/formulas/executable data cannot be imported by the preview lane.' );
                if ( strlen( (string) $value ) > 4096 )
                    return self::error( 'mad4b_import_cell_unbounded', 'Spreadsheet cell exceeds bounded length.' );
                if ( is_string( $value ) &&
                    ( preg_match( '/^[=+@]/', ltrim( $value ) ) ||
                      preg_match( '/^-(?!\\d+(?:\\.\\d+)?$)/', ltrim( $value ) ) ||
                      preg_match( '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', $value ) ) )
                    return self::error( 'mad4b_import_formula_or_control_denied',
                        'Executable spreadsheet formula or control data is not a valid import value.' );
            }
            $id = (string) $row[ $identity ];
            $row_hashes[] = self::digest( $row );
            $errors = array();
            if ( '' === $id || isset( $ids[ $id ] ) ) $errors[] = 'identity_missing_or_duplicate';
            $ids[ $id ] = true;
            foreach ( $policy['required_relationships'] as $relation ) {
                if ( ! isset( $row[ $relation ] ) || '' === trim( (string) $row[ $relation ] ) )
                    $errors[] = 'required_relationship_unresolved';
            }
            if ( $currency_key && isset( $row[ $currency_key ] ) &&
                ! in_array( (string) $row[ $currency_key ], $allowed_currencies, true ) )
                $errors[] = 'currency_not_in_approved_allowlist';
            if ( isset( $row['_wpml_import_after_process_post_status'] ) &&
                ! in_array( (string) $row['_wpml_import_after_process_post_status'],
                    array( 'draft', 'pending', 'private', 'publish' ), true ) )
                $errors[] = 'invalid_wordpress_post_status';
            $period_end = false;
            if ( $period_start_key ) {
                $period_start = self::parse_period(
                    $row[ $period_start_key ], $period_format );
                $period_end = self::parse_period(
                    $row[ $period_end_key ], $period_format );
                if ( false === $period_start || false === $period_end )
                    $errors[] = 'date_period_invalid';
                elseif ( $period_start > $period_end )
                    $errors[] = 'date_interval_reversed';
            }
            $decimal_scale = $policy['decimal_scale'];
            $number_pattern = 0 === $decimal_scale ? '/^\\d{1,14}$/D' :
                '/^\\d{1,14}(?:\\.\\d{1,' . $decimal_scale . '})?$/D';
            foreach ( $price_fields as $price_key ) {
                if ( ! array_key_exists( $price_key, $row ) ||
                    ! preg_match( $number_pattern, (string) $row[ $price_key ] ) )
                    $errors[] = 'price_not_approved_decimal';
            }
            if ( 'review_monotonic' === $price_policy ) {
                for ( $tier = 0; $tier + 1 < count( $price_fields ); $tier++ ) {
                    $left = $price_fields[ $tier ];
                    $right = $price_fields[ $tier + 1 ];
                    if ( isset( $row[ $left ], $row[ $right ] ) &&
                        preg_match( $number_pattern, (string) $row[ $left ] ) &&
                        preg_match( $number_pattern, (string) $row[ $right ] ) &&
                        (float) $row[ $left ] < (float) $row[ $right ] ) {
                        $errors[] = 'price_tier_order_requires_commercial_review';
                        break;
                    }
                }
            }
            if ( $flag_expired && false !== $period_end &&
                $period_end < time() )
                $errors[] = 'historical_rate_period_requires_review';
            $group_col = '_wpml_import_translation_group';
            $lang_col = '_wpml_import_language_code';
            if ( isset( $row[ $group_col ], $row[ $lang_col ] ) ) {
                $pair = (string) $row[ $group_col ] . '|' . (string) $row[ $lang_col ];
                if ( isset( $groups[ $pair ] ) ) $errors[] = 'duplicate_translation_group_language';
                $groups[ $pair ] = true;
                $group_name = (string) $row[ $group_col ];
                if ( ! isset( $group_languages[ $group_name ] ) ) $group_languages[ $group_name ] = array();
                $group_languages[ $group_name ][ (string) $row[ $lang_col ] ] = true;
                if ( $policy['wpml_languages'] && ! in_array(
                    (string) $row[ $lang_col ], $policy['wpml_languages'], true ) )
                    $errors[] = 'wpml_language_not_allowed';
            }
            foreach ( $errors as $reason ) {
                $issue_total++;
                if ( in_array( $reason, array(
                    'price_tier_order_requires_commercial_review',
                    'historical_rate_period_requires_review'
                ), true ) ) $review_count++; else $block_count++;
                $issue_counts[ $reason ] = isset( $issue_counts[ $reason ] ) ?
                    $issue_counts[ $reason ] + 1 : 1;
                if ( $issue_total > $issue_offset &&
                    count( $issues ) < self::MAX_ISSUES )
                    $issues[] = array( 'row' => (int) $i + 1,
                        'identity_sha256' => hash( 'sha256', $id ),
                        'reason' => $reason, 'severity' =>
                            in_array( $reason, array(
                                'price_tier_order_requires_commercial_review',
                                'historical_rate_period_requires_review'
                            ), true ) ? 'review' : 'block' );
            }
        }
        if ( ! empty( $policy['require_complete_wpml_groups'] ) ) {
            foreach ( $group_languages as $group_name => $observed_languages ) {
                foreach ( $policy['wpml_languages'] as $language ) {
                    if ( ! isset( $observed_languages[ $language ] ) ) {
                        $issue_total++; $block_count++;
                        $reason = 'wpml_group_missing_required_language';
                        $issue_counts[ $reason ] = isset( $issue_counts[ $reason ] ) ?
                            $issue_counts[ $reason ] + 1 : 1;
                        if ( $issue_total > $issue_offset &&
                            count( $issues ) < self::MAX_ISSUES )
                            $issues[] = array( 'row' => 0,
                                'identity_sha256' => hash( 'sha256', $group_name ),
                                'reason' => $reason, 'severity' => 'block' );
                    }
                }
            }
        }
        $plan = array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'profile_slug' => $slug, 'profile_revision' => $profile['revision'],
            'authority_sha256' => $profile['authority_sha256'],
            'identity_field' => $identity, 'field_mapping' => $mapping,
            'headers' => $headers, 'row_hashes' => $row_hashes,
            'allowed_currencies' => $allowed_currencies,
            'currency_field' => $currency_key,
            'price_fields' => $price_fields,
            'decimal_scale' => $policy['decimal_scale'],
            'price_tier_policy' => $price_policy,
            'review_past_intervals' => $flag_expired,
            'period_start_field' => $period_start_key,
            'period_end_field' => $period_end_key,
            'period_format' => $period_format,
            'policy_sha256' => $policy_result['policy_sha256'] );
        return array( 'contract' => self::CONTRACT, 'plan_sha256' => self::digest( $plan ),
            'profile_slug' => $slug, 'profile_revision' => $profile['revision'],
            'authority_sha256' => $profile['authority_sha256'],
            'policy_sha256' => $policy_result['policy_sha256'],
            'row_count' => count( $rows ),
            'issue_count_observed' => $issue_total,
            'block_issue_count' => $block_count,
            'review_issue_count' => $review_count,
            'issue_counts_by_reason' => $issue_counts,
            'issues' => $issues,
            'issue_offset' => $issue_offset,
            'issues_truncated' => $issue_total > $issue_offset + count( $issues ),
            'ready_for_import_execution' => false,
            'requires_human_review' => true, 'source_values_persisted' => false,
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function plan( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() )
            return self::error( 'mad4b_import_plan_denied', 'Enrolled administrator required.' );
        return self::inspect( $input );
    }
    public static function issues_page( $input = array() ) {
        if ( ! is_array( $input ) || ! current_user_can( 'manage_options' ) )
            return self::error( 'mad4b_import_issues_page_denied', 'Administrator access required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $sha = isset( $input['snapshot_sha256'] ) ? (string) $input['snapshot_sha256'] : '';
        $offset = isset( $input['issue_offset'] ) ? $input['issue_offset'] : 0;
        if ( ! is_int( $offset ) || $offset < 0 || $offset > 10000 )
            return self::error( 'mad4b_import_issues_offset_invalid', 'Issue page offset out of bounds.' );
        $loaded = MAD4B_SCP_Activity_Import_Snapshot::raw_snapshot( $slug, $sha );
        if ( is_wp_error( $loaded ) ) return $loaded;
        $all = self::inspect( $loaded['input'] );
        if ( is_wp_error( $all ) ) return $all;
        if ( ! hash_equals( $loaded['receipt']['plan']['plan_sha256'], $all['plan_sha256'] ) ||
            $loaded['receipt']['plan']['issue_count_observed'] !== $all['issue_count_observed'] )
            return self::error( 'mad4b_import_issues_source_stale', 'Snapshot policy or issue set has changed.' );
        $page = self::inspect( $loaded['input'], $offset );
        if ( is_wp_error( $page ) ) return $page;
        return array( 'contract' => 'mad4b.import-issues-page.v1',
            'profile_slug' => $slug, 'snapshot_sha256' => $sha,
            'plan_sha256' => $page['plan_sha256'],
            'issue_offset' => $offset, 'issues' => $page['issues'],
            'next_offset' => $page['issues_truncated'] ?
                $offset + count( $page['issues'] ) : null,
            'issue_count_total' => $page['issue_count_observed'],
            'issue_counts_by_reason' => $page['issue_counts_by_reason'],
            'block_issue_count' => $page['block_issue_count'],
            'review_issue_count' => $page['review_issue_count'],
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function review( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() )
            return self::error( 'mad4b_import_review_denied', 'Enrolled administrator required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) )
            return self::error( 'mad4b_import_review_profile_invalid', 'Exact site profile required.' );
        $stage = get_option( self::option_key( $slug ), false );
        if ( ! is_array( $stage ) ) return array( 'contract' => self::CONTRACT, 'state' => 'no_staged_feed', 'read_only' => true );
        if ( ! isset( $stage['contract'] ) ||
            MAD4B_SCP_Activity_Import_Snapshot::CONTRACT !== $stage['contract'] )
            return self::error( 'mad4b_import_legacy_preview_untrusted',
                'Unencrypted legacy import review is not eligible for approval or export.' );
        return array( 'contract' => self::CONTRACT, 'state' => 'requires_review',
            'source' => $stage['source'], 'payload_sha256' => $stage['payload_sha256'],
            'snapshot_sha256' => $stage['snapshot_sha256'],
            'received_at' => $stage['received_at'], 'plan' => $stage['plan'],
            'source_values_encrypted_at_rest' => true,
            'write_performed' => false, 'read_only' => true );
    }
    public static function register_rest() {
        register_rest_route( 'mad4b/v1', '/activity-import/intake', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'receive_signed' ),
            'permission_callback' => array( __CLASS__, 'authorize_signed' ) ) );
    }
    /**
     * Site-host constant must provide independent source keys. Untrusted JSON
     * cannot choose or upgrade its identity/role; only the validated key may.
     * Example host configuration is documented, never committed as a secret.
     */
    private static function verified_sender( $request ) {
        if ( ! self::enrolled() ||
            ! method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) ||
            ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) ||
            ! defined( 'MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS' ) ||
            ! is_array( MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS ) )
            return self::error( 'mad4b_import_source_keys_required',
                'An enrolled Staging site with per-source managed keys is required.' );
        $key_id = (string) $request->get_header( 'x-mad4b-key-id' );
        if ( ! preg_match( '/^[a-z][a-z0-9_-]{2,60}$/D', $key_id ) ||
            ! isset( MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS[ $key_id ] ) )
            return self::error( 'mad4b_import_key_id_unknown', 'Configured source key identifier required.' );
        $spec = MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS[ $key_id ];
        if ( ! is_array( $spec ) || array_diff( array_keys( $spec ),
            array( 'mode', 'secret', 'profile_slugs', 'site_uuid', 'enabled' ) ) ||
            empty( $spec['enabled'] ) ||
            ! isset( $spec['secret'], $spec['mode'], $spec['site_uuid'], $spec['profile_slugs'] ) ||
            ! is_string( $spec['secret'] ) || strlen( $spec['secret'] ) < 32 ||
            ! is_string( $spec['site_uuid'] ) ||
            ! hash_equals( (string) MAD4B_SCP_Site_Profile::site_uuid(), $spec['site_uuid'] ) ||
            ! in_array( $spec['mode'], array( 'google_apps_script', 'signed_generic_webhook' ), true ) ||
            ! is_array( $spec['profile_slugs'] ) ||
            count( $spec['profile_slugs'] ) < 1 || count( $spec['profile_slugs'] ) > 40 )
            return self::error( 'mad4b_import_key_scope_invalid', 'Site-bound source key must have a valid mode and scoped profiles.' );
        $raw = $request->get_body();
        if ( ! is_string( $raw ) || strlen( $raw ) < 2 || strlen( $raw ) > 1048576 )
            return self::error( 'mad4b_import_webhook_size', 'Signed payload must be below 1 MiB.' );
        $received = (string) $request->get_header( 'x-mad4b-signature' );
        $expected = hash_hmac( 'sha256', $raw, $spec['secret'] );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $received ) ||
            ! hash_equals( $expected, $received ) )
            return self::error( 'mad4b_import_webhook_signature', 'Valid per-source raw-body HMAC is required.' );
        $data = json_decode( $raw, true );
        $slug = is_array( $data ) && isset( $data['input']['profile_slug'] ) ?
            (string) $data['input']['profile_slug'] : '';
        $claimed = is_array( $data ) && isset( $data['source_mode'] ) ?
            (string) $data['source_mode'] : '';
        if ( ! in_array( $slug, $spec['profile_slugs'], true ) ||
            ! hash_equals( $spec['mode'], $claimed ) )
            return self::error( 'mad4b_import_source_scope_denied',
                'Request source mode and exact profile must match its independently managed key.' );
        return array( 'key_id' => $key_id, 'mode' => $spec['mode'] );
    }
    public static function authorize_signed( $request ) {
        $source = self::verified_sender( $request );
        return is_wp_error( $source ) ? $source : true;
    }
    public static function receive_signed( $request ) {
        if ( ! method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) ||
            ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) )
            return self::error( 'mad4b_import_intake_staging_only', 'External intake is restricted to enrolled Staging.' );
        $sender = self::verified_sender( $request );
        if ( is_wp_error( $sender ) ) return $sender;
        $data = json_decode( $request->get_body(), true );
        if ( ! is_array( $data ) || ! isset( $data['issued_at'], $data['nonce'], $data['input'], $data['site_uuid'] ) ||
            ! is_int( $data['issued_at'] ) || abs( time() - $data['issued_at'] ) > 300 ||
            ! is_string( $data['nonce'] ) || ! preg_match( '/^[A-Za-z0-9_-]{16,100}$/D', $data['nonce'] ) ||
            (string) $data['site_uuid'] !== (string) MAD4B_SCP_Site_Profile::site_uuid() )
            return self::error( 'mad4b_import_webhook_replay_or_site', 'Fresh site-bound signed request required.' );
        // Reuse the same profile validator; inbound HMAC carries data authority
        // only for STAGING, never a WordPress-post mutation or approval.
        $nonce_key = 'mad4b_import_nonce_' . hash( 'sha256',
            $data['site_uuid'] . '|' . $sender['key_id'] . '|' . $data['nonce'] );
        if ( ! add_option( $nonce_key, time(), '', false ) )
            return self::error( 'mad4b_import_webhook_replay', 'Webhook nonce already accepted.' );
        // Nonces remain rejected for the entire signature-validity window,
        // then WordPress Cron may reclaim the stored bounded replay marker.
        if ( function_exists( 'wp_schedule_single_event' ) )
            wp_schedule_single_event( time() + 610, 'mad4b_activity_import_expire_nonce', array( $nonce_key ) );
        $input = $data['input'];
        if ( ! is_array( $input ) ) return self::error( 'mad4b_import_webhook_input', 'Invalid import input.' );
        $preview = self::inspect( $input );
        if ( is_wp_error( $preview ) ) return $preview;
        $source_mode = $sender['mode'];
        $receipt = MAD4B_SCP_Activity_Import_Snapshot::stage(
            $preview['profile_slug'], $input, $preview, $source_mode, $sender['key_id'] );
        if ( is_wp_error( $receipt ) ) return $receipt;
        return $receipt;
    }
    public static function expire_nonce( $key ) {
        if ( ! is_string( $key ) || ! preg_match( '/^mad4b_import_nonce_[a-f0-9]{64}$/D', $key ) ) return;
        $recorded = get_option( $key, false );
        if ( is_numeric( $recorded ) && (int) $recorded + 600 <= time() ) delete_option( $key );
    }
    /**
     * Admin CSV upload is a second actual intake transport, separate from
     * Google Apps Script and HMAC webhooks. It stores no uploaded raw records.
     */
    /** Return safe, non-secret diagnostics to the same WordPress wizard step. */
    private static function return_to_guide( $slug, $error_code, $step = 2 ) {
        $slug = sanitize_key( (string) $slug );
        $code = sanitize_key( (string) $error_code );
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) )
            wp_die( 'Select an enabled import Profile first.' );
        wp_safe_redirect( add_query_arg( array(
            'page' => 'mad4b-import-review', 'profile_slug' => $slug,
            'wizard_step' => (int) $step, 'ui_error' => $code
        ), admin_url( 'tools.php' ) ) );
        exit;
    }
    /** Optional XLSX source input, with exactly the same site-owned policy. */
    public static function admin_upload_xlsx() {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() ||
            ! method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) ||
            ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) )
            wp_die( 'An enrolled Staging site administrator is required.' );
        check_admin_referer( 'mad4b_activity_xlsx_intake', 'mad4b_import_xlsx_nonce' );
        $slug = isset( $_POST['profile_slug'] ) ?
            sanitize_key( wp_unslash( $_POST['profile_slug'] ) ) : '';
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) )
            wp_die( 'Select the approved import Profile first.' );
        $mode = MAD4B_SCP_Activity_Import_Modes::plan( array(
            'profile_slug' => $slug, 'mode_id' => 'admin_xlsx_convert' ) );
        if ( is_wp_error( $mode ) || empty( $mode['eligible_for_staging_review'] ) )
            self::return_to_guide( $slug, 'mad4b_xlsx_parser_unavailable' );
        $parsed = class_exists( 'MAD4B_SCP_Activity_Import_Xlsx' ) ?
            MAD4B_SCP_Activity_Import_Xlsx::parse(
                isset( $_FILES['import_xlsx'] ) ? $_FILES['import_xlsx'] : array() ) :
            self::error( 'mad4b_xlsx_parser_unavailable', 'XLSX converter not configured.' );
        if ( is_wp_error( $parsed ) )
            self::return_to_guide( $slug, $parsed->get_error_code() );
        $input = array( 'profile_slug' => $slug,
            'headers' => $parsed['headers'], 'rows' => $parsed['rows'] );
        $preview = self::inspect( $input );
        if ( is_wp_error( $preview ) )
            self::return_to_guide( $slug, $preview->get_error_code() );
        $receipt = MAD4B_SCP_Activity_Import_Snapshot::stage(
            $slug, $input, $preview, 'admin_xlsx_convert',
            (string) get_current_user_id() );
        if ( is_wp_error( $receipt ) )
            self::return_to_guide( $slug, $receipt->get_error_code() );
        wp_safe_redirect( add_query_arg( array(
            'page' => 'mad4b-import-review', 'profile_slug' => $slug,
            'wizard_step' => 3, 'staged' => 1 ), admin_url( 'tools.php' ) ) );
        exit;
    }
    public static function admin_upload_csv() {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() ||
            ! method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) ||
            ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) )
            wp_die( 'Staging-only enrolled administrator required.' );
        check_admin_referer( 'mad4b_activity_csv_intake', 'mad4b_import_nonce' );
        $slug = isset( $_POST['profile_slug'] ) ?
            sanitize_key( wp_unslash( $_POST['profile_slug'] ) ) : '';
        $file = isset( $_FILES['import_csv'] ) ? $_FILES['import_csv'] : null;
        if ( ! is_array( $file ) || ! isset( $file['error'], $file['size'], $file['tmp_name'], $file['name'] ) ||
            UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] < 1 ||
            (int) $file['size'] > 1048576 ||
            ! preg_match( '/\\.csv$/iD', (string) $file['name'] ) ||
            ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) )
            self::return_to_guide( $slug, 'mad4b_ui_csv_upload_invalid' );
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) ||
            ! MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile ) )
            self::return_to_guide( $slug, 'mad4b_import_site_validation_not_configured' );
        $fh = fopen( $file['tmp_name'], 'rb' );
        if ( false === $fh ) self::return_to_guide( $slug, 'mad4b_ui_csv_open_failed' );
        $headers = fgetcsv( $fh, 16384, ',', '"', '\\' );
        if ( ! is_array( $headers ) || count( $headers ) < 1 ||
            count( $headers ) > self::MAX_COLUMNS ) {
            fclose( $fh );
            self::return_to_guide( $slug, 'mad4b_ui_csv_header_invalid' );
        }
        $headers[0] = preg_replace( '/^\\xEF\\xBB\\xBF/', '', $headers[0] );
        $headers = array_map( 'trim', $headers );
        $rows = array();
        while ( ( $cells = fgetcsv( $fh, 16384, ',', '"', '\\' ) ) !== false ) {
            if ( count( $cells ) === 1 && ( null === $cells[0] || '' === trim( $cells[0] ) ) ) continue;
            if ( count( $cells ) !== count( $headers ) || count( $rows ) >= self::MAX_ROWS ) {
                fclose( $fh );
                self::return_to_guide( $slug, 'mad4b_ui_csv_rows_invalid' );
            }
            foreach ( $cells as $cell ) {
                if ( ! is_string( $cell ) || strlen( $cell ) > 4096 ||
                    preg_match( '/^[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', $cell ) ||
                    ( preg_match( '/^[=+@]/', ltrim( $cell ) ) ) )
                {
                    fclose( $fh );
                    self::return_to_guide( $slug, 'mad4b_ui_csv_unsafe_value' );
                }
            }
            $rows[] = array_combine( $headers, $cells );
        }
        fclose( $fh );
        $input = array( 'profile_slug' => $slug,
            'headers' => $headers, 'rows' => $rows );
        // Identity, currencies, WPML and field mapping are always taken from
        // the enrolled Profile policy. Uploaded source cannot redefine them.
        $preview = self::inspect( $input );
        if ( is_wp_error( $preview ) ) self::return_to_guide( $slug, $preview->get_error_code() );
        $receipt = MAD4B_SCP_Activity_Import_Snapshot::stage(
            $slug, $input, $preview, 'admin_csv_upload',
            (string) get_current_user_id() );
        if ( is_wp_error( $receipt ) ) self::return_to_guide( $slug, $receipt->get_error_code() );
        wp_safe_redirect( add_query_arg( array( 'page' => 'mad4b-import-review',
            'profile_slug' => $slug, 'wizard_step' => 3, 'staged' => 1 ), admin_url( 'tools.php' ) ) );
        exit;
    }
    /**
     * Archive a read-only review snapshot, never delete imported business data.
     * This makes subsequent signed/manual feed proposals possible.
     */
    public static function approve_preview() {
        if ( ! current_user_can( 'manage_options' ) )
            wp_die( 'Site administrator authority required.' );
        check_admin_referer( 'mad4b_activity_approve_review', 'mad4b_approve_nonce' );
        $slug = isset( $_POST['profile_slug'] ) ?
            sanitize_key( wp_unslash( $_POST['profile_slug'] ) ) : '';
        $sha = isset( $_POST['snapshot_sha256'] ) ?
            sanitize_text_field( wp_unslash( $_POST['snapshot_sha256'] ) ) : '';
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) )
            wp_die( 'Exact immutable source snapshot ID required.' );
        $ack_checked = isset( $_POST['acknowledge_warnings'] ) &&
            '1' === (string) wp_unslash( $_POST['acknowledge_warnings'] );
        $warnings = isset( $_POST['acknowledged_warning_count'] ) ?
            absint( wp_unslash( $_POST['acknowledged_warning_count'] ) ) : -1;
        if ( ! $ack_checked || $warnings < 0 )
            self::return_to_guide( $slug, 'mad4b_import_warning_ack_confirmation_missing', 4 );
        $approved = MAD4B_SCP_Activity_Import_Snapshot::approve(
            $slug, $sha, true, $warnings );
        if ( is_wp_error( $approved ) ) self::return_to_guide( $slug, $approved->get_error_code(), 4 );
        wp_safe_redirect( add_query_arg( array( 'page' => 'mad4b-import-review',
            'profile_slug' => $slug, 'wizard_step' => 4, 'approved' => 1 ), admin_url( 'tools.php' ) ) );
        exit;
    }
    public static function approved_csv_download() {
        if ( ! current_user_can( 'manage_options' ) )
            wp_die( 'Site administrator authority required.' );
        check_admin_referer( 'mad4b_activity_export_approved', 'mad4b_export_nonce' );
        $slug = isset( $_POST['profile_slug'] ) ?
            sanitize_key( wp_unslash( $_POST['profile_slug'] ) ) : '';
        $sha = isset( $_POST['snapshot_sha256'] ) ?
            sanitize_text_field( wp_unslash( $_POST['snapshot_sha256'] ) ) : '';
        $result = MAD4B_SCP_Activity_Import_Snapshot::export_approved_csv( $slug, $sha );
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ) );
        wp_die( 'Export did not produce an approved CSV stream.' );
    }
    public static function archive_preview() {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() ||
            ! method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) ||
            ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) )
            wp_die( 'Staging administrator authority required.' );
        check_admin_referer( 'mad4b_activity_archive_review', 'mad4b_archive_nonce' );
        $slug = isset( $_POST['profile_slug'] ) ?
            sanitize_key( wp_unslash( $_POST['profile_slug'] ) ) : '';
        $expected = isset( $_POST['expected_payload_sha256'] ) ?
            sanitize_text_field( wp_unslash( $_POST['expected_payload_sha256'] ) ) : '';
        $snapshot = isset( $_POST['expected_snapshot_sha256'] ) ?
            sanitize_text_field( wp_unslash( $_POST['expected_snapshot_sha256'] ) ) : '';
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $snapshot ) )
            wp_die( 'Exact review identity and both hashes required.' );
        $key = self::option_key( $slug );
        $state = get_option( $key, false );
        if ( ! is_array( $state ) ||
            ! isset( $state['payload_sha256'], $state['snapshot_sha256'] ) ||
            ! hash_equals( (string) $state['payload_sha256'], $expected ) ||
            ! hash_equals( (string) $state['snapshot_sha256'], $snapshot ) )
            wp_die( 'Review changed since the archive confirmation.' );
        $audit_key = 'mad4b_activity_import_archive_' . hash( 'sha256',
            MAD4B_SCP_Site_Profile::site_uuid() . '|' . $slug . '|' . $snapshot );
        $audit = array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'profile_slug' => $slug, 'payload_sha256' => $expected,
            'snapshot_sha256' => $snapshot,
            'archived_at' => gmdate( 'c' ), 'post_writes' => 0 );
        if ( ! add_option( $audit_key, $audit, '', false ) ||
            self::digest( get_option( $audit_key, false ) ) !== self::digest( $audit ) )
            wp_die( 'Immutable preview archive could not be confirmed.' );
        delete_option( $key );
        if ( false !== get_option( $key, false ) )
            wp_die( 'Preview archive recorded but active review could not be cleared.' );
        wp_safe_redirect( add_query_arg( array( 'page' => 'mad4b-import-review',
            'profile_slug' => $slug, 'wizard_step' => 2, 'archived' => 1 ), admin_url( 'tools.php' ) ) );
        exit;
    }
    public static function register_admin() {
        add_management_page( 'MAD4B Import Review', 'MAD4B Import Review',
            'manage_options', 'mad4b-import-review', array( __CLASS__, 'admin_page' ) );
    }
    public static function admin_page() {
        if ( class_exists( 'MAD4B_SCP_Activity_Import_Experience' ) )
            return MAD4B_SCP_Activity_Import_Experience::render();
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        echo '<div class="wrap"><h1>MAD4B Activity Import Review</h1>';
        echo '<p>Review-only transports: signed Apps Script/Make/n8n/Zapier/BitFlows push, or Staging administrator CSV upload. No import job is executed here.</p>';
        echo '<h2>Alternative import modes</h2>';
        $catalog = class_exists( 'MAD4B_SCP_Activity_Import_Modes' )
            ? MAD4B_SCP_Activity_Import_Modes::catalog() : new WP_Error( 'modes_unavailable', 'Mode registry not loaded.' );
        if ( ! is_wp_error( $catalog ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Mode</th><th>Family</th><th>State</th><th>Transport</th><th>Destination</th></tr></thead><tbody>';
            foreach ( $catalog['modes'] as $m ) {
                echo '<tr><td>' . esc_html( $m['id'] ) . '</td><td>' . esc_html( $m['family'] ) .
                    '</td><td>' . esc_html( $m['state'] ) . '</td><td>' . esc_html( $m['transport'] ) .
                    '</td><td>' . esc_html( $m['destination'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        $slug = isset( $_GET['profile_slug'] ) ? sanitize_key( wp_unslash( $_GET['profile_slug'] ) ) : '';
        echo '<form method="get"><input type="hidden" name="page" value="mad4b-import-review" />';
        echo '<label>Content Experience Profile <input name="profile_slug" value="' . esc_attr( $slug ) . '" /></label>';
        submit_button( 'Inspect staged conflicts', 'secondary', '', false );
        echo '</form>';
        if ( $slug && is_array( $catalog ) ) {
            $chosen = isset( $_GET['mode_id'] ) ? sanitize_key( wp_unslash( $_GET['mode_id'] ) ) : '';
            echo '<form method="get">';
            echo '<input type="hidden" name="page" value="mad4b-import-review" />';
            echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
            echo '<label>Import Mode <select name="mode_id"><option value="">Choose a transport</option>';
            foreach ( $catalog['modes'] as $m )
                echo '<option value="' . esc_attr( $m['id'] ) . '"' .
                    selected( $chosen, $m['id'], false ) . '>' .
                    esc_html( $m['id'] . ' — ' . $m['state'] ) . '</option>';
            echo '</select></label>';
            submit_button( 'Inspect mode requirements', 'secondary', '', false );
            echo '</form>';
            if ( $chosen ) {
                $mode_plan = MAD4B_SCP_Activity_Import_Modes::plan( array(
                    'profile_slug' => $slug, 'mode_id' => $chosen ) );
                if ( is_wp_error( $mode_plan ) ) echo '<p>' .
                    esc_html( $mode_plan->get_error_message() ) . '</p>';
                else {
                    echo '<h3>Selected mode prerequisites</h3><ul>';
                    foreach ( $mode_plan['required_setup'] as $step )
                        echo '<li>' . esc_html( $step ) . '</li>';
                    echo '</ul><p>Ready for staging review: ' .
                        esc_html( $mode_plan['eligible_for_staging_review'] ? 'yes' : 'no' ) .
                        '. Automatic post writing: no.</p>';
                }
            }
        }
        if ( method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) &&
            MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) ) {
            echo '<h2>Staging CSV source review</h2>';
            echo '<form enctype="multipart/form-data" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="mad4b_activity_import_csv" />';
            wp_nonce_field( 'mad4b_activity_csv_intake', 'mad4b_import_nonce' );
            echo '<p><label>Profile Slug <input required name="profile_slug" value="' . esc_attr( $slug ) . '" /></label></p>';
            echo '<p>Identifier, allowed currencies and field mapping are governed by this site\'s Import Contract, not by the uploaded file.</p>';
            echo '<p><label>CSV file (max 1 MiB / 500 rows) <input required type="file" name="import_csv" accept=".csv,text/csv" /></label></p>';
            submit_button( 'Stage CSV for conflict review', 'secondary' );
            echo '</form>';
        }
        if ( $slug ) {
            $review = self::review( array( 'profile_slug' => $slug ) );
            if ( is_wp_error( $review ) ) echo '<p>' . esc_html( $review->get_error_message() ) . '</p>';
            elseif ( isset( $review['plan']['issues'] ) ) {
                $issue_offset = isset( $_GET['issue_offset'] ) ?
                    absint( $_GET['issue_offset'] ) : 0;
                $issues_view = isset( $review['snapshot_sha256'] ) ?
                    self::issues_page( array( 'profile_slug' => $slug,
                        'snapshot_sha256' => $review['snapshot_sha256'],
                        'issue_offset' => $issue_offset ) ) : $review['plan'];
                echo '<h3>Source validation conflicts</h3>';
                echo '<p>Plan: <code>' . esc_html( $review['plan']['plan_sha256'] ) .
                    '</code>; total: ' . esc_html( $review['plan']['issue_count_observed'] ) . '</p>';
                if ( is_wp_error( $issues_view ) ) {
                    echo '<p>' . esc_html( $issues_view->get_error_message() ) . '</p>';
                } else {
                    echo '<table class="widefat striped"><thead><tr><th>Row</th><th>Reason</th><th>Severity</th></tr></thead><tbody>';
                    foreach ( $issues_view['issues'] as $issue )
                        echo '<tr><td>' . esc_html( $issue['row'] ) . '</td><td>' .
                            esc_html( $issue['reason'] ) . '</td><td>' .
                            esc_html( $issue['severity'] ) . '</td></tr>';
                    echo '</tbody></table>';
                    if ( isset( $issues_view['next_offset'] ) &&
                        null !== $issues_view['next_offset'] )
                        echo '<p><a href="' . esc_url( add_query_arg( array(
                            'page' => 'mad4b-import-review',
                            'profile_slug' => $slug,
                            'issue_offset' => $issues_view['next_offset']
                        ), admin_url( 'tools.php' ) ) ) .
                        '">Review next 200 source issues</a></p>';
                }
                if ( isset( $review['snapshot_sha256'] ) &&
                    class_exists( 'MAD4B_SCP_Activity_Import_Reconciliation' ) ) {
                    $page_index = isset( $_GET['reconcile_start'] ) ?
                        absint( $_GET['reconcile_start'] ) : 0;
                    $diff = MAD4B_SCP_Activity_Import_Reconciliation::plan( array(
                        'profile_slug' => $slug,
                        'snapshot_sha256' => $review['snapshot_sha256'],
                        'start_index' => $page_index, 'page_size' => 25
                    ) );
                    if ( is_wp_error( $diff ) ) {
                        echo '<p>Independent destination comparison unavailable: ' .
                            esc_html( $diff->get_error_message() ) . '</p>';
                    } else {
                        echo '<h3>WordPress destination comparison (no changes)</h3>';
                        echo '<p>Matched: ' . esc_html( $diff['counts']['matched'] ) .
                            ', Different: ' . esc_html( $diff['counts']['different'] ) .
                            ', Missing: ' . esc_html( $diff['counts']['missing'] ) .
                            ', Ambiguous: ' . esc_html( $diff['counts']['ambiguous'] ) . '</p>';
                        echo '<table class="widefat striped"><thead><tr><th>Row</th><th>Match status</th><th>Field conflicts</th></tr></thead><tbody>';
                        foreach ( $diff['items'] as $item ) {
                            echo '<tr><td>' . esc_html( $item['row_index'] + 1 ) .
                                '</td><td>' . esc_html( $item['status'] ) . '</td><td>';
                            foreach ( $item['field_issues'] as $issue )
                                echo '<p>' . esc_html( $issue['field'] . ': ' . $issue['reason'] ) . '</p>';
                            echo '</td></tr>';
                        }
                        echo '</tbody></table>';
                        if ( null !== $diff['next_index'] )
                            echo '<p><a href="' . esc_url( add_query_arg( array(
                                'page' => 'mad4b-import-review', 'profile_slug' => $slug,
                                'reconcile_start' => $diff['next_index']
                            ), admin_url( 'tools.php' ) ) ) .
                            '">Review next 25 destination rows</a></p>';
                    }
                }
                if ( isset( $review['snapshot_sha256'] ) ) {
                    $receipt = MAD4B_SCP_Activity_Import_Snapshot::approval(
                        $slug, $review['snapshot_sha256'] );
                    if ( ! is_wp_error( $receipt ) ) {
                        echo '<p>Approved snapshot <code>' .
                            esc_html( $review['snapshot_sha256'] ) .
                            '</code>. Ready for manual CSV handoff, not automated post write.</p>';
                        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                        echo '<input type="hidden" name="action" value="mad4b_activity_import_export" />';
                        echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
                        echo '<input type="hidden" name="snapshot_sha256" value="' .
                            esc_attr( $review['snapshot_sha256'] ) . '" />';
                        wp_nonce_field( 'mad4b_activity_export_approved', 'mad4b_export_nonce' );
                        submit_button( 'Download approved immutable CSV', 'secondary' );
                        echo '</form>';
                    } elseif ( isset( $review['plan']['block_issue_count'] ) &&
                        0 === (int) $review['plan']['block_issue_count'] &&
                        empty( $review['plan']['issues_truncated'] ) ) {
                        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                        echo '<input type="hidden" name="action" value="mad4b_activity_import_approve" />';
                        echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
                        echo '<input type="hidden" name="snapshot_sha256" value="' .
                            esc_attr( $review['snapshot_sha256'] ) . '" />';
                        wp_nonce_field( 'mad4b_activity_approve_review', 'mad4b_approve_nonce' );
                        submit_button( 'Approve exact staged snapshot for manual export', 'primary' );
                        echo '</form>';
                    } else {
                        echo '<p>Approval blocked: fix all blocking issues and truncated reviews at source, then stage a new snapshot.</p>';
                    }
                }
                if ( isset( $review['payload_sha256'] ) &&
                    method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) &&
                    MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) ) {
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                    echo '<input type="hidden" name="action" value="mad4b_activity_import_archive" />';
                    echo '<input type="hidden" name="profile_slug" value="' . esc_attr( $slug ) . '" />';
                    echo '<input type="hidden" name="expected_payload_sha256" value="' .
                        esc_attr( $review['payload_sha256'] ) . '" />';
                    echo '<input type="hidden" name="expected_snapshot_sha256" value="' .
                        esc_attr( $review['snapshot_sha256'] ) . '" />';
                    wp_nonce_field( 'mad4b_activity_archive_review', 'mad4b_archive_nonce' );
                    submit_button( 'Archive reviewed snapshot (no WordPress post changes)', 'secondary' );
                    echo '</form>';
                }
            } else echo '<p>No staged feed for this profile.</p>';
        }
        echo '</div>';
    }
}
if ( function_exists( 'add_action' ) ) {
    add_action( 'rest_api_init', array( 'MAD4B_SCP_Activity_Import_Review', 'register_rest' ) );
    add_action( 'admin_menu', array( 'MAD4B_SCP_Activity_Import_Review', 'register_admin' ) );
    add_action( 'admin_post_mad4b_activity_import_csv', array( 'MAD4B_SCP_Activity_Import_Review', 'admin_upload_csv' ) );
    add_action( 'admin_post_mad4b_activity_import_xlsx', array( 'MAD4B_SCP_Activity_Import_Review', 'admin_upload_xlsx' ) );
    add_action( 'admin_post_mad4b_activity_import_archive', array( 'MAD4B_SCP_Activity_Import_Review', 'archive_preview' ) );
    add_action( 'admin_post_mad4b_activity_import_approve', array( 'MAD4B_SCP_Activity_Import_Review', 'approve_preview' ) );
    add_action( 'admin_post_mad4b_activity_import_export', array( 'MAD4B_SCP_Activity_Import_Review', 'approved_csv_download' ) );
    add_action( 'mad4b_activity_import_expire_nonce', array( 'MAD4B_SCP_Activity_Import_Review', 'expire_nonce' ), 10, 1 );
}
