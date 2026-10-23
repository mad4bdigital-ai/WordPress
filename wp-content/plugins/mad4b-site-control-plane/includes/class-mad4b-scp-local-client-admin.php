<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Admin-only local client credential setup; always fail closed without external vault. */
final class MAD4B_SCP_Local_Client_Admin {
    public static function boot() {
        // No hooks on Production, Staging or ordinary unbound local sites.
        if ( ! function_exists( 'wp_get_environment_type' )
            || 'local' !== wp_get_environment_type()
            || ! defined( 'MAD4B_LOCAL_CLIENT_SITE_UUID' ) ) return;
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_mad4b_local_client_provision', array( __CLASS__, 'provision' ) );
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
    public static function menu() {
        if ( ! self::allowed() ) return;
        add_management_page( 'MAD4B Local Client', 'MAD4B Local Client', 'manage_options', 'mad4b-local-client', array( __CLASS__, 'page' ) );
    }
    public static function page() {
        if ( ! self::allowed() ) wp_die( 'Access denied.', '', array( 'response' => 403 ) );
        echo '<div class="wrap"><h1>MAD4B Local Client</h1><p>Isolated local site identity is bound. No OAuth or Breakglass authority is inherited.</p>';
        $pending = get_option( 'mad4b_local_client_pending_request', array() );
        if ( is_array( $pending ) && ! empty( $pending['id'] ) && time() < (int) ( $pending['expires'] ?? 0 ) ) {
            echo '<p><strong>Remote MCP requested credential provisioning.</strong> Confirm only if you recognize this request.</p>';
        }
        $vault = self::vault();
        $provisioned = $vault && is_file( $vault );
        echo '<p>Local provisioning: ' . ( self::provision_enabled() ? 'enabled by site configuration' : 'disabled' ) . '</p>';
        echo '<p>External credential vault: ' . ( $vault ? 'configured' : 'not configured (provisioning disabled)' ) . '</p>';
        echo '<p>Credential: <strong>' . ( $provisioned ? 'stored on server' : 'not provisioned' ) . '</strong></p>';
        if ( $provisioned ) echo '<p>Use the MCP auth preflight and authenticated REST probe to confirm it is valid.</p>';
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
        echo '<input type="hidden" name="action" value="mad4b_local_client_provision">';
        if ( is_array( $pending ) && ! empty( $pending['id'] ) ) echo '<input type="hidden" name="request_id" value="' . esc_attr( $pending['id'] ) . '">';
        wp_nonce_field( 'mad4b_local_client_provision' );
        $inactive = ! self::provision_enabled() || ! $vault || ! is_array( $pending ) || empty( $pending['id'] ) || $provisioned || time() >= (int) ( $pending['expires'] ?? 0 );
        submit_button( 'Provision dedicated application credential', 'primary', 'submit', false, $inactive ? array( 'disabled' => 'disabled' ) : array() );
        echo '</form></div>';
    }
    public static function provision() {
        if ( ! self::allowed() || ! self::provision_enabled() ) wp_die( 'Access denied.', '', array( 'response' => 403 ) );
        check_admin_referer( 'mad4b_local_client_provision' );
        $pending = get_option( 'mad4b_local_client_pending_request', array() );
        $submitted = isset( $_POST['request_id'] ) && is_string( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
        if ( ! is_array( $pending ) || empty( $pending['id'] ) || ! $submitted || ! hash_equals( (string) $pending['id'], $submitted )
            || time() >= (int) ( $pending['expires'] ?? 0 ) || (int) ( $pending['user_id'] ?? 0 ) !== get_current_user_id() ) {
            wp_die( 'Missing, expired, or mismatched remote request.', '', array( 'response' => 403 ) );
        }
        $vault = self::vault();
        if ( ! $vault || file_exists( $vault ) ) wp_die( 'Secure vault unavailable or already provisioned.', '', array( 'response' => 409 ) );
        $user = wp_get_current_user();
        // Use the approved, exact pending user identity, not a hard-coded WP ID.
        if ( ! $user || ! class_exists( 'WP_Application_Passwords' ) || ! wp_is_application_passwords_available_for_user( $user ) ) wp_die( 'Application passwords unavailable.', '', array( 'response' => 403 ) );
        $result = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'MAD4B isolated local MCP' ) );
        if ( is_wp_error( $result ) ) wp_die( 'Credential provisioning failed.', '', array( 'response' => 500 ) );
        $payload = wp_json_encode( array( 'login' => $user->user_login, 'password' => $result[0] ) );
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
        delete_option( 'mad4b_local_client_pending_request' );
        wp_safe_redirect( admin_url( 'tools.php?page=mad4b-local-client&provisioned=1' ) );
        exit;
    }
}
MAD4B_SCP_Local_Client_Admin::boot();
