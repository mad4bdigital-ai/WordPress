<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Administrative UI for the same read-only deployment service used via MCP. */
final class MAD4B_SCP_Cross_Plugin_Admin {
    const PAGE = 'mad4b-plugin-deployment';
    const ACTION = 'mad4b_cross_plugin_preview';
    const AJAX_PREVIEW = 'mad4b_cross_plugin_preview_ajax';
    const INTAKE = 'mad4b_cross_plugin_intake';

    public static function boot() {
        MAD4B_SCP_Admin_Route_Registry::register( self::PAGE, 'manage_options' );
        MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'menu' ), 32 );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_preview' ) );
        add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( __CLASS__, 'handle_ajax_preview' ) );
        add_action( 'admin_post_' . self::INTAKE, array( __CLASS__, 'handle_intake' ) );
    }

    public static function menu() {
        add_submenu_page( MAD4B_SCP_Admin_UI::PAGE_SLUG,
            'Plugin Deployment', 'Plugin Deployment', 'manage_options',
            self::PAGE, array( __CLASS__, 'render' ) );
    }

    public static function environment() {
        $wp = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
        $site = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::current_environment() : 'unknown';
        return in_array( $wp, array( 'staging', 'production' ), true )
            && $wp === $site && MAD4B_SCP_Site_Profile::origin_enrolled()
            ? $wp : 'not_verified';
    }

    private static function key() { return 'mad4b_plugin_deployment_preview_' . (int) get_current_user_id(); }

    /**
     * Explicit nonce-protected AJAX preview for the Plugins row dialog.
     * Returns only bounded read-only evidence; never a package apply input.
     */
    public static function handle_ajax_preview() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
            || ! current_user_can( 'manage_options' )
            || 'not_verified' === self::environment() ) {
            wp_send_json_error( array( 'state' => 'NOT_AUTHORIZED',
                'blockers' => array( 'administrator_and_enrolled_site_required' ) ), 403 );
            return;
        }
        if ( ! check_ajax_referer( self::ACTION, '_wpnonce', false ) ) {
            wp_send_json_error( array( 'state' => 'NOT_AUTHORIZED',
                'blockers' => array( 'invalid_preview_nonce' ) ), 403 );
            return;
        }
        $input = array();
        foreach ( array( 'source_id', 'ref_type', 'reference', 'reason' ) as $field ) {
            $value = isset( $_POST[$field] ) && is_string( $_POST[$field] )
                ? wp_unslash( $_POST[$field] ) : '';
            $input[$field] = strlen( $value ) <= 500 ? trim( $value ) : '';
        }
        $result = strlen( $input['reason'] ) >= 3
            ? MAD4B_SCP_Cross_Plugin_Deployment::plan( $input )
            : new WP_Error( 'cross_plugin_reason_required', 'A bounded audit reason is required.' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'state' => 'BLOCKED',
                'blockers' => array( sanitize_key( $result->get_error_code() ) ) ), 400 );
            return;
        }
        $blockers = array_slice( (array) ( $result['blockers'] ?? array() ), 0, 20 );
        wp_send_json_success( array(
            'state' => ! empty( $result['eligible'] ) ? 'PLAN_ELIGIBLE_NOT_AUTHORIZED' : 'NEEDS_REVIEW',
            'operation' => (string) ( $result['operation'] ?? '' ),
            'resolved_sha' => (string) ( $result['resolved_sha'] ?? '' ),
            'plugin_file' => (string) ( $result['plugin_file'] ?? '' ),
            'blockers' => array_values( array_map( 'sanitize_key', $blockers ) ),
            'mutation_performed' => false,
            'authorizing' => false,
        ) );
    }

    public static function handle_preview() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
            || ! current_user_can( 'manage_options' ) || 'not_verified' === self::environment() )
            wp_die( 'Verified administrator and site identity are required.', '', array( 'response' => 403 ) );
        check_admin_referer( self::ACTION );
        $input = array();
        foreach ( array( 'source_id', 'ref_type', 'reference', 'reason' ) as $field ) {
            $v = isset( $_POST[$field] ) && is_string( $_POST[$field] ) ? wp_unslash( $_POST[$field] ) : '';
            $input[$field] = strlen( $v ) <= 500 ? trim( $v ) : '';
        }
        $result = strlen( $input['reason'] ) >= 3
            ? MAD4B_SCP_Cross_Plugin_Deployment::plan( $input )
            : new WP_Error( 'cross_plugin_reason_required', 'A bounded audit reason is required.' );
        set_transient( self::key(), array(
            'environment' => self::environment(),
            'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
            'time' => time(),
            'state' => is_wp_error( $result ) ? 'BLOCKED'
                : ( ! empty( $result['eligible'] ) ? 'PLAN_ELIGIBLE_NOT_AUTHORIZED' : 'NEEDS_REVIEW' ),
            'plan' => is_wp_error( $result ) ? array() : $result,
            'blockers' => is_wp_error( $result ) ? array( $result->get_error_code() )
                : (array) ( $result['blockers'] ?? array() ),
        ), 600 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }


    public static function handle_intake() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
            || ! current_user_can( 'manage_options' ) || 'not_verified' === self::environment() )
            wp_die( 'Verified administrator is required.', '', array( 'response' => 403 ) );
        check_admin_referer( self::INTAKE );
        $input = array();
        foreach ( array( 'source_id', 'repository', 'plugin_subdirectory', 'plugin_file', 'provider_id' ) as $name ) {
            $value = isset( $_POST[$name] ) && is_string( $_POST[$name] ) ? wp_unslash( $_POST[$name] ) : '';
            $input[$name] = strlen( $value ) < 250 ? trim( $value ) : '';
        }
        $result = MAD4B_SCP_Cross_Plugin_Deployment::onboarding_plan( $input );
        set_transient( self::key() . '_intake', array(
            'time' => time(), 'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
            'environment' => self::environment(), 'result' => is_wp_error( $result ) ? array(
                'state' => 'BLOCKED', 'reason' => $result->get_error_code() ) : $result,
        ), 600 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    private static function preview() {
        $row = get_transient( self::key() );
        $digest = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::profile_digest() : '';
        return is_array( $row ) && ( time() - (int) ( $row['time'] ?? 0 ) ) <= 600
            && hash_equals( (string) ( $row['environment'] ?? '' ), self::environment() )
            && hash_equals( (string) ( $row['profile_digest'] ?? '' ), (string) $digest )
            ? $row : array();
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not authorized.' );
        $result = MAD4B_SCP_Cross_Plugin_Deployment::discover();
        $environment = self::environment();
        echo '<div class="wrap"><h1>Cross-Plugin Deployment</h1>';
        echo '<p class="description">Shared Staging / Production discovery and source planning. External repositories require reviewed source registration.</p>';
        echo '<p>Environment: <code>' . esc_html( $environment ) . '</code></p>';
        echo '<table class="widefat striped"><thead><tr><th>Plugin</th><th>Repository and directory</th><th>Installed</th><th>Package certification</th></tr></thead><tbody>';
        foreach ( $result['items'] as $item ) {
            echo '<tr><td>' . esc_html( $item['label'] ) . '</td><td><code>'
                . esc_html( $item['repository'] ) . '</code> / <code>'
                . esc_html( $item['plugin_subdirectory'] ) . '</code></td><td>'
                . esc_html( $item['installed'] ? $item['installed_version'] : 'Not installed' )
                . '</td><td>' . esc_html( $item['package_certification'] ) . '</td></tr>';
        }
        if ( empty( $result['items'] ) ) echo '<tr><td colspan="4">No approved source entries.</td></tr>';
        echo '</tbody></table>';
        if ( 'not_verified' === $environment ) {
            echo '<div class="notice notice-warning"><p>Environment and enrolled origin must match before planning.</p></div></div>';
            return;
        }
        echo '<h2>Select a source for read-only planning</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( self::ACTION );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<p><label>Plugin <select name="source_id">';
        foreach ( $result['items'] as $item )
            echo '<option value="' . esc_attr( $item['source_id'] ) . '">'
                . esc_html( $item['label'] ) . '</option>';
        echo '</select></label></p>';
        echo '<p><label>Reference <select name="ref_type">'
            . '<option value="commit">Exact commit SHA</option><option value="branch">Branch HEAD</option>'
            . '<option value="pull_request">Pull Request HEAD</option>'
            . '<option value="branch_commit">Branch@historical SHA</option></select></label></p>';
        echo '<p><label>Source reference <input class="regular-text" name="reference" maxlength="161" required></label></p>';
        echo '<p><label>Audit reason <input class="regular-text" name="reason" maxlength="500" minlength="3" required></label></p>';
        echo '<p><button class="button button-secondary" type="submit">Preview source and certified package</button></p></form>';
        $preview = self::preview();
        if ( $preview ) {
            echo '<h2>Exact preview</h2><p>State: <code>' . esc_html( $preview['state'] ) . '</code></p>';
            foreach ( array( 'resolved_sha' => 'Commit', 'operation' => 'Operation',
                'plugin_file' => 'Plugin file', 'native_plan_sha256' => 'Package plan',
                'native_executor' => 'Governed executor' ) as $key => $label ) {
                if ( ! empty( $preview['plan'][$key] ) )
                    echo '<p>' . esc_html( $label ) . ': <code>'
                        . esc_html( $preview['plan'][$key] ) . '</code></p>';
            }
            if ( $preview['blockers'] ) {
                echo '<ul>';
                foreach ( array_slice( $preview['blockers'], 0, 20 ) as $code )
                    echo '<li><code>' . esc_html( (string) $code ) . '</code></li>';
                echo '</ul>';
            }
            echo '<p>Plan is not approval. When eligible, the existing governed Remote MCP package executor requires its own exact approval and revalidation.</p>';
        }

        echo '<h2>Propose another repository / plugin for review</h2>';
        echo '<p>Same-repository or external GitHub owner/repo sources are accepted as proposals only. Review the source and publish its certified ZIP before installation.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( self::INTAKE );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::INTAKE ) . '">';
        foreach ( array(
            'source_id' => 'Source ID (lowercase slug)',
            'repository' => 'GitHub owner/repository',
            'plugin_subdirectory' => 'Repository source subdirectory',
            'plugin_file' => 'WordPress plugin main file',
            'provider_id' => 'Provider ID'
        ) as $field => $caption ) {
            echo '<p><label>' . esc_html( $caption ) . ' <input class="regular-text" name="'
                . esc_attr( $field ) . '" maxlength="240" required></label></p>';
        }
        echo '<p><button type="submit" class="button">Prepare source-review request</button></p></form>';
        $intake = get_transient( self::key() . '_intake' );
        if ( is_array( $intake ) && time() - (int) ( $intake['time'] ?? 0 ) <= 600
            && hash_equals( (string) ( $intake['environment'] ?? '' ), $environment )
            && hash_equals( (string) ( $intake['profile_digest'] ?? '' ),
                (string) MAD4B_SCP_Site_Profile::profile_digest() ) ) {
            $review = (array) ( $intake['result'] ?? array() );
            echo '<p>Intake: <code>' . esc_html( (string) ( $review['state'] ?? 'UNKNOWN' ) ) . '</code></p>';
            if ( ! empty( $review['candidate_digest'] ) )
                echo '<p>Review digest: <code>' . esc_html( $review['candidate_digest'] ) . '</code></p>';
            if ( ! empty( $review['reason'] ) )
                echo '<p>Reason: <code>' . esc_html( $review['reason'] ) . '</code></p>';
        }
        if ( 'production' === $environment )
            echo '<p>Production is supported for discovery and planning. Execution remains blocked pending a separate certified Production promotion and executor; Staging grants never carry over.</p>';
        echo '<p class="description">No network calls or WP-Cron jobs occur when opening this page. A plan is an explicit, bounded request only.</p></div>';
    }
}
