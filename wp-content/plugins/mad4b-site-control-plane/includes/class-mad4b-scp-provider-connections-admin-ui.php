<?php
/**
 * Provider-neutral, read-only wp-admin connection inventory. All actual
 * refresh/reauthorization actions occur only through separately governed
 * provider-specific POSTs or the approved MCP mutation boundary.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Provider_Connections_Admin_UI {
    const PAGE_SLUG = 'mad4b-provider-connections';
    private static $booted = false;
    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        MAD4B_SCP_Admin_Route_Registry::register( self::PAGE_SLUG, 'manage_options' );
        MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'menu' ), 49 );
    }
    public static function menu() {
        add_submenu_page( 'mad4b-control-plane',
            __( 'Provider Connections', 'mad4b-site-control-plane' ),
            __( 'Provider Connections', 'mad4b-site-control-plane' ),
            'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) );
    }
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Administrator permission required.', 'mad4b-site-control-plane' ) );
        }
        $result = MAD4B_SCP_Provider_Connection_Center::discover();
        echo '<div class="wrap"><h1>' . esc_html__( 'Provider Connections', 'mad4b-site-control-plane' ) . '</h1>';
        echo '<p>' . esc_html__( 'Live connection health and recommended recovery. Discovery is read-only; owner OAuth consent, exact one-time approval and post-operation verification are separate.', 'mad4b-site-control-plane' ) . '</p>';
        if ( is_wp_error( $result ) || empty( $result['providers'] ) ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No verified provider adapters are available to inspect.', 'mad4b-site-control-plane' ) . '</p></div></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Provider', 'mad4b-site-control-plane' ) . '</th><th>' . esc_html__( 'Live state', 'mad4b-site-control-plane' ) . '</th><th>' . esc_html__( 'Recommended action', 'mad4b-site-control-plane' ) . '</th><th>' . esc_html__( 'Owner handoff', 'mad4b-site-control-plane' ) . '</th></tr></thead><tbody>';
        foreach ( $result['providers'] as $row ) {
            $plan = MAD4B_SCP_Provider_Connection_Center::plan( array( 'provider' => $row['provider'] ) );
            $action = is_array( $plan ) && ! empty( $plan['action'] ) ? (string) $plan['action'] : 'unavailable';
            echo '<tr><td><strong>' . esc_html( $row['label'] ) . '</strong><br><code>' . esc_html( $row['provider'] ) . '</code></td>';
            echo '<td><code>' . esc_html( $row['health_state'] ) . '</code></td><td><code>' . esc_html( $action ) . '</code>';
            if ( 'refresh' === $action ) echo '<p class="description">' . esc_html__( 'Local refresh uses saved credentials. Remote execution requires a separate exact owner approval ticket.', 'mad4b-site-control-plane' ) . '</p>';
            if ( 'oauth_handoff' === $action ) echo '<p class="description">' . esc_html__( 'An owner must finish authorization in the provider browser. Remote discovery cannot sign in on their behalf.', 'mad4b-site-control-plane' ) . '</p>';
            if ( 'resolve_pending_revocation' === $action ) echo '<p class="description">' . esc_html__( 'Resolve the pending provider revocation first; do not reconnect or refresh.', 'mad4b-site-control-plane' ) . '</p>';
            echo '</td><td>';
            $url = isset( $row['owner_handoff_url'] ) ? (string) $row['owner_handoff_url'] : '';
            if ( $url ) echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Open connection controls', 'mad4b-site-control-plane' ) . '</a>';
            else echo esc_html__( 'No certified handoff available', 'mad4b-site-control-plane' );
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__( 'Adding a new provider requires an adapter with a read-only status contract, reviewed owner handoff and separately certified refresh executor. No wildcard provider mutation authority is created.', 'mad4b-site-control-plane' ) . '</p></div>';
    }
}
MAD4B_SCP_Provider_Connections_Admin_UI::boot();
