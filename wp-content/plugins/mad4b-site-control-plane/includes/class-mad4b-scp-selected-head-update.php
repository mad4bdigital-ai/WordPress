<?php
/**
 * Optional, explicitly selected GitHub HEAD -> certified Staging package.
 *
 * This capability never runs automatically, never changes the default
 * master Release channel, and never builds or trusts arbitrary GitHub source
 * archives as WordPress installable packages.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Selected_Head_Update {
    const CONTRACT = 'mad4b.control-plane-selected-head-update.v1';
    const PLAN_ABILITY = 'mad4b/control-plane-selected-head-plan';
    const APPLY_ABILITY = 'mad4b/control-plane-selected-head-apply';
    const CONFIRMATION = 'INSTALL SELECTED HEAD ON STAGING';
    const REPO = 'mad4bdigital-ai/WordPress';
    const RELEASE_TAG = 'mad4b-site-control-plane-update-channel';
    const MAX_MANIFEST_BYTES = 16384;
    const MAX_ZIP_BYTES = 16777216;

    public static function boot() {
        // Register scoped WordPress-only attestation trust enrollment without
        // touching the plugin bootstrap or its G9 delivery fingerprints.
        if ( ! class_exists( 'MAD4B_SCP_CI_Outage_Attestation', false ) )
            require_once __DIR__ . '/class-mad4b-scp-ci-outage-attestation.php';
        MAD4B_SCP_CI_Outage_Attestation::boot();
        // Optional PHP-native packaging is non-authorizing and Staging-only.
        require_once __DIR__ . '/class-mad4b-scp-wp-native-build.php';
        MAD4B_SCP_WP_Native_Build::boot();
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 35 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $abilities = array(
            array( self::PLAN_ABILITY, 'Plan optional exact-HEAD Staging update', 'plan', true, array( 'MAD4B_SCP_Policy', 'can_read' ), self::plan_schema() ),
            array( self::APPLY_ABILITY, 'Apply explicitly approved exact-HEAD Staging update', 'apply', false, array( __CLASS__, 'can_apply' ), self::apply_schema() ),
        );
        foreach ( $abilities as $a ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $a[0] ) ) continue;
            wp_register_ability( $a[0], array(
                'label' => $a[1],
                'description' => 'Opt-in, immutable GitHub source and certified package update for enrolled Staging only.',
                'category' => $a[3] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $a[2] ),
                'permission_callback' => $a[4],
                'input_schema' => $a[5],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $a[3] ? 'read' : 'admin' ),
                    'annotations' => array( 'readonly' => $a[3], 'destructive' => ! $a[3], 'idempotent' => $a[3] ),
                ),
            ) );
        }
    }

    /** Default remains the signed master channel; optional mode needs host opt-in. */
    private static function opt_in() {
        if ( class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' ) &&
            MAD4B_SCP_WordPress_Native_Opt_In::enabled() ) return true;
        return defined( 'MAD4B_SCP_SELECTED_HEAD_UPDATES_ENABLED' )
            && true === constant( 'MAD4B_SCP_SELECTED_HEAD_UPDATES_ENABLED' )
            && defined( 'MAD4B_SCP_STAGING_CANDIDATE_UPDATES_ENABLED' )
            && true === constant( 'MAD4B_SCP_STAGING_CANDIDATE_UPDATES_ENABLED' );
    }

    private static function candidate_channel() {
        return class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' ) &&
            MAD4B_SCP_WordPress_Native_Opt_In::enabled()
            ? 'wordpress_native_candidate_upload' : 'staging_candidate_upload';
    }

    private static function exact_staging() {
        if ( class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' ) &&
            MAD4B_SCP_WordPress_Native_Opt_In::enabled() ) return true;
        return class_exists( 'MAD4B_SCP_Site_Profile' )
            && method_exists( 'MAD4B_SCP_Site_Profile', 'wordpress_environment_explicit' )
            && MAD4B_SCP_Site_Profile::wordpress_environment_explicit()
            && function_exists( 'wp_get_environment_type' )
            && 'staging' === wp_get_environment_type()
            && is_array( MAD4B_SCP_Site_Profile::status() )
            && ! empty( MAD4B_SCP_Site_Profile::status()['deployment_binding_configured'] )
            && ! empty( MAD4B_SCP_Site_Profile::status()['same_origin_clone_protection'] );
    }

    public static function can_apply( $input = null ) {
        if ( ! self::opt_in() || ! self::exact_staging() )
            return new WP_Error( 'mad4b_selected_head_staging_opt_in_required', 'Exact enrolled Staging and explicit opt-in are required.' );
        if ( ! class_exists( 'MAD4B_SCP_Self_Update' ) ||
            ! method_exists( 'MAD4B_SCP_Self_Update', 'can_upload_apply' ) )
            return new WP_Error( 'mad4b_selected_head_update_backend_missing', 'Governed package update backend is unavailable.' );
        // Reuse all existing enrolled-owner, OAuth step-up and central approval
        // gates of the manual Staging candidate upload, not bootstrap/breakglass.
        // Do not preflight the *different* control-plane-upload-apply ability:
        // it may require a distinct one-time owner ticket and cannot authorize
        // the selected-head operation. Check actor/environment/OAuth here, then
        // authorize exactly self::APPLY_ABILITY once at the Ability boundary.
        $admin = MAD4B_SCP_Policy::can_admin();
        if ( is_wp_error( $admin ) || ! $admin ) return $admin;
        if ( ! MAD4B_SCP_Policy::can_mutate() )
            return new WP_Error( 'mad4b_selected_head_mutation_disabled', 'Control Plane mutation is disabled.' );
        if ( ! current_user_can( 'manage_options' )
            || ! class_exists( 'MAD4B_SCP_Site_Profile', false )
            || ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() )
            || ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
                MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) )
            return new WP_Error( 'mad4b_selected_head_staging_owner_step_up_required',
                'Enrolled Staging administrator with verified OAuth step-up is required.' );
        if ( ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return new WP_Error( 'mad4b_selected_head_authorization_missing', 'Central authorization unavailable.' );
        return MAD4B_SCP_Authorization::authorize_mutation(
            self::APPLY_ABILITY, 'mad4b-admin', 'core',
            is_array( $input ) ? $input : array()
        );
    }

    private static function fail( $code, $message ) {
        return new WP_Error( 'mad4b_selected_head_' . $code, $message );
    }

    private static function source( $input ) {
        if ( ! class_exists( 'MAD4B_SCP_Staging_Source_Selector' ) )
            require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-source-selector.php';
        return MAD4B_SCP_Staging_Source_Selector::resolve( $input );
    }

    private static function manifest( $sha ) {
        if ( 1 !== preg_match( '/^[a-f0-9]{40}$/D', (string) $sha ) )
            return self::fail( 'sha_invalid', 'Exact source SHA is required.' );
        // The caller NEVER supplies any URL, package path or package bytes.
        $url = 'https://github.com/' . self::REPO . '/releases/download/' .
            self::RELEASE_TAG . '/mad4b-site-control-plane-update-' . $sha . '.json';
        if ( ! function_exists( 'wp_safe_remote_get' ) )
            return self::fail( 'network_unavailable', 'Safe HTTPS client required.' );
        $res = wp_safe_remote_get( $url, array(
            'timeout' => 12, 'redirection' => 3,
            'limit_response_size' => self::MAX_MANIFEST_BYTES + 1,
            'sslverify' => true,
            'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'MAD4B-OptIn-Selected-Head' ),
        ) );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) )
            return self::fail( 'certified_artifact_unavailable', 'No exact certified Staging manifest exists for the selected HEAD.' );
        $raw = wp_remote_retrieve_body( $res );
        if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_MANIFEST_BYTES )
            return self::fail( 'manifest_unbounded', 'Selected-HEAD manifest is invalid or oversized.' );
        $m = json_decode( $raw, true );
        if ( ! is_array( $m ) || (string) ( $m['source_commit_sha'] ?? '' ) !== $sha )
            return self::fail( 'manifest_head_mismatch', 'Selected HEAD and immutable package manifest differ.' );
        foreach ( array( 'archive_sha256', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
            if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $m[ $field ] ?? '' ) ) )
                return self::fail( 'manifest_identity_incomplete', 'Package build identity is incomplete.' );
        }
        // An unavailable/queued GitHub CI run is not an implicit PASS and is
        // not a required precondition when independent signed native evidence
        // is available. Staging-only offline receipts cannot certify master.
        $master_certified = true === ( $m['published_from_master'] ?? false )
            && true === ( $m['release_root_trust_verified'] ?? false );
        $ci_candidate = true === ( $m['staging_candidate_certified'] ?? false )
            && true === ( $m['release_verdict_success'] ?? false )
            && ! empty( $m['release_verdict_run_id'] );
        $ci_master = $master_certified && true === ( $m['release_verdict_success'] ?? false )
            && ! empty( $m['release_verdict_run_id'] );
        $offline_verified = array();
        if ( ! $ci_master && ! $ci_candidate &&
            true === ( $m['staging_offline_candidate'] ?? false ) &&
            false === ( $m['production_authorized'] ?? null ) &&
            false === ( $m['production_auto_update_enabled'] ?? null ) &&
            false === ( $m['published_from_master'] ?? null ) &&
            ! empty( $m['ci_outage_attestation'] ) &&
            class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
            if ( ! class_exists( 'MAD4B_SCP_CI_Outage_Attestation', false ) )
                require_once __DIR__ . '/class-mad4b-scp-ci-outage-attestation.php';
            $offline_verified = MAD4B_SCP_CI_Outage_Attestation::verify(
                $m['ci_outage_attestation'], $m, MAD4B_SCP_Site_Profile::status()
            );
            if ( is_wp_error( $offline_verified ) ) return $offline_verified;
        }
        if ( ! $ci_master && ! $ci_candidate && empty( $offline_verified['verified'] ) )
            return self::fail( 'candidate_not_certified', 'Exact HEAD requires completed trusted CI OR an owner-enrolled independently signed Staging-native test receipt.' );
        if ( ! isset( $m['version'] ) || ! is_string( $m['version'] ) ||
            strlen( $m['version'] ) < 1 || strlen( $m['version'] ) > 64 ||
            ! isset( $m['size_bytes'] ) || ! is_numeric( $m['size_bytes'] ) ||
            (int) $m['size_bytes'] < 1 || (int) $m['size_bytes'] > self::MAX_ZIP_BYTES )
            return self::fail( 'manifest_target_invalid', 'Certified package version or size is invalid.' );
        $zip_url = 'https://github.com/' . self::REPO . '/releases/download/' .
            self::RELEASE_TAG . '/mad4b-site-control-plane-' . $sha . '.zip';
        if ( ! isset( $m['package_url'] ) || ! hash_equals( $zip_url, (string) $m['package_url'] ) )
            return self::fail( 'package_url_untrusted', 'Only the immutable repository-owned ZIP is permitted.' );
        $identity = array();
        foreach ( array( 'version', 'source_commit_sha', 'archive_sha256', 'build_fingerprint', 'package_manifest_digest', 'size_bytes' ) as $k )
            $identity[ $k ] = $m[ $k ];
        return array( 'identity' => $identity, 'zip_url' => $zip_url,
            'manifest_sha256' => hash( 'sha256', $raw ),
            'release_verdict_run_id' => isset( $m['release_verdict_run_id'] ) ? (int) $m['release_verdict_run_id'] : 0,
            'evidence_mode' => ! empty( $offline_verified['verified'] ) ? 'owner_signed_ci_outage' : 'github_ci_verdict',
            'offline_receipt_sha256' => ! empty( $offline_verified['receipt_sha256'] ) ? $offline_verified['receipt_sha256'] : '',
            'offline_receipt_expires_at' => ! empty( $offline_verified['expires_at'] ) ? $offline_verified['expires_at'] : 0,
            'github_ci_terminal_required' => empty( $offline_verified['verified'] ) );
    }

    /** Pure assistant handoff; action metadata does not grant any authority. */
    public static function blocker_recovery( $blockers ) {
        // Native Staging opt-in is a separate owner-approved WordPress lane.
        // Do not advertise a Host-only bootstrap when that lane can be
        // enabled on the exact enrolled Site Profile without a Host Runner.
        $native_site_ready = false;
        if ( class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' )
            && class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
            $site = MAD4B_SCP_Site_Profile::status();
            if ( is_array( $site ) ) {
                $policy = MAD4B_SCP_WordPress_Native_Opt_In::site_policy( $site );
                $native_site_ready = is_array( $policy )
                    && ! empty( $policy['eligible'] )
                    && function_exists( 'wp_get_environment_type' )
                    && 'staging' === wp_get_environment_type();
            }
        }
        $catalog = array(
            'selected_head_opt_in_disabled' => array(
                'step_id' => 'enable_selected_head_host_opt_in',
                'actor' => 'staging_host_operator',
                'surface' => 'one_time_host_bootstrap',
                'tool' => 'host_owned_one_time_staging_provisioning',
                'requires_explicit_consent' => true,
                'remote_mcp_auto_apply_allowed' => false,
            ),
            'exact_staging_binding_required' => array(
                'step_id' => 'align_exact_staging_host_and_profile_binding',
                'actor' => 'staging_host_operator',
                'surface' => 'wp_config_then_fresh_wordpress_request_and_profile_save',
                'tool' => 'host_owned_one_time_staging_provisioning',
                'requires_explicit_consent' => true,
                'remote_mcp_auto_apply_allowed' => false,
            ),
            'mad4b_selected_head_certified_artifact_unavailable' => array(
                'step_id' => 'publish_exact_signed_staging_candidate',
                'actor' => 'trusted_repository_release_operator',
                // An unavailable/queued Actions runner is not a mandatory gate.
                // A Staging-only independently signed native test report is an
                // alternative, never a declaration that GitHub CI passed.
                'surface' => 'independent_native_six_gate_tests_and_signed_receipt_or_trusted_ci_verdict',
                'tool' => 'tools/mad4b_standalone_evidence.py + tools/mad4b_publish_offline_staging_candidate.py',
                'accepted_evidence_modes' => array( 'owner_signed_ci_outage', 'github_ci_verdict' ),
                'github_actions_queue_clearance_required' => false,
                'staging_only' => true,
                'source_bound_native_tests_required' => true,
                'independent_ed25519_signer_required_without_ci' => true,
                'requires_explicit_consent' => true,
                'remote_mcp_auto_apply_allowed' => false,
            ),
            'candidate_not_certified' => array(
                'step_id' => 'repair_candidate_evidence_not_ci_queue',
                'actor' => 'trusted_repository_release_operator',
                'surface' => 'exact_head_native_gate_failures_or_missing_independent_attestation',
                'tool' => 'tools/mad4b_standalone_evidence.py + tools/mad4b_publish_offline_staging_candidate.py',
                'accepted_evidence_modes' => array( 'owner_signed_ci_outage', 'github_ci_verdict' ),
                'github_actions_queue_clearance_required' => false,
                'staging_only' => true,
                'source_bound_native_tests_required' => true,
                'independent_ed25519_signer_required_without_ci' => true,
                'requires_explicit_consent' => true,
                'remote_mcp_auto_apply_allowed' => false,
            ),
            'already_on_exact_source_commit' => array(
                'step_id' => 'verify_installed_candidate',
                'actor' => 'staging_operator',
                'surface' => 'read_only_package_identity_and_fresh_wordpress_request',
                'requires_explicit_consent' => false,
                'remote_mcp_auto_apply_allowed' => false,
            ),
        );
        if ( $native_site_ready ) {
            $native_enable = array(
                'step_id' => 'owner_approve_wordpress_native_candidate_mode',
                'actor' => 'enrolled_staging_owner',
                'surface' => 'wordPress_governed_mcp_exact_owner_oauth',
                'tool' => 'mad4b/wordpress-native-candidate-opt-in-plan',
                'apply_ability' => 'mad4b/wordpress-native-candidate-opt-in-apply',
                'next_after_enable' => 'mad4b/wp-native-build-plan',
                'requires_separate_approval' => true,
                'requires_exact_nhi_grant' => true,
                'host_runner_required' => false,
                'release_or_install_certified' => false,
                'production_allowed' => false,
                'remote_mcp_auto_apply_allowed' => false,
            );
            $catalog['selected_head_opt_in_disabled'] = $native_enable;
            $catalog['exact_staging_binding_required'] = array_merge(
                $native_enable, array( 'step_id' => 'use_non_binding_wordpress_native_staging_lane' ) );
        }
        $steps = array();
        foreach ( array_values( array_unique( (array) $blockers ) ) as $blocker ) {
            if ( ! is_string( $blocker ) || '' === $blocker ) continue;
            $entry = isset( $catalog[ $blocker ] ) ? $catalog[ $blocker ] : array(
                'step_id' => 'inspect_exact_governed_preflight',
                'actor' => 'staging_operator',
                'surface' => 'read_only_diagnostics_and_explicit_plan_review',
                'requires_explicit_consent' => true,
                'remote_mcp_auto_apply_allowed' => false,
            );
            $entry['blocker'] = $blocker;
            $steps[] = $entry;
        }
        return $steps;
    }

    public static function plan( $input ) {
        $input = is_array( $input ) ? $input : array();
        $candidate = isset( $input['candidate_source'] ) ? $input['candidate_source'] : null;
        $reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';
        $blockers = array();
        if ( ! self::opt_in() ) $blockers[] = 'selected_head_opt_in_disabled';
        if ( ! self::exact_staging() ) $blockers[] = 'exact_staging_binding_required';
        $resolved = self::source( $candidate );
        if ( is_wp_error( $resolved ) ) return $resolved;
        if ( self::REPO !== $resolved['repository'] )
            $blockers[] = 'selected_head_repository_unsupported';
        $package = self::REPO === $resolved['repository'] ? self::manifest( $resolved['resolved_sha'] )
            : self::fail( 'repository_unsupported', 'Only the installed plugin repository release is accepted.' );
        $upload_plan = array();
        if ( is_wp_error( $package ) ) {
            $blockers[] = $package->get_error_code();
        } else {
            $upload_plan = MAD4B_SCP_Self_Update::upload_plan( array_merge( $package['identity'], array(
                'channel' => self::candidate_channel(), 'candidate_source' => $candidate, 'reason' => $reason,
            ) ) );
            if ( is_wp_error( $upload_plan ) ) $blockers[] = $upload_plan->get_error_code();
            elseif ( empty( $upload_plan['eligible'] ) ) $blockers = array_merge( $blockers, $upload_plan['blockers'] );
        }
        $blockers = array_values( array_unique( $blockers ) );
        sort( $blockers, SORT_STRING );
        $selected = array(
            'contract' => self::CONTRACT, 'mode' => 'explicit_opt_in_only',
            'default_release_channel_unchanged' => true, 'automatic_update' => false,
            'production_allowed' => false,
            // Local CI may test the same exact source for Hostinger, managed
            // hosting or a disposable container; it cannot sign a release.
            'local_ci_multi_environment' => array(
                'supported' => true,
                'runner' => 'tools/mad4b-local-ci-parity.py',
                'expected_source_sha' => $resolved['resolved_sha'],
                'hosting_target_not_assumed_docker' => true,
                'local_receipt_authorizing' => false,
                'github_ci_certified_by_local_runner' => false,
                'staging_package_certification_still_required' => true,
                'github_ci_terminal_result_required' => false,
                'independent_signed_ci_outage_receipt_supported' => true,
            ),
            'source' => $resolved,
            'package_identity' => is_wp_error( $package ) ? array() : $package['identity'],
            'manifest_sha256' => is_wp_error( $package ) ? '' : $package['manifest_sha256'],
            'release_verdict_run_id' => is_wp_error( $package ) ? 0 : $package['release_verdict_run_id'],
            'verification_evidence_mode' => is_wp_error( $package ) ? 'unavailable' : $package['evidence_mode'],
            'offline_receipt_sha256' => is_wp_error( $package ) ? '' : $package['offline_receipt_sha256'],
            'offline_receipt_expires_at' => is_wp_error( $package ) ? 0 : $package['offline_receipt_expires_at'],
            'github_ci_terminal_required' => is_wp_error( $package ) ? false : $package['github_ci_terminal_required'],
            'underlying_upload_plan_sha256' => is_array( $upload_plan ) ? ( $upload_plan['plan_sha256'] ?? '' ) : '',
            'update_channel' => self::candidate_channel(),
            'wordpress_native' => 'wordpress_native_candidate_upload' === self::candidate_channel(),
            'eligible' => empty( $blockers ), 'blockers' => $blockers,
            'assistant_recovery' => self::blocker_recovery( $blockers ),
            // A blocked signed release is NOT equivalent to there being no
            // source-build path. Re-evaluate registered provider preflights on
            // each exact-head plan, keeping build and install grants separate.
            'alternate_recovery_routes' => ( ! empty( $blockers ) &&
                class_exists( 'MAD4B_SCP_Recovery_Route_Resolver' ) )
                ? MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
                    'goal' => 'staging_install',
                    'expected_head' => (string) $resolved['resolved_sha'],
                    'blockers' => $blockers,
                ) )
                : null,
            'unattended_host_bootstrap' => false,
            'candidate_release_manifest_required' => true,
            'queued_ci_is_blocker_when_signed_offline_receipt_exists' => false,
            'github_actions_required_for_staging_candidate' => false,
            'github_actions_stalled_awaiting_queue_clearance' => false,
            'accepted_staging_evidence_modes' => array( 'github_ci_verdict', 'owner_signed_ci_outage' ),
            'independent_evidence_required_if_ci_unavailable' => true,
            'native_six_gate_failures_may_be_ignored' => false,
            'native_five_gate_failures_may_be_ignored' => false, // compatibility metadata; never permits bypass
            'offline_signer_and_certified_exact_head_manifest_required' => true,
            'ci_queue_state_used_as_authority' => false,
            'reason' => $reason, 'mutation_performed' => false, 'authorizing' => false,
        );
        $selected['plan_sha256'] = hash( 'sha256', wp_json_encode( $selected ) );
        $selected['write_binding'] = array( 'expected_plan_sha256' => $selected['plan_sha256'] );
        return $selected;
    }

    public static function apply( $input ) {
        // The exact one-time approval must have been claimed by the registered
        // WordPress Ability and carried through the governed execution fence.
        if ( ! class_exists( 'MAD4B_SCP_Authorization', false )
            || ! MAD4B_SCP_Authorization::execution_callback_started( self::APPLY_ABILITY )
            || ! class_exists( 'MAD4B_SCP_Execution_Fence', false )
            || ! MAD4B_SCP_Execution_Fence::has_active_frame() )
            return self::fail( 'governed_execution_required',
                'Use the claimed governed WordPress Ability, not a direct PHP installation call.' );
        $input = is_array( $input ) ? $input : array();
        if ( ( $input['confirmation'] ?? '' ) !== self::CONFIRMATION )
            return self::fail( 'confirmation_required', 'Explicit reviewed Staging update confirmation required.' );
        $access = self::can_apply( $input );
        if ( is_wp_error( $access ) ) return $access;
        if ( ! MAD4B_SCP_Authorization::permission_result_from_authorization( $access ) )
            return self::fail( 'authorization_denied',
                'The exact selected-head owner authorization was not granted.' );
        $expected = (string) ( $input['expected_plan_sha256'] ?? '' );
        if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) )
            return self::fail( 'plan_digest_required', 'Exact plan digest required.' );
        $plan_input = array( 'candidate_source' => $input['candidate_source'] ?? null, 'reason' => $input['reason'] ?? '' );
        $plan = self::plan( $plan_input );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! hash_equals( $plan['plan_sha256'], $expected ) )
            return self::fail( 'plan_changed', 'Selected HEAD or certified artifact changed after approval; review a new plan.' );
        if ( empty( $plan['eligible'] ) )
            return new WP_Error( 'mad4b_selected_head_preflight_blocked', 'Selected-HEAD update cannot proceed.', array( 'blockers' => $plan['blockers'] ) );

        // Plan generated an exact immutable GitHub-owned ZIP URL; the caller
        // cannot substitute a URL or local archive.
        $sha = $plan['source']['resolved_sha'];
        $zip_url = 'https://github.com/' . self::REPO . '/releases/download/' .
            self::RELEASE_TAG . '/mad4b-site-control-plane-' . $sha . '.zip';
        $res = wp_safe_remote_get( $zip_url, array(
            'timeout' => 30, 'redirection' => 3, 'sslverify' => true,
            'limit_response_size' => self::MAX_ZIP_BYTES + 1,
            'headers' => array( 'Accept' => 'application/zip', 'User-Agent' => 'MAD4B-OptIn-Selected-Head' ),
        ) );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) )
            return self::fail( 'archive_download_failed', 'Exact certified archive could not be downloaded.' );
        $bytes = wp_remote_retrieve_body( $res );
        if ( ! is_string( $bytes ) ||
            strlen( $bytes ) !== (int) $plan['package_identity']['size_bytes'] ||
            ! hash_equals( $plan['package_identity']['archive_sha256'], hash( 'sha256', $bytes ) ) )
            return self::fail( 'archive_integrity_failed', 'Exact ZIP digest or byte length does not match the reviewed package.');
        // Second admission check after potentially lengthy download.
        $access = self::can_apply( $input );
        if ( is_wp_error( $access ) || true !== $access ) return $access;
        $fresh = self::source( $plan_input['candidate_source'] );
        if ( is_wp_error( $fresh ) || ! hash_equals( $sha, is_array( $fresh ) ? (string) $fresh['resolved_sha'] : '' ) )
            return self::fail( 'selected_head_moved', 'Branch/PR moved after download. New approval required.' );

        // A signed CI-outage receipt may expire while the archive is being
        // fetched. Re-validate the exact same immutable manifest and signer
        // evidence immediately before invoking the core WordPress installer.
        if ( 'owner_signed_ci_outage' === ( $plan['verification_evidence_mode'] ?? '' ) ) {
            $current_evidence = self::manifest( $sha );
            if ( is_wp_error( $current_evidence ) ||
                ! hash_equals( (string) $plan['manifest_sha256'],
                    is_array( $current_evidence ) ? (string) $current_evidence['manifest_sha256'] : '' ) ||
                ! hash_equals( (string) $plan['offline_receipt_sha256'],
                    is_array( $current_evidence ) ? (string) $current_evidence['offline_receipt_sha256'] : '' ) )
                return self::fail( 'offline_evidence_expired_or_changed', 'Independent Staging offline evidence expired or changed before install.' );
        }

        $upload = array_merge( $plan['package_identity'], $plan_input, array(
            'channel' => $plan['update_channel'],
            'candidate_confirmation' => 'INSTALL EXACT STAGING CANDIDATE',
            'expected_plan_sha256' => $plan['underlying_upload_plan_sha256'],
            'package_base64' => base64_encode( $bytes ),
        ) );
        unset( $bytes );
        // This existing verifier performs archive/provenance verification,
        // live source re-resolution, maintenance lease, backup, readback and
        // rollback. No second installer or core update-channel mutation.
        return MAD4B_SCP_Self_Update::upload_apply( $upload );
    }

    private static function plan_schema() {
        return array( 'type' => 'object', 'additionalProperties' => false,
            'required' => array( 'candidate_source', 'reason' ),
            'properties' => array(
                'candidate_source' => array( 'type' => 'object', 'additionalProperties' => false,
                    'required' => array( 'repository', 'type', 'reference' ),
                    'properties' => array(
                        'repository' => array( 'type' => 'string', 'maxLength' => 140 ),
                        'type' => array( 'type' => 'string', 'enum' => array( 'pull_request', 'branch', 'commit', 'branch_commit' ) ),
                        'reference' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 161 ),
                    ),
                ),
                'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
            ),
        );
    }
    private static function apply_schema() {
        $s = self::plan_schema();
        $s['required'][] = 'expected_plan_sha256';
        $s['required'][] = 'confirmation';
        $s['properties']['expected_plan_sha256'] = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
        $s['properties']['confirmation'] = array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) );
        return $s;
    }
}
