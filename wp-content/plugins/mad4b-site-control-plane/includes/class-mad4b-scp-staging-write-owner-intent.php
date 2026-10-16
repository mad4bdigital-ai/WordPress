<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Exact, short-lived owner intent: NEVER a grant, ticket or execution authority. */
final class MAD4B_SCP_Staging_Write_Owner_Intent {
    const OPTION = 'mad4b_scp_staging_write_owner_intent_v1';
    const PAGE = 'mad4b-staging-write-owner-review';
    const ATTEST_ABILITY = 'mad4b/staging-write-owner-attest';
    const REVIEW_ABILITY = 'mad4b/staging-write-owner-review';
    const ACTION = 'mad4b_staging_write_owner_review';
    const CONFIRM = 'AUTHORIZE EXACT STAGING WRITE CONVERGENCE';
    const TTL = 600;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 18 );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'post' ) );
    }
    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::REVIEW_ABILITY ) ) {
            wp_register_ability( self::REVIEW_ABILITY, array(
                'label' => 'Review Exact Staging Write Owner Plan',
                'description' => 'Read-only, nonauthorizing exact owner review for current source and Site Profile.',
                'category' => 'mad4b-staging-write-authority',
                'execute_callback' => array( __CLASS__, 'review' ),
                'permission_callback' => array( __CLASS__, 'can_remote_attest' ),
                'input_schema' => array( 'type'=>'object', 'properties'=>array(), 'additionalProperties'=>false ),
                'output_schema' => array( 'type'=>'object', 'additionalProperties'=>true ),
                'meta' => array( 'public'=>false, 'show_in_rest'=>false,
                    'mcp'=>array( 'public'=>false, 'type'=>'tool', 'surface'=>'read',
                        'non_authorizing'=>true, 'production_mutation_allowed'=>false ),
                    'annotations'=>array( 'readonly'=>true, 'destructive'=>false, 'idempotent'=>true ) ),
            ) );
        }
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ATTEST_ABILITY ) ) return;
        wp_register_ability( self::ATTEST_ABILITY, array(
            'label' => 'Attest Exact Staging Write Owner Decision',
            'description' => 'Record only a short-lived, independently reviewed plan-bound owner intent, not an execution ticket, grant or deployment.',
            'category' => 'mad4b-staging-write-authority',
            'execute_callback' => array( __CLASS__, 'attest' ),
            'permission_callback' => array( __CLASS__, 'can_remote_attest' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'review_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM ) ),
                ),
                'required' => array( 'review_digest', 'confirmation' ),
                'additionalProperties' => false,
            ),
            'output_schema' => array( 'type'=>'object', 'additionalProperties'=>true ),
            'meta' => array( 'public'=>false, 'show_in_rest'=>false,
                'mcp' => array( 'public'=>false, 'type'=>'tool', 'surface'=>'enrollment',
                    'chatgpt_direct_step_up'=>true, 'exact_chatgpt_client_required'=>true,
                    'generic_remote_admin'=>false, 'production_mutation_allowed'=>false,
                    'owner_intent_only'=>true, 'breakglass_allowed'=>false ),
                'annotations' => array( 'readonly'=>false, 'destructive'=>false, 'idempotent'=>false ),
            ),
        ) );
    }
    public static function can_remote_attest( $input = null ) {
        if ( ! self::owner() ) return new WP_Error( 'mad4b_staging_owner_not_explicitly_enrolled', 'Exact enrolled Staging owner required.' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) )
            return new WP_Error( 'mad4b_staging_owner_oauth_step_up_required', 'Verified owner OAuth authority step-up required.' );
        if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
            return new WP_Error( 'mad4b_staging_owner_chatgpt_client_required', 'Exact ChatGPT OAuth client required.' );
        if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) ) return new WP_Error( 'mad4b_staging_owner_identity_missing', 'Verified OAuth subject required.' );
        $id = MAD4B_SCP_Identity_Context::current();
        if ( is_wp_error( $id ) || ! is_array( $id ) || empty( $id['authenticated'] )
            || 'oauth' !== (string) ( $id['subject_type'] ?? '' )
            || 'oauth2_bearer' !== (string) ( $id['auth_method'] ?? '' ) )
            return new WP_Error( 'mad4b_staging_owner_oauth_subject_required', 'Normal OAuth bearer identity required.' );
        return true;
    }
    public static function owner() {
        if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::configured()
            || 'staging' !== MAD4B_SCP_Site_Profile::current_environment()
            || ! MAD4B_SCP_Site_Profile::origin_enrolled()
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return false;
        $ids = MAD4B_SCP_Site_Profile::oauth_user_ids();
        // Never accept legacy_zero_touch fallback as an owner attestation.
        if ( ! is_array( $ids ) || 1 !== count( array_unique( array_map( 'intval', $ids ) ) )
            || ! in_array( (int) get_current_user_id(), array_map( 'intval', $ids ), true ) ) return false;
        if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass() ) return false;
        if ( class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' )
            && MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled() ) return false;
        return true;
    }
    public static function digest( array $plan ) {
        $bound = array( 'contract'=>'mad4b.staging-write-owner-intent.v1', 'operation'=>'mad4b/staging-write-authority-convergence-apply' );
        foreach ( array( 'plan_sha256', 'site_uuid', 'site_profile_revision', 'site_profile_digest',
            'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $key )
            $bound[ $key ] = $plan[ $key ] ?? null;
        return hash( 'sha256', wp_json_encode( $bound, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }
    /** Site-secret keyed receipt, not a forgeable bare options digest. */
    private static function signature( array $record ) {
        if ( ! function_exists( 'wp_salt' ) ) return '';
        $secret = (string) wp_salt( 'auth' );
        if ( '' === $secret ) return '';
        return hash_hmac( 'sha256',
            wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $secret );
    }
    public static function review() {
        if ( ! self::owner() ) return new WP_Error( 'mad4b_owner_explicit_enrollment_required', 'Explicit Staging owner required.' );
        $local = function_exists( 'is_admin' ) && is_admin()
            && ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false )
              || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() );
        $plan = $local
            ? MAD4B_SCP_Staging_Write_Authority_Convergence::plan_for_local_owner()
            : MAD4B_SCP_Staging_Write_Authority_Convergence::plan();
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! is_array( $plan ) || empty( $plan['ready_to_apply'] ) || empty( $plan['plan_sha256'] ) ) {
            $blockers = is_array( $plan ) && isset( $plan['hard_blockers'] ) && is_array( $plan['hard_blockers'] )
                ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $plan['hard_blockers'] ) ) ) ) : array();
            $grant_blockers = is_array( $plan ) && isset( $plan['exact_grant_plan_blockers'] ) && is_array( $plan['exact_grant_plan_blockers'] )
                ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $plan['exact_grant_plan_blockers'] ) ) ) ) : array();
            return new WP_Error( 'mad4b_owner_plan_not_ready', 'Fresh eligible Staging Write plan required.',
                array( 'blockers' => array_slice( $blockers, 0, 20 ),
                    'exact_grant_blockers' => array_slice( $grant_blockers, 0, 20 ),
                    'read_only' => true, 'mutation_performed' => false ) );
        }
        // Return only the exact identity needed for owner review, not the
        // broad grant inventory or private provider information.
        $identity = array();
        foreach ( array( 'plan_sha256', 'site_uuid', 'site_profile_revision',
            'site_profile_digest', 'source_commit_sha', 'build_fingerprint',
            'package_manifest_digest', 'artifact_identity' ) as $key )
            $identity[ $key ] = $plan[ $key ] ?? null;
        return array( 'contract'=>'mad4b.staging-write-owner-review.v1',
            'review_digest'=>self::digest( $plan ), 'plan'=>$identity,
            'read_only'=>true, 'authorizing'=>false, 'mutation_performed'=>false,
            'decision_recorded'=>false,
        );
    }
    public static function attest( $input ) {
        $review = self::review();
        if ( is_wp_error( $review ) ) return $review;
        if ( ! is_array( $input ) || ! isset( $input['review_digest'], $input['confirmation'] )
            || ! is_string( $input['review_digest'] )
            || ! hash_equals( $review['review_digest'], $input['review_digest'] )
            || self::CONFIRM !== $input['confirmation'] )
            return new WP_Error( 'mad4b_owner_review_mismatch', 'Exact plan and confirmation required.' );
        if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) )
            return new WP_Error( 'mad4b_owner_audit_unavailable', 'Durable audit required.' );
        try { $nonce = bin2hex( random_bytes( 16 ) ); }
        catch ( Throwable $exception ) { return new WP_Error( 'mad4b_owner_entropy_unavailable', 'Secure nonce unavailable.' ); }
        $record = array( 'owner'=>(int) get_current_user_id(), 'review_digest'=>$review['review_digest'],
            'issued'=>time(), 'expires'=>time()+self::TTL, 'nonce'=>$nonce );
        $record['receipt_sha256'] = self::signature( $record );
        if ( '' === $record['receipt_sha256'] ) return new WP_Error( 'mad4b_owner_signing_unavailable', 'Site-secret signature unavailable.' );
        $audit = MAD4B_SCP_Audit::record( 'mad4b/staging-write-owner-reviewed', array(
            'owner'=>$record['owner'], 'site_uuid'=>$review['plan']['site_uuid'],
            'review_digest'=>$review['review_digest'], 'receipt_sha256'=>$record['receipt_sha256'],
            'expires'=>$record['expires'], 'authority_granted'=>false, 'production_mutation'=>false ), 'ok' );
        if ( is_wp_error( $audit ) ) return $audit;
        if ( ! update_option( self::OPTION, $record, false ) )
            return new WP_Error( 'mad4b_owner_review_persist_failed', 'Owner intent not persisted.' );
        $saved = get_option( self::OPTION, array() );
        if ( ! is_array( $saved ) || ! hash_equals( $record['receipt_sha256'], (string) ( $saved['receipt_sha256'] ?? '' ) ) )
            return new WP_Error( 'mad4b_owner_review_readback_failed', 'Owner intent readback failed.' );
        return array( 'state'=>'OWNER_INTENT_NOT_AUTHORITY', 'receipt_sha256'=>$record['receipt_sha256'],
            'expires_at'=>$record['expires'], 'mutation_performed'=>false );
    }
    public static function claim( array $plan, $input ) {
        if ( ! self::owner() ) return new WP_Error( 'mad4b_owner_claim_identity_denied', 'Explicit enrolled Staging owner required.' );
        $hash = is_array( $input ) ? (string) ( $input['owner_intent_sha256'] ?? '' ) : '';
        $saved = get_option( self::OPTION, array() );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! is_array( $saved )
            || ! isset( $saved['receipt_sha256'], $saved['owner'], $saved['issued'],
                $saved['expires'], $saved['nonce'], $saved['review_digest'] ) )
            return new WP_Error( 'mad4b_owner_receipt_missing', 'Exact owner intent required.' );
        $payload = $saved;
        unset( $payload['receipt_sha256'] );
        $computed = self::signature( $payload );
        if ( ! hash_equals( $computed, (string) $saved['receipt_sha256'] )
            || ! hash_equals( $hash, (string) $saved['receipt_sha256'] )
            || ! hash_equals( self::digest( $plan ), (string) $saved['review_digest'] )
            || (int) $saved['owner'] !== (int) get_current_user_id()
            || time() >= (int) $saved['expires']
            || (int) $saved['issued'] > time()
            || (int) $saved['expires'] - (int) $saved['issued'] > self::TTL )
            return new WP_Error( 'mad4b_owner_receipt_stale', 'Expired, altered or different plan/owner.' );
        if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) )
            return new WP_Error( 'mad4b_owner_claim_audit_unavailable', 'Durable claim audit unavailable.' );
        // wp_options.option_name is unique. Failed/unknown attempts consume this receipt.
        $claim_key = 'mad4b_scp_owner_claim_' . substr( $hash, 0, 40 );
        if ( ! add_option( $claim_key, array( 'receipt'=>$hash, 'claimed'=>time() ), '', false ) )
            return new WP_Error( 'mad4b_owner_receipt_replayed', 'This owner receipt was already claimed.' );
        $audit = MAD4B_SCP_Audit::record( 'mad4b/staging-write-owner-claimed', array(
            'owner'=>$saved['owner'], 'receipt_sha256'=>$hash, 'production_mutation'=>false ), 'ok' );
        if ( is_wp_error( $audit ) ) return new WP_Error( 'mad4b_owner_claim_audit_failed', 'Claim consumed; reconcile before retry.' );
        return array( 'owner_user'=>$saved['owner'], 'receipt_sha256'=>$hash, 'claimed_once'=>true );
    }
    /**
     * Read-only owner-intent UI receipt. The URL is NEVER evidence of success.
     * The signed, same-owner, same-plan, unexpired option is authoritative for
     * rendering a recorded/claimed state. This does not create write access.
     */
    public static function page_intent_status( $review = null ) {
        $saved = get_option( self::OPTION, array() );
        $absent = array( 'state' => 'NO_VERIFIED_OWNER_INTENT', 'receipt_sha256' => '',
            'expires_at' => 0, 'read_only' => true, 'write_authorized' => false );
        if ( ! is_array( $saved ) || ! isset( $saved['receipt_sha256'], $saved['owner'],
            $saved['review_digest'], $saved['issued'], $saved['expires'], $saved['nonce'] ) )
            return $absent;
        $receipt = $saved['receipt_sha256'];
        if ( ! is_string( $receipt ) || ! preg_match( '/^[a-f0-9]{64}$/D', $receipt ) )
            return $absent;
        $signed = $saved;
        unset( $signed['receipt_sha256'] );
        $actual = self::signature( $signed );
        if ( '' === $actual || ! hash_equals( $actual, $receipt )
            || (int) $saved['owner'] !== (int) get_current_user_id()
            || ! self::owner() ) return $absent;
        $valid_time = (int) $saved['issued'] <= time()
            && (int) $saved['expires'] > time()
            && (int) $saved['expires'] > (int) $saved['issued']
            && (int) $saved['expires'] - (int) $saved['issued'] <= self::TTL;
        $same_review = is_array( $review ) && isset( $review['review_digest'] )
            && is_string( $review['review_digest'] )
            && hash_equals( (string) $review['review_digest'], (string) $saved['review_digest'] );
        $claim_key = 'mad4b_scp_owner_claim_' . substr( $receipt, 0, 40 );
        $claim = get_option( $claim_key, array() );
        $claimed = is_array( $claim ) && isset( $claim['receipt'] )
            && hash_equals( $receipt, (string) $claim['receipt'] );
        return array(
            'state' => ! $same_review ? 'OWNER_INTENT_PLAN_CHANGED'
                : ( ! $valid_time ? 'OWNER_INTENT_EXPIRED'
                : ( $claimed ? 'OWNER_INTENT_CLAIMED' : 'OWNER_INTENT_CURRENT' ) ),
            'receipt_sha256' => $receipt,
            'expires_at' => (int) $saved['expires'],
            'read_only' => true, 'write_authorized' => false,
        );
    }

    public static function menu() {
        add_management_page( 'MAD4B Staging Write Owner Review', 'MAD4B Write Review',
            'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
    }
    public static function page() {
        if ( ! self::owner() ) wp_die( 'Explicit enrolled Staging owner required.' );
        if ( ! headers_sent() && function_exists( 'nocache_headers' ) ) nocache_headers();
        $review = self::review();
        $view = self::page_intent_status( is_array( $review ) ? $review : null );
        echo '<div class="wrap"><h1>Staging Write — Exact Owner Review</h1>';
        if ( is_wp_error( $review ) ) {
            if ( 'NO_VERIFIED_OWNER_INTENT' !== $view['state'] )
                echo '<div class="notice notice-warning"><p>Previous owner confirmation exists, but the current exact plan is not eligible. State: <code>'
                    . esc_html( $view['state'] ) . '</code>. Reconcile the plan before requesting a new confirmation.</p></div>';
            echo '<p role="alert">' . esc_html( $review->get_error_code() ) . '</p>';
            $details = $review->get_error_data();
            if ( is_array( $details ) ) {
                foreach ( array( 'blockers', 'exact_grant_blockers' ) as $key ) {
                    $items = isset( $details[ $key ] ) && is_array( $details[ $key ] ) ? $details[ $key ] : array();
                    if ( ! $items ) continue;
                    echo '<h2>' . esc_html( 'exact_grant_blockers' === $key ? 'Exact grant plan blockers' : 'Convergence prerequisites' ) . '</h2><ul>';
                    foreach ( array_slice( $items, 0, 20 ) as $item )
                        echo '<li><code>' . esc_html( (string) $item ) . '</code></li>';
                    echo '</ul>';
                }
            }
            echo '</div>';
            return;
        }
        echo '<p>Owner intent only: no grant, ticket, OAuth change or installation.</p>';
        if ( 'OWNER_INTENT_CURRENT' === $view['state'] )
            echo '<div class="notice notice-success" role="status"><p><strong>Owner confirmation recorded and verified.</strong> This records intent only, not an applied write permission. Receipt expires at UTC timestamp <code>'
                . esc_html( gmdate( 'Y-m-d H:i:s', (int) $view['expires_at'] ) . ' UTC' ) . '</code>.</p></div>';
        elseif ( 'OWNER_INTENT_CLAIMED' === $view['state'] )
            echo '<div class="notice notice-info" role="status"><p><strong>Owner confirmation consumed.</strong> Check a fresh Governed Write status and candidate binding. Do not submit the same approval again.</p></div>';
        elseif ( 'OWNER_INTENT_EXPIRED' === $view['state'] || 'OWNER_INTENT_PLAN_CHANGED' === $view['state'] )
            echo '<div class="notice notice-warning" role="status"><p>Previous confirmation cannot be reused: <code>'
                . esc_html( $view['state'] ) . '</code>. An exact current plan and a new confirmation are required.</p></div>';
        if ( ! in_array( $view['state'], array( 'OWNER_INTENT_CURRENT', 'OWNER_INTENT_CLAIMED' ), true ) )
            echo '<p><strong>Manual WordPress approval:</strong> confirm the exact site, revision, source, plan SHA and type '
                . '<code>' . esc_html( self::CONFIRM ) . '</code>. This saves one owner intent for 600 seconds; it does not apply grants.</p>';
        echo '<p><strong>Remote OAuth alternative:</strong> discover '
            . '<code>' . esc_html( self::REVIEW_ABILITY ) . '</code>, obtain the current review digest, then use '
            . '<code>' . esc_html( MAD4B_SCP_Staging_Write_Authority_Convergence::HANDSHAKE_ABILITY ) . '</code> '
            . 'and a verified owner OAuth Step-Up to execute '
            . '<code>' . esc_html( MAD4B_SCP_Staging_Write_Authority_Convergence::APPLY_ABILITY ) . '</code> '
            . 'with exact current plan fields and owner_review_digest. No direct grant or Developer/Breakglass authority.</p>';
        echo '<p>Site UUID: <code>' . esc_html( $review['plan']['site_uuid'] ) . '</code></p>';
        echo '<p>Source: <code>' . esc_html( $review['plan']['source_commit_sha'] ) . '</code></p>';
        echo '<p>Plan SHA: <code>' . esc_html( $review['plan']['plan_sha256'] ) . '</code></p>';
        if ( in_array( $view['state'], array( 'OWNER_INTENT_CURRENT', 'OWNER_INTENT_CLAIMED' ), true ) )
            echo '<p>Verified receipt: <code>' . esc_html( $view['receipt_sha256'] ) . '</code></p>';
        if ( 'OWNER_INTENT_CURRENT' === $view['state'] )
            echo '<p><strong>Next:</strong> use a delegated OAuth session for the SAME enrolled owner, get a fresh exact Staging Write handshake, then apply with <code>owner_intent_sha256</code> equal to the receipt above and all expected plan fields. The receipt is not an execution ticket. Finish with a NEW-REQUEST readback of grant counts and candidate binding, then recheck the runtime recovery checkpoint and Context source selection.</p>';
        // Post/Redirect/Get must display the persisted terminal state, not
        // return to the same blank confirmation form after a successful POST.
        if ( in_array( $view['state'], array( 'OWNER_INTENT_CURRENT', 'OWNER_INTENT_CLAIMED' ), true ) ) {
            echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mad4b-operational-remediation' ) ) . '">View current Control Plane runtime status</a></p></div>';
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="review_digest" value="' . esc_attr( $review['review_digest'] ) . '">';
        wp_nonce_field( self::ACTION . ':' . $review['review_digest'] );
        echo '<p>Type <code>' . esc_html( self::CONFIRM ) . '</code></p>';
        echo '<input type="text" name="confirmation" style="width:36em" required>';
        submit_button( 'Attest exact Staging owner decision' );
        echo '</form></div>';
    }
    public static function post() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::owner() )
            wp_die( 'Owner POST required.' );
        $review = isset( $_POST['review_digest'] ) && is_string( $_POST['review_digest'] )
            ? sanitize_text_field( wp_unslash( $_POST['review_digest'] ) ) : '';
        check_admin_referer( self::ACTION . ':' . $review );
        $confirmation = isset( $_POST['confirmation'] ) && is_string( $_POST['confirmation'] )
            ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
        $result = self::attest( array( 'review_digest'=>$review, 'confirmation'=>$confirmation ) );
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_code() ) );
        // Read the just-persisted signed option. The redirect query is never
        // an authority receipt and must not be shown as success if stale.
        $fresh = self::review();
        $verified = self::page_intent_status( is_array( $fresh ) ? $fresh : null );
        if ( ! is_array( $fresh ) || 'OWNER_INTENT_CURRENT' !== $verified['state']
            || ! hash_equals( (string) $result['receipt_sha256'], (string) $verified['receipt_sha256'] ) )
            wp_die( 'Owner intent was submitted but a fresh signed readback could not be verified. Do not retry until the current status is inspected.' );
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE . '&intent=recorded' ), 303 );
        exit;
    }
}
