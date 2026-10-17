<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Security & Forensics Foundation v1. Uses existing Audit v2, Site Profile,
 * policy and WordPress Abilities. Never authorizes mutation or certifies cleanup.
 * Independent off-host observation and witnessed retention are separate systems.
 */
final class MAD4B_SCP_Security_Forensics {
    const CONTRACT = 'mad4b.security-forensics.foundation.v1';
    const MAX_FILES = 48;
    const MAX_ENTRIES = 192;
    const MAX_BYTES = 8388608;
    const MAX_FILE_BYTES = 2097152;
    const MAX_SECONDS = 0.25;
    private static $booted = false;
    private static $registered = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        if ( ! function_exists( 'add_action' ) ) return;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 45 );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 90 );
        // Native WordPress lifecycle hooks are observations, not proof that all
        // filesystem, SSH, WP-CLI or direct-DB mutations were captured.
        add_action( 'activated_plugin', array( __CLASS__, 'activated' ), 10, 2 );
        add_action( 'deactivated_plugin', array( __CLASS__, 'deactivated' ), 10, 2 );
        add_action( 'deleted_plugin', array( __CLASS__, 'deleted' ), 10, 2 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'upgraded' ), 10, 2 );
        add_action( 'switch_theme', array( __CLASS__, 'theme_switched' ), 10, 2 );
        add_action( 'set_user_role', array( __CLASS__, 'role_changed' ), 10, 3 );
        add_action( 'user_register', array( __CLASS__, 'user_created' ), 10, 1 );
        add_action( 'wp_login', array( __CLASS__, 'login_success' ), 10, 2 );
        add_action( 'updated_option', array( __CLASS__, 'setting_changed' ), 10, 3 );
    }

    public static function allowed() {
        return class_exists( 'MAD4B_SCP_Security_Diagnostics', false )
            && MAD4B_SCP_Security_Diagnostics::can_read();
    }

    private static function base( $surface ) {
        return array(
            'contract' => self::CONTRACT, 'surface' => $surface,
            'site_id' => function_exists( 'get_current_blog_id' ) ? (string) get_current_blog_id() : 'unknown',
            'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
            'request_id' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '',
            'evidence_id' => null, 'read_only' => true,
            'mutation_performed' => false, 'release_ready' => false,
            'site_clean_certified' => false, 'baseline_approved' => false,
            'external_witness_verified' => false, 'coverage_complete' => false
        );
    }

    public static function status( $input = array() ) {
        if ( ! self::allowed() || ! is_array( $input ) || $input ) return new WP_Error( 'mad4b_security_denied', 'Read-only security status is unavailable.' );
        $audit = class_exists( 'MAD4B_SCP_Audit', false ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
        return self::base( 'overview' ) + array(
            'security_posture' => 'not_certified',
            'audit_storage_ready' => ! empty( $audit['ready'] ),
            'file_baseline' => 'setup_required',
            'file_monitor' => 'on_demand_bounded_only',
            'evidence_mode' => 'not_configured',
            'remote_vault' => 'not_verified',
            'independent_watchdog' => 'not_verified',
            'production_mutation_authorized' => false
        );
    }

    /** Minimal event metadata only. Never store IP headers, usernames, secrets or option values. */
    private static function observe( $event, array $attributes = array() ) {
        if ( ! class_exists( 'MAD4B_SCP_Audit', false ) ) return;
        $source = defined( 'WP_CLI' ) && WP_CLI ? 'wp_cli'
            : ( defined( 'REST_REQUEST' ) && REST_REQUEST ? 'rest'
            : ( defined( 'DOING_CRON' ) && DOING_CRON ? 'cron'
            : ( function_exists( 'is_admin' ) && is_admin() ? 'admin' : 'unknown' ) ) );
        $uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $summary = array(
            'event' => $event,
            'source' => $source,
            'actor_id' => $uid > 0 ? $uid : null,
            'actor_attribution' => $uid > 0 ? 'authenticated_wp_context' : 'actor_unknown',
            'ssh_actor_inferred' => false,
            'authorization' => 'unverified',
            'host_ip' => null,
            'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
            'site_id' => function_exists( 'get_current_blog_id' ) ? (string) get_current_blog_id() : 'unknown',
            'source_hook' => 'wordpress_core',
            'external_evidence_retained' => false
        );
        foreach ( $attributes as $key => $value ) $summary[ $key ] = $value;
        // No alternative unauthenticated audit store: if Audit v2 is unavailable,
        // event capture is explicitly incomplete, not silently "successful".
        MAD4B_SCP_Audit::record( 'mad4b/security-forensics-event', $summary, 'observed' );
    }

    private static function safe_slug( $value ) {
        return is_string( $value ) ? substr( preg_replace( '/[^a-zA-Z0-9_\/.\-]/', '', $value ), 0, 180 ) : '';
    }
    public static function activated( $plugin, $network_wide = false ) {
        self::observe( 'plugin.activated', array( 'plugin' => self::safe_slug( $plugin ), 'network_wide' => (bool) $network_wide ) );
    }
    public static function deactivated( $plugin, $network_wide = false ) {
        self::observe( 'plugin.deactivated', array( 'plugin' => self::safe_slug( $plugin ), 'network_wide' => (bool) $network_wide ) );
    }
    public static function deleted( $plugin, $deleted = false ) {
        if ( $deleted ) self::observe( 'plugin.deleted', array( 'plugin' => self::safe_slug( $plugin ) ) );
    }
    public static function upgraded( $upgrader, $options = array() ) {
        if ( ! is_array( $options ) ) return;
        $type = isset( $options['type'] ) && in_array( $options['type'], array( 'plugin', 'theme', 'core' ), true )
            ? $options['type'] : 'unknown';
        $action = isset( $options['action'] ) && in_array( $options['action'], array( 'install', 'update' ), true )
            ? $options['action'] : 'unknown';
        if ( 'unknown' !== $type && 'unknown' !== $action )
            self::observe( $type . '.' . $action . '.completed', array( 'package_type' => $type ) );
    }
    public static function theme_switched( $new_name, $new_theme = null ) {
        self::observe( 'theme.switched', array( 'theme' => self::safe_slug( is_object( $new_theme ) && method_exists( $new_theme, 'get_stylesheet' ) ? $new_theme->get_stylesheet() : '' ) ) );
    }
    public static function role_changed( $user_id, $role = '', $old_roles = array() ) {
        self::observe( 'user.role_changed', array( 'subject_user_id' => (int) $user_id, 'role' => self::safe_slug( $role ) ) );
    }
    public static function user_created( $user_id ) {
        self::observe( 'user.created', array( 'subject_user_id' => (int) $user_id ) );
    }
    public static function login_success( $login, $user ) {
        self::observe( 'user.login', array( 'subject_user_id' => is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : null ) );
    }
    public static function setting_changed( $option, $old, $new ) {
        if ( in_array( $option, array( 'siteurl', 'home', 'default_role', 'users_can_register', 'active_plugins' ), true ) )
            self::observe( 'settings.changed', array( 'option_name' => $option, 'old_new_values_excluded' => true ) );
    }

    private static function validate_input( $input, $allow = array() ) {
        return is_array( $input ) && ! array_diff( array_keys( $input ), $allow );
    }

    /**
     * A small read-only sample, not a baseline, not full-site protection.
     * It includes plugins, MU plugins, themes, uploads, upgrade and root.
     * Never opens wp-config.php; hashes contents but returns no raw bytes.
     */
    public static function integrity_scan( $input = array() ) {
        if ( ! self::allowed() || ! self::validate_input( $input, array( 'scope' ) ) )
            return new WP_Error( 'mad4b_security_scan_denied', 'Read-only scan denied.' );
        $allowed = array( 'plugins', 'mu_plugins', 'themes', 'uploads', 'upgrade', 'root' );
        $scope = isset( $input['scope'] ) && is_string( $input['scope'] ) ? $input['scope'] : 'uploads';
        if ( ! in_array( $scope, $allowed, true ) )
            return new WP_Error( 'mad4b_security_scope_denied', 'Scope not allowlisted.' );
        $relative = array(
            'plugins' => 'wp-content/plugins', 'mu_plugins' => 'wp-content/mu-plugins',
            'themes' => 'wp-content/themes', 'uploads' => 'wp-content/uploads',
            'upgrade' => 'wp-content/upgrade', 'root' => ''
        );
        $root = realpath( ABSPATH );
        if ( ! $root ) return new WP_Error( 'mad4b_security_root_missing', 'WordPress root unavailable.' );
        $target = $relative[ $scope ] === '' ? $root : $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative[ $scope ] );
        if ( is_link( $target ) || ! is_dir( $target ) || ! is_readable( $target ) )
            return self::base( 'integrity_scan' ) + array( 'scope' => $scope, 'coverage' => 'unavailable', 'findings' => array() );
        $entries = 0; $files = 0; $bytes = 0; $items = array(); $incomplete = false;
        $started = microtime( true );
        $stack = array( array( $target, 0 ) );
        while ( $stack ) {
            if ( microtime( true ) - $started > self::MAX_SECONDS || $entries >= self::MAX_ENTRIES || $files >= self::MAX_FILES ) {
                $incomplete = true; break;
            }
            $task = array_pop( $stack ); $dir = $task[0]; $depth = $task[1];
            $listing = @scandir( $dir );
            if ( ! is_array( $listing ) ) { $incomplete = true; continue; }
            foreach ( $listing as $name ) {
                if ( '.' === $name || '..' === $name ) continue;
                if ( ++$entries > self::MAX_ENTRIES || microtime( true ) - $started > self::MAX_SECONDS ) { $incomplete = true; break 2; }
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                $rel = str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $root ) + 1 ) );
                if ( is_link( $path ) ) { $items[] = array( 'path' => $rel, 'kind' => 'symlink_unfollowed', 'severity' => 'review' ); continue; }
                if ( is_dir( $path ) ) {
                    if ( $depth < 3 ) $stack[] = array( $path, $depth + 1 ); else $incomplete = true;
                    continue;
                }
                if ( ! is_file( $path ) ) { $incomplete = true; continue; }
                if ( ++$files > self::MAX_FILES ) { $incomplete = true; break 2; }
                $size = @filesize( $path );
                if ( false === $size || $size > self::MAX_FILE_BYTES || $bytes + $size > self::MAX_BYTES ) {
                    $incomplete = true; continue;
                }
                $bytes += $size;
                $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
                // No copying source or returning sensitive configuration contents.
                $digest = @hash_file( 'sha256', $path );
                if ( ! is_string( $digest ) ) { $incomplete = true; continue; }
                $suspicious = ( in_array( $scope, array( 'uploads', 'upgrade' ), true )
                    && in_array( $ext, array( 'php', 'phtml', 'phar', 'php5', 'php7', 'php8' ), true ) );
                $items[] = array( 'path' => $rel, 'sha256' => $digest, 'bytes' => (int) $size,
                    'classification' => $suspicious ? 'suspicious_placement_unconfirmed' : 'unclassified',
                    'severity' => $suspicious ? 'high' : 'inventory' );
            }
        }
        return self::base( 'integrity_scan' ) + array(
            'scope' => $scope, 'scanned_files' => $files, 'scanned_bytes' => $bytes,
            'coverage' => $incomplete ? 'partial' : 'bounded_scope_only',
            'files' => $items, 'file_changes_certified' => false,
            'ssh_actor_attributed' => false, 'trusted_baseline_required' => true
        );
    }

    public static function events( $input = array() ) {
        if ( ! self::allowed() || ! self::validate_input( $input, array( 'limit' ) ) )
            return new WP_Error( 'mad4b_security_events_denied', 'Read denied.' );
        $limit = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
        if ( $limit < 1 || $limit > 50 ) return new WP_Error( 'mad4b_security_events_limit', 'Limit outside 1..50.' );
        $tail = class_exists( 'MAD4B_SCP_Audit', false ) ? MAD4B_SCP_Audit::tail( min( 200, $limit * 4 ) ) : array();
        $events = array();
        foreach ( $tail as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['ability'] ) || 'mad4b/security-forensics-event' !== $row['ability'] ) continue;
            // Return only the native signed chain envelope, not its arbitrary summary.
            $events[] = array( 'sequence' => isset( $row['sequence'] ) ? (int) $row['sequence'] : null,
                'entry_hash' => isset( $row['entry_hash'] ) ? $row['entry_hash'] : null,
                'ability' => $row['ability'] );
        }
        return self::base( 'events_query' ) + array( 'events' => array_slice( $events, -$limit ),
            'source' => 'mad4b_audit_v2', 'historical_coverage_certified' => false );
    }

    public static function incident( $input = array() ) {
        if ( ! self::allowed() || ! self::validate_input( $input ) ) return new WP_Error( 'mad4b_security_incident_denied', 'Read denied.' );
        return self::base( 'incident_report' ) + array(
            'state' => 'evidence_collection_required', 'actor' => 'unknown',
            'incident_confirmed' => false, 'cross_source_correlation' => 'not_configured',
            'required_sources' => array( 'mad4b_audit_v2', 'off_host_file_observer', 'independent_witness' )
        );
    }

    public static function baseline_plan( $input = array() ) {
        if ( ! self::allowed() || ! self::validate_input( $input ) ) return new WP_Error( 'mad4b_security_plan_denied', 'Read denied.' );
        return self::base( 'baseline_plan' ) + array(
            'plan_only' => true, 'approved' => false,
            'steps' => array( 'verify_site_identity', 'inspect_suspicious_files', 'compare_certified_source',
                'record_external_witness_checkpoint', 'obtain_owner_approval' ),
            'baseline_written' => false
        );
    }
    public static function policy_plan( $input = array() ) {
        if ( ! self::allowed() || ! self::validate_input( $input ) ) return new WP_Error( 'mad4b_security_plan_denied', 'Read denied.' );
        return self::base( 'policy_plan' ) + array(
            'plan_only' => true, 'production_quarantine_requires_explicit_owner_gate' => true,
            'governed_write_bypass_allowed' => false, 'developer_or_breakglass_granted' => false
        );
    }
    public static function quarantine_plan( $input = array() ) {
        if ( ! self::allowed() || ! self::validate_input( $input ) ) return new WP_Error( 'mad4b_security_plan_denied', 'Read denied.' );
        return self::base( 'quarantine_plan' ) + array(
            'plan_only' => true, 'apply_ability_registered' => false,
            'evidence_before_action_required' => true, 'owner_gate_required' => true
        );
    }

    public static function register_abilities() {
        if ( self::$registered || ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) return;
        $names = array(
            'mad4b/security-status' => array( 'status', array() ),
            'mad4b/security-integrity-scan' => array( 'integrity_scan', array(
                'scope' => array( 'type' => 'string', 'enum' => array( 'plugins', 'mu_plugins', 'themes', 'uploads', 'upgrade', 'root' ) ) ) ),
            'mad4b/security-events-query' => array( 'events', array( 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ) ) ),
            'mad4b/security-incident-report' => array( 'incident', array() ),
            'mad4b/security-baseline-plan' => array( 'baseline_plan', array() ),
            'mad4b/security-policy-plan' => array( 'policy_plan', array() ),
            'mad4b/security-quarantine-plan' => array( 'quarantine_plan', array() )
        );
        foreach ( array_keys( $names ) as $name ) if ( wp_has_ability( $name ) ) return;
        $ok = true;
        foreach ( $names as $name => $spec ) {
            $registered = wp_register_ability( $name, array(
                'label' => 'MAD4B Security & Forensics ' . $spec[0],
                'description' => 'Site-scoped read-only evidence or non-authorizing plan. No quarantine, cleanup or production acceptance.',
                'category' => 'mad4b-read', 'execute_callback' => array( __CLASS__, $spec[0] ),
                'permission_callback' => array( __CLASS__, 'allowed' ),
                'input_schema' => array( 'type' => 'object', 'properties' => $spec[1], 'additionalProperties' => false ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read', 'non_authorizing' => true ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) )
            ) );
            if ( ! is_object( $registered ) || is_wp_error( $registered ) || ! wp_has_ability( $name ) ) $ok = false;
        }
        self::$registered = $ok;
    }

    public static function menu() {
        if ( ! function_exists( 'add_submenu_page' ) ) return;
        add_submenu_page( 'mad4b-control-plane', 'Security & Forensics', 'Security & Forensics',
            'manage_options', 'mad4b-control-plane-security', array( __CLASS__, 'render' ) );
    }
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            if ( function_exists( 'wp_die' ) ) wp_die( esc_html__( 'Not authorized.', 'mad4b-site-control-plane' ) );
            return;
        }
        $sections = array( 'Overview', 'File Integrity', 'Activity Audit', 'Plugin Guardian',
            'Threat Scanner', 'Incidents', 'Evidence Vault', 'Policies' );
        echo '<div class="wrap"><h1>MAD4B Security &amp; Forensics</h1>';
        echo '<p><strong>Security posture: Not certified.</strong> Collection, independent witness and baseline approval are not configured.</p>';
        echo '<p>Read-only Foundation v1. Quarantine/apply and Production mutation are not available.</p>';
        echo '<nav class="nav-tab-wrapper" aria-label="Security Center sections">';
        $selected = isset( $_GET['section'] ) && is_string( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'overview';
        foreach ( $sections as $label ) {
            $key = sanitize_title( $label );
            $url = add_query_arg( array( 'page' => 'mad4b-control-plane-security', 'section' => $key ), admin_url( 'admin.php' ) );
            echo '<a class="nav-tab ' . ( $selected === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav><p>Current section: <strong>' . esc_html( $selected ) . '</strong>. Use governed MCP read abilities for bounded evidence. No scanner runs on page load.</p></div>';
    }
}
