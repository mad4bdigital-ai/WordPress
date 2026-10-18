<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Native WordPress Plugins-screen update dialog.
 * Runs inside the early zero-touch page boot, never the heavy Control Plane.
 * GET is source-only/cache-only and cannot install or probe GitHub.
 */
final class MAD4B_SCP_Plugin_Update_Popup {
    const DIALOG_PREFIX = 'mad4b-update-dialog-';
    private static $booted = false;
    private static $seen = array();
    private static $catalog = null;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 30, 4 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_footer-plugins.php', array( __CLASS__, 'render' ) );
    }

    public static function can_inspect() {
        return is_admin() && ( current_user_can( 'manage_options' )
            || current_user_can( 'update_plugins' ) );
    }

    private static function core_plugin( $plugin_file ) {
        $canonical = plugin_basename( MAD4B_SCP_FILE );
        if ( $canonical === $plugin_file ) return true;
        if ( ! defined( 'WP_PLUGIN_DIR' ) ) return false;
        $candidate = realpath( WP_PLUGIN_DIR . '/' . $plugin_file );
        $actual = realpath( MAD4B_SCP_FILE );
        return false !== $candidate && false !== $actual && hash_equals( $actual, $candidate );
    }

    private static function reviewed_sources() {
        if ( null !== self::$catalog ) return self::$catalog;
        self::$catalog = array();
        $path = MAD4B_SCP_DIR . 'config/cross-plugin-sources.json';
        if ( ! is_readable( $path ) ) return self::$catalog;
        $json = file_get_contents( $path );
        if ( ! is_string( $json ) || strlen( $json ) > 256 * 1024 ) return self::$catalog;
        $source = json_decode( $json, true );
        if ( ! is_array( $source ) || 'mad4b.cross-plugin-sources.v1' !== ( $source['contract'] ?? '' ) )
            return self::$catalog;
        foreach ( $source['sources'] ?? array() as $item ) {
            if ( ! is_array( $item ) ) continue;
            $file = (string) ( $item['plugin_file'] ?? '' );
            $id = (string) ( $item['id'] ?? '' );
            if ( ! preg_match( '#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+\.php$#D', $file )
                || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id )
                || self::core_plugin( $file ) || isset( self::$catalog[$file] ) ) continue;
            self::$catalog[$file] = array(
                'id' => $id, 'label' => substr( (string) ( $item['label'] ?? $id ), 0, 120 ),
                'repository' => substr( (string) ( $item['repository'] ?? '' ), 0, 180 ),
                'review' => (string) ( $item['review_state'] ?? 'needs_review' ),
                'certificate' => (string) ( $item['package_certification'] ?? 'pending' ),
            );
            if ( count( self::$catalog ) >= 40 ) break;
        }
        return self::$catalog;
    }

    public static function entry( $plugin_file ) {
        $plugin_file = (string) $plugin_file;
        if ( self::core_plugin( $plugin_file ) ) return array(
            'id' => 'control-plane',
            'name' => 'MAD4B Site Control Plane',
            'version' => MAD4B_SCP_VERSION,
            'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
            'kind' => 'core',
            'target' => admin_url( 'admin.php?page=mad4b-runtime-components&tab=maintenance' ),
        );
        $sources = self::reviewed_sources();
        if ( ! isset( $sources[$plugin_file] ) ) return array();
        $data = $sources[$plugin_file];
        return array(
            'id' => $data['id'],
            'name' => $data['label'],
            'repository' => $data['repository'],
            'review' => $data['review'],
            'certificate' => $data['certificate'],
            'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
            'kind' => 'cross',
            'target' => admin_url( 'admin.php?page=mad4b-plugin-deployment' ),
        );
    }

    public static function plugin_action_links( $links, $plugin_file, $plugin_data = array(), $context = '' ) {
        unset( $context );
        $links = is_array( $links ) ? $links : array();
        if ( ! self::can_inspect() ) return $links;
        $entry = self::entry( $plugin_file );
        if ( ! $entry ) return $links;
        if ( 'cross' === $entry['kind'] && ! current_user_can( 'manage_options' ) ) return $links;
        $entry['installed_version'] = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
        $hash = substr( hash( 'sha256', (string) $plugin_file ), 0, 16 );
        $id = self::DIALOG_PREFIX . $hash;
        self::$seen[$id] = $entry;
        // The valid fallback URL works if JavaScript or <dialog> is unavailable.
        $links['mad4b_governed_update'] = '<a href="' . esc_url( $entry['target'] )
            . '" class="mad4b-update-dialog-trigger" data-mad4b-update-dialog="' . esc_attr( $id )
            . '" aria-haspopup="dialog" aria-controls="' . esc_attr( $id ) . '">'
            . esc_html__( 'Review MAD4B update', 'mad4b-site-control-plane' ) . '</a>';
        return $links;
    }

    public static function enqueue( $hook ) {
        if ( 'plugins.php' !== $hook || ! self::can_inspect() ) return;
        $version = defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '1';
        wp_enqueue_style( 'mad4b-plugin-update-dialog',
            plugins_url( 'assets/plugin-update-dialog.css', MAD4B_SCP_FILE ), array(), $version );
        wp_enqueue_script( 'mad4b-plugin-update-dialog',
            plugins_url( 'assets/plugin-update-dialog.js', MAD4B_SCP_FILE ), array(), $version, true );
    }

    private static function info( $term, $description ) {
        echo '<div class="mad4b-ud-meta"><dt>' . esc_html( $term ) . '</dt><dd>'
            . esc_html( (string) $description ) . '</dd></div>';
    }

    public static function render() {
        if ( ! self::can_inspect() || ! self::$seen ) return;
        foreach ( self::$seen as $id => $entry ) {
            $core = 'core' === $entry['kind'];
            echo '<dialog class="mad4b-ud-dialog" id="' . esc_attr( $id )
                . '" aria-labelledby="' . esc_attr( $id . '-title' )
                . '" aria-describedby="' . esc_attr( $id . '-description' ) . '">';
            echo '<div class="mad4b-ud-header"><div><p class="mad4b-ud-eyebrow">'
                . esc_html( $core ? 'MAD4B verified release channel' : 'Reviewed plugin source' )
                . '</p><h2 id="' . esc_attr( $id . '-title' ) . '">'
                . esc_html( $entry['name'] ) . '</h2></div>';
            echo '<button type="button" class="button mad4b-ud-dismiss" data-mad4b-dialog-close aria-label="Close update review">&times;</button></div>';
            echo '<p id="' . esc_attr( $id . '-description' ) . '" class="mad4b-ud-intro">'
                . esc_html( $core
                    ? 'Inspect the release source and request a verified update. Checking does not install anything.'
                    : 'Inspect installed state and reviewed Git source. A candidate is not an approved install.' )
                . '</p>';
            echo '<dl class="mad4b-ud-details">';
            self::info( 'Installed version', $core ? $entry['version'] :
                ( '' !== $entry['installed_version'] ? $entry['installed_version'] : 'Not determined' ) );
            self::info( 'WordPress environment', $entry['environment'] );
            if ( $core ) {
                self::info( 'Update status', 'Not checked on this page; open the certified channel.' );
                self::info( 'Source', 'Signed MAD4B release pointer, not arbitrary GitHub ZIP.' );
            } else {
                self::info( 'GitHub repository', $entry['repository'] );
                self::info( 'Source review', $entry['review'] );
                self::info( 'ZIP certificate', $entry['certificate'] );
            }
            echo '</dl><div class="mad4b-ud-actions">';
            echo '<a class="button button-secondary" href="' . esc_url( $entry['target'] ) . '">'
                . esc_html( $core ? 'Open Runtime Update' : 'Open Plugin Deployment' ) . '</a>';
            echo '<button type="button" class="button" data-mad4b-dialog-close>Close</button></div>';
            if ( $core ) {
                echo '<div class="mad4b-ud-separator"></div><h3>Check official MAD4B release</h3>';
                echo '<p class="description">An explicit nonce-protected check can contact the signed release channel. It does not install any plugin.</p>';
                echo '<form class="mad4b-ud-form" method="post" action="'
                    . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="mad4b_control_plane_refresh_update">';
                wp_nonce_field( 'mad4b_control_plane_refresh_update' );
                echo '<button class="button button-primary" type="submit">Check certified updates</button></form>';
                if ( 'staging' === $entry['environment'] ) {
                    echo '<p class="description">To preview a branch or exact commit, use the Staging selector in Runtime Update. Installation needs independent owner approval.</p>';
                }
            } else {
                echo '<div class="mad4b-ud-separator"></div><h3>Preview exact source</h3>';
                echo '<p class="description">This read-only request resolves a Git reference against its reviewed repository; it cannot install a ZIP.</p>';
                echo '<form class="mad4b-ud-form" method="post" action="'
                    . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="mad4b_cross_plugin_preview">';
                echo '<input type="hidden" name="source_id" value="' . esc_attr( $entry['id'] ) . '">';
                wp_nonce_field( 'mad4b_cross_plugin_preview' );
                echo '<label>Git reference type<select name="ref_type">'
                    . '<option value="commit">Exact commit SHA</option><option value="branch">Branch HEAD</option>'
                    . '<option value="pull_request">Pull Request</option><option value="branch_commit">Branch@SHA</option></select></label>';
                echo '<label>Reference<input name="reference" class="regular-text" maxlength="161" required'
                    . ' placeholder="branch, PR number, or 40-character SHA"></label>';
                echo '<label>Reason<input name="reason" class="regular-text" minlength="3" maxlength="500" required></label>';
                echo '<button class="button button-primary" type="submit">Preview certified source</button></form>';
            }
            echo '<p class="mad4b-ud-footnote">No automatic install. Staging and Production authorizations remain separate; the installer verifies provenance, permissions, backup and readback.</p></dialog>';
        }
    }
}
