<?php
/**
 * Staging owner choice: WordPress-native selected-HEAD update without
 * a Host Runner. Does not grant any MCP mutation or override existing
 * audit, central approval, OAuth step-up, maintenance, backup or rollback.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_WordPress_Native_Opt_In {
    const OPTION = 'mad4b_scp_wp_native_candidate_opt_in_v1';
    const ADMIN_ACTION = 'mad4b_wp_native_candidate_opt_in';
    const CONFIRM = 'ENABLE WORDPRESS NATIVE STAGING CANDIDATES';
    public static function boot() {
        add_action( 'admin_post_' . self::ADMIN_ACTION, array( __CLASS__, 'handle_admin' ) );
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 35 );
    }
    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/wordpress-native-update-status' ) ) return;
        wp_register_ability( 'mad4b/wordpress-native-update-status', array(
            'label' => 'WordPress-Native Candidate Update Status',
            'description' => 'Read exact Staging Site Profile and administrator opt-in. No host credentials, writes or grants.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'status' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }
    /** Pure site boundary; never use hostname as an environment classifier. */
    public static function site_policy( array $site ) {
        $errors = array();
        if ( 'staging' !== (string) ( $site['configured_environment'] ?? '' ) ||
            'staging' !== (string) ( $site['environment'] ?? '' ) )
            $errors[] = 'exact_staging_profile_required';
        if ( empty( $site['configured'] ) || empty( $site['authority_ready'] ) ||
            empty( $site['origin_match'] ) || empty( $site['environment_match'] ) ||
            empty( $site['profile_environment_authoritative'] ) ||
            empty( $site['site_uuid'] ) || empty( $site['profile_digest'] ) ||
            empty( $site['canonical_origin'] ) ||
            ! hash_equals( (string) ( $site['current_origin'] ?? '' ),
                (string) ( $site['canonical_origin'] ?? '' ) ) ||
            ! empty( $site['mutation_pending_audit'] ) || ! empty( $site['reenrollment_required'] ) ||
            ! empty( $site['foreign_profile_detected'] ) )
            $errors[] = 'exact_site_profile_identity_required';
        $wp = (string) ( $site['wordpress_environment'] ?? 'unknown' );
        $explicit = ! empty( $site['wordpress_environment_explicit'] );
        if ( $explicit && 'staging' !== $wp ) $errors[] = 'explicit_nonstaging_host_denied';
        if ( 'staging' !== $wp && ! ( 'production' === $wp && ! $explicit &&
            ! empty( $site['implicit_nonproduction_override_confirmed'] ) ) )
            $errors[] = 'staging_attestation_required';
        return array( 'eligible' => empty( $errors ), 'blockers' => $errors,
            'host_runner_required' => false, 'production_allowed' => false );
    }
    public static function enabled() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return false;
        $site = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $site ) || empty( self::site_policy( $site )['eligible'] ) ) return false;
        if ( defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true !== MAD4B_MCP_MUTATION_ENABLED ) return false;
        if ( function_exists( 'is_multisite' ) && is_multisite() ) return false;
        $row = get_option( self::OPTION, array() );
        return is_array( $row ) && ! empty( $row['enabled'] )
            && hash_equals( (string) ( $site['site_uuid'] ?? '' ), (string) ( $row['site_uuid'] ?? '' ) )
            && hash_equals( (string) ( $site['profile_digest'] ?? '' ), (string) ( $row['profile_digest'] ?? '' ) )
            && hash_equals( (string) ( $site['canonical_origin'] ?? '' ), (string) ( $row['canonical_origin'] ?? '' ) );
    }
    public static function status( $input = array() ) {
        if ( ! empty( $input ) ) return new WP_Error( 'mad4b_native_opt_in_status_no_inputs', 'This read accepts no inputs.' );
        $site = MAD4B_SCP_Site_Profile::status();
        $policy = self::site_policy( is_array( $site ) ? $site : array() );
        return array(
            'contract' => 'mad4b.wordpress-native-update-opt-in.v1',
            'eligible_site' => ! empty( $policy['eligible'] ),
            'enabled' => self::enabled(),
            'blockers' => $policy['blockers'],
            'admin_action' => self::ADMIN_ACTION,
            'administrator_consent_required' => true,
            'candidate_certification_required' => true,
            'owner_oauth_step_up_required' => true,
            'existing_central_write_approval_required' => true,
            'host_runner_required' => false,
            'deployment_binding_required_for_this_lane' => false,
            'production_allowed' => false,
            'read_only' => true, 'mutation_performed' => false, 'authorizing' => false,
        );
    }
    public static function handle_admin() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
            ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) )
            wp_die( 'WordPress plugin administrator required.', '', array( 'response' => 403 ) );
        check_admin_referer( self::ADMIN_ACTION );
        $site = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $site ) || empty( self::site_policy( $site )['eligible'] ) ||
            ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) )
            wp_die( 'Exact enrolled Staging administrator required.', '', array( 'response' => 403 ) );
        $expected = isset( $_POST['expected_profile_digest'] ) ? (string) wp_unslash( $_POST['expected_profile_digest'] ) : '';
        if ( ! hash_equals( (string) $site['profile_digest'], $expected ) )
            wp_die( 'Site Profile changed; refresh and review.', '', array( 'response' => 409 ) );
        $mode = isset( $_POST['mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['mode'] ) ) : '';
        if ( ! in_array( $mode, array( 'enable', 'disable' ), true ) )
            wp_die( 'Invalid candidate mode.', '', array( 'response' => 400 ) );
        if ( 'enable' === $mode ) {
            $phrase = isset( $_POST['confirmation'] ) ? trim( (string) wp_unslash( $_POST['confirmation'] ) ) : '';
            if ( ! hash_equals( self::CONFIRM, $phrase ) )
                wp_die( 'Exact Staging consent phrase required.', '', array( 'response' => 403 ) );
        }
        $record = array( 'enabled' => 'enable' === $mode,
            'site_uuid' => (string) $site['site_uuid'],
            'profile_digest' => (string) $site['profile_digest'],
            'canonical_origin' => (string) $site['canonical_origin'],
            'approved_by_user_id' => get_current_user_id(), 'saved_at' => time() );
        $before = get_option( self::OPTION, array() );
        update_option( self::OPTION, $record, false );
        if ( get_option( self::OPTION, array() ) !== $record ) {
            update_option( self::OPTION, $before, false );
            wp_die( 'Opt-in persistence not verified.', '', array( 'response' => 500 ) );
        }
        $event = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::record(
            'mad4b/wordpress-native-candidate-opt-in',
            array( 'site_uuid' => (string) $site['site_uuid'],
                'profile_digest' => (string) $site['profile_digest'],
                'enabled' => 'enable' === $mode, 'production_authorized' => false ), 'success'
        ) : new WP_Error( 'mad4b_native_opt_in_audit_unavailable', 'Audit unavailable.' );
        if ( is_wp_error( $event ) ) {
            update_option( self::OPTION, $before, false );
            wp_die( 'Opt-in audit failed; previous setting restored.', '', array( 'response' => 500 ) );
        }
        wp_safe_redirect( add_query_arg( array( 'page' => 'mad4b-control-plane-site-profile',
            'mad4b_site_profile' => 'enable' === $mode ? 'native_candidate_enabled' : 'native_candidate_disabled' ),
            admin_url( 'admin.php' ) ) );
        exit;
    }
    public static function render_admin( array $site ) {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) return;
        $policy = self::site_policy( $site );
        $enabled = self::enabled();
        echo '<div class="notice notice-info inline" style="padding:12px 16px;max-width:950px">';
        echo '<p><strong>' . esc_html__( 'WordPress-Native Candidate Update (No Host Runner)', 'mad4b-site-control-plane' ) . '</strong></p>';
        echo '<p>' . esc_html( $enabled ? 'Enabled for exact Site Profile.' : 'Disabled; independent administrator consent required.' ) . '</p>';
        if ( empty( $policy['eligible'] ) ) {
            echo '<p>' . esc_html( implode( ', ', $policy['blockers'] ) ) . '</p></div>'; return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION ) . '" />';
        echo '<input type="hidden" name="expected_profile_digest" value="' . esc_attr( (string) $site['profile_digest'] ) . '" />';
        wp_nonce_field( self::ADMIN_ACTION );
        if ( $enabled ) {
            echo '<input type="hidden" name="mode" value="disable" />';
            submit_button( __( 'Disable WordPress-native candidate mode', 'mad4b-site-control-plane' ), 'secondary', 'submit', false );
        } else {
            echo '<input type="hidden" name="mode" value="enable" />';
            echo '<input class="regular-text" type="text" autocomplete="off" required name="confirmation" placeholder="' . esc_attr( self::CONFIRM ) . '" />';
            submit_button( __( 'Enable WordPress-native candidate mode', 'mad4b-site-control-plane' ), 'primary', 'submit', false );
        }
        echo '</form><p class="description">' .
            esc_html__( 'Only the host requirement is replaced. Existing central write authority, owner OAuth step-up, certified GitHub artifact, exact plan approval, backup and rollback remain mandatory.', 'mad4b-site-control-plane' ) .
            '</p></div>';
    }
}
