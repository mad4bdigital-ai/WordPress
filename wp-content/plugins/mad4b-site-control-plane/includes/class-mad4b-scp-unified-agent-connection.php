<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One assisted, explicitly confirmed path through the existing MAD4B Agent Registry,
 * OAuth identity and WordPress Application Passwords. No client secrets in HTML.
 * Neither a saved receipt nor an application password grants MAD4B execution rights.
 */
final class MAD4B_SCP_Unified_Agent_Connection {
    const CONTRACT = 'mad4b.unified-agent-connection.v1';
    const RECEIPTS = 'mad4b_unified_agent_connections_v1';
    const SLUG = 'chatgpt-governed-write';
    const PAGE = 'mad4b-control-plane-chatgpt';

    public static function boot() {
        add_action( 'admin_post_mad4b_unified_agent_activate', array( __CLASS__, 'activate' ) );
        add_action( 'admin_post_mad4b_unified_agent_revoke', array( __CLASS__, 'revoke_credential' ) );
    }

    private static function allowed() {
        return current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy', false )
            && MAD4B_SCP_Policy::can_connect_user( get_current_user_id() );
    }

    private static function environment() {
        if ( class_exists( 'MAD4B_SCP_Environment', false ) ) return sanitize_key( (string) MAD4B_SCP_Environment::effective() );
        if ( class_exists( 'MAD4B_SCP_Site_Profile', false ) ) return sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
        return function_exists( 'wp_get_environment_type' ) ? sanitize_key( wp_get_environment_type() ) : 'unknown';
    }

    private static function site_id() {
        return class_exists( 'MAD4B_SCP_Site_Profile', false ) ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '';
    }

    public static function site_ready() {
        return class_exists( 'MAD4B_SCP_Site_Profile', false )
            && MAD4B_SCP_Site_Profile::configured()
            && MAD4B_SCP_Site_Profile::origin_enrolled()
            && MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
            && '' !== self::site_id()
            && ! in_array( self::environment(), array( '', 'unknown' ), true );
    }

    /** This path is independent of OAuth and needs explicit operator provision. */
    public static function vault_path( $user_id = 0 ) {
        if ( ! defined( 'MAD4B_AGENT_CONNECTION_VAULT_DIR' ) ) return '';
        $dir = constant( 'MAD4B_AGENT_CONNECTION_VAULT_DIR' );
        if ( ! is_string( $dir ) || '' === $dir || '/' !== substr( $dir, 0, 1 ) ) return '';
        $root = realpath( $dir );
        $webroot = realpath( ABSPATH );
        $content = defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : false;
        if ( ! $root || ! is_dir( $root ) || is_link( $dir ) || ! is_writable( $root )
            || ! $webroot || '/' === $root || ( fileperms( $root ) & 0077 ) !== 0
            || $root === $webroot || 0 === strpos( $root . '/', $webroot . '/' )
            || ( $content && ( $root === $content || 0 === strpos( $root . '/', $content . '/' ) ) ) ) return '';
        $site = self::site_id();
        if ( ! preg_match( '/^[a-f0-9-]{36}$/iD', $site ) ) return '';
        return $root . '/mad4b-agent-' . strtolower( $site ) . '-u' . absint( $user_id ?: get_current_user_id() ) . '.json';
    }

