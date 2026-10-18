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
        if ( self::$booted || ! function_exists( 'add_filter' ) || ! function_exists( 'add_action' ) ) return;
        self::$booted = true;
        add_action( 'init', array( __CLASS__, 'load_translations' ), 0 );
        add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 30, 4 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_footer-plugins.php', array( __CLASS__, 'render' ) );
    }

    public static function load_translations() {
        load_plugin_textdomain( 'mad4b-site-control-plane', false,
            dirname( plugin_basename( MAD4B_SCP_FILE ) ) . '/languages' );
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
        $bytes = filesize( $path );
        if ( false === $bytes || $bytes > 256 * 1024 ) return self::$catalog;
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
        if ( ! is_string( $plugin_file ) || strlen( $plugin_file ) > 280 ) return array();
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
            . esc_html( 'core' === $entry['kind'] ? __( 'Review MAD4B update', 'mad4b-site-control-plane' ) : __( 'Review plugin deployment', 'mad4b-site-control-plane' ) ) . '</a>';
        return $links;
    }

    public static function enqueue( $hook ) {
        if ( 'plugins.php' !== $hook || ! self::can_inspect() ) return;
        $version = defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '1';
        // Exact builds can share an RC label. Reuse the entrypoint's bounded
        // provenance digest so an installed correction cannot keep old UI JS.
        if ( defined( 'MAD4B_SCP_BOOT_PROVENANCE_SHA256' )
            && preg_match( '/^[a-f0-9]{64}$/D', (string) MAD4B_SCP_BOOT_PROVENANCE_SHA256 ) )
            $version .= '-' . substr( (string) MAD4B_SCP_BOOT_PROVENANCE_SHA256, 0, 12 );
        wp_enqueue_style( 'mad4b-plugin-update-dialog',
            plugins_url( 'assets/plugin-update-dialog.css', MAD4B_SCP_FILE ), array(), $version );
        wp_enqueue_script( 'mad4b-plugin-update-dialog',
            plugins_url( 'assets/plugin-update-dialog.js', MAD4B_SCP_FILE ), array(), $version, true );
        wp_localize_script( 'mad4b-plugin-update-dialog', 'MAD4BUpdateDialog', array(
            'checking' => __( 'Checking source and package eligibility...', 'mad4b-site-control-plane' ),
            'changed' => __( 'Source changed. Preview again to check the current selection.', 'mad4b-site-control-plane' ),
            'timeout' => __( 'The preview timed out. Check your connection and preview again.', 'mad4b-site-control-plane' ),
            'eligible' => __( 'Eligible for separate approval', 'mad4b-site-control-plane' ),
            'needsReview' => __( 'Needs review', 'mad4b-site-control-plane' ),
            'notAuthorized' => __( 'Not authorized', 'mad4b-site-control-plane' ),
            'blocked' => __( 'Blocked', 'mad4b-site-control-plane' ),
            'operation' => __( 'Operation', 'mad4b-site-control-plane' ),
            'commit' => __( 'Exact commit', 'mad4b-site-control-plane' ),
            'plugin' => __( 'Plugin', 'mad4b-site-control-plane' ),
            'guidance' => __( 'Open Plugin Deployment to review the missing certificate, site permissions or source evidence before requesting approval.', 'mad4b-site-control-plane' ),
            'details' => __( 'Technical details', 'mad4b-site-control-plane' ),
            'previewOnly' => __( 'Preview only. Installation requires a separately certified ZIP and exact approval.', 'mad4b-site-control-plane' ),
            'failed' => __( 'Preview could not be completed. Use Open Plugin Deployment for a full review.', 'mad4b-site-control-plane' ),
        ) );
    }

    private static function info( $term, $description ) {
        echo '<div class="mad4b-ud-meta"><dt>' . esc_html( $term ) . '</dt><dd>'
            . esc_html( (string) $description ) . '</dd></div>';
    }

    public static function render() {
        if ( ! self::can_inspect() || ! self::$seen ) return;
        foreach ( self::$seen as $id => $entry ) {
            // Render against the current WordPress environment. A previously
            // observed Staging row must never expose the Staging source form
            // after the site's environment classification changes.
            $entry['environment'] = function_exists( 'wp_get_environment_type' )
                ? wp_get_environment_type() : 'unknown';
            $core = 'core' === $entry['kind'];
            echo '<dialog class="mad4b-ud-dialog" id="' . esc_attr( $id )
                . '" aria-labelledby="' . esc_attr( $id . '-title' )
                . '" aria-describedby="' . esc_attr( $id . '-description' ) . '">';
            echo '<div class="mad4b-ud-header"><div><p class="mad4b-ud-eyebrow">'
                . esc_html( $core ? __( 'MAD4B verified release channel', 'mad4b-site-control-plane' ) : __( 'Reviewed plugin source', 'mad4b-site-control-plane' ) )
                . '</p><h2 id="' . esc_attr( $id . '-title' ) . '">'
                . esc_html( $entry['name'] ) . '</h2></div>';
            echo '<button type="button" class="button mad4b-ud-dismiss" data-mad4b-dialog-close aria-label="' . esc_attr__( 'Close update review', 'mad4b-site-control-plane' ) . '">&times;</button></div>';
            echo '<p id="' . esc_attr( $id . '-description' ) . '" class="mad4b-ud-intro">'
                . esc_html( $core
                    ? __( 'Inspect the release source and request a verified update. Checking does not install anything.', 'mad4b-site-control-plane' )
                    : __( 'Inspect installed state and reviewed Git source. A candidate is not an approved install.', 'mad4b-site-control-plane' ) )
                . '</p>';
            echo '<dl class="mad4b-ud-details">';
            self::info( __( 'Installed version', 'mad4b-site-control-plane' ), $core ? $entry['version'] :
                ( '' !== $entry['installed_version'] ? $entry['installed_version'] : __( 'Not determined', 'mad4b-site-control-plane' ) ) );
            self::info( __( 'WordPress environment', 'mad4b-site-control-plane' ), $entry['environment'] );
            if ( $core ) {
                self::info( __( 'Update status', 'mad4b-site-control-plane' ), __( 'Not checked on this page; open the certified channel.', 'mad4b-site-control-plane' ) );
                self::info( __( 'Source', 'mad4b-site-control-plane' ), __( 'Signed MAD4B release pointer, not arbitrary GitHub ZIP.', 'mad4b-site-control-plane' ) );
            } else {
                self::info( __( 'GitHub repository', 'mad4b-site-control-plane' ), $entry['repository'] );
                self::info( __( 'Source review', 'mad4b-site-control-plane' ), $entry['review'] );
                self::info( __( 'ZIP certificate', 'mad4b-site-control-plane' ), $entry['certificate'] );
            }
            echo '</dl><div class="mad4b-ud-actions">';
            echo '<a class="button button-secondary" href="' . esc_url( $entry['target'] ) . '">'
                . esc_html( $core ? __( 'Open Runtime Update', 'mad4b-site-control-plane' ) : __( 'Open Plugin Deployment', 'mad4b-site-control-plane' ) ) . '</a>';
            echo '<button type="button" class="button" data-mad4b-dialog-close>' . esc_html__( 'Close', 'mad4b-site-control-plane' ) . '</button></div>';
            if ( $core ) {
                echo '<div class="mad4b-ud-separator"></div><h3>' . esc_html__( 'Check official MAD4B release', 'mad4b-site-control-plane' ) . '</h3>';
                echo '<p class="description">' . esc_html__( 'An explicit nonce-protected check can contact the signed release channel. It does not install any plugin.', 'mad4b-site-control-plane' ) . '</p>';
                echo '<form class="mad4b-ud-form" method="post" action="'
                    . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="mad4b_control_plane_refresh_update">';
                wp_nonce_field( 'mad4b_control_plane_refresh_update' );
                echo '<button class="button button-primary" type="submit">' . esc_html__( 'Check certified updates', 'mad4b-site-control-plane' ) . '</button></form>';
                if ( 'staging' === $entry['environment'] && current_user_can( 'manage_options' ) ) {
                    echo '<div class="mad4b-ud-separator"></div><h3>' . esc_html__( 'Preview Staging branch or commit', 'mad4b-site-control-plane' ) . '</h3>';
                    echo '<p class="description">' . esc_html__( 'The exact Git source is resolved and validated after submission. A preview does not install it.', 'mad4b-site-control-plane' ) . '</p>';
                    echo '<form class="mad4b-ud-form" method="post" action="'
                        . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                    echo '<input type="hidden" name="action" value="mad4b_staging_source_preview">';
                    wp_nonce_field( 'mad4b_staging_source_preview' );
                    echo '<label>' . esc_html__( 'Source type', 'mad4b-site-control-plane' ) . '<select name="source_type" data-mad4b-source-select>'
                        . '<option value="branch">' . esc_html__( 'Branch HEAD', 'mad4b-site-control-plane' ) . '</option>'
                        . '<option value="branch_commit">' . esc_html__( 'Specific commit in branch', 'mad4b-site-control-plane' ) . '</option>'
                        . '<option value="commit">' . esc_html__( 'Exact commit SHA', 'mad4b-site-control-plane' ) . '</option>'
                        . '<option value="pull_request">' . esc_html__( 'PR HEAD', 'mad4b-site-control-plane' ) . '</option></select></label>';
                    echo '<label data-mad4b-source-field="branch">' . esc_html__( 'Branch name', 'mad4b-site-control-plane' )
                        . '<input class="regular-text" name="branch" maxlength="120" placeholder="feature/review"></label>';
                    echo '<label data-mad4b-source-field="commit">' . esc_html__( 'Commit SHA', 'mad4b-site-control-plane' )
                        . '<input class="regular-text" name="commit" maxlength="40" placeholder="' . esc_attr__( '40-character SHA', 'mad4b-site-control-plane' ) . '"></label>';
                    echo '<label data-mad4b-source-field="reference">' . esc_html__( 'PR number', 'mad4b-site-control-plane' )
                        . '<input class="regular-text" name="reference" maxlength="9" inputmode="numeric"></label>';
                    echo '<label>' . esc_html__( 'Reason', 'mad4b-site-control-plane' )
                        . '<input class="regular-text" name="reason" minlength="3" maxlength="500" required></label>';
                    echo '<button class="button button-secondary" type="submit">' . esc_html__( 'Preview this source', 'mad4b-site-control-plane' ) . '</button></form>';
                }
            } else {
                echo '<div class="mad4b-ud-separator"></div><h3>' . esc_html__( 'Preview exact source', 'mad4b-site-control-plane' ) . '</h3>';
                echo '<p class="description">' . esc_html__( 'This read-only request resolves a Git reference against its reviewed repository; it cannot install a ZIP.', 'mad4b-site-control-plane' ) . '</p>';
                echo '<form class="mad4b-ud-form" data-mad4b-async-preview data-mad4b-ajax-url="'
                    . esc_url( admin_url( 'admin-ajax.php' ) ) . '" method="post" action="'
                    . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="mad4b_cross_plugin_preview">';
                echo '<input type="hidden" name="source_id" value="' . esc_attr( $entry['id'] ) . '">';
                wp_nonce_field( 'mad4b_cross_plugin_preview' );
                echo '<label>' . esc_html__( 'Git reference type', 'mad4b-site-control-plane' ) . '<select name="ref_type">'
                    . '<option value="commit">' . esc_html__( 'Exact commit SHA', 'mad4b-site-control-plane' ) . '</option><option value="branch">' . esc_html__( 'Branch HEAD', 'mad4b-site-control-plane' ) . '</option>'
                    . '<option value="pull_request">' . esc_html__( 'Pull Request', 'mad4b-site-control-plane' ) . '</option><option value="branch_commit">' . esc_html__( 'Branch@SHA', 'mad4b-site-control-plane' ) . '</option></select></label>';
                echo '<label>' . esc_html__( 'Reference', 'mad4b-site-control-plane' ) . '<input name="reference" class="regular-text" maxlength="161" required'
                    . ' placeholder="' . esc_attr__( 'branch, PR number, or 40-character SHA', 'mad4b-site-control-plane' ) . '"></label>';
                echo '<label>' . esc_html__( 'Reason', 'mad4b-site-control-plane' ) . '<input name="reason" class="regular-text" minlength="3" maxlength="500" required></label>';
                echo '<button class="button button-primary" type="submit">' . esc_html__( 'Preview certified source', 'mad4b-site-control-plane' ) . '</button>'
                    . '<div class="mad4b-ud-preview-result" data-mad4b-preview-result role="status" aria-live="polite" aria-atomic="true" hidden></div></form>';
            }
            echo '<p class="mad4b-ud-footnote">' . esc_html__( 'No automatic install. Staging and Production authorizations remain separate; the installer verifies provenance, permissions, backup and readback.', 'mad4b-site-control-plane' ) . '</p></dialog>';
        }
    }
}
