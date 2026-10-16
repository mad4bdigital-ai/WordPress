<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Read-only, canonical capability classification for BOTH WP Admin and MCP.
 * Names are a bounded recovery taxonomy; actual availability comes ONLY from
 * live WP Abilities registration and canonical MCP mount inspection.
 */
final class MAD4B_SCP_Recovery_Route_Matrix {
    const ABILITY = 'mad4b/recovery-route-matrix';
    const CONTRACT = 'mad4b.recovery-route-matrix.v1';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 42 );
    }
    public static function register() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Discover Staging and Production Recovery Route Matrix',
            'description' => 'Read-only compare of current registered WP abilities, host-admin routes and MCP execution lanes. Never an approval.',
            'category' => 'mad4b-staging-write-authority',
            'execute_callback' => array( __CLASS__, 'inspect' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read',
                    'generic_remote_admin' => false, 'production_mutation_allowed' => false ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }
    public static function can_read( $input = null ) {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    }
    private static function row( $order, $stage, $manual, $ability, $lane, $gate, $after ) {
        return array(
            'order' => $order,
            'stage' => $stage,
            'wordpress_admin_path' => $manual,
            'mcp_ability' => $ability,
            'mcp_lane' => $lane,
            'approval_gate' => $gate,
            'after' => $after,
        );
    }
    private static function rows() {
        // These are known lifecycle semantics, NOT asserted tool availability.
        return array(
            self::row( 10, 'discovery', 'admin.php?page=mad4b-operational-remediation',
                'mad4b/chatgpt-tool-projection-discover', 'read', 'none', 'compare_registered_and_mounted' ),
            self::row( 20, 'runtime_release', 'plugins.php',
                'mad4b/control-plane-native-plan', 'read', 'release_certification', 'verify_exact_installed_identity' ),
            self::row( 30, 'host_binding_status', 'admin.php?page=mad4b-operational-remediation#mad4b-staging-deployment-identity',
                'mad4b/deployment-binding-native-status', 'read', 'none', 'readback_before_write' ),
            self::row( 40, 'host_binding_create', 'admin.php?page=mad4b-operational-remediation#mad4b-staging-deployment-identity',
                'mad4b/deployment-binding-native-apply', 'write', 'separate_exact_owner_ticket', 'new_request_binding_readback' ),
            self::row( 50, 'host_binding_readback', 'admin.php?page=mad4b-operational-remediation#mad4b-staging-deployment-identity',
                'mad4b/deployment-binding-native-readback', 'read', 'none', 'replan_staging_write_if_binding_verified' ),
            self::row( 60, 'owner_review', 'tools.php?page=mad4b-staging-write-owner-review',
                'mad4b/staging-write-owner-review', 'read', 'enrolled_owner', 'record_exact_owner_intent' ),
            self::row( 70, 'owner_intent', 'tools.php?page=mad4b-staging-write-owner-review',
                'mad4b/staging-write-owner-attest', 'enrollment', 'wp_nonce_or_oauth_step_up', 'intent_is_not_execution_ticket' ),
            self::row( 80, 'write_plan', 'tools.php?page=mad4b-staging-write-owner-review',
                'mad4b/staging-write-authority-convergence-handshake', 'read', 'enrolled_owner_oauth', 'review_exact_plan_and_explicit_confirmation' ),
            self::row( 90, 'write_execute', 'tools.php?page=mad4b-staging-write-owner-review',
                'mad4b/staging-write-authority-convergence-apply', 'enrollment', 'exact_owner_intent_plus_oauth_step_up', 'verify_current_write_and_grants' ),
            self::row( 100, 'write_readback', 'admin.php?page=mad4b-operational-remediation',
                'mad4b/staging-write-authority-convergence-status', 'read', 'none', 'resume_safe_runtime_phases' ),
            self::row( 110, 'runtime_resume', 'admin.php?page=mad4b-operator-control-center',
                'mad4b/runtime-convergence-plan', 'read', 'automation_policy_and_checkpoint', 'verify_schema_skills_and_current_write' ),
            self::row( 120, 'managed_skills', 'admin.php?page=mad4b-operational-remediation',
                'mad4b/skills-runtime-certification', 'read', 'none', 'independent_runtime_checkpoint' ),
            self::row( 130, 'context_onboarding', 'admin.php?page=mad4b-control-plane-context',
                'mad4b/context-source-onboarding-preflight', 'read', 'owner_source_selection', 'verify_google_provider_and_source' ),
            self::row( 140, 'production_host_identity', 'admin.php?page=mad4b-operational-remediation#mad4b-production-deployment-identity',
                'mad4b/deployment-binding-production-status', 'read', 'production_separate_release', 'verify_production_host_locally' ),
            self::row( 135, 'staging_promotion_evidence', 'admin.php?page=mad4b-operational-remediation',
                'mad4b/production-readiness-evaluate', 'read', 'independent_production_owner_release', 'bind_candidate_and_external_evidence' ),
            self::row( 150, 'production_host_enrollment', 'admin.php?page=mad4b-operational-remediation#mad4b-production-deployment-identity',
                '', 'not_implemented', 'production_owner_and_verified_backup', 'no_automatic_secret_creation' ),
            self::row( 160, 'production_release_execution', 'admin.php?page=mad4b-operational-remediation#mad4b-production-deployment-identity',
                '', 'not_implemented', 'independent_production_release_attestation', 'canary_and_rollback_required' ),
            self::row( 170, 'production_restore_verification', 'admin.php?page=mad4b-operational-remediation#mad4b-production-deployment-identity',
                '', 'not_implemented', 'production_backup_restore_approval', 'independent_recovery_evidence_required' ),
        );
    }
    public static function inspect( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) || $input )
            return new WP_Error( 'mad4b_recovery_matrix_read_denied', 'Current administrator read permission required.' );
        $environment = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'unknown';
        $profile_env = class_exists( 'MAD4B_SCP_Site_Profile', false ) &&
            method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' )
            ? (string) MAD4B_SCP_Site_Profile::current_environment() : 'unknown';
        $exact_env = in_array( $environment, array( 'staging', 'production' ), true ) && $environment === $profile_env;
        $out = array();
        $found = 0; $mounted = 0;
        foreach ( self::rows() as $item ) {
            $name = $item['mcp_ability'];
            $registered = '' !== $name && function_exists( 'wp_has_ability' ) && wp_has_ability( $name );
            $exposure_checked = class_exists( 'MAD4B_SCP_Servers', false )
                && method_exists( 'MAD4B_SCP_Servers', 'ability_is_mounted' );
            $is_mounted = $registered && $exposure_checked
                && ( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $name )
                  || MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $name )
                  || MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-enrollment', $name ) );
            $prod = 0 === strpos( $item['stage'], 'production_' );
            $allowed_env = $exact_env && ( $prod ? 'production' === $environment : 'staging' === $environment
                || in_array( $item['stage'], array( 'discovery', 'runtime_release' ), true ) );
            $item['ability_registered'] = (bool) $registered;
            $item['remote_mount_verified'] = (bool) $is_mounted;
            $item['remote_mount_inspection_available'] = $exposure_checked;
            $item['environment_applicable'] = $allowed_env;
            $item['wordpress_route_type'] = 'admin_navigation_only';
            $item['remote_authority_granted'] = false;
            $item['execution_performed'] = false;
            $item['state'] = ! $allowed_env ? 'NOT_APPLICABLE_TO_EXACT_ENVIRONMENT'
                : ( '' === $name ? 'GOVERNED_EXECUTOR_NOT_IMPLEMENTED'
                : ( ! $registered ? 'ABILITY_NOT_REGISTERED'
                : ( ! $is_mounted ? 'REGISTERED_MCP_NOT_VERIFIED'
                : ( 'none' === $item['approval_gate'] ? 'DISCOVERED_READ_ROUTE'
                    : 'DISCOVERED_OWNER_OR_PROVIDER_GATE' ) ) ) );
            if ( $registered ) ++$found;
            if ( $is_mounted ) ++$mounted;
            $out[] = $item;
        }
        usort( $out, static function ( $a, $b ) { return $a['order'] <=> $b['order']; } );
        return array(
            'contract' => self::CONTRACT, 'environment' => $environment,
            'profile_environment' => $profile_env, 'exact_environment' => $exact_env,
            'hosting_relationship_claim' => 'same_hostinger_account_user_reported_not_cryptographic_proof',
            'separate_site_uuids_and_host_secrets_required' => true,
            'stage_count' => count( $out ), 'registered_count' => $found,
            'remote_mount_verified_count' => $mounted, 'stages' => $out,
            'discovery_source' => 'live_wp_registered_abilities_and_canonical_mcp_mounts',
            'coverage' => 'bounded_recovery_lifecycle_scope_not_all_wordpress_plugins',
            'owner_step_up_required_for_write' => true,
            'automatic_mutation_allowed' => false,
            'read_only' => true, 'authorizing' => false, 'mutation_performed' => false,
            'production_mutation_performed' => false,
        );
    }
    public static function render_admin() {
        if ( ! self::can_read() ) return;
        $data = self::inspect();
        if ( is_wp_error( $data ) ) {
            echo '<p role="alert">' . esc_html( $data->get_error_code() ) . '</p>'; return;
        }
        echo '<section><h2 id="mad4b-recovery-route-matrix">Recovery routes — WordPress vs OAuth/MCP</h2>';
        echo '<p>Current WP Abilities and MCP mounts are discovered on every request. A registered tool or owner review does not grant write authority. Each row is classified by environment and owner gate.</p>';
        echo '<p><strong>Environment:</strong> <code>' . esc_html( $data['environment'] )
            . '</code> <strong>Registered:</strong> ' . esc_html( (string) $data['registered_count'] )
            . '/' . esc_html( (string) $data['stage_count'] ) . ' <strong>MCP mounts verified:</strong> '
            . esc_html( (string) $data['remote_mount_verified_count'] ) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>Stage</th><th>WordPress Admin</th><th>Remote MCP</th><th>Availability</th><th>Approval</th><th>Afterwards</th></tr></thead><tbody>';
        foreach ( $data['stages'] as $row ) {
            echo '<tr><td>' . esc_html( $row['stage'] ) . '</td><td><a href="'
                . esc_url( admin_url( $row['wordpress_admin_path'] ) ) . '">Open WordPress</a> <code>'
                . esc_html( $row['wordpress_admin_path'] ) . '</code></td><td><code>'
                . esc_html( $row['mcp_ability'] ) . '</code> (' . esc_html( $row['mcp_lane'] )
                . ')</td><td><code>' . esc_html( $row['state'] ) . '</code></td><td><code>'
                . esc_html( $row['approval_gate'] ) . '</code></td><td>'
                . esc_html( $row['after'] ) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }
}
