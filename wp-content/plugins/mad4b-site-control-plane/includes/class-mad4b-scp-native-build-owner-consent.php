<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Human confirmation evidence for one exact Staging WordPress-native source
 * build request. NEVER an OAuth token, execution grant, approval ticket, or
 * permission to run the build. Owner must still complete the governed Admin
 * execution lane with its existing one-time ticket and OAuth step-up.
 */
final class MAD4B_SCP_Native_Build_Owner_Consent {
    const CONTRACT = 'mad4b.native-build-owner-consent.v1';
    const PLAN_ABILITY = 'mad4b/wp-native-owner-confirmation-plan';
    const STATUS_ABILITY = 'mad4b/wp-native-owner-confirmation-status';
    const OPTION = 'mad4b_native_build_owner_consent_v1';
    const FORM_ACTION = 'mad4b_native_build_owner_consent';
    const MAX_TTL = 600;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_post_' . self::FORM_ACTION, array( __CLASS__, 'handle_form' ) );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( array(
            self::PLAN_ABILITY => array( __CLASS__, 'plan' ),
            self::STATUS_ABILITY => array( __CLASS__, 'status' ),
        ) as $name => $callback ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            wp_register_ability( $name, array(
                'label' => 'Read Staging Native Build Owner Confirmation',
                'description' => 'Show a scoped review string and owner-consent status; this never approves, delegates execution, or grants scope.',
                'category' => 'mad4b-read',
                'execute_callback' => $callback,
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => array( 'type' => 'object',
                    'properties' => array( 'expected_head' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ) ),
                    'required' => array( 'expected_head' ), 'additionalProperties' => false ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'surface' => 'read', 'type' => 'tool',
                        'non_authorizing' => true ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
            ) );
        }
    }

    private static function staging_ready() {
        return class_exists( 'MAD4B_SCP_Site_Profile' ) &&
            MAD4B_SCP_Site_Profile::configured() &&
            'staging' === MAD4B_SCP_Site_Profile::current_environment() &&
            MAD4B_SCP_Site_Profile::origin_enrolled() &&
            MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
    }

    private static function scope() {
        return array(
            'site_uuid' => (string) MAD4B_SCP_Site_Profile::site_uuid(),
            'site_profile_revision' => (int) MAD4B_SCP_Site_Profile::revision(),
            'site_profile_digest' => (string) MAD4B_SCP_Site_Profile::profile_digest(),
            'environment' => 'staging',
        );
    }

    private static function payload( array $plan ) {
        return array_merge( array(
            'contract' => self::CONTRACT,
            'ability_name' => 'mad4b/wp-native-build-start',
            'expected_head' => (string) $plan['source_commit_sha'],
            'expected_plan_sha256' => (string) $plan['plan_sha256'],
            'confirmation' => MAD4B_SCP_WP_Native_Build::CONFIRM,
            'build_only' => true,
            'release_certified' => false,
            'production_allowed' => false,
        ), self::scope() );
    }

    private static function digest( array $payload ) {
        return hash( 'sha256', wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    public static function plan( $input = array() ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || true !== MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_owner_consent_read_denied', 'Governed read required.' );
        if ( ! is_array( $input ) || array_keys( $input ) !== array( 'expected_head' ) )
            return new WP_Error( 'mad4b_owner_consent_input_invalid', 'One exact expected_head is required.' );
        $head = $input['expected_head'];
        if ( ! is_string( $head ) || ! preg_match( '/^[a-f0-9]{40}$/D', $head ) )
            return new WP_Error( 'mad4b_owner_consent_head_invalid', 'An immutable lowercase source SHA is required.' );
        if ( ! self::staging_ready() )
            return new WP_Error( 'mad4b_owner_consent_staging_required', 'Exact enrolled Staging is required.' );
        if ( ! class_exists( 'MAD4B_SCP_WP_Native_Build' ) )
            return new WP_Error( 'mad4b_owner_consent_builder_missing', 'Native Staging builder is unavailable.' );
        $plan = MAD4B_SCP_WP_Native_Build::plan( array( 'expected_head' => $head ) );
        if ( is_wp_error( $plan ) ) return $plan;
        $payload = self::payload( $plan );
        $digest = self::digest( $payload );
        return array(
            'contract' => self::CONTRACT, 'state' => ! empty( $plan['eligible'] ) ? 'OWNER_REVIEW_AVAILABLE' : 'BUILD_PREFLIGHT_BLOCKED',
            'build_eligible' => ! empty( $plan['eligible'] ),
            'build_blockers' => $plan['blockers'] ?? array(),
            'review_payload' => $payload, 'review_sha256' => $digest,
            'confirmation_format' => MAD4B_SCP_WP_Native_Build::CONFIRM . ' | ' .
                (string) $plan['source_commit_sha'] . ' | ' . (string) $plan['plan_sha256'] .
                ' | ' . (string) $payload['site_uuid'] . ' | ' . $digest,
            'owner_consent_entrypoint' => 'wp-admin/tools.php?page=mad4b-native-build-owner-consent',
            'owner_authenticated_in_current_mcp_read' => false,
            'owner_approval_ticket_issued' => false,
            'oauth_step_up_replaced' => false, 'execution_claim_replaced' => false,
            'can_auto_approve_from_chat_text' => false,
            'requires_governed_execution_ticket' => true,
            'requires_exact_owner_oauth_step_up_for_build' => true,
            'read_only' => true, 'mutation_performed' => false,
        );
    }

    public static function status( $input = array() ) {
        $plan = self::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        $saved = get_option( self::OPTION, array() );
        $matching = is_array( $saved ) && isset( $saved['review_sha256'], $saved['expires_at'] ) &&
            is_string( $saved['review_sha256'] ) &&
            hash_equals( $plan['review_sha256'], $saved['review_sha256'] ) &&
            time() <= (int) $saved['expires_at'] &&
            ! empty( $saved['owner_id'] ) && ! empty( $saved['consent_sha256'] );
        return array(
            'contract' => self::CONTRACT . '.status.v1',
            'state' => $matching ? 'OWNER_INTENT_ATTESTED_EXECUTION_NOT_AUTHORIZED' : 'OWNER_INTENT_NOT_CURRENT',
            'expected_head' => $plan['review_payload']['expected_head'],
            'review_sha256' => $plan['review_sha256'],
            'owner_intent_valid' => (bool) $matching,
            'consent_sha256' => $matching ? (string) $saved['consent_sha256'] : '',
            'expires_at' => $matching ? (int) $saved['expires_at'] : null,
            'ticket_issued' => false, 'execution_authorized' => false,
            'installation_authorized' => false, 'read_only' => true,
        );
    }

    public static function owner_can_attest() {
        return self::staging_ready() && current_user_can( 'manage_options' ) &&
            method_exists( 'MAD4B_SCP_Site_Profile', 'user_is_enrolled' ) &&
            MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() );
    }

    public static function attest( $input ) {
        if ( ! self::owner_can_attest() )
            return new WP_Error( 'mad4b_owner_consent_identity_denied', 'Verified enrolled Staging owner required.' );
        if ( ! is_array( $input ) || count( $input ) !== 3 ||
            ! isset( $input['expected_head'], $input['review_sha256'], $input['confirmation'] ) )
            return new WP_Error( 'mad4b_owner_consent_arguments_invalid', 'Exact review, source and confirmation required.' );
        $plan = self::plan( array( 'expected_head' => $input['expected_head'] ) );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( empty( $plan['build_eligible'] ) )
            return new WP_Error( 'mad4b_owner_consent_build_blocked', 'Source build is no longer eligible.' );
        if ( ! is_string( $input['review_sha256'] ) ||
            ! hash_equals( $plan['review_sha256'], $input['review_sha256'] ) ||
            ! is_string( $input['confirmation'] ) ||
            ! hash_equals( $plan['confirmation_format'], $input['confirmation'] ) )
            return new WP_Error( 'mad4b_owner_consent_review_mismatch', 'Confirmation must match the current exact review payload.' );
        $now = time();
        $owner = (int) get_current_user_id();
        $receipt = array(
            'contract' => self::CONTRACT . '.intent.v1',
            'review_sha256' => $plan['review_sha256'], 'owner_id' => $owner,
            'issued_at' => $now, 'expires_at' => $now + self::MAX_TTL,
            'nonce' => bin2hex( random_bytes( 16 ) ),
        );
        $receipt['consent_sha256'] = self::digest( $receipt );
        if ( ! update_option( self::OPTION, $receipt, false ) )
            return new WP_Error( 'mad4b_owner_consent_persist_failed', 'The scoped owner intent could not be persisted.' );
        $readback = get_option( self::OPTION, array() );
        if ( ! is_array( $readback ) ||
            ! hash_equals( (string) $receipt['consent_sha256'], (string) ( $readback['consent_sha256'] ?? '' ) ) )
            return new WP_Error( 'mad4b_owner_consent_readback_failed', 'Owner intent persistence readback failed.' );
        if ( class_exists( 'MAD4B_SCP_Audit' ) )
            MAD4B_SCP_Audit::record( 'mad4b/native-build-owner-intent', array(
                'owner_id' => $owner, 'review_sha256' => $receipt['review_sha256'],
                'consent_sha256' => $receipt['consent_sha256'],
                'expected_head' => $plan['review_payload']['expected_head'],
                'expires_at' => $receipt['expires_at'],
                'oauth_scope_granted' => false, 'approval_ticket_issued' => false,
            ), 'ok' );
        return array(
            'contract' => self::CONTRACT . '.intent.v1',
            'state' => 'OWNER_INTENT_ATTESTED_EXECUTION_NOT_AUTHORIZED',
            'consent_sha256' => $receipt['consent_sha256'], 'expires_at' => $receipt['expires_at'],
            'approval_ticket_issued' => false, 'execution_authorized' => false,
            'requires_exact_owner_oauth_step_up_for_build' => true,
        );
    }

    public static function admin_menu() {
        if ( ! function_exists( 'add_management_page' ) ) return;
        add_management_page( 'MAD4B Native Build Owner Consent', 'MAD4B Build Consent',
            'manage_options', 'mad4b-native-build-owner-consent', array( __CLASS__, 'render_page' ) );
    }

    public static function render_page() {
        if ( ! self::owner_can_attest() ) {
            if ( function_exists( 'wp_die' ) ) wp_die( 'Enrolled Staging owner required.' );
            return;
        }
        $head = isset( $_GET['expected_head'] ) && is_string( $_GET['expected_head'] )
            ? sanitize_text_field( wp_unslash( $_GET['expected_head'] ) ) : '';
        $review = self::plan( array( 'expected_head' => $head ) );
        echo '<div class="wrap"><h1>MAD4B Staging Native Build Owner Consent</h1>';
        if ( is_wp_error( $review ) ) {
            echo '<p>Supply the exact immutable development SHA in the expected_head query parameter.</p></div>';
            return;
        }
        echo '<p>This records owner intent only. It does not approve a mutation ticket or run WP-Cron.</p>';
        echo '<pre>' . esc_html( $review['confirmation_format'] ) . '</pre>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( self::FORM_ACTION . ':' . $review['review_sha256'] );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::FORM_ACTION ) . '">';
        echo '<input type="hidden" name="expected_head" value="' . esc_attr( $head ) . '">';
        echo '<input type="hidden" name="review_sha256" value="' . esc_attr( $review['review_sha256'] ) . '">';
        echo '<p>Copy the complete confirmation line:</p><textarea rows="3" cols="105" name="confirmation" required></textarea>';
        submit_button( 'Record exact Staging owner intent' );
        echo '</form></div>';
    }

    public static function handle_form() {
        if ( ! self::owner_can_attest() ) {
            wp_die( 'Enrolled Staging owner required.', '', array( 'response' => 403 ) );
        }
        $digest = isset( $_POST['review_sha256'] ) && is_string( $_POST['review_sha256'] )
            ? sanitize_text_field( wp_unslash( $_POST['review_sha256'] ) ) : '';
        check_admin_referer( self::FORM_ACTION . ':' . $digest );
        $input = array();
        foreach ( array( 'expected_head', 'review_sha256', 'confirmation' ) as $key ) {
            $input[$key] = isset( $_POST[$key] ) && is_string( $_POST[$key] )
                ? sanitize_text_field( wp_unslash( $_POST[$key] ) ) : '';
        }
        $result = self::attest( $input );
        if ( is_wp_error( $result ) ) wp_die( 'Owner intent rejected: ' . esc_html( $result->get_error_code() ), '', array( 'response' => 409 ) );
        wp_safe_redirect( add_query_arg( array( 'page' => 'mad4b-native-build-owner-consent',
            'expected_head' => $input['expected_head'], 'intent' => 'recorded' ), admin_url( 'tools.php' ) ) );
        exit;
    }
}
