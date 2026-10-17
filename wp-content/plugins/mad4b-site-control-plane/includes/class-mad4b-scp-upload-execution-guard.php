<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only, provider-neutral upload execution guard.
 * NEVER treats a local file or a web server hint as enforcement attestation.
 */
final class MAD4B_SCP_Upload_Execution_Guard {
    const CONTRACT = 'mad4b.upload-execution-guard.v1';
    const DISCOVER_ABILITY = 'mad4b/upload-guard-discover';
    const PLAN_ABILITY = 'mad4b/upload-guard-policy-plan';
    private static $booted = false;
    private static $attempted = false;
    private static $registered = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        if ( function_exists( 'add_action' ) )
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 45 );
    }

    public static function can_read() {
        return self::$registered
            && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && function_exists( 'get_current_user_id' )
            && class_exists( 'MAD4B_SCP_Policy', false )
            && true === MAD4B_SCP_Policy::can_read()
            && true === MAD4B_SCP_Policy::can_connect_user( get_current_user_id() )
            && class_exists( 'MAD4B_SCP_Site_Profile', false )
            && MAD4B_SCP_Site_Profile::configured()
            && MAD4B_SCP_Site_Profile::origin_enrolled()
            && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
    }

    public static function register_abilities() {
        if ( self::$attempted || ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) return;
        self::$attempted = true;
        if ( wp_has_ability( self::DISCOVER_ABILITY ) || wp_has_ability( self::PLAN_ABILITY ) ) return;
        $success = true;
        foreach ( array(
            self::DISCOVER_ABILITY => array( 'Upload Execution Guard Discovery', 'discover' ),
            self::PLAN_ABILITY => array( 'Upload Execution Guard Policy Plan', 'policy_plan' ),
        ) as $name => $definition ) {
            $r = wp_register_ability( $name, array(
                'label' => $definition[0],
                'description' => 'Bounded, non-authorizing upload root inspection. No file writes, network probes or server configuration changes.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $definition[1] ),
                'permission_callback' => array( __CLASS__, 'can_read' ),
                'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read', 'non_authorizing' => true ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                ),
            ) );
            if ( ! is_object( $r ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $r ) ) || ! wp_has_ability( $name ) )
                $success = false;
        }
        self::$registered = $success;
    }

    private static function check( $input ) {
        if ( ! is_array( $input ) || ! empty( $input ) )
            return new WP_Error( 'mad4b_upload_guard_invalid_input', 'No arbitrary upload paths are accepted.' );
        if ( ! self::can_read() )
            return new WP_Error( 'mad4b_upload_guard_read_denied', 'Governed Read and enrolled Site Profile are required.' );
        return true;
    }

    private static function result_base( $type ) {
        return array(
            'contract' => self::CONTRACT,
            'surface' => $type,
            'read_only' => true,
            'mutation_performed' => false,
            'network_requests_performed' => false,
            'host_commands_executed' => false,
            'direct_php_execution_verified_blocked' => false,
            'site_protected_certified' => false,
            'coverage' => 'current_wordpress_site_upload_root_only',
        );
    }

    private static function within( $path, $root ) {
        if ( ! is_string( $root ) || '' === $root ) return false;
        $a = realpath( $path ); $b = realpath( $root );
        if ( false === $a || false === $b ) return false;
        $b = rtrim( $b, '/\\' );
        return $a === $b || 0 === strpos( $a, $b . DIRECTORY_SEPARATOR );
    }

    private static function relpath( $path, $root ) {
        if ( ! self::within( $path, $root ) ) return null;
        $a = realpath( $path ); $b = rtrim( realpath( $root ), '/\\' );
        return $a === $b ? '.' : str_replace( DIRECTORY_SEPARATOR, '/', substr( $a, strlen( $b ) + 1 ) );
    }

    private static function symlink_in_upload_path( $path, $root ) {
        $prefix = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;
        if ( 0 !== strpos( $path, $prefix ) ) return true;
        $suffix = substr( $path, strlen( $prefix ) );
        $p = rtrim( $root, '/\\' );
        foreach ( explode( DIRECTORY_SEPARATOR, $suffix ) as $piece ) {
            if ( '' === $piece || '.' === $piece || '..' === $piece ) return true;
            $p .= DIRECTORY_SEPARATOR . $piece;
            if ( is_link( $p ) ) return true;
        }
        return false;
    }

    private static function same_origin( $url ) {
        if ( ! function_exists( 'home_url' ) || ! is_string( $url ) ) return null;
        $a = parse_url( home_url( '/' ) );
        $b = parse_url( $url );
        if ( ! is_array( $a ) || ! is_array( $b )
            || ! isset( $a['host'], $a['scheme'], $b['host'], $b['scheme'] ) ) return null;
        $s1 = strtolower( $a['scheme'] ); $s2 = strtolower( $b['scheme'] );
        if ( ! in_array( $s1, array( 'http', 'https' ), true ) || ! in_array( $s2, array( 'http', 'https' ), true ) )
            return null;
        $p1 = isset( $a['port'] ) ? (int) $a['port'] : ( 'https' === $s1 ? 443 : 80 );
        $p2 = isset( $b['port'] ) ? (int) $b['port'] : ( 'https' === $s2 ? 443 : 80 );
        return $s1 === $s2 && $p1 === $p2 && strtolower( $a['host'] ) === strtolower( $b['host'] );
    }

    private static function server_hint() {
        $v = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] )
            ? strtolower( substr( $_SERVER['SERVER_SOFTWARE'], 0, 180 ) ) : '';
        if ( false !== strpos( $v, 'litespeed' ) ) return 'litespeed_hint';
        if ( false !== strpos( $v, 'apache' ) ) return 'apache_hint';
        if ( false !== strpos( $v, 'nginx' ) ) return 'nginx_hint';
        if ( false !== strpos( $v, 'microsoft-iis' ) ) return 'iis_hint';
        return 'unknown';
    }

    private static function inventory() {
        // Do not create upload folders during discovery.
        $data = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false, false ) : null;
        if ( ! is_array( $data ) || ! empty( $data['error'] ) )
            return array( 'status' => 'UPLOAD_SETTINGS_UNAVAILABLE', 'blockers' => array( 'upload_directory_api_failed' ) );
        $path = isset( $data['basedir'] ) && is_string( $data['basedir'] ) ? $data['basedir'] : '';
        $url = isset( $data['baseurl'] ) && is_string( $data['baseurl'] ) ? $data['baseurl'] : '';
        $r = array(
            'status' => 'UNKNOWN',
            'site_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : null,
            'multisite' => function_exists( 'is_multisite' ) ? (bool) is_multisite() : null,
            'directory_exists' => false,
            'wordpress_content_relative_path' => null,
            'wordpress_root_relative_path' => null,
            'upload_url_same_origin' => null,
            'htaccess_present' => false,
            'htaccess_sha256' => null,
            'htaccess_marker_present' => false,
            'blockers' => array(),
        );
        if ( '' === $path || ! is_dir( $path ) ) {
            $r['status'] = 'UPLOAD_DIRECTORY_UNAVAILABLE';
            $r['blockers'][] = 'upload_directory_not_present';
            return $r;
        }
        $r['directory_exists'] = true;
        if ( is_link( $path ) ) {
            $r['status'] = 'SYMLINK_REQUIRES_HOST_REVIEW';
            $r['blockers'][] = 'upload_root_is_symlink';
            return $r;
        }
        $content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '';
        $r['wordpress_content_relative_path'] = self::relpath( $path, $content );
        $r['wordpress_root_relative_path'] = self::relpath( $path, ABSPATH );
        if ( null === $r['wordpress_content_relative_path'] ) {
            $r['status'] = 'UPLOADS_OUTSIDE_CONTENT_ROOT';
            $r['blockers'][] = 'nonstandard_upload_root_requires_host_mapping';
            return $r;
        }
        if ( self::symlink_in_upload_path( $path, $content ) ) {
            $r['status'] = 'UPLOAD_PATH_REQUIRES_HOST_REVIEW';
            $r['blockers'][] = 'upload_path_symlink_or_unmapped_parent';
            return $r;
        }
        $r['upload_url_same_origin'] = self::same_origin( $url );
        if ( false === $r['upload_url_same_origin'] ) {
            $r['status'] = 'REMOTE_OR_CDN_UPLOAD_URL';
            $r['blockers'][] = 'upload_delivery_separate_origin';
            return $r;
        }
        if ( null === $r['upload_url_same_origin'] ) {
            $r['status'] = 'UPLOAD_URL_UNVERIFIED';
            $r['blockers'][] = 'upload_url_origin_unavailable';
            return $r;
        }
        $policy = rtrim( $path, '/\\' ) . DIRECTORY_SEPARATOR . '.htaccess';
        if ( is_link( $policy ) ) {
            $r['status'] = 'HTACCESS_SYMLINK_REVIEW_REQUIRED';
            $r['blockers'][] = 'htaccess_is_symlink';
            return $r;
        }
        if ( is_file( $policy ) ) {
            $r['htaccess_present'] = true;
            if ( is_readable( $policy ) ) {
                $size = filesize( $policy );
                if ( ! is_int( $size ) || $size > 65536 ) $r['blockers'][] = 'htaccess_read_budget_exceeded';
                else {
                    $r['htaccess_sha256'] = hash_file( 'sha256', $policy );
                    if ( $size <= 16384 ) {
                        $sample = file_get_contents( $policy, false, null, 0, 16384 );
                        $r['htaccess_marker_present'] = is_string( $sample )
                            && false !== strpos( $sample, 'MAD4B-UPLOAD-EXECUTION-GUARD:v1' );
                    }
                }
            } else $r['blockers'][] = 'htaccess_not_readable';
        }
        $r['status'] = 'UNVERIFIED_NEEDS_ORIGIN_PROBE';
        return $r;
    }

    public static function discover( $input = array() ) {
        $v = self::check( $input );
        if ( true !== $v ) return $v;
        return self::result_base( 'discover' ) + array(
            'server_hint' => self::server_hint(),
            'server_hint_authoritative' => false,
            'uploads' => self::inventory(),
            'additional_upload_roots_inventoried' => false,
            'multisite_network_inventoried' => false,
            'external_origin_attestation' => 'NOT_RUN',
        );
    }

    public static function policy_plan( $input = array() ) {
        $v = self::check( $input );
        if ( true !== $v ) return $v;
        $root = self::inventory();
        $hint = self::server_hint();
        $route = 'HOST_ADAPTER_REQUIRED';
        if ( in_array( $hint, array( 'apache_hint', 'litespeed_hint' ), true ) )
            $route = 'HTACCESS_CANDIDATE_NEEDS_OVERRIDE_AND_HANDLER_VERIFICATION';
        elseif ( 'nginx_hint' === $hint ) $route = 'NGINX_HOST_CONFIG_REQUIRED';
        elseif ( 'iis_hint' === $hint ) $route = 'IIS_HOST_CONFIG_REQUIRED';
        if ( 'UNVERIFIED_NEEDS_ORIGIN_PROBE' !== $root['status'] || ! empty( $root['blockers'] ) )
            $route = 'HOST_MAPPING_REVIEW_REQUIRED';
        return self::result_base( 'policy_plan' ) + array(
            'server_hint' => $hint,
            'server_hint_authoritative' => false,
            'root_status' => $root['status'],
            'proposed_route' => $route,
            'proposed_action' => 'block_php_like_extensions_at_web_server_before_php_handler',
            'execution_authorized' => false,
            'config_mutation_available' => false,
            'staging_canary_required' => true,
            'outside_origin_http_probe_required' => true,
            'static_assets_readback_required' => true,
            'host_managed_backup_and_rollback_required' => true,
            'out_of_band_php_include_protection' => false,
            'warning' => 'A local .htaccess marker or server hint cannot certify enforcement; verify independently at origin.',
        );
    }
}
