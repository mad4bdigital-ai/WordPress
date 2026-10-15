<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP09 read-only live readiness inventory. A provider being loaded never
 * substitutes for a certified import, rollback or Production release.
 * Suppresses all keys, API tokens and input data.
 */
final class MAD4B_SCP_Import_Acceptance_Gates {
    const CONTRACT = 'mad4b.import-live-acceptance.v1';
    public static function status( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) )
            return new WP_Error( 'mad4b_import_acceptance_denied',
                'Administrator access is required to inspect provider readiness.' );
        $slug = isset( $input['profile_slug'] ) ?
            sanitize_key( (string) $input['profile_slug'] ) : '';
        if ( ! preg_match( '/^[a-z0-9_-]{2,48}$/D', $slug ) )
            return new WP_Error( 'mad4b_import_acceptance_profile_required',
                'Choose one exact configured Content Experience Profile.' );
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) ) return $profile;
        $policy = MAD4B_SCP_Activity_Import_Authority::profile_contract( $profile );
        $site = class_exists( 'MAD4B_SCP_Site_Profile' ) &&
            MAD4B_SCP_Site_Profile::configured() &&
            MAD4B_SCP_Site_Profile::origin_enrolled() &&
            MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
        $staging = $site &&
            MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ) );
        $key_ready = defined( 'MAD4B_ACTIVITY_IMPORT_DATA_KEY' ) &&
            is_string( MAD4B_ACTIVITY_IMPORT_DATA_KEY ) &&
            strlen( MAD4B_ACTIVITY_IMPORT_DATA_KEY ) >= 32 &&
            function_exists( 'openssl_encrypt' ) &&
            function_exists( 'openssl_decrypt' ) &&
            in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
        $wpai_loaded = class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' );
        $wpml_loaded = defined( 'ICL_SITEPRESS_VERSION' ) ||
            class_exists( 'SitePress' );
        $jetengine_loaded = defined( 'JET_ENGINE_VERSION' ) ||
            function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
        $xlsx_ready = class_exists( 'MAD4B_SCP_Activity_Import_Xlsx' ) &&
            MAD4B_SCP_Activity_Import_Xlsx::available();
        $allowed = isset( $policy['enabled_modes'] ) &&
            is_array( $policy['enabled_modes'] ) ? $policy['enabled_modes'] : array();
        $batch_mode = MAD4B_SCP_Activity_Import_Authority::mode_allowed(
            $profile, 'admin_csv_upload' );
        $brand = array( 'reviewed' => false,
            'coverage_evidence_available' => false,
            'missing_required_context_count' => null );
        if ( class_exists( 'MAD4B_SCP_Context_Authority' ) &&
            method_exists( 'MAD4B_SCP_Context_Authority', 'brand_core_coverage' ) ) {
            $data = MAD4B_SCP_Context_Authority::brand_core_coverage();
            if ( is_array( $data ) ) {
                $brand['coverage_evidence_available'] = true;
                $brand['missing_required_context_count'] =
                    isset( $data['missing_required_context_sets'] ) &&
                    is_array( $data['missing_required_context_sets'] ) ?
                    count( $data['missing_required_context_sets'] ) : null;
            }
        }
        $checks = array(
            'enrolled_site' => $site,
            'staging_environment' => $staging,
            'profile_enabled' => ! empty( $profile['enabled'] ),
            'profile_owned_import_validation' => ! empty( $policy['validation'] ),
            'source_mode_allowlist_nonempty' => ! empty( $allowed ),
            'aes_gcm_encrypted_review_key' => $key_ready,
            'conditional_xlsx_parser_approved_and_available' => $xlsx_ready,
            'wp_all_import_plugin_detected' => $wpai_loaded,
            'wpml_runtime_detected' => $wpml_loaded,
            'jetengine_runtime_detected' => $jetengine_loaded,
            'bounded_batch_review_allowed' => $batch_mode && $key_ready && $staging
        );
        $unverified = array(
            'exact_head_staging_deploy_readback',
            'native_php_74_83_tests',
            'browser_keyboard_mobile_rtl',
            'wp_all_import_version_template_and_source_snapshot_provenance',
            'wpml_group_links_after_provider_execution',
            'jetengine_cct_and_relations_after_provider_execution',
            'google_sheets_multi_editor_conditional_cas',
            'third_party_wp_all_import_msr02_shared_write_fence',
            'provider_write_transaction_and_compensating_rollback',
            'production_release_owner_authorization'
        );
        $next = array();
        if ( !$site ) $next[] = 'Enroll and verify the exact WordPress site Profile.';
        if ( !$staging ) $next[] = 'Select a dedicated Staging runtime before source intake.';
        if ( empty( $policy['validation'] ) || empty( $allowed ) )
            $next[] = 'Configure site-owned required fields and an explicit permitted Mode.';
        if ( !$key_ready ) $next[] = 'Provision a managed 32+ character import encryption key on the host.';
        if ( !$xlsx_ready ) $next[] =
            'To use XLSX, attest a vetted local PhpSpreadsheet parser; otherwise use CSV.';
        if ( !$wpai_loaded ) $next[] =
            'Install a compatible WP All Import provider before provider-specific acceptance.';
        if ( !$wpml_loaded ) $next[] =
            'If translations are required, install and verify native WPML readback.';
        if ( !$jetengine_loaded ) $next[] =
            'If CCT/relations are required, certify the installed JetEngine adapter.';
        $next[] =
            'Run exact-HEAD native PHP/browser tests and independently verify real provider writes on Staging.';
        $next[] =
            'Do not authorize live multi-provider writes until CAS, leases and rollback are independently demonstrated.';
        return array(
            'contract' => self::CONTRACT,
            'profile_slug' => $slug,
            'profile_revision' => isset( $profile['revision'] ) ? $profile['revision'] : null,
            'profile_authority_sha256' =>
                isset( $profile['authority_sha256'] ) ?
                $profile['authority_sha256'] : null,
            'runtime_probe_executed' => true,
            'provider_presence_checks' => $checks,
            'provider_presence_not_certification' => true,
            'brand_core' => $brand,
            'uncertified_acceptance_gates' => $unverified,
            'next_safe_actions' => $next,
            'source_code_release_ready' => false,
            'ready_for_automatic_import' => false,
            'production_promotion_authorized' => false,
            'read_only' => true, 'mutation_performed' => false
        );
    }
}
