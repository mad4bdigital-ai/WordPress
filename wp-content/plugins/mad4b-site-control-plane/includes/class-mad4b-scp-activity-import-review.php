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
        return array(
            'contract' => self::CONTRACT, 'read_only' => true,
            'wp_all_import_detected' => class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' ),
            'available_sources' => array( 'xlsx_via_bounded_csv_handoff', 'csv_upload', 'google_sheet_managed', 'apps_script_signed_webhook' ),
            'available_destinations' => array( 'wp_all_import_existing_template', 'governed_content_experience_profile' ),
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
            empty( $profile['activity_contract']['enabled'] ) )
            return self::error( 'mad4b_wpai_profile_not_enabled', 'Enabled Profile Activity facet required.' );
        $import_id = isset( $input['import_id'] ) ? (int) $input['import_id'] : 0;
        $unique = isset( $input['unique_identifier'] ) ? (string) $input['unique_identifier'] : '';
        $mode = isset( $input['mode'] ) ? (string) $input['mode'] : '';
        if ( $import_id < 1 || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $unique ) ||
            ! in_array( $mode, array( 'create_new', 'match_existing', 'update_existing' ), true ) )
            return self::error( 'mad4b_wpai_handoff_invalid', 'Exact installed import ID, stable unique key and explicit import mode required.' );
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
        foreach ( $update_fields as $field ) {
            if ( ! is_string( $field ) || ! isset( $permitted_meta[ $field ] ) )
                return self::error( 'mad4b_wpai_update_not_allowed', 'Only parent Profile-allowlisted Meta fields may be updated.' );
        }
        $requested_delete = ! empty( $input['delete_missing'] );
        $requested_publish = ! empty( $input['publish_immediately'] );
        $contract = array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'profile_slug' => $slug, 'profile_revision' => $profile['revision'],
            'profile_authority_sha256' => $profile['authority_sha256'],
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
    private static function inspect( $input ) {
        if ( ! is_array( $input ) ) return self::error( 'mad4b_import_payload_invalid', 'Object required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) ||
            ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) )
            return self::error( 'mad4b_import_profile_invalid', 'Exact Experience Profile required.' );
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) ||
            empty( $profile['activity_contract']['enabled'] ) )
            return self::error( 'mad4b_import_profile_not_enabled', 'Enabled Activity facet required.' );
        $rows = isset( $input['rows'] ) ? $input['rows'] : array();
        $headers = isset( $input['headers'] ) ? $input['headers'] : array();
        if ( ! is_array( $rows ) || ! is_array( $headers ) ||
            count( $rows ) > self::MAX_ROWS || count( $headers ) < 1 ||
            count( $headers ) > self::MAX_COLUMNS )
            return self::error( 'mad4b_import_bounds', 'Bounded spreadsheet preview required.' );
        $seen = array();
        foreach ( $headers as $h ) {
            if ( ! is_string( $h ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,120}$/D', $h ) ||
                isset( $seen[ $h ] ) )
                return self::error( 'mad4b_import_column_invalid', 'Column labels must be unique safe identifiers.' );
            $seen[ $h ] = true;
        }
        $identity = isset( $input['identity_field'] ) ? (string) $input['identity_field'] : '';
        if ( ! isset( $seen[ $identity ] ) )
            return self::error( 'mad4b_import_identity_missing', 'Explicit unique identifier field required.' );
        $mapping = isset( $input['field_mapping'] ) ? $input['field_mapping'] : array();
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
        $allowed_currencies = isset( $input['allowed_currencies'] ) ? $input['allowed_currencies'] : array();
        if ( ! is_array( $allowed_currencies ) || count( $allowed_currencies ) > 20 )
            return self::error( 'mad4b_import_currency_policy_invalid', 'Explicit bounded currency allowlist required.' );
        $allowed_currencies = array_values( array_unique( array_map( 'strval', $allowed_currencies ) ) );
        if ( isset( $seen['base_currency'] ) && empty( $allowed_currencies ) )
            return self::error( 'mad4b_import_currency_allowlist_required',
                'Commercial currency fields require an explicit site-approved currency list.' );
        foreach ( $allowed_currencies as $currency ) {
            if ( ! preg_match( '/^[A-Z]{3}$/D', $currency ) )
                return self::error( 'mad4b_import_currency_code_invalid',
                    'Approved currency codes must use the exact three-letter uppercase contract.' );
        }
        $price_policy = isset( $input['price_tier_policy'] ) ? (string) $input['price_tier_policy'] : 'none';
        if ( ! in_array( $price_policy, array( 'none', 'review_monotonic' ), true ) )
            return self::error( 'mad4b_import_price_policy_invalid', 'Only configured price review policy is permitted.' );
        $flag_expired = ! empty( $input['review_past_intervals'] );
        $issues = array(); $issue_total = 0; $issue_counts = array();
        $ids = array(); $groups = array(); $row_hashes = array();
        foreach ( $rows as $i => $row ) {
            if ( ! is_array( $row ) || array_diff( array_keys( $row ), $headers ) ||
                array_diff( $headers, array_keys( $row ) ) )
                return self::error( 'mad4b_import_row_shape', 'All rows must contain precisely the declared columns.' );
            foreach ( $row as $value ) {
                if ( ! is_scalar( $value ) && null !== $value )
                    return self::error( 'mad4b_import_value_type', 'Nested structures/formulas/executable data cannot be imported by the preview lane.' );
                if ( strlen( (string) $value ) > 4096 )
                    return self::error( 'mad4b_import_cell_unbounded', 'Spreadsheet cell exceeds bounded length.' );
            }
            $id = (string) $row[ $identity ];
            $row_hashes[] = self::digest( $row );
            $errors = array();
            if ( '' === $id || isset( $ids[ $id ] ) ) $errors[] = 'identity_missing_or_duplicate';
            $ids[ $id ] = true;
            if ( isset( $row['base_currency'] ) && $allowed_currencies &&
                ! in_array( (string) $row['base_currency'], $allowed_currencies, true ) )
                $errors[] = 'currency_not_in_approved_allowlist';
            if ( isset( $row['_wpml_import_after_process_post_status'] ) &&
                ! in_array( (string) $row['_wpml_import_after_process_post_status'],
                    array( 'draft', 'pending', 'private', 'publish' ), true ) )
                $errors[] = 'invalid_wordpress_post_status';
            if ( isset( $row['tour_rate_start_date'], $row['tour_rate_end_date'] ) &&
                is_numeric( $row['tour_rate_start_date'] ) &&
                is_numeric( $row['tour_rate_end_date'] ) &&
                (float) $row['tour_rate_start_date'] > (float) $row['tour_rate_end_date'] )
                $errors[] = 'date_interval_reversed';
            if ( isset( $row['single_price'], $row['double_price'], $row['triple_price'] ) ) {
                foreach ( array( 'single_price', 'double_price', 'triple_price' ) as $price_key ) {
                    if ( ! is_numeric( $row[ $price_key ] ) || (float) $row[ $price_key ] < 0 )
                        $errors[] = 'price_not_nonnegative_number';
                }
                if ( 'review_monotonic' === $price_policy &&
                    is_numeric( $row['single_price'] ) &&
                    is_numeric( $row['double_price'] ) &&
                    is_numeric( $row['triple_price'] ) &&
                    ( (float) $row['single_price'] < (float) $row['double_price'] ||
                      (float) $row['double_price'] < (float) $row['triple_price'] ) )
                    $errors[] = 'price_tier_order_requires_commercial_review';
            }
            if ( $flag_expired && isset( $row['tour_rate_end_date'] ) &&
                is_numeric( $row['tour_rate_end_date'] ) &&
                (float) $row['tour_rate_end_date'] < time() )
                $errors[] = 'historical_rate_period_requires_review';
            $group_col = '_wpml_import_translation_group';
            $lang_col = '_wpml_import_language_code';
            if ( isset( $row[ $group_col ], $row[ $lang_col ] ) ) {
                $pair = (string) $row[ $group_col ] . '|' . (string) $row[ $lang_col ];
                if ( isset( $groups[ $pair ] ) ) $errors[] = 'duplicate_translation_group_language';
                $groups[ $pair ] = true;
            }
            foreach ( $errors as $reason ) {
                $issue_total++;
                $issue_counts[ $reason ] = isset( $issue_counts[ $reason ] ) ?
                    $issue_counts[ $reason ] + 1 : 1;
                if ( count( $issues ) < self::MAX_ISSUES )
                    $issues[] = array( 'row' => (int) $i + 1,
                        'identity_sha256' => hash( 'sha256', $id ),
                        'reason' => $reason, 'severity' =>
                            in_array( $reason, array(
                                'price_tier_order_requires_commercial_review',
                                'historical_rate_period_requires_review'
                            ), true ) ? 'review' : 'block' );
            }
        }
        $plan = array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'profile_slug' => $slug, 'profile_revision' => $profile['revision'],
            'authority_sha256' => $profile['authority_sha256'],
            'identity_field' => $identity, 'field_mapping' => $mapping,
            'headers' => $headers, 'row_hashes' => $row_hashes,
            'allowed_currencies' => $allowed_currencies,
            'price_tier_policy' => $price_policy,
            'review_past_intervals' => $flag_expired );
        return array( 'contract' => self::CONTRACT, 'plan_sha256' => self::digest( $plan ),
            'profile_slug' => $slug, 'row_count' => count( $rows ),
            'issue_count_observed' => $issue_total,
            'issue_counts_by_reason' => $issue_counts,
            'issues' => $issues, 'issues_truncated' => $issue_total > count( $issues ),
            'ready_for_import_execution' => false,
            'requires_human_review' => true, 'source_values_persisted' => false,
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function plan( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() )
            return self::error( 'mad4b_import_plan_denied', 'Enrolled administrator required.' );
        return self::inspect( $input );
    }
    public static function review( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() )
            return self::error( 'mad4b_import_review_denied', 'Enrolled administrator required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) )
            return self::error( 'mad4b_import_review_profile_invalid', 'Exact site profile required.' );
        $stage = get_option( self::option_key( $slug ), false );
        if ( ! is_array( $stage ) ) return array( 'contract' => self::CONTRACT, 'state' => 'no_staged_feed', 'read_only' => true );
        return array( 'contract' => self::CONTRACT, 'state' => 'requires_review',
            'source' => $stage['source'], 'payload_sha256' => $stage['payload_sha256'],
            'received_at' => $stage['received_at'], 'plan' => $stage['plan'],
            'write_performed' => false, 'read_only' => true );
    }
    public static function register_rest() {
        register_rest_route( 'mad4b/v1', '/activity-import/intake', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'receive_signed' ),
            'permission_callback' => array( __CLASS__, 'authorize_signed' ) ) );
    }
    public static function authorize_signed( $request ) {
        if ( ! self::enrolled() ||
            ! method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) ||
            ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) ||
            ! defined( 'MAD4B_ACTIVITY_IMPORT_WEBHOOK_SECRET' ) ||
            ! is_string( MAD4B_ACTIVITY_IMPORT_WEBHOOK_SECRET ) ||
            strlen( MAD4B_ACTIVITY_IMPORT_WEBHOOK_SECRET ) < 32 )
            return self::error( 'mad4b_import_webhook_disabled', 'Webhook disabled until site-scoped host secret is provisioned.' );
        $raw = $request->get_body();
        if ( ! is_string( $raw ) || strlen( $raw ) > 1048576 )
            return self::error( 'mad4b_import_webhook_size', 'Signed payload must be below 1 MiB.' );
        $provided = (string) $request->get_header( 'x-mad4b-signature' );
        $expected = hash_hmac( 'sha256', $raw, MAD4B_ACTIVITY_IMPORT_WEBHOOK_SECRET );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $provided ) ||
            ! hash_equals( $expected, $provided ) )
            return self::error( 'mad4b_import_webhook_signature', 'Valid request signature required.' );
        return true;
    }
    public static function receive_signed( $request ) {
        if ( ! method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) ||
            ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) )
            return self::error( 'mad4b_import_intake_staging_only', 'External intake is restricted to enrolled Staging.' );
        $data = json_decode( $request->get_body(), true );
        if ( ! is_array( $data ) || ! isset( $data['issued_at'], $data['nonce'], $data['input'], $data['site_uuid'] ) ||
            ! is_int( $data['issued_at'] ) || abs( time() - $data['issued_at'] ) > 300 ||
            ! is_string( $data['nonce'] ) || ! preg_match( '/^[A-Za-z0-9_-]{16,100}$/D', $data['nonce'] ) ||
            (string) $data['site_uuid'] !== (string) MAD4B_SCP_Site_Profile::site_uuid() )
            return self::error( 'mad4b_import_webhook_replay_or_site', 'Fresh site-bound signed request required.' );
        // Reuse the same profile validator; inbound HMAC carries data authority
        // only for STAGING, never a WordPress-post mutation or approval.
        $nonce_key = 'mad4b_import_nonce_' . hash( 'sha256', $data['site_uuid'] . '|' . $data['nonce'] );
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
        $source_mode = isset( $data['source_mode'] ) ? (string) $data['source_mode'] : 'google_apps_script';
        if ( ! in_array( $source_mode, array( 'google_apps_script', 'signed_generic_webhook' ), true ) )
            return self::error( 'mad4b_import_intake_source_invalid', 'Only registered signed-push source modes are accepted.' );
        $record = array( 'source' => $source_mode, 'received_at' => gmdate( 'c' ),
            'payload_sha256' => hash( 'sha256', $request->get_body() ), 'plan' => $preview );
        $key = self::option_key( $preview['profile_slug'] );
        // One inbox snapshot at a time: no implicit overwrites or auto-import.
        if ( ! add_option( $key, $record, '', false ) )
            return self::error( 'mad4b_import_review_pending', 'Resolve existing staged import review before accepting new data.' );
        if ( self::digest( get_option( $key, false ) ) !== self::digest( $record ) )
            return self::error( 'mad4b_import_stage_unverified', 'Staged review could not be independently read back.' );
        return array( 'contract' => self::CONTRACT, 'staged' => true,
            'plan_sha256' => $preview['plan_sha256'],
            'issue_count' => $preview['issue_count_observed'], 'post_writes' => 0 );
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
    public static function admin_upload_csv() {
        if ( ! current_user_can( 'manage_options' ) || ! self::enrolled() ||
            ! method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) ||
            ! MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) ) )
            wp_die( 'Staging-only enrolled administrator required.' );
        check_admin_referer( 'mad4b_activity_csv_intake', 'mad4b_import_nonce' );
        $file = isset( $_FILES['import_csv'] ) ? $_FILES['import_csv'] : null;
        if ( ! is_array( $file ) || ! isset( $file['error'], $file['size'], $file['tmp_name'], $file['name'] ) ||
            UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] < 1 ||
            (int) $file['size'] > 1048576 ||
            ! preg_match( '/\\.csv$/iD', (string) $file['name'] ) ||
            ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) )
            wp_die( 'A genuine CSV upload under 1 MiB is required.' );
        $slug = isset( $_POST['profile_slug'] ) ?
            sanitize_key( wp_unslash( $_POST['profile_slug'] ) ) : '';
        $identity = isset( $_POST['identity_field'] ) ?
            sanitize_key( wp_unslash( $_POST['identity_field'] ) ) : '';
        $allowed = isset( $_POST['allowed_currencies'] ) ?
            strtoupper( (string) wp_unslash( $_POST['allowed_currencies'] ) ) : '';
        $currencies = array_values( array_filter( array_map( 'trim', explode( ',', $allowed ) ) ) );
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) ||
            empty( $profile['activity_contract']['enabled'] ) )
            wp_die( 'Exact enabled Content Experience Profile required.' );
        $fh = fopen( $file['tmp_name'], 'rb' );
        if ( false === $fh ) wp_die( 'CSV open failed.' );
        $headers = fgetcsv( $fh, 16384, ',', '"', '\\' );
        if ( ! is_array( $headers ) || count( $headers ) < 1 ||
            count( $headers ) > self::MAX_COLUMNS ) {
            fclose( $fh );
            wp_die( 'CSV header is invalid.' );
        }
        $headers[0] = preg_replace( '/^\\xEF\\xBB\\xBF/', '', $headers[0] );
        $headers = array_map( 'trim', $headers );
        $rows = array();
        while ( ( $cells = fgetcsv( $fh, 16384, ',', '"', '\\' ) ) !== false ) {
            if ( count( $cells ) === 1 && ( null === $cells[0] || '' === trim( $cells[0] ) ) ) continue;
            if ( count( $cells ) !== count( $headers ) || count( $rows ) >= self::MAX_ROWS ) {
                fclose( $fh );
                wp_die( 'CSV row width or record count exceeds the bounded contract.' );
            }
            foreach ( $cells as $cell ) {
                if ( ! is_string( $cell ) || strlen( $cell ) > 4096 ||
                    preg_match( '/^[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', $cell ) ||
                    ( preg_match( '/^[=+@]/', ltrim( $cell ) ) ) )
                {
                    fclose( $fh );
                    wp_die( 'Unsafe spreadsheet formula, control character or oversized cell.' );
                }
            }
            $rows[] = array_combine( $headers, $cells );
        }
        fclose( $fh );
        $meta = array_fill_keys( (array) $profile['meta_keys'], true );
        $mapping = array();
        foreach ( $headers as $header )
            if ( isset( $meta[ $header ] ) ) $mapping[ $header ] = $header;
        if ( !$mapping ) wp_die( 'No approved Meta mapping found. Configure the parent Profile first.' );
        $input = array( 'profile_slug' => $slug, 'identity_field' => $identity,
            'headers' => $headers, 'rows' => $rows,
            'field_mapping' => $mapping, 'allowed_currencies' => $currencies,
            'price_tier_policy' => 'review_monotonic', 'review_past_intervals' => true );
        $preview = self::inspect( $input );
        if ( is_wp_error( $preview ) ) wp_die( esc_html( $preview->get_error_message() ) );
        $key = self::option_key( $slug );
        $record = array( 'source' => 'admin_csv_upload',
            'received_at' => gmdate( 'c' ),
            'payload_sha256' => hash( 'sha256', wp_json_encode( $input ) ),
            'plan' => $preview );
        if ( ! add_option( $key, $record, '', false ) )
            wp_die( 'An existing import review must be completed or archived first.' );
        if ( self::digest( get_option( $key, false ) ) !== self::digest( $record ) )
            wp_die( 'Review staging persistence could not be verified.' );
        wp_safe_redirect( add_query_arg( array( 'page' => 'mad4b-import-review',
            'profile_slug' => $slug, 'staged' => 1 ), admin_url( 'tools.php' ) ) );
        exit;
    }
    public static function register_admin() {
        add_management_page( 'MAD4B Import Review', 'MAD4B Import Review',
            'manage_options', 'mad4b-import-review', array( __CLASS__, 'admin_page' ) );
    }
    public static function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        echo '<div class="wrap"><h1>MAD4B Activity Import Review</h1>';
        echo '<p>Read-only review. No WP All Import job or Google Sheets write is executed here.</p>';
        $slug = isset( $_GET['profile_slug'] ) ? sanitize_key( wp_unslash( $_GET['profile_slug'] ) ) : '';
        echo '<form method="get"><input type="hidden" name="page" value="mad4b-import-review" />';
        echo '<label>Content Experience Profile <input name="profile_slug" value="' . esc_attr( $slug ) . '" /></label>';
        submit_button( 'Inspect staged conflicts', 'secondary', '', false );
        echo '</form>';
        if ( $slug ) {
            $review = self::review( array( 'profile_slug' => $slug ) );
            if ( is_wp_error( $review ) ) echo '<p>' . esc_html( $review->get_error_message() ) . '</p>';
            elseif ( isset( $review['plan']['issues'] ) ) {
                echo '<p>Plan: <code>' . esc_html( $review['plan']['plan_sha256'] ) . '</code></p><table class="widefat striped"><thead><tr><th>Row</th><th>Reason</th><th>Severity</th></tr></thead><tbody>';
                foreach ( $review['plan']['issues'] as $issue )
                    echo '<tr><td>' . esc_html( $issue['row'] ) . '</td><td>' . esc_html( $issue['reason'] ) . '</td><td>' . esc_html( $issue['severity'] ) . '</td></tr>';
                echo '</tbody></table>';
            } else echo '<p>No staged feed for this profile.</p>';
        }
        echo '</div>';
    }
}
if ( function_exists( 'add_action' ) ) {
    add_action( 'rest_api_init', array( 'MAD4B_SCP_Activity_Import_Review', 'register_rest' ) );
    add_action( 'admin_menu', array( 'MAD4B_SCP_Activity_Import_Review', 'register_admin' ) );
    add_action( 'admin_post_mad4b_activity_import_csv', array( 'MAD4B_SCP_Activity_Import_Review', 'admin_upload_csv' ) );
    add_action( 'mad4b_activity_import_expire_nonce', array( 'MAD4B_SCP_Activity_Import_Review', 'expire_nonce' ), 10, 1 );
}
