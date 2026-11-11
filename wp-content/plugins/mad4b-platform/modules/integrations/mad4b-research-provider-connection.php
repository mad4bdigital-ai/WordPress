<?php
/**
 * Plugin Name: MAD4B Firecrawl Provider Connection & Research Router
 * Description: Woo Lab first-consumer private credential form and task-aware, non-executing provider routing.
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class MAD4B_Firecrawl_Provider_Connection {
    const PAGE = 'mad4b-firecrawl-provider';
    const POST_ACTION = 'mad4b_firecrawl_provider_credentials';
    const ROUTE_ABILITY = 'mad4b/research-provider-route-plan';
    const CONTRACT = 'mad4b.research-provider-routing.v1';
    const CREDENTIAL = '/run/mad4b-local-vault/firecrawl-control-plane/consumer.credential';

    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
        add_action( 'admin_post_' . self::POST_ACTION, array( __CLASS__, 'post' ) );
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 47 );
    }
    public static function allowed() {
        return class_exists( 'MAD4B_Research_Firecrawl_Bridge' )
            && MAD4B_Research_Firecrawl_Bridge::permitted();
    }
    private static function consumer_id() {
        $id = defined( 'MAD4B_FIRECRAWL_CONSUMER_ID' )
            ? constant( 'MAD4B_FIRECRAWL_CONSUMER_ID' ) : 'mad4b-woo-lab-control-plane';
        return is_string( $id ) && preg_match( '/\\Amad4b-[a-z0-9-]{3,48}\\z/D', $id ) ? $id : '';
    }
    private static function path() {
        return defined( 'MAD4B_FIRECRAWL_SECRET_FILE' )
            ? constant( 'MAD4B_FIRECRAWL_SECRET_FILE' ) : self::CREDENTIAL;
    }
    public static function menu() {
        add_submenu_page( 'mad4b-control-plane', 'Research Providers', 'Research Providers',
            'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
    }
    public static function render() {
        if ( ! self::allowed() ) wp_die( 'Provider administrator permission is required.', '', array( 'response' => 403 ) );
        $id = self::consumer_id();
        $ready = MAD4B_Research_Firecrawl_Bridge::status( array() );
        $configured = ! is_wp_error( $ready ) && ! empty( $ready['configured'] );
        $healthy = ! is_wp_error( $ready ) && ! empty( $ready['healthy'] );
        echo '<div class="wrap"><h1>Research Providers</h1>';
        echo '<p>Shared Firecrawl for governed page research; SerpAPI remains the source for certified Google SERP observations.</p>';
        echo '<h2>Firecrawl (private Docker gateway)</h2><dl>';
        echo '<dt>Credential state</dt><dd>' . esc_html( $configured ? 'Stored in private site vault' : 'Not configured' ) . '</dd>';
        echo '<dt>Gateway health</dt><dd>' . esc_html( $healthy ? 'Reachable' : 'Unavailable; no public endpoint configured' ) . '</dd>';
        echo '<dt>Consumer identity</dt><dd><code>' . esc_html( $id ) . '</code></dd>';
        echo '<dt>Provider endpoint</dt><dd><code>gateway:8080 (Docker private only)</code></dd></dl>';
        echo '<p>Credentials are never shown again. Saving requires successful live authentication and does not edit the gateway user account.</p>';
        if ( '' !== $id ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '">';
            wp_nonce_field( self::POST_ACTION );
            echo '<input type="hidden" name="operation" value="save">';
            echo '<p><label for="mad4b-firecrawl-username">Service username</label><br><input id="mad4b-firecrawl-username" class="regular-text" type="text" name="username" value="' . esc_attr( $id ) . '" required readonly autocomplete="off"></p>';
            echo '<p><label for="mad4b-firecrawl-password">Service password (replace only)</label><br><input id="mad4b-firecrawl-password" class="regular-text" type="password" name="password" value="" required minlength="40" maxlength="128" autocomplete="new-password"></p>';
            submit_button( 'Verify and save credentials' );
            echo '</form>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '">';
            wp_nonce_field( self::POST_ACTION );
            echo '<input type="hidden" name="operation" value="test">';
            submit_button( 'Test saved Firecrawl connection', 'secondary' );
            echo '</form>';
        }
        $notice = isset( $_GET['mad4b_firecrawl_result'] ) && is_string( $_GET['mad4b_firecrawl_result'] )
            ? sanitize_key( wp_unslash( $_GET['mad4b_firecrawl_result'] ) ) : '';
        if ( in_array( $notice, array( 'saved', 'verified', 'failed' ), true ) ) {
            echo '<p role="status"><strong>' . esc_html( 'Result: ' . $notice . '. Current connection state is displayed above.' ) . '</strong></p>';
        }
        echo '<h2>SerpAPI (Google SERP)</h2><p>Use the existing separately encrypted Search Provider connection form and its certification/budget checks.</p>';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mad4b-search-intelligence&section=providers' ) ) . '">Configure SerpAPI / other SERP providers</a></p>';
        echo '<p><strong>Routing:</strong> content/SEO page/price candidate extraction uses Firecrawl; rankings and SERP features use the certified SERP provider. Neither connection alone authorizes scheduled collection, retained research, content publication or price changes.</p></div>';
    }
    private static function write_verified( $username, $password ) {
        if ( ! is_string( $username ) || ! hash_equals( self::consumer_id(), $username ) ) return false;
        if ( ! MAD4B_Research_Firecrawl_Bridge::verify_candidate_credentials( $username, $password ) ) return false;
        $file = self::path();
        if ( ! is_string( $file ) || '' === $file || is_link( $file ) ) return false;
        $dir = dirname( $file );
        if ( ! is_dir( $dir ) || is_link( $dir ) || ! is_writable( $dir ) || ( @fileperms( $dir ) & 0077 ) !== 0 ) return false;
        $tmp = @tempnam( $dir, '.firecrawl-credential-' );
        if ( ! is_string( $tmp ) || '' === $tmp || is_link( $tmp ) ) return false;
        $data = 'username=' . $username . "\n" . 'password=' . $password . "\n";
        try {
            if ( ! @chmod( $tmp, 0600 ) || strlen( $data ) !== @file_put_contents( $tmp, $data, LOCK_EX )
                || ! @rename( $tmp, $file ) ) return false;
            return true;
        } finally { if ( is_file( $tmp ) ) @unlink( $tmp ); }
    }
    public static function post() {
        if ( ! self::allowed() ) wp_die( 'Provider administrator permission is required.', '', array( 'response' => 403 ) );
        check_admin_referer( self::POST_ACTION );
        $operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] )
            ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
        $state = 'failed';
        if ( 'test' === $operation ) {
            $result = MAD4B_Research_Firecrawl_Bridge::preview( array( 'url' => 'https://example.com' ) );
            if ( ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result['preview'] ) ) $state = 'verified';
        } elseif ( 'save' === $operation ) {
            $username = isset( $_POST['username'] ) && is_string( $_POST['username'] ) ? wp_unslash( $_POST['username'] ) : '';
            $password = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
            if ( self::write_verified( $username, $password ) ) $state = 'saved';
        }
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'mad4b_firecrawl_result' => $state ), admin_url( 'admin.php' ) ) );
        exit;
    }
    public static function register() {
        if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'MAD4B_SCP_Policy' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ROUTE_ABILITY ) ) return;
        wp_register_ability( self::ROUTE_ABILITY, array(
            'label' => 'Plan Research Provider Routing',
            'description' => 'Read-only selection: Firecrawl for bounded page extraction; certified SERP provider for Google results and rankings. Never performs search.',
            'category' => 'mad4b-admin', 'execute_callback' => array( __CLASS__, 'route' ),
            'permission_callback' => array( __CLASS__, 'allowed' ),
            'input_schema' => array( 'type' => 'object', 'required' => array( 'intent' ),
                'properties' => array( 'intent' => array( 'type' => 'string', 'enum' => array( 'page_content', 'competitor_pricing', 'page_seo', 'google_serp', 'keyword_discovery' ) ) ),
                'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }
    public static function route( $input ) {
        if ( ! self::allowed() ) return new WP_Error( 'mad4b_research_route_denied', 'Research provider access denied.' );
        if ( ! is_array( $input ) || array_keys( $input ) !== array( 'intent' ) ) return new WP_Error( 'mad4b_research_route_invalid', 'One research intent is required.' );
        $intent = $input['intent'];
        if ( ! in_array( $intent, array( 'page_content', 'competitor_pricing', 'page_seo', 'google_serp', 'keyword_discovery' ), true ) ) return new WP_Error( 'mad4b_research_route_invalid', 'Unrecognized research intent.' );
        $serp = in_array( $intent, array( 'google_serp', 'keyword_discovery' ), true );
        if ( $serp ) {
            $state = 'not_configured';
            $certified = false;
            if ( class_exists( 'MAD4B_SCP_Search_Provider_Connections' ) ) {
                $status = MAD4B_SCP_Search_Provider_Connections::status( 'serpapi' );
                if ( is_array( $status ) ) $state = (string) ( $status['connection_state'] ?? 'unknown' );
            }
            if ( class_exists( 'MAD4B_SCP_Search_Providers' ) ) {
                $providers = MAD4B_SCP_Search_Providers::adapters();
                if ( isset( $providers['serpapi'] ) ) {
                    $descriptor = $providers['serpapi']->descriptor();
                    $certified = is_array( $descriptor ) && ! empty( $descriptor['certified'] )
                        && (int)( $descriptor['certification_expires_at'] ?? 0 ) > time();
                }
            }
            return array( 'contract' => self::CONTRACT, 'intent' => $intent,
                'preferred_provider' => 'serpapi', 'provider_state' => $state,
                'certified' => $certified, 'execution_ready' => $certified && 'VERIFIED' === $state,
                'fallback_to_firecrawl_ranking' => false, 'execution_performed' => false );
        }
        $state = MAD4B_Research_Firecrawl_Bridge::status( array() );
        $ready = is_array( $state ) && ! empty( $state['configured'] ) && ! empty( $state['healthy'] );
        return array( 'contract' => self::CONTRACT, 'intent' => $intent,
            'preferred_provider' => 'firecrawl', 'provider_state' => $ready ? 'connected' : 'unavailable',
            'execution_ready' => $ready, 'requires_exact_source_allowlist' => true,
            'requires_rights_and_budget_review' => true, 'fallback_to_serpapi_as_full_content_extractor' => false,
            'price_changes_authorized' => false, 'execution_performed' => false );
    }
}
MAD4B_Firecrawl_Provider_Connection::boot();
