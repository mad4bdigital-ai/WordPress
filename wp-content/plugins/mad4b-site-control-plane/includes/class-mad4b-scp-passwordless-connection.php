<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/** Local-only, administrator-authorized, one-time sign-in for a low-privilege integration user. */
final class MAD4B_SCP_Passwordless_Connection {
    private static $mcp_identity = null;
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_mad4b_pwless_create', array( __CLASS__, 'create' ) );
        add_action( 'init', array( __CLASS__, 'login' ), 1 );
        add_action( 'admin_post_mad4b_pwless_mcp_issue', array( __CLASS__, 'issue_mcp' ) );
        add_filter( 'mad4b_scp_authenticated_subject_context', array( __CLASS__, 'identity_context' ), 45 );
    }
    private static function available() {
        return in_array( wp_get_environment_type(), array( 'local', 'staging' ), true ) && ( 'local' === wp_get_environment_type() || is_ssl() ) && class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled();
    }
    private static function authorized() {
        return self::available() && current_user_can( 'manage_options' ) && class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_connect_user( get_current_user_id() );
    }
    public static function menu() {
        if ( self::authorized() ) add_management_page( 'MAD4B Connection Methods', 'MAD4B Connection', 'manage_options', 'mad4b-connection-methods', array( __CLASS__, 'page' ) );
    }
    public static function page() {
        if ( ! self::authorized() ) wp_die( 'Forbidden', '', array( 'response' => 403 ) );
        echo '<div class="wrap"><h1>MAD4B Connection Methods</h1><p>OAuth: preferred for remote MCP; Application Password: governed local client; one-time sign-in: interactive WordPress access only, not MCP authorization.</p>';
        echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=mad4b-local-client' ) ) . '">Application Password setup</a></p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mad4b_pwless_create">';
        wp_nonce_field( 'mad4b_pwless_create' );
        echo '<label><input type="checkbox" name="admin_role" value="1"> Grant administrator role to integration user (local/staging only)</label>';
        submit_button( 'Create limited integration user and one-time sign-in' );
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mad4b_pwless_mcp_issue">';
        wp_nonce_field('mad4b_pwless_mcp_issue');
        submit_button('Issue short-lived MCP connection token', 'secondary');
        echo '</form>';
        $mcp_token = get_transient('mad4b_pwless_mcp_display_' . get_current_user_id());
        if (is_string($mcp_token)) {
            delete_transient('mad4b_pwless_mcp_display_' . get_current_user_id());
            echo '<p>One-time display (15 minutes): <input readonly size="100" value="' . esc_attr($mcp_token) . '"></p>';
        }
        $token = get_transient( 'mad4b_pwless_display_' . get_current_user_id() );
        if ( is_string( $token ) && preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
            delete_transient( 'mad4b_pwless_display_' . get_current_user_id() );
            $url = add_query_arg( 'mad4b_pwless', '1', home_url( '/' ) ) . '#' . $token;
            echo '<p>One-time link (expires in five minutes): <input readonly size="100" value="' . esc_attr( $url ) . '"></p>';
        }
        echo '</div>';
    }
    public static function create() {
        if ( ! self::authorized() ) wp_die( 'Forbidden', '', array( 'response' => 403 ) );
        check_admin_referer( 'mad4b_pwless_create' );
        $login = 'mad4b_connection_' . substr( hash( 'sha256', MAD4B_SCP_Site_Profile::site_uuid() ), 0, 12 );
        $user = get_user_by( 'login', $login );
        if ( ! $user ) {
            $id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 64, true, true ), 'role' => 'subscriber', 'display_name' => 'MAD4B Limited Connection' ) );
            if ( is_wp_error( $id ) ) wp_die( 'Unable to create integration user.', '', array( 'response' => 500 ) );
            $user = get_userdata( $id );
        }
        if ( ! $user || ! array_intersect( array( 'subscriber', 'administrator' ), (array) $user->roles ) || count( (array) $user->roles ) !== 1 ) wp_die( 'Integration role mismatch.', '', array( 'response' => 409 ) );
        if ( ! empty( $_POST['admin_role'] ) ) {
            if ( ! current_user_can( 'promote_users' ) ) wp_die( 'Role management denied.', '', array( 'response' => 403 ) );
            $user->set_role( 'administrator' );
        }
        $token = bin2hex( random_bytes( 32 ) );
        $key = 'mad4b_pwless_use_' . hash( 'sha256', $token );
        set_transient( $key, array( 'uid' => $user->ID, 'site' => MAD4B_SCP_Site_Profile::site_uuid() ), 300 );
        set_transient( 'mad4b_pwless_display_' . get_current_user_id(), $token, 60 );
        wp_safe_redirect( admin_url( 'tools.php?page=mad4b-connection-methods' ) );
        exit;
    }
    public static function issue_mcp() {
        if (!self::authorized()) wp_die('Forbidden', '', array('response'=>403));
        check_admin_referer('mad4b_pwless_mcp_issue');
        $policy = MAD4B_SCP_Context_Authority::review_policy();
        $agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id((string)($policy['ai_agent_public_id'] ?? ''));
        if (!is_array($agent) || ($agent['status'] ?? '') !== 'enabled') wp_die('Agent unavailable', '', array('response'=>409));
        $grant = MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'], 'mad4b-write', 'mad4b/context-ai-review', 'core');
        if (!is_array($grant) || ($grant['effect'] ?? '') !== 'allow') wp_die('Agent grant unavailable', '', array('response'=>403));
        $login = 'mad4b_connection_' . substr( hash( 'sha256', MAD4B_SCP_Site_Profile::site_uuid() ), 0, 12 );
        $integration_user = get_user_by( 'login', $login );
        if ( ! $integration_user || ! in_array( 'administrator', (array) $integration_user->roles, true ) || count( $integration_user->roles ) !== 1 ) wp_die( 'Administrator integration user not provisioned.', '', array( 'response' => 409 ) );
        $token = bin2hex(random_bytes(32));
        $fingerprint = hash('sha256', 'mad4b_pwless_mcp' . chr(0) . MAD4B_SCP_Site_Profile::site_uuid() . chr(0) . $agent['public_id']);
        $bound = MAD4B_SCP_Agent_Registry::bind_subject($agent['public_id'], 'mad4b_pwless_mcp', $fingerprint, 'Local passwordless MCP');
        if (true !== $bound) wp_die('Agent identity bind failed', '', array('response'=>409));
        set_transient('mad4b_pwless_mcp_' . hash('sha256', $token), array('site'=>MAD4B_SCP_Site_Profile::site_uuid(), 'agent'=>$agent['public_id'], 'fingerprint'=>$fingerprint, 'wp_user_id'=>(int)$integration_user->ID), 900);
        set_transient('mad4b_pwless_mcp_display_' . get_current_user_id(), $token, 60);
        wp_safe_redirect(admin_url('tools.php?page=mad4b-connection-methods'));
        exit;
    }
    public static function verify_mcp($request) {
        self::$mcp_identity = null;
        if (!self::available() || !is_object($request) || !method_exists($request,'get_route')) return false;
        if ('/mcp/mad4b-chatgpt' !== '/' . trim((string)$request->get_route(),'/')) return false;
        $header = trim((string)$request->get_header('authorization'));
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $header, $m)) return false;
        $data = get_transient('mad4b_pwless_mcp_' . hash('sha256', $m[1]));
        if (!is_array($data) || !hash_equals((string)MAD4B_SCP_Site_Profile::site_uuid(), (string)($data['site']??''))) return false;
        $identity = array('authenticated'=>true, 'subject_type'=>'mad4b_pwless_mcp', 'subject_fingerprint'=>(string)($data['fingerprint']??''), 'auth_method'=>'mad4b_passwordless_bearer', 'origin'=>'mcp', 'token_scopes'=>array());
        $agent = MAD4B_SCP_Agent_Registry::resolve_agent($identity);
        if (!is_array($agent) || !hash_equals((string)$agent['public_id'], (string)($data['agent']??''))) return false;
        $grant = MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],'mad4b-write','mad4b/context-ai-review','core');
        if (!is_array($grant) || ($grant['effect']??'') !== 'allow') return false;
        $wp_user = get_userdata( (int) ( $data['wp_user_id'] ?? 0 ) );
        if ( ! $wp_user || ! in_array( 'administrator', (array) $wp_user->roles, true ) || count( (array) $wp_user->roles ) !== 1 ) return false;
        if ( ! str_starts_with( $wp_user->user_login, 'mad4b_connection_' ) ) return false;
        $identity['wp_user_id'] = (int) $wp_user->ID;
        wp_set_current_user( $wp_user->ID );
        self::$mcp_identity = $identity;
        return true;
    }
    public static function identity_context($context) {
        return self::$mcp_identity && is_array($context) && empty($context['authenticated']) ? array_merge($context,self::$mcp_identity) : $context;
    }
    public static function login() {
        if ( ! self::available() ) return;
        if ( isset( $_GET['mad4b_pwless'] ) ) {
            nocache_headers();
            header( 'Referrer-Policy: no-referrer' );
            header( 'Content-Type: text/html; charset=utf-8' );
            echo '<!doctype html><meta name="robots" content="noindex"><form method="post" id="f"><input type="hidden" name="mad4b_pwless_token" id="t"></form><script>const x=location.hash.slice(1);history.replaceState(null,"",location.pathname);if(/^[a-f0-9]{64}$/.test(x)){document.getElementById("t").value=x;document.getElementById("f").submit()}else document.body.textContent="Invalid link"</script>';
            exit;
        }
        if ( ! isset( $_POST['mad4b_pwless_token'] ) || ! is_string( $_POST['mad4b_pwless_token'] ) ) return;
        nocache_headers();
        $token = (string) wp_unslash( $_POST['mad4b_pwless_token'] );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { status_header( 403 ); exit; }
        $key = 'mad4b_pwless_use_' . hash( 'sha256', $token );
        $data = get_transient( $key );
        delete_transient( $key );
        if ( ! is_array( $data ) || empty( $data['uid'] ) || ! hash_equals( (string) MAD4B_SCP_Site_Profile::site_uuid(), (string) ( $data['site'] ?? '' ) ) ) { status_header( 403 ); exit; }
        $user = get_userdata( (int) $data['uid'] );
        if ( ! $user || ! in_array( (string) reset( $user->roles ), array( 'subscriber', 'administrator' ), true ) || count( $user->roles ) !== 1 || ! str_starts_with( $user->user_login, 'mad4b_connection_' ) ) { status_header( 403 ); exit; }
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, false, is_ssl() );
        wp_safe_redirect( admin_url( 'profile.php' ) );
        exit;
    }
}
MAD4B_SCP_Passwordless_Connection::boot();
