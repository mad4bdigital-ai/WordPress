<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Admin-only local client credential setup; always fail closed without external vault. */
final class MAD4B_SCP_Local_Client_Admin {
    const PENDING_OPTION = 'mad4b_local_client_pending_request';
    const AUDIT_OPTION = 'mad4b_local_client_audit_v1';
    const REQUEST_TTL = 300;
    public static function boot() {
        // No hooks on Production, Staging or ordinary unbound local sites.
        if ( ! function_exists( 'wp_get_environment_type' )
            || 'local' !== wp_get_environment_type()
            || ! defined( 'MAD4B_LOCAL_CLIENT_SITE_UUID' ) ) return;
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_mad4b_local_client_request', array( __CLASS__, 'request' ) );
        add_action( 'admin_post_mad4b_local_client_provision', array( __CLASS__, 'provision' ) );
        add_action( 'admin_post_mad4b_local_client_revoke', array( __CLASS__, 'revoke' ) );
    }
    public static function allowed() {
        return class_exists( 'MAD4B_SCP_Local_Client' ) && MAD4B_SCP_Local_Client::can_local_admin();
    }
    private static function provision_enabled() {
        return defined( 'MAD4B_LOCAL_CLIENT_PROVISION_ENABLED' )
            && true === constant( 'MAD4B_LOCAL_CLIENT_PROVISION_ENABLED' );
    }
    private static function vault() {
        if ( ! defined( 'MAD4B_LOCAL_CLIENT_VAULT_PATH' ) ) return '';
        $path = constant( 'MAD4B_LOCAL_CLIENT_VAULT_PATH' );
        if ( ! is_string( $path ) || '' === $path || '/' !== substr( $path, 0, 1 ) || is_link( $path ) ) return '';
        $parent = realpath( dirname( $path ) );
        $root = realpath( ABSPATH );
        if ( ! $parent || ! $root || 0 === strpos( $parent . '/', $root . '/' ) ) return '';
        if ( ! is_dir( $parent ) || ! is_writable( $parent ) ) return '';
        if ( ( fileperms( $parent ) & 0022 ) !== 0 ) return '';
        return $path;
    }
    /** Bounded, credential-free audit events, with autoload explicitly disabled. */
    private static function audit( $event, $user_id ) {
        $rows = get_option( self::AUDIT_OPTION, array() );
        if ( ! is_array( $rows ) ) $rows = array();
        $rows[] = array( 'event' => $event, 'user_id' => (int) $user_id, 'at' => time() );
        update_option( self::AUDIT_OPTION, array_slice( $rows, -12 ), false );
    }
    /** Pure request contract, checked again at final credential issuance. */
    public static function valid_pending( $pending, $submitted, $user_id, $site_uuid, $now = null ) {
        if ( ! is_array( $pending ) || ! is_string( $submitted )
            || '' === $submitted || ! is_string( $site_uuid ) ) return false;
        $now = null === $now ? time() : (int) $now;
        return 'local_client_provision' === ( $pending['intent'] ?? '' )
            && is_string( $pending['id'] ?? null )
            && hash_equals( $pending['id'], $submitted )
            && is_string( $pending['site_uuid'] ?? null )
            && hash_equals( $pending['site_uuid'], $site_uuid )
            && (int) ( $pending['user_id'] ?? 0 ) === (int) $user_id
            && (int) ( $pending['created'] ?? 0 ) > $now - self::REQUEST_TTL
            && (int) ( $pending['created'] ?? 0 ) <= $now
            && (int) ( $pending['expires'] ?? 0 ) > $now
            && (int) ( $pending['expires'] ?? 0 ) <= $now + self::REQUEST_TTL;
    }
    /** Operator starts an explicit local request. Never create a password here. */
    public static function request() {
        if ( ! self::allowed() || ! self::provision_enabled() )
            wp_die( 'Access denied.', '', array( 'response' => 403 ) );
        check_admin_referer( 'mad4b_local_client_request' );
        $vault = self::vault();
        if ( ! $vault || file_exists( $vault ) )
            wp_die( 'Credential vault is unavailable or already exists.', '', array( 'response' => 409 ) );
        $user_id = get_current_user_id();
        $previous = get_option( self::PENDING_OPTION, array() );
        if ( is_array( $previous ) && (int) ( $previous['expires'] ?? 0 ) > time() )
            wp_die( 'A local provisioning request is already pending.', '', array( 'response' => 409 ) );
        if ( false !== get_option( self::PENDING_OPTION, false ) ) {
            delete_option( self::PENDING_OPTION );
        }
        try { $request_id = bin2hex( random_bytes( 16 ) ); }
        catch ( Throwable $error ) {
            wp_die( 'Secure local request generation unavailable.', '', array( 'response' => 500 ) );
        }
        $now = time();
        $pending = array(
            'id' => $request_id, 'intent' => 'local_client_provision',
            'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'user_id' => (int) $user_id, 'created' => $now,
            'expires' => $now + self::REQUEST_TTL,
        );
        if ( ! add_option( self::PENDING_OPTION, $pending, '', false ) )
            wp_die( 'Local request could not be recorded.', '', array( 'response' => 409 ) );
        self::audit( 'request_created', $user_id );
        wp_safe_redirect( admin_url( 'tools.php?page=mad4b-local-client&pending=1' ) );
        exit;
    }
    /**
     * Prefer the explicit Context reviewer when valid. On an isolated,
     * exact-bound Local Lab with human-only Context review, the canonical
     * Site Profile governed-write Agent may provision the VPS client instead.
     * This does NOT delegate AI review or grant any new NHI ability.
     */
    public static function approved_agent_public_id() {
        $review = class_exists( 'MAD4B_SCP_Context_Authority' )
            ? MAD4B_SCP_Context_Authority::review_policy() : array();
        $current = isset( $review['ai_agent_public_id'] ) ? (string) $review['ai_agent_public_id'] : '';
        if ( '' !== $current ) return $current;
        if ( ! self::allowed() || ! self::provision_enabled()
            || ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
            || ! MAD4B_SCP_Site_Profile::write_enabled()
            || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return '';
        $slug = sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() );
        if ( '' === $slug ) return '';
        $agent = MAD4B_SCP_Agent_Registry::get_agent_by_slug( $slug );
        if ( ! is_array( $agent ) || 'enabled' !== ( $agent['status'] ?? '' )
            || $slug !== ( $agent['slug'] ?? '' ) ) return '';
        $grant = MAD4B_SCP_Agent_Registry::exact_grant(
            (int) $agent['id'], 'mad4b-write', 'mad4b/context-ai-review', 'core'
        );
        // Existing exact all-environment allow is necessary because this
        // canonical Site Profile Agent was enrolled for Staging but runs
        // through an isolated Local Lab. Deny-first grants remain authoritative.
        return is_array( $grant ) && 'allow' === ( $grant['effect'] ?? '' )
            && 'all' === ( $grant['environment'] ?? '' )
            ? (string) ( $agent['public_id'] ?? '' ) : '';
    }
    public static function menu() {
        if ( ! self::allowed() ) return;
        add_management_page( 'MAD4B Local Client', 'MAD4B Local Client', 'manage_options', 'mad4b-local-client', array( __CLASS__, 'page' ) );
    }
    public static function page() {
        if ( ! self::allowed() ) wp_die( 'Access denied.', '', array( 'response' => 403 ) );
        echo '<div class="wrap"><h1>MAD4B Local Client</h1><p>Isolated local site identity is bound. No OAuth or Breakglass authority is inherited.</p>';
        $pending = get_option( self::PENDING_OPTION, array() );
        if ( is_array( $pending ) && ! empty( $pending['id'] ) && time() < (int) ( $pending['expires'] ?? 0 ) ) {
            echo '<p><strong>This site administrator initiated a local provisioning request.</strong> Confirm only if you recognize it. Expires in five minutes.</p>';
        }
        $vault = self::vault();
        $provisioned = $vault && is_file( $vault );
        echo '<p>Local provisioning: ' . ( self::provision_enabled() ? 'enabled by site configuration' : 'disabled' ) . '</p>';
        echo '<p>Exact Agent binding: ' . ( '' !== self::approved_agent_public_id() ? 'eligible' : 'not ready' ) . '</p>';
        echo '<p>External credential vault: ' . ( $vault ? 'configured' : 'not configured (provisioning disabled)' ) . '</p>';
        echo '<p>Credential: <strong>' . ( $provisioned ? 'stored on server' : 'not provisioned' ) . '</strong></p>';
        if ( $provisioned ) echo '<p>Use the MCP auth preflight and authenticated REST probe to confirm it is valid. Revoke from this page when no longer needed.</p>';
        $can_request = self::provision_enabled() && $vault && ! $provisioned && ( ! is_array( $pending ) || (int) ( $pending['expires'] ?? 0 ) <= time() );
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        echo '<input type="hidden" name="action" value="mad4b_local_client_request">';
        wp_nonce_field( 'mad4b_local_client_request' );
        submit_button( 'Start a five-minute local credential request', 'secondary', 'submit', false, $can_request ? array() : array( 'disabled' => 'disabled' ) );
        echo '</form>';
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        echo '<input type="hidden" name="action" value="mad4b_local_client_provision">';
        if ( is_array( $pending ) && ! empty( $pending['id'] ) ) echo '<input type="hidden" name="request_id" value="' . esc_attr( $pending['id'] ) . '">';
        wp_nonce_field( 'mad4b_local_client_provision' );
        $inactive = ! self::provision_enabled() || ! $vault || ! is_array( $pending ) || empty( $pending['id'] ) || $provisioned || time() >= (int) ( $pending['expires'] ?? 0 );
        submit_button( 'Provision dedicated application credential', 'primary', 'submit', false, $inactive ? array( 'disabled' => 'disabled' ) : array() );
        echo '</form>';
        if ( $provisioned ) {
            echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
            echo '<input type="hidden" name="action" value="mad4b_local_client_revoke">';
            wp_nonce_field( 'mad4b_local_client_revoke' );
            submit_button( 'Revoke this local application credential', 'delete', 'submit', false );
            echo '</form>';
        }
        echo '</div>';
    }
    public static function provision() {
        if ( ! self::allowed() || ! self::provision_enabled() ) wp_die( 'Access denied.', '', array( 'response' => 403 ) );
        check_admin_referer( 'mad4b_local_client_provision' );
        $pending = get_option( self::PENDING_OPTION, array() );
        $submitted = isset( $_POST['request_id'] ) && is_string( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
        if ( ! self::valid_pending( $pending, $submitted, get_current_user_id(), MAD4B_SCP_Site_Profile::site_uuid() ) )
            wp_die( 'Missing, expired, or mismatched local request.', '', array( 'response' => 403 ) );
        $vault = self::vault();
        if ( ! $vault || file_exists( $vault ) ) wp_die( 'Secure vault unavailable or already provisioned.', '', array( 'response' => 409 ) );
        $user = wp_get_current_user();
        // Use the approved, exact pending user identity, not a hard-coded WP ID.
        if ( ! $user || (int) $user->ID !== (int) get_current_user_id()
            || ! class_exists( 'WP_Application_Passwords' ) || ! wp_is_application_passwords_available_for_user( $user ) ) wp_die( 'Application passwords unavailable.', '', array( 'response' => 403 ) );
        $result = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'MAD4B isolated local MCP' ) );
        if ( is_wp_error( $result ) ) wp_die( 'Credential provisioning failed.', '', array( 'response' => 500 ) );
        $uuid = isset( $result[1]['uuid'] ) && is_string( $result[1]['uuid'] ) ? $result[1]['uuid'] : '';
        if ( '' === $uuid ) {
            wp_die( 'Issued credential metadata unavailable; revoke it manually.', '', array( 'response' => 500 ) );
        }
        $payload = wp_json_encode( array(
            'login' => $user->user_login, 'password' => $result[0],
            'user_id' => (int) $user->ID, 'uuid' => $uuid,
            'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(), 'created' => time(),
        ) );
        if ( ! is_string( $payload ) || '' === $payload ) {
            WP_Application_Passwords::delete_application_password( $user->ID, $uuid );
            wp_die( 'Credential storage encoding failed.', '', array( 'response' => 500 ) );
        }
        $old_umask = umask( 0077 );
        $handle = @fopen( $vault, 'x' );
        umask( $old_umask );
        $ok = $handle && strlen( $payload ) === fwrite( $handle, $payload ) && fflush( $handle );
        if ( $handle ) fclose( $handle );
        if ( ! $ok ) {
            if ( $handle && file_exists( $vault ) ) @unlink( $vault );
            WP_Application_Passwords::delete_application_password( $user->ID, $result[1]['uuid'] );
            wp_die( 'Credential vault write failed; password revoked.', '', array( 'response' => 500 ) );
        }
        @chmod( $vault, 0600 );
        // Bind the WordPress-verified application password to the delegated agent.
        // Never leave an unbound credential live if enrollment fails.
        $agent_id = self::approved_agent_public_id();
        $fingerprint = hash( 'sha256', implode( chr( 0 ), array( 'wp_app_password', MAD4B_SCP_Site_Profile::site_uuid(), (int) $user->ID, $uuid ) ) );
        $bound = '' !== $agent_id && class_exists( 'MAD4B_SCP_Agent_Registry' )
            ? MAD4B_SCP_Agent_Registry::bind_subject( $agent_id, 'wp_app_password', $fingerprint, 'Local client application password' )
            : false;
        if ( true !== $bound ) {
            WP_Application_Passwords::delete_application_password( $user->ID, $uuid );
            @unlink( $vault );
            self::audit( 'binding_failed_credential_revoked', $user->ID );
            wp_die( 'Agent binding failed; issued credential revoked.', '', array( 'response' => 409 ) );
        }
        delete_option( self::PENDING_OPTION );
        self::audit( 'credential_issued', $user->ID );
        wp_safe_redirect( admin_url( 'tools.php?page=mad4b-local-client&provisioned=1' ) );
        exit;
    }
    /** Revoke the exact issued application password before deleting the vault. */
    public static function revoke() {
        if ( ! self::allowed() || ! self::provision_enabled() )
            wp_die( 'Access denied.', '', array( 'response' => 403 ) );
        check_admin_referer( 'mad4b_local_client_revoke' );
        $vault = self::vault();
        if ( ! $vault || ! is_file( $vault ) || is_link( $vault )
            || ! is_readable( $vault ) || filesize( $vault ) > 8192
            || ( fileperms( $vault ) & 0077 ) !== 0 )
            wp_die( 'Secure credential record not available for revocation.', '', array( 'response' => 409 ) );
        $record = json_decode( (string) file_get_contents( $vault ), true );
        $user = wp_get_current_user();
        if ( ! is_array( $record ) || ! $user
            || (int) ( $record['user_id'] ?? 0 ) !== (int) $user->ID
            || ! is_string( $record['uuid'] ?? null ) || '' === $record['uuid']
            || ! is_string( $record['site_uuid'] ?? null )
            || ! hash_equals( (string) MAD4B_SCP_Site_Profile::site_uuid(), $record['site_uuid'] )
            || ! class_exists( 'WP_Application_Passwords' ) )
            wp_die( 'Credential identity cannot be verified for revocation.', '', array( 'response' => 403 ) );
        $result = WP_Application_Passwords::delete_application_password( $user->ID, $record['uuid'] );
        if ( is_wp_error( $result ) || true !== $result )
            wp_die( 'Credential revocation failed; vault retained.', '', array( 'response' => 500 ) );
        if ( ! @unlink( $vault ) )
            wp_die( 'Credential revoked but vault deletion requires operator action.', '', array( 'response' => 500 ) );
        self::audit( 'credential_revoked', $user->ID );
        wp_safe_redirect( admin_url( 'tools.php?page=mad4b-local-client&revoked=1' ) );
        exit;
    }
}
MAD4B_SCP_Local_Client_Admin::boot();
