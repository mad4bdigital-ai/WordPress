<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-local Control Plane policy for filtered SEO publication.
 * The effective environment is resolved by MAD4B Site Profile, never by URL,
 * query arguments, raw WP defaults, or an implicit staging/production guess.
 * This is a policy gate, not an authorization/grant replacement.
 */
final class MAD4B_SCP_DFSB_Environment_Policy {
    const OPTION = 'mad4b_scp_dfsb_environment_modes';
    const CONTRACT = 'mad4b.scp.dfsb-environment-policy.v1';
    const FEATURES = array( 'publication', 'managed_routes', 'native_local_boot' );

    public static function environment() {
        if ( ! class_exists( 'MAD4B_SCP_Environment' ) ) return '';
        $environment = sanitize_key( (string) MAD4B_SCP_Environment::effective() );
        return in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true )
            ? $environment : '';
    }

    public static function allows( $feature ) {
        $feature = sanitize_key( (string) $feature );
        $environment = self::environment();
        if ( ! $environment || ! in_array( $feature, self::FEATURES, true ) ) return false;
        $options = get_option( self::OPTION, array() );
        if ( ! is_array( $options ) ) return false;
        $environment_modes = $options[ $environment ] ?? array();
        // Exact boolean true is required. Absent, malformed, or migrated settings deny.
        return is_array( $environment_modes ) && true === ( $environment_modes[ $feature ] ?? false );
    }

    /** An exact approved Git commit is mandatory for source-local operation. */
    public static function localPackageApproved( $sourceSha ) {
        if ( ! self::allows( 'native_local_boot' ) ) return false;
        $env = self::environment();
        $all = get_option( self::OPTION, array() );
        $expected = is_array( $all ) ? (string) ( $all[ $env ]['native_local_source_sha'] ?? '' ) : '';
        $actual = strtolower( trim( (string) $sourceSha ) );
        return (bool) preg_match( '/^[a-f0-9]{40}$/D', $expected )
            && (bool) preg_match( '/^[a-f0-9]{40}$/D', $actual )
            && hash_equals( $expected, $actual );
    }

    public static function snapshot() {
        return array(
            'contract' => self::CONTRACT,
            'effective_environment' => self::environment(),
            'publication' => self::allows( 'publication' ),
            'managed_routes' => self::allows( 'managed_routes' ),
            'native_local_boot' => self::allows( 'native_local_boot' ),
            'authorizing' => false,
            'source' => self::OPTION,
        );
    }

    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
        add_action( 'admin_post_mad4b_scp_dfsb_policy_save', array( __CLASS__, 'save' ) );
    }

    public static function menu() {
        add_submenu_page( 'mad4b-control-plane', 'Dynamic SEO Environments',
            'Dynamic SEO Environments', 'manage_options',
            'mad4b-dfsb-environments', array( __CLASS__, 'render' ) );
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient capability', '', array( 'response' => 403 ) );
        check_admin_referer( 'mad4b_scp_dfsb_environment_policy' );
        $environment = self::environment();
        if ( '' === $environment ) wp_die( 'Unresolved MAD4B environment', '', array( 'response' => 409 ) );
        $settings = get_option( self::OPTION, array() );
        if ( ! is_array( $settings ) ) $settings = array();
        $mode = isset( $_POST['dfsb_mode'] ) && is_array( $_POST['dfsb_mode'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['dfsb_mode'] ) ) : array();
        $settings[ $environment ] = array(
            'publication' => in_array( 'publication', $mode, true ),
            'managed_routes' => in_array( 'managed_routes', $mode, true ),
            'native_local_boot' => in_array( 'native_local_boot', $mode, true ),
            'native_local_source_sha' => sanitize_text_field( wp_unslash( $_POST['native_local_source_sha'] ?? '' ) ),
        );
        update_option( self::OPTION, $settings, false );
        wp_safe_redirect( add_query_arg( 'updated', '1',
            admin_url( 'admin.php?page=mad4b-dfsb-environments' ) ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $env = self::environment();
        echo '<div class="wrap"><h1>Dynamic SEO — Environment Policy</h1>';
        echo '<p>Source: MAD4B Site Profile. Each site and environment has an independent policy. ';
        echo 'Enabling a mode does not bypass DFSB, WPML, shared presentation, Rank Math, provider verification, or SEO approval gates.</p>';
        echo '<p><strong>Effective environment:</strong> ' . esc_html( $env ? $env : 'unresolved' ) . '</p>';
        if ( ! $env ) { echo '<p>Environment authority unavailable; policy is disabled.</p></div>'; return; }
        if ( isset( $_GET['updated'] ) && '1' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) {
            echo '<div class="notice notice-success"><p>Environment policy saved.</p></div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'mad4b_scp_dfsb_environment_policy' );
        echo '<input type="hidden" name="action" value="mad4b_scp_dfsb_policy_save">';
        foreach ( array(
            'managed_routes' => 'Enable approved managed filter routes for this environment',
            'publication' => 'Allow verified filtered SEO publication for this environment',
            'native_local_boot' => 'Permit guarded Full Boot of an exact approved local Git source with valid installed manifest',
        ) as $feature => $label ) {
            echo '<p><label><input type="checkbox" name="dfsb_mode[]" value="' . esc_attr( $feature ) . '" '
                . checked( self::allows( $feature ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
        }
        $all = get_option( self::OPTION, array() );
        $expected = is_array( $all ) ? (string) ( $all[ $env ]['native_local_source_sha'] ?? '' ) : '';
        echo '<p><label>Approved local Git commit SHA (40 hexadecimal characters): ';
        echo '<input type="text" name="native_local_source_sha" maxlength="40" value="' . esc_attr( $expected ) . '" size="44" autocomplete="off"></label></p>';
        submit_button( 'Save environment policy' );
        echo '</form></div>';
    }
}
