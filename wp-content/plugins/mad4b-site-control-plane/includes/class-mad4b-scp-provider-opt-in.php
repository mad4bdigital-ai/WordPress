<?php
/**
 * Read-only, cross-module provider connection opt-in discovery.
 *
 * Automatic means surfacing the next eligible owner action in wp-admin.
 * No credential collection, OAuth consent, key-generation, remote calls,
 * background enrollment, privilege changes or mutation occurs here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Provider_Opt_In {
    const CONTRACT = 'mad4b.provider-opt-in-status.v1';
    const ABILITY = 'mad4b/provider-opt-in-status';
    private static $snapshot = null;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 41 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Provider Opt-in Status',
            'description' => 'Detect missing provider connections and return safe owner setup handoffs. No secret values, OAuth consent, automated enrollment or authority changes.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'status' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array(),
                'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    public static function can_read() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_read();
    }

    /** The only portable fields allowed from dynamically registered providers. */
    private static function row( $id, $label, $state, $page, $detail, $fields = array() ) {
        $id = sanitize_key( (string) $id );
        $page = sanitize_key( (string) $page );
        if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $id )
            || ! in_array( $state, array( 'MISSING', 'RECONNECT', 'UNVERIFIED' ), true )
            || ! is_string( $label ) || '' === trim( $label ) || strlen( $label ) > 128
            || ! is_string( $detail ) || strlen( $detail ) > 320
            || ! class_exists( 'MAD4B_SCP_Admin_Route_Registry' )
            || ! isset( MAD4B_SCP_Admin_Route_Registry::routes()[ $page ] ) ) return null;
        $names = array();
        foreach ( array_slice( (array) $fields, 0, 8 ) as $name ) {
            if ( is_string( $name ) && preg_match( '/^[A-Z][A-Z0-9_]{1,79}$/D', $name ) )
                $names[] = $name;
        }
        return array( 'provider_id' => $id, 'label' => sanitize_text_field( $label ),
            'state' => $state, 'detail' => sanitize_text_field( $detail ),
            'handoff_page' => $page, 'required_environment_names' => array_values( array_unique( $names ) ),
            'credential_values_included' => false, 'ready_to_execute' => false,
            'consent_required' => true, 'authorizing' => false );
    }

    public static function status( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) || $input )
            return new WP_Error( 'mad4b_provider_optin_read_denied', 'Administrator read-only provider opt-in status required.' );
        if ( null !== self::$snapshot ) return self::$snapshot;
        $items = array();

        // Existing provider connection centre owns OAuth status and handoff.
        if ( class_exists( 'MAD4B_SCP_Provider_Connection_Center', false ) ) {
            $result = MAD4B_SCP_Provider_Connection_Center::discover();
            foreach ( array_slice( is_array( $result ) ? (array) ( $result['providers'] ?? array() ) : array(), 0, 32 ) as $provider ) {
                if ( ! is_array( $provider ) || empty( $provider['provider'] ) ) continue;
                $connected = ! empty( $provider['connected'] );
                $needs_review = !$connected || ! empty( $provider['reconnect_required'] )
                    || ! empty( $provider['revocation_pending'] )
                    || ! empty( $provider['refresh_required'] );
                if ( !$needs_review ) continue;
                $id = (string) $provider['provider'];
                $is_google = 'google_drive' === $id;
                $state = ! empty( $provider['reconnect_required'] ) || ! empty( $provider['revocation_pending'] )
                    ? 'RECONNECT' : ( ! empty( $provider['refresh_required'] ) ? 'UNVERIFIED' : 'MISSING' );
                $items[] = self::row( $id, (string) ( $provider['label'] ?? $id ), $state,
                    $is_google ? 'mad4b-control-plane-context' : 'mad4b-control-plane-connection',
                    $is_google
                        ? 'Continue owner-managed Google OAuth or resolve the pending revocation.'
                        : 'Open the existing Provider Connections workspace to inspect the owner handoff. Never submit secrets to this status page.' );
            }
        }

        // The existing Search enrollment owns encrypted secrets and tests.
        if ( class_exists( 'MAD4B_SCP_Search_Providers', false )
            && class_exists( 'MAD4B_SCP_Search_Provider_Connections', false )
            && interface_exists( 'MAD4B_SCP_Search_SERP_Enrollment', false ) ) {
            foreach ( array_slice( MAD4B_SCP_Search_Providers::adapters(), 0, 12 ) as $id => $adapter ) {
                if ( ! $adapter instanceof MAD4B_SCP_Search_SERP_Enrollment ) continue;
                $state = MAD4B_SCP_Search_Provider_Connections::status( $id );
                // Unconfigured optional Search vendors must not create noisy
                // global warnings; a required vendor can opt in via the
                // bounded extension hook below.
                if ( ! is_array( $state ) || empty( $state['configured'] )
                    || ! in_array( $state['connection_state'] ?? '',
                        array( 'CHECK_FAILED', 'CHECK_EXPIRED', 'NOT_CHECKED' ), true ) ) continue;
                $meta = $adapter->enrollment();
                $items[] = self::row( 'search_' . $id,
                    is_array( $meta ) && isset( $meta['label'] ) ? (string) $meta['label'] : (string) $id,
                    is_array( $state ) && ! empty( $state['configured'] ) ? 'UNVERIFIED' : 'MISSING',
                    'mad4b-search-intelligence',
                    'Use the existing local encrypted credential form and connection test.' );
            }
        }

        // WordPress holds only browser *preferences*, not runner secrets.
        if ( class_exists( 'MAD4B_SCP_Browser_Acceptance_Admin_UI', false )
            && class_exists( 'MAD4B_SCP_Native_Capability_Browser_Provider', false ) ) {
            $browser = MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection();
            $caps = MAD4B_SCP_Native_Capability_Browser_Provider::capabilities();
            $executor = (string) ( $browser['executor'] ?? 'auto' );
            $managed = MAD4B_SCP_Browser_Acceptance_Admin_UI::managed_executors();
            $selected = $managed[ $executor ][1] ?? array();
            if ( empty( $caps['browser_attestation_public_key_valid'] )
                || empty( $browser['credential_verified'] )
                || empty( $browser['external_runner_connected'] ) ) {
                $items[] = self::row( 'browser_runner', 'Browser Acceptance Runner', 'UNVERIFIED',
                    'mad4b-browser-acceptance',
                    'Select an external runner, provision its credentials outside WordPress, and verify signed browser evidence.',
                    $selected );
            }
        }

        // Extension hook is read-only, bounded, and cannot smuggle a link or secret.
        $extra = apply_filters( 'mad4b_scp_provider_opt_in_requirements', array() );
        foreach ( array_slice( is_array( $extra ) ? $extra : array(), 0, 16 ) as $candidate ) {
            if ( ! is_array( $candidate ) ) continue;
            $items[] = self::row( $candidate['provider_id'] ?? '', $candidate['label'] ?? '',
                $candidate['state'] ?? '', $candidate['handoff_page'] ?? '',
                $candidate['detail'] ?? '', $candidate['required_environment_names'] ?? array() );
        }
        $unique = array();
        foreach ( $items as $item ) if ( is_array( $item ) && ! isset( $unique[ $item['provider_id'] ] ) )
            $unique[ $item['provider_id'] ] = $item;
        self::$snapshot = array( 'contract' => self::CONTRACT,
            'count' => count( $unique ), 'items' => array_values( $unique ),
            'auto_triggered' => 0 < count( $unique ), 'trigger_type' => 'read_only_owner_prompt',
            'secret_values_included' => false, 'mutation_performed' => false,
            'oauth_consent_performed' => false, 'authority_created' => false,
            'production_mutation' => false, 'read_only' => true );
        return self::$snapshot;
    }

    /** A single compact opt-in prompt on any registered MAD4B page. */
    public static function render( $current_page = '' ) {
        if ( '' === $current_page || ! self::can_read() ) return;
        $status = self::status();
        if ( ! is_array( $status ) || empty( $status['count'] ) ) return;
        echo '<details class="mad4b-scp-panel mad4b-provider-optin" data-mad4b-provider-optin>';
        echo '<summary><strong>' . esc_html__( 'Provider setup needed', 'mad4b-site-control-plane' )
            . '</strong> (' . esc_html( (string) $status['count'] ) . ') â€” '
            . esc_html__( 'Choose a secure connection action; no credentials are collected here.', 'mad4b-site-control-plane' )
            . '</summary><ul>';
        foreach ( $status['items'] as $item ) {
            $url = MAD4B_SCP_Admin_Workspace::link( $item['handoff_page'] );
            if ( '' === $url ) continue;
            if ( 'mad4b-control-plane-context' === $item['handoff_page'] )
                $url = add_query_arg( 'tab', 'google-drive', $url );
            elseif ( 'mad4b-search-intelligence' === $item['handoff_page'] )
                $url = add_query_arg( 'section', 'providers', $url ) . '#search-providers';
            echo '<li><strong>' . esc_html( $item['label'] ) . '</strong>: '
                . esc_html( $item['state'] ) . ' â€” ' . esc_html( $item['detail'] )
                . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Open secure setup', 'mad4b-site-control-plane' ) . '</a></li>';
        }
        echo '</ul></details>';
    }
}
MAD4B_SCP_Provider_Opt_In::boot();