    private static function credential_record() {
        $file = self::vault_path();
        if ( ! $file || is_link( $file ) || ! is_file( $file )
            || ( fileperms( $file ) & 0077 ) !== 0 || filesize( $file ) > 8192 ) return null;
        $record = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $record ) || (int) ( $record['user_id'] ?? 0 ) !== get_current_user_id()
            || ! hash_equals( self::site_id(), (string) ( $record['site'] ?? '' ) )
            || ! is_string( $record['uuid'] ?? null ) ) return null;
        return $record;
    }

    public static function credential_available() {
        if ( ! self::site_ready() || ! self::vault_path() || ! is_ssl() ) return false;
        $user = wp_get_current_user();
        return class_exists( 'WP_Application_Passwords' )
            && function_exists( 'wp_is_application_passwords_available_for_user' )
            && $user && $user->ID === get_current_user_id()
            && wp_is_application_passwords_available_for_user( $user );
    }

    private static function redirect( $status ) {
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&connection_setup=' . rawurlencode( $status ) ) );
        exit;
    }

    private static function fail( $message, $code = 403 ) {
        wp_die( esc_html( $message ), esc_html__( 'MAD4B connection setup', 'mad4b-site-control-plane' ), array( 'response' => $code ) );
    }

    /** Only the already signed-in account can provision its own credential. */
    private static function issue_credential( $agent ) {
        if ( ! self::credential_available() ) return new WP_Error( 'mad4b_connection_vault_unavailable', 'Secure HTTPS Application Password vault is unavailable.' );
        $file = self::vault_path();
        if ( file_exists( $file ) || is_link( $file ) ) return new WP_Error( 'mad4b_connection_credential_exists', 'Existing credential must be revoked separately.' );
        $user = wp_get_current_user();
        $result = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'MAD4B Agent Connection — ' . self::site_id() ) );
        if ( is_wp_error( $result ) ) return $result;
        $uuid = isset( $result[1]['uuid'] ) ? (string) $result[1]['uuid'] : '';
        if ( ! preg_match( '/^[a-f0-9-]{36}$/iD', $uuid ) ) {
            if ( '' !== $uuid ) WP_Application_Passwords::delete_application_password( $user->ID, $uuid );
            return new WP_Error( 'mad4b_connection_credential_invalid', 'Credential metadata unavailable; review WordPress Application Passwords.' );
        }
        $record = wp_json_encode( array(
            'site' => self::site_id(), 'user_id' => (int) $user->ID,
            'agent_public_id' => (string) $agent['public_id'], 'uuid' => $uuid,
            'login' => (string) $user->user_login, 'password' => (string) $result[0],
            'created' => time(),
        ) );
        $old_mask = umask( 0077 );
        $handle = @fopen( $file, 'x' );
        umask( $old_mask );
        $ok = $handle && is_string( $record ) && strlen( $record ) === fwrite( $handle, $record ) && fflush( $handle );
        if ( $handle ) fclose( $handle );
        if ( ! $ok ) {
            if ( $handle && is_file( $file ) ) @unlink( $file );
            WP_Application_Passwords::delete_application_password( $user->ID, $uuid );
            return new WP_Error( 'mad4b_connection_vault_write', 'Vault write failed; issued password revoked.' );
        }
        @chmod( $file, 0600 );
        return $uuid;
    }

    /** Read-only snapshot of a user-visible receipt; never read credentials for display. */
    private static function receipt_key() {
        return hash( 'sha256', self::site_id() . '|' . get_current_user_id() );
    }

    private static function receipt() {
        $records = get_option( self::RECEIPTS, array() );
        return is_array( $records ) && isset( $records[ self::receipt_key() ] ) && is_array( $records[ self::receipt_key() ] )
            ? $records[ self::receipt_key() ] : array();
    }

    private static function save_receipt( array $row ) {
        $records = get_option( self::RECEIPTS, array() );
        if ( ! is_array( $records ) ) $records = array();
        $records[ self::receipt_key() ] = $row;
        update_option( self::RECEIPTS, array_slice( $records, -30, null, true ), false );
    }

    public static function available_agents() {
        if ( ! self::allowed() || ! class_exists( 'MAD4B_SCP_Schema', false ) || ! MAD4B_SCP_Schema::is_ready() ) return array();
        global $wpdb;
        $tables = MAD4B_SCP_Schema::tables();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT public_id, slug, label, status, environment, wp_user_id FROM {$tables['agents']}
             WHERE (wp_user_id = %d OR wp_user_id IS NULL OR wp_user_id = 0) ORDER BY id DESC LIMIT 30",
            get_current_user_id()
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    public static function activate() {
        if ( ! self::allowed() ) self::fail( 'Administrator connection permission required.' );
        check_admin_referer( 'mad4b_unified_agent_activate' );
        if ( ! self::site_ready() ) self::fail( 'Enroll this site identity and origin before connecting.', 409 );
        if ( ! class_exists( 'MAD4B_SCP_Schema', false ) || ! MAD4B_SCP_Schema::is_ready() ) self::fail( 'Agent Registry schema is not ready.', 409 );
        if ( empty( $_POST['approve_connection'] ) ) self::fail( 'Explicit connection approval is required.' );
        $method = isset( $_POST['auth_method'] ) ? sanitize_key( wp_unslash( $_POST['auth_method'] ) ) : '';
        if ( ! in_array( $method, array( 'oauth', 'application_password', 'both' ), true ) ) self::fail( 'Unsupported authentication method.' );
        $use_password = in_array( $method, array( 'application_password', 'both' ), true );
        if ( $use_password && ( empty( $_POST['approve_password'] ) || ! self::credential_available() ) )
            self::fail( 'Explicit Application Password approval and secure external Vault are required.', 409 );
        if ( in_array( $method, array( 'oauth', 'both' ), true ) ) {
            $oauth = MAD4B_SCP_ChatGPT_Connection_Admin_UI::status();
            if ( empty( $oauth['ready_for_chatgpt_draft'] ) ) self::fail( 'OAuth runtime is not ready. Review the connection diagnostics.', 409 );
        }
        $selected = isset( $_POST['agent'] ) ? sanitize_text_field( wp_unslash( $_POST['agent'] ) ) : '';
        $allowed = array();
        foreach ( self::available_agents() as $row ) $allowed[ (string) $row['public_id'] ] = $row;
        if ( 'new' === $selected ) {
            $existing = MAD4B_SCP_Agent_Registry::get_agent_by_slug( self::SLUG . '-u' . get_current_user_id() );
            if ( $existing && (int) ( $existing['wp_user_id'] ?? 0 ) !== get_current_user_id() )
                self::fail( 'An agent with this name belongs to another user.', 409 );
            $agent = $existing ?: MAD4B_SCP_Agent_Registry::create_agent( array(
                'slug' => self::SLUG . '-u' . get_current_user_id(), 'label' => 'ChatGPT Governed Agent', 'wp_user_id' => get_current_user_id(),
                'environment' => 'all', 'status' => 'disabled',
            ) );
        } elseif ( isset( $allowed[ $selected ] ) ) {
            $agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $selected );
        } else self::fail( 'Select an existing agent or choose Create new.', 400 );
        if ( is_wp_error( $agent ) ) self::fail( $agent->get_error_message(), 409 );
        if ( ! is_array( $agent ) || ! in_array( (int) ( $agent['wp_user_id'] ?? 0 ), array( 0, get_current_user_id() ), true ) )
            self::fail( 'Agent belongs to another WordPress user.', 403 );
        if ( $use_password ) {
            $issued = self::issue_credential( $agent );
            if ( is_wp_error( $issued ) ) self::fail( $issued->get_error_message(), 409 );
        }
        if ( 'enabled' !== (string) $agent['status'] ) {
            $agent = MAD4B_SCP_Agent_Registry::update_agent( $agent['public_id'], array( 'status' => 'enabled' ), $agent['revision'] );
            if ( is_wp_error( $agent ) ) self::fail( $agent->get_error_message(), 409 );
        }
        // Only a discoverable, explicitly mounted read ability is eligible.
        // No wildcard, write, Developer, Production or Breakglass grant is minted here.
        $read_grants = 0;
        foreach ( array( 'mad4b/site-info', 'mad4b/site-profile-status' ) as $ability ) {
            if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability )
                || ! class_exists( 'MAD4B_SCP_Servers', false ) ) continue;
            $provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-chatgpt', $ability );
            if ( ! is_string( $provider ) || '' === $provider ) continue;
            $prior = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-chatgpt', $ability, $provider );
            if ( ! is_wp_error( $prior ) ) continue;
            if ( 'mad4b_nhi_grant_missing' !== $prior->get_error_code() ) continue;
            $granted = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-chatgpt', $ability,
                $provider, array(), 'allow', self::environment() );
            if ( true === $granted ) ++$read_grants;
        }
        self::save_receipt( array(
            'contract' => self::CONTRACT, 'agent_public_id' => (string) $agent['public_id'],
            'agent_label' => (string) $agent['label'], 'environment' => self::environment(),
            'site_fingerprint' => hash( 'sha256', self::site_id() . '|' . home_url( '/' ) ),
            'wp_user_id' => get_current_user_id(), 'authentication' => $method,
            'oauth_status' => in_array( $method, array( 'oauth', 'both' ), true ) ? 'ready_for_external_consent' : 'not_selected',
            'application_password' => $use_password ? 'vault_provisioned_not_verified' : 'not_selected',
            'read_grants_added' => $read_grants, 'write_grants_added' => 0,
            'external_session_verified' => false, 'at' => time(),
        ) );
        self::redirect( 'ready' );
    }

    public static function revoke_credential() {
        if ( ! self::allowed() ) self::fail( 'Administrator connection permission required.' );
        check_admin_referer( 'mad4b_unified_agent_revoke' );
        if ( ! self::site_ready() ) self::fail( 'Site identity mismatch.', 409 );
        $record = self::credential_record();
        $file = self::vault_path();
        if ( ! $record || ! $file || ! class_exists( 'WP_Application_Passwords' ) ) self::fail( 'No verified external credential record.', 409 );
        $result = WP_Application_Passwords::delete_application_password( get_current_user_id(), $record['uuid'] );
        if ( is_wp_error( $result ) || true !== $result ) self::fail( 'WordPress password revocation failed; Vault retained.', 500 );
        if ( ! @unlink( $file ) ) self::fail( 'Password revoked; Vault cleanup requires operator attention.', 500 );
        $receipt = self::receipt();
        if ( $receipt ) {
            $receipt['application_password'] = 'revoked';
            $receipt['external_session_verified'] = false;
            $receipt['at'] = time();
            self::save_receipt( $receipt );
        }
        self::redirect( 'revoked' );
    }

    /** Request-local health projection; no network or credential disclosure. */
    public static function health() {
        $oauth = MAD4B_SCP_ChatGPT_Connection_Admin_UI::status();
        $receipt = self::receipt();
        $fingerprint = hash( 'sha256', self::site_id() . '|' . home_url( '/' ) );
        $matches = $receipt && isset( $receipt['site_fingerprint'] )
            && hash_equals( $fingerprint, (string) $receipt['site_fingerprint'] );
        return array(
            'Identity' => self::site_ready() ? 'Enrolled site ready' : 'Enroll Site Profile first',
            'Authentication' => ! empty( $oauth['ready_for_chatgpt_draft'] ) ? 'OAuth ready for consent' : 'OAuth needs setup',
            'Grants' => $matches ? 'Agent configured; verified per request' : 'Setup pending',
            'Tools' => ! empty( $oauth['gateway_registration_identity_ready'] ) ? 'Gateway registered' : 'Gateway not ready',
            'Functional test' => ! empty( $oauth['external_connection_previously_verified'] )
                ? 'Previously verified — live recheck required' : 'External test not yet verified',
            'Credential Vault' => self::credential_record() ? 'Stored on server' : ( self::credential_available()
                ? 'Available on explicit approval' : 'Not configured or unavailable' ),
        );
    }

    public static function render() {
        if ( ! self::allowed() ) return;
        $agents = self::available_agents();
        $receipt = self::receipt();
        $record = self::credential_record();
        $env = self::environment();
        $ready = self::site_ready();
        $app = self::credential_available();
        $oauth = MAD4B_SCP_ChatGPT_Connection_Admin_UI::status();
        echo '<section class="card" style="max-width:1080px;padding:18px 22px;margin:18px 0 24px">';
        echo '<h2>Connect AI Agent <small style="font-weight:400">— MAD4B Unified Connection Setup</small></h2>';
        echo '<p>Pick an existing Agent or create one when you confirm. Authentication and site policies are evaluated for this runtime; no UUID or OAuth keys to enter.</p>';
        if ( $receipt ) {
            echo '<div class="notice notice-info inline"><p><strong>Connection setup saved:</strong> '
                . esc_html( $receipt['agent_label'] ?? '' ) . ' · ' . esc_html( $receipt['authentication'] ?? '' )
                . ' · ' . esc_html( $receipt['environment'] ?? '' )
                . '. OAuth is only ready for external consent until ChatGPT completes authorization. No live remote session is certified by this receipt.</p></div>';
        }
        echo '<h3>Connection health</h3><ol>';
        foreach ( self::health() as $stage => $message ) {
            echo '<li><strong>' . esc_html( $stage ) . ':</strong> ' . esc_html( $message ) . '</li>';
        }
        echo '</ol>';
        echo '<p><strong>Detected environment:</strong> ' . esc_html( $env )
            . ' · <strong>Site identity:</strong> ' . ( $ready ? 'enrolled' : 'needs enrollment' )
            . ' · <strong>OAuth:</strong> ' . ( ! empty( $oauth['ready_for_chatgpt_draft'] ) ? 'ready for consent' : 'needs setup' )
            . ' · <strong>Application Password:</strong> ' . ( $record ? 'stored outside web root' : ( $app ? 'available on confirmation' : 'external Vault not ready' ) ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'mad4b_unified_agent_activate' );
        echo '<input type="hidden" name="action" value="mad4b_unified_agent_activate">';
        echo '<p><label for="mad4b-agent-choice"><strong>1. Agent identity</strong></label><br>';
        echo '<select id="mad4b-agent-choice" name="agent" style="min-width:300px">';
        foreach ( $agents as $agent ) {
            echo '<option value="' . esc_attr( $agent['public_id'] ) . '">' . esc_html( $agent['label'] ) . ' (' . esc_html( $agent['status'] ) . ')</option>';
        }
        echo '<option value="new"' . selected( empty( $agents ), true, false ) . '>Create ChatGPT Governed Agent</option></select></p>';
        echo '<p><label for="mad4b-agent-method"><strong>2. Authentication</strong></label><br>';
        echo '<select name="auth_method" id="mad4b-agent-method">';
        echo '<option value="oauth">OAuth 2.0 — recommended</option>';
        echo '<option value="application_password"' . disabled( ! $app || (bool) $record, true, false ) . '>Application Password — trusted integration (secure Vault)</option>';
        echo '<option value="both"' . disabled( ! $app || (bool) $record, true, false ) . '>Both — independent credentials</option></select></p>';
        echo '<p><strong>3. Runtime permissions</strong> — automatically detect read abilities; never grant write, production or breakglass permissions in this wizard. Existing execution policies remain authoritative.</p>';
        echo '<p><label><input type="checkbox" name="approve_connection" value="1" required> I approve connecting this Agent on the detected site and environment.</label></p>';
        echo '<p><label><input type="checkbox" name="approve_password" value="1"> I explicitly approve creating my own WordPress Application Password in the configured external Vault (only if selected above).</label></p>';
        submit_button( 'Connect & Activate', 'primary', 'submit', false, $ready ? array() : array( 'disabled' => 'disabled' ) );
        echo '</form>';
        if ( $record ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
            wp_nonce_field( 'mad4b_unified_agent_revoke' );
            echo '<input type="hidden" name="action" value="mad4b_unified_agent_revoke">';
            submit_button( 'Revoke stored Application Password', 'secondary', 'submit', false );
            echo '</form>';
        }
        echo '<p><small>4. Functional test: verify WordPress site identity and OAuth readiness above, then complete external ChatGPT OAuth consent. A connection receipt is not a live handshake result. Credential rotation = revoke and explicitly issue again.</small></p>';
        echo '</section>';
    }
}
