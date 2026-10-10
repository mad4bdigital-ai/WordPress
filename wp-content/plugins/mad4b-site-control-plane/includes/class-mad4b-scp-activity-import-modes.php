<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP02. A transport registry, never an implicit importer.
 * Modes compose source ingestion + preparation + an explicit destination.
 * Providers opt in through trusted site-installed PHP, not chat JSON.
 */
final class MAD4B_SCP_Activity_Import_Modes {
    const CONTRACT = 'mad4b.activity-import-modes.v1';
    private static function err( $code, $message ) { return new WP_Error( $code, $message ); }
    private static function digest( $value ) {
        $json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    private static function mode( $id, $family, $source, $transport, $destination, $support,
        $requirements, $detected, $accepts_review, $automated_write = false ) {
        return array(
            'id' => $id, 'family' => $family, 'source' => $source,
            'transport' => $transport, 'destination' => $destination,
            'support' => $support, 'requirements' => $requirements,
            'detected' => (bool) $detected,
            'review_intake_implemented' => (bool) $accepts_review,
            'automated_write_certified' => (bool) $automated_write,
            'state' => $accepts_review && $detected ? 'review_only_ready' :
                ( $detected ? 'handoff_or_adapter_required' : 'not_configured' ),
            'no_implicit_production_writes' => true
        );
    }
    private static function plugin( $name ) {
        return function_exists( 'is_plugin_active' ) && is_plugin_active( $name );
    }
    private static function builtins() {
        $wpai = class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' );
        $signed_keys = defined( 'MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS' ) &&
            is_array( MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS ) ?
            MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS : array();
        $has_script_key = false; $has_generic_key = false;
        foreach ( $signed_keys as $key_id => $source ) {
            if ( ! is_string( $key_id ) ||
                ! preg_match( '/^[a-z][a-z0-9_-]{2,60}$/D', $key_id ) ||
                ! is_array( $source ) || empty( $source['enabled'] ) ||
                empty( $source['secret'] ) || ! is_string( $source['secret'] ) ||
                strlen( $source['secret'] ) < 32 ||
                ! isset( $source['site_uuid'], $source['profile_slugs'] ) ||
                ! is_string( $source['site_uuid'] ) ||
                $source['site_uuid'] !== MAD4B_SCP_Site_Profile::site_uuid() ||
                ! is_array( $source['profile_slugs'] ) ||
                ! $source['profile_slugs'] ) continue;
            if ( isset( $source['mode'] ) && 'google_apps_script' === $source['mode'] )
                $has_script_key = true;
            if ( isset( $source['mode'] ) && 'signed_generic_webhook' === $source['mode'] )
                $has_generic_key = true;
        }
        $https = function_exists( 'wp_safe_remote_get' );
        $google = class_exists( 'MAD4B_SCP_Google_Drive_Context' ) &&
            method_exists( 'MAD4B_SCP_Google_Drive_Context', 'connection_status' );
        $cli = defined( 'WP_CLI' ) && WP_CLI;
        $staging = method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) &&
            MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) );
        $encrypted_storage = defined( 'MAD4B_ACTIVITY_IMPORT_DATA_KEY' ) &&
            is_string( MAD4B_ACTIVITY_IMPORT_DATA_KEY ) &&
            strlen( MAD4B_ACTIVITY_IMPORT_DATA_KEY ) >= 32 &&
            function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) &&
            in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
        $stage_ready = $staging && $encrypted_storage;
        $files = array();
        // Each source mode deliberately reports its actual capability:
        // "detected" never means a source URL or credential is authorized.
        $files[] = self::mode( 'admin_csv_upload', 'file', 'csv_tsv',
            'wp_admin_nonce_upload', 'review_inbox', 'native_review',
            array( 'admin', 'staging', 'approved_profile', 'encrypted_snapshot' ), $stage_ready, $stage_ready );
        $xlsx_ready = $stage_ready &&
            class_exists( 'MAD4B_SCP_Activity_Import_Xlsx' ) &&
            MAD4B_SCP_Activity_Import_Xlsx::available();
        $files[] = self::mode( 'admin_xlsx_convert', 'file', 'xlsx',
            'admin_upload_converter', 'review_inbox', 'native_review',
            array( 'controlled_parser', 'zip_limits', 'no_formulas',
                'encrypted_snapshot', 'single_sheet' ), $xlsx_ready, $xlsx_ready );
        $files[] = self::mode( 'wp_media_csv', 'file', 'media_library',
            'attachment_read', 'review_inbox', 'adapter_required',
            array( 'attachment_identity', 'mime', 'file_size', 'site_grant' ), false, false );
        $files[] = self::mode( 'local_managed_file', 'file', 'managed_local_file',
            'host_path_binding', 'review_inbox', 'adapter_required',
            array( 'directory_allowlist', 'nonpublic', 'immutable_snapshot' ), false, false );
        $files[] = self::mode( 'https_csv_pull', 'remote_file', 'remote_https',
            'bounded_wp_http_pull', 'review_inbox', 'adapter_required',
            array( 'url_allowlist', 'ssrf_denial', 'size_limit', 'etag' ), $https, false );
        $files[] = self::mode( 'sftp_ftp_pull', 'remote_file', 'sftp_ftp',
            'managed_credential_transfer', 'review_inbox', 'adapter_required',
            array( 'managed_secret', 'host_allowlist', 'fingerprint', 'integrity' ), false, false );
        $files[] = self::mode( 'object_storage', 'remote_file', 's3_gcs_azure',
            'managed_object_credential', 'review_inbox', 'adapter_required',
            array( 'managed_secret', 'object_version', 'scope' ), false, false );
        $files[] = self::mode( 'google_sheets_oauth', 'google', 'google_sheet',
            'managed_google_read', 'review_inbox', 'provider_certification_required',
            array( 'site_bound_oauth', 'sheet_id', 'range', 'scopes', 'revision' ), $google, false );
        $files[] = self::mode( 'google_drive_file', 'google', 'google_drive',
            'managed_google_file_export', 'review_inbox', 'adapter_required',
            array( 'site_bound_oauth', 'file_id', 'snapshot_hash' ), $google, false );
        $files[] = self::mode( 'google_apps_script', 'push', 'google_sheet',
            'signed_hmac_webhook', 'review_inbox', 'native_review',
            array( 'site_secret', 'nonce', 'timestamp', 'exact_json', 'encrypted_snapshot' ), $has_script_key && $stage_ready, $has_script_key && $stage_ready );
        $files[] = self::mode( 'signed_generic_webhook', 'push', 'make_n8n_zapier_pabbly_bitflows_custom',
            'signed_hmac_webhook', 'review_inbox', 'native_review',
            array( 'site_secret', 'nonce', 'timestamp', 'exact_json', 'encrypted_snapshot' ), $has_generic_key && $stage_ready, $has_generic_key && $stage_ready );
        $files[] = self::mode( 'wordpress_authenticated_rest', 'push', 'rest_api',
            'wp_rest_auth', 'review_inbox', 'adapter_required',
            array( 'administrator', 'nonce_or_app_password', 'profile_scope' ), false, false );
        $files[] = self::mode( 'email_attachment', 'push', 'inbound_email',
            'managed_mail_gateway', 'review_inbox', 'adapter_required',
            array( 'verified_sender', 'attachment_antimalware', 'size', 'review' ), false, false );
        $files[] = self::mode( 'wp_all_import_wizard', 'wp_all_import', 'csv_xml_xlsx_google_sheets_url',
            'plugin_wizard', 'wp_all_import_import_job', 'plugin_handoff',
            array( 'plugin_installed', 'existing_import_id', 'unique_key', 'approved_mapping' ), $wpai, false );
        $files[] = self::mode( 'wp_all_import_from_url', 'wp_all_import', 'remote_https_ftp_sftp',
            'plugin_source_configuration', 'wp_all_import_import_job', 'plugin_handoff',
            array( 'plugin_installed', 'approved_source_url', 'existing_import_id' ), $wpai, false );
        $files[] = self::mode( 'wp_all_import_manual_rerun', 'wp_all_import', 'existing_import_job',
            'plugin_admin_run', 'wp_all_import_import_job', 'plugin_handoff',
            array( 'plugin_installed', 'exact_import_id', 'staging_approval' ), $wpai, false );
        $files[] = self::mode( 'wp_all_import_cron', 'scheduler', 'existing_import_job',
            'plugin_trigger_and_processing_cron', 'wp_all_import_import_job', 'plugin_handoff',
            array( 'plugin_installed', 'secret_urls_not_in_chat', 'host_scheduler' ), $wpai, false );
        $files[] = self::mode( 'wp_all_import_wpcli', 'scheduler', 'existing_import_job',
            'wp_cli_all_import_run', 'wp_all_import_import_job', 'plugin_handoff',
            array( 'plugin_installed', 'wp_cli', 'authorized_host' ), $wpai && $cli, false );
        $files[] = self::mode( 'wp_all_import_auto_schedule', 'scheduler', 'existing_import_job',
            'plugin_automatic_scheduling', 'wp_all_import_import_job', 'plugin_handoff',
            array( 'plugin_installed', 'scheduling_subscription', 'verified_import_id' ), $wpai, false );
        $files[] = self::mode( 'wordpress_cron_worker', 'scheduler', 'staged_snapshot',
            'wp_cron', 'governed_import_queue', 'adapter_required',
            array( 'job_store', 'worker_lease', 'receipts', 'recovery' ), function_exists( 'wp_schedule_single_event' ), false );
        $files[] = self::mode( 'action_scheduler_worker', 'scheduler', 'staged_snapshot',
            'action_scheduler', 'governed_import_queue', 'adapter_required',
            array( 'job_store', 'worker_lease', 'receipts', 'recovery' ), function_exists( 'as_enqueue_async_action' ), false );
        $files[] = self::mode( 'woocommerce_product_csv', 'native_plugin', 'woocommerce_csv',
            'woocommerce_native_importer', 'woocommerce_products', 'plugin_handoff',
            array( 'woocommerce_active', 'product_schema', 'sku_identity' ), class_exists( 'WooCommerce' ), false );
        $files[] = self::mode( 'governed_profile_apply', 'native_plugin', 'approved_rows',
            'content_experience_plan_apply', 'wordpress_profile', 'certification_required',
            array( 'exact_plan', 'permissions', 'per_row_revision', 'rollback' ), class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ), false );
        return $files;
    }
    /** Trusted server-installed extensions may add manifest entries, never executable callbacks from input. */
    public static function catalog( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::configured() ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
            ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() )
            return self::err( 'mad4b_import_modes_denied', 'Enrolled site administrator required.' );
        $modes = self::builtins();
        $builtin_ids = array();
        foreach ( $modes as $entry ) $builtin_ids[ $entry['id'] ] = $entry;
        $filtered = function_exists( 'apply_filters' )
            ? apply_filters( 'mad4b_activity_import_mode_manifests', $modes ) : $modes;
        if ( ! is_array( $filtered ) || count( $filtered ) > 60 )
            return self::err( 'mad4b_import_mode_registry_invalid', 'Trusted mode registry exceeds bounds.' );
        $required = array( 'id', 'family', 'source', 'transport', 'destination', 'support',
            'requirements', 'detected', 'review_intake_implemented',
            'automated_write_certified', 'state', 'no_implicit_production_writes' );
        $out = array();
        foreach ( $filtered as $entry ) {
            if ( ! is_array( $entry ) || array_diff( $required, array_keys( $entry ) ) ||
                ! is_string( $entry['id'] ) ||
                ! preg_match( '/^[a-z][a-z0-9_]{2,64}$/D', $entry['id'] ) ||
                isset( $out[ $entry['id'] ] ) || ! is_array( $entry['requirements'] ) ||
                count( $entry['requirements'] ) > 20 ||
                empty( $entry['no_implicit_production_writes'] ) )
                return self::err( 'mad4b_import_mode_registry_entry_invalid', 'Invalid/duplicate unsafe import mode manifest.' );
            foreach ( array( 'family', 'source', 'transport', 'destination',
                'support', 'state' ) as $text_key ) {
                if ( ! is_string( $entry[ $text_key ] ) ||
                    ! preg_match( '/^[a-z][a-z0-9_]{1,120}$/D', $entry[ $text_key ] ) )
                    return self::err( 'mad4b_import_mode_text_invalid',
                        'Manifest labels require safe bounded identifiers.' );
            }
            foreach ( $entry['requirements'] as $requirement ) {
                if ( ! is_string( $requirement ) ||
                    ! preg_match( '/^[a-z][a-z0-9_]{1,120}$/D', $requirement ) )
                    return self::err( 'mad4b_import_mode_requirement_invalid',
                        'Mode prerequistes must be safe bounded identifiers.' );
            }
            if ( isset( $builtin_ids[ $entry['id'] ] ) &&
                self::digest( $builtin_ids[ $entry['id'] ] ) !== self::digest( $entry ) )
                return self::err( 'mad4b_import_builtin_override_denied',
                    'Trusted extensions cannot change built-in certification claims.' );
            $clean = array();
            foreach ( $required as $key ) $clean[ $key ] = $entry[ $key ];
            // A manifest may describe an installed provider but cannot certify
            // execution or a new inbound REST parser by renaming its metadata.
            $clean['automated_write_certified'] = false;
            if ( ! isset( $builtin_ids[ $entry['id'] ] ) ) {
                $clean['review_intake_implemented'] = false;
                $clean['detected'] = false;
                $clean['state'] = 'adapter_required';
            }
            $out[ $entry['id'] ] = $clean;
        }
        $preferred = array();
        foreach ( array( 'admin_csv_upload', 'signed_generic_webhook', 'google_apps_script',
            'wp_all_import_wizard', 'wp_all_import_cron', 'wp_all_import_wpcli' ) as $id )
            if ( isset( $out[ $id ] ) && $out[ $id ]['review_intake_implemented'] &&
                $out[ $id ]['detected'] ) $preferred[] = $id;
        return array( 'contract' => self::CONTRACT, 'mode_count' => count( $out ),
            'modes' => array_values( $out ), 'recommended_review_modes' => $preferred,
            'dynamic_extensions_supported' => true, 'auto_write_enabled' => false,
            'readonly' => true, 'mutation_performed' => false );
    }
    public static function plan( $input = array() ) {
        if ( ! is_array( $input ) ) return self::err( 'mad4b_import_mode_request_invalid', 'Expected bounded mode request.' );
        $catalog = self::catalog();
        if ( is_wp_error( $catalog ) ) return $catalog;
        $id = isset( $input['mode_id'] ) ? (string) $input['mode_id'] : '';
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) ||
            ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) )
            return self::err( 'mad4b_import_mode_profile_invalid', 'Exact enabled site profile is required.' );
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) ||
            ! MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile ) )
            return self::err( 'mad4b_import_mode_profile_not_ready', 'Business Activity facet must be enabled.' );
        $policy = MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile );
        if ( ! empty( $policy['enabled_modes'] ) &&
            ! in_array( $id, $policy['enabled_modes'], true ) )
            return self::err( 'mad4b_import_mode_disabled_for_profile',
                'This transport is not enabled by the governed site Activity Profile.' );
        $profile_scoped_key_ready = true;
        if ( in_array( $id, array( 'google_apps_script', 'signed_generic_webhook' ), true ) ) {
            $profile_scoped_key_ready = false;
            $keys = defined( 'MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS' ) &&
                is_array( MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS ) ?
                MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS : array();
            foreach ( $keys as $key_id => $key ) {
                if ( is_string( $key_id ) && is_array( $key ) &&
                    ! empty( $key['enabled'] ) &&
                    isset( $key['mode'], $key['profile_slugs'], $key['secret'] ) &&
                    $key['mode'] === $id &&
                    is_array( $key['profile_slugs'] ) &&
                    in_array( $slug, $key['profile_slugs'], true ) &&
                    is_string( $key['secret'] ) &&
                    strlen( $key['secret'] ) >= 32 ) {
                    $profile_scoped_key_ready = true; break;
                }
            }
        }
        foreach ( $catalog['modes'] as $mode ) {
            if ( $mode['id'] !== $id ) continue;
            $staging = method_exists( 'MAD4B_SCP_Site_Profile', 'environment_allowed' ) &&
                MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) );
            $fingerprint = array(
                'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
                'profile_slug' => $slug, 'profile_revision' => $profile['revision'],
                'profile_sha256' => $profile['authority_sha256'],
                'mode_id' => $id, 'mode' => $mode,
                'staging_verified' => $staging
            );
            return array( 'contract' => self::CONTRACT, 'mode' => $mode,
                'plan_sha256' => self::digest( $fingerprint ),
                'eligible_for_staging_review' => $staging &&
                    $profile_scoped_key_ready &&
                    $mode['detected'] && $mode['review_intake_implemented'],
                'source_key_bound_to_profile' => $profile_scoped_key_ready,
                'site_staging_verified' => $staging,
                'required_setup' => $mode['requirements'],
                'profile_preferred_mode' => isset( $policy['preferred_mode'] ) ? $policy['preferred_mode'] : '',
                'profile_fallback_modes' => isset( $policy['fallback_modes'] ) ? $policy['fallback_modes'] : array(),
                'fallback_requires_new_explicit_plan' => true,
                'no_automatic_fallback_for_import_writes' => true,
                'ready_to_mutate_posts' => false,
                'read_only' => true, 'mutation_performed' => false );
        }
        return self::err( 'mad4b_import_mode_unknown', 'Mode is not in the exact trusted catalog.' );
    }
}
