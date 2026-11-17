<?php
/**
 * MAD4B Production identity quick start (read-only enrollment).
 *
 * Presentation only: submits the existing Site Profile admin-post action using
 * the logged-in Administrator's WordPress nonce. No impersonation, new API,
 * OAuth, production write, remote job, or direct database update.
 */
defined( 'ABSPATH' ) || exit;

final class MAD4B_Production_Identity_Quickstart_V1 {
    const CONTRACT = 'mad4b.production-identity-quickstart.v1';
    const ORIGINS = array(
        'https://egypttourgates.com',
        'https://tourism.almallahgroup-mg.com',
    );

    private static function owner_page() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return false;
        if ( 'production' !== wp_get_environment_type() ) return false;
        if ( ! isset( $_GET['page'] ) || ! is_string( $_GET['page'] ) ||
            'mad4b-control-plane-site-profile' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) return false;
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile', false ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile_Admin', false ) ||
            ! class_exists( 'MAD4B_SCP_Audit', false ) ) return false;

        $origin = untrailingslashit( home_url( '/' ) );
        return in_array( $origin, self::ORIGINS, true )
            && $origin === untrailingslashit( site_url( '/' ) );
    }

    public static function eligible() {
        if ( ! self::owner_page() ) return false;
        // Only genuinely uninitialized profiles are safe. A cloned, invalid,
        // pending-audit or existing record always goes through the full review.
        $existing = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
        if ( null !== $existing && false !== $existing ) return false;

        $status = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $status ) || ! empty( $status['configured'] ) ||
             ! empty( $status['site_uuid'] ) ) return false;
        $audit = MAD4B_SCP_Audit::storage_status();
        return is_array( $audit ) && ! empty( $audit['ready'] );
    }

    public static function completion_uuid() {
        if ( ! self::owner_page() ) return '';
        $record = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
        if ( ! is_array( $record ) || ! empty( $record['features']['write'] ) ) return '';
        $status = MAD4B_SCP_Site_Profile::status();
        $uuid = (string) ( $status['site_uuid'] ?? '' );
        if ( empty( $status['configured'] ) || empty( $status['origin_match'] )
             || empty( $status['environment_match'] )
             || empty( $status['deployment_binding_match'] )
             || ! empty( $status['mutation_pending_audit'] )
             || ! empty( $status['reenrollment_required'] )
             || ! wp_is_uuid( $uuid )
             || $uuid !== (string) ( $record['site_uuid'] ?? '' )
             || 'production' !== (string) ( $record['environment'] ?? '' )
             || untrailingslashit( home_url( '/' ) ) !== (string) ( $record['canonical_origin'] ?? '' ) ) return '';
        return $uuid;
    }

    public static function render() {
        if ( ! self::eligible() ) {
            $uuid = self::completion_uuid();
            if ( '' === $uuid ) return;
            echo '<div class="notice notice-success" style="max-width:940px;padding:12px 16px">';
            echo '<h2>' . esc_html__( 'Production identity saved', 'mad4b-site-control-plane' ) . '</h2>';
            echo '<p>' . esc_html__( 'This read-only Production site identity is present. Complete only the exact company manager approval in Central; no duplicate Site Profile save is needed.', 'mad4b-site-control-plane' ) . '</p>';
            echo '<p>Site UUID: <code>' . esc_html( $uuid ) . '</code></p>';
            echo '<p><a href="https://tours.dev.mad4b.com/wp-admin/admin.php?page=mad4b-company-portfolio-managers" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Central company owner approvals', 'mad4b-site-control-plane' ) . '</a></p>';
            echo '</div>';
            return;
        }
        echo '<div class="notice notice-info" style="max-width:940px;padding:12px 16px">';
        echo '<h2>' . esc_html__( 'Connect this Production site in one step', 'mad4b-site-control-plane' ) . '</h2>';
        echo '<p>' . esc_html__( 'Creates a unique identity for this exact WordPress origin using the existing Administrator session, nonce, and append-only audit. All remote writes and payments remain unchanged and disabled by this action.', 'mad4b-site-control-plane' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="mad4b_site_profile_save">';
        echo '<input type="hidden" name="environment" value="production">';
        echo '<input type="hidden" name="environment_sync_mode" value="profile_only">';
        echo '<input type="hidden" name="expected_revision" value="0">';
        echo '<input type="hidden" name="expected_profile_digest" value="">';
        wp_nonce_field( MAD4B_SCP_Site_Profile_Admin::ACTION_SAVE );
        submit_button( __( 'Create Production identity (no write access)', 'mad4b-site-control-plane' ), 'primary', 'submit', false );
        echo '</form>';
        echo '<p class="description">' . esc_html__( 'Next: approve this new UUID for the exact company in Central Master Admin. No OAuth setup or Original305/312/313 job is needed simply to create a read-only site identity.', 'mad4b-site-control-plane' ) . '</p>';
        echo '</div>';
    }
}
add_action( 'admin_notices', array( 'MAD4B_Production_Identity_Quickstart_V1', 'render' ), 9 );
