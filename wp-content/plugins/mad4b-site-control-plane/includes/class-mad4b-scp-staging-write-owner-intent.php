<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Exact, short-lived owner intent: NEVER a grant, ticket or execution authority. */
final class MAD4B_SCP_Staging_Write_Owner_Intent {
    const OPTION = 'mad4b_scp_staging_write_owner_intent_v1';
    const PAGE = 'mad4b-staging-write-owner-review';
    const ACTION = 'mad4b_staging_write_owner_review';
    const CONFIRM = 'AUTHORIZE EXACT STAGING WRITE CONVERGENCE';
    const TTL = 600;

    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'post' ) );
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
        $plan = MAD4B_SCP_Staging_Write_Authority_Convergence::plan();
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! is_array( $plan ) || empty( $plan['ready_to_apply'] ) || empty( $plan['plan_sha256'] ) )
            return new WP_Error( 'mad4b_owner_plan_not_ready', 'Fresh eligible Staging Write plan required.' );
        return array( 'plan'=>$plan, 'review_digest'=>self::digest( $plan ) );
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
    public static function menu() {
        add_management_page( 'MAD4B Staging Write Owner Review', 'MAD4B Write Review',
            'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
    }
    public static function page() {
        if ( ! self::owner() ) wp_die( 'Explicit enrolled Staging owner required.' );
        $review = self::review();
        echo '<div class="wrap"><h1>Staging Write — Exact Owner Review</h1>';
        if ( is_wp_error( $review ) ) {
            echo '<p role="alert">' . esc_html( $review->get_error_code() ) . '</p></div>';
            return;
        }
        $saved = get_option( self::OPTION, array() );
        echo '<p>Owner intent only: no grant, ticket, OAuth change or installation.</p>';
        echo '<p>Site UUID: <code>' . esc_html( $review['plan']['site_uuid'] ) . '</code></p>';
        echo '<p>Source: <code>' . esc_html( $review['plan']['source_commit_sha'] ) . '</code></p>';
        echo '<p>Plan SHA: <code>' . esc_html( $review['plan']['plan_sha256'] ) . '</code></p>';
        if ( is_array( $saved ) && isset( $saved['receipt_sha256'], $saved['review_digest'] )
            && hash_equals( $review['review_digest'], (string) $saved['review_digest'] ) )
            echo '<p>Receipt: <code>' . esc_html( $saved['receipt_sha256'] ) . '</code></p>';
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
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE . '&intent=recorded' ) );
        exit;
    }
}
