<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded, non-authorizing, site-local security triage.
 *
 * This is an IOC/sample scanner, NOT an antivirus, external browser attestation,
 * core checksum verifier, incident cleanup operation, or proof of a clean site.
 * No HTTP, shell, option write, SQL mutation, repair, arbitrary path or raw
 * content leaves the WordPress process. All results are deliberately incomplete.
 */
final class MAD4B_SCP_Security_Diagnostics {
    const CONTRACT = 'mad4b.security-diagnostics.v1';
    const MAX_FILES = 120;
    const MAX_ENTRIES = 240;
    const MAX_SAMPLE_BYTES = 8192;
    const MAX_FINDINGS = 20;
    const MAX_DB_ROWS = 100;
    const MAX_DB_VALUE_BYTES = 4096;
    private static $booted = false;
    private static $attempted = false;
    private static $registered = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        if ( function_exists( 'add_action' ) ) {
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 44 );
        }
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
        $empty = array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
        $host = array( 'type' => 'string', 'minLength' => 4, 'maxLength' => 128,
            'pattern' => '^[a-zA-Z0-9][a-zA-Z0-9.-]*[a-zA-Z0-9]$' );
        $definitions = array(
            'mad4b/security-site-posture' => array( 'Security Site Posture (read only)', 'posture', $empty ),
            'mad4b/security-file-indicators' => array( 'Security File Indicator Sample (read only)', 'file_indicators',
                array( 'type' => 'object', 'properties' => array(
                    'scope' => array( 'type' => 'string',
                        'enum' => array( 'uploads', 'mu_plugins', 'plugins', 'themes', 'root' ) ),
                    'indicator_host' => $host,
                ), 'required' => array( 'scope' ), 'additionalProperties' => false ) ),
            'mad4b/security-file-integrity' => array( 'Security Fixed-File SHA256 Comparison (read only)', 'file_integrity',
                array( 'type' => 'object', 'properties' => array(
                    'target' => array( 'type' => 'string', 'enum' => array(
                        'index.php', 'wp-settings.php', '.htaccess', '.user.ini' ) ),
                    'expected_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}
                array( 'type' => 'object', 'properties' => array(
                    'surface' => array( 'type' => 'string', 'enum' => array( 'options', 'posts', 'postmeta' ) ),
                    'indicator_host' => $host,
                ), 'required' => array( 'surface' ), 'additionalProperties' => false ) ),
        );
        // A collision or partial registration cannot grant a usable callback.
        foreach ( array_keys( $definitions ) as $name ) if ( wp_has_ability( $name ) ) return;
        $ok = true;
        foreach ( $definitions as $name => $spec ) {
            $registered = wp_register_ability( $name, array(
                'label' => $spec[0],
                'description' => 'Bounded, redacted, non-authorizing WordPress security evidence; incomplete sample, not malware certification or remediation.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $spec[1] ),
                'permission_callback' => array( __CLASS__, 'can_read' ),
                'input_schema' => $spec[2],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read',
                        'non_authorizing' => true ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                ),
            ) );
            if ( ! is_object( $registered ) || is_wp_error( $registered ) || ! wp_has_ability( $name ) ) $ok = false;
        }
        self::$registered = $ok;
    }

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_security_' . $reason,
            'Security triage denied or unavailable. No scan or mutation was authorized.',
            array( 'read_only' => true, 'mutation_performed' => false ) );
    }

    private static function input( $input, $fields, $required = '' ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), $fields )
            || ( '' !== $required && ( ! isset( $input[ $required ] ) || ! is_string( $input[ $required ] ) ) ) )
            return self::error( 'invalid_input' );
        if ( isset( $input['indicator_host'] ) ) {
            $host = $input['indicator_host'];
            if ( ! is_string( $host ) || strlen( $host ) > 128 || strlen( $host ) < 4
                || ! preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])$/iD', $host )
                || false === strpos( $host, '.' ) || false !== strpos( $host, '..' ) )
                return self::error( 'invalid_indicator_host' );
        }
        if ( ! self::can_read() ) return self::error( 'read_permission_denied' );
        return true;
    }

    private static function base( $surface ) {
        return array(
            'contract' => self::CONTRACT,
            'surface' => $surface,
            'read_only' => true,
            'mutation_performed' => false,
            'network_requests_performed' => false,
            'shell_commands_executed' => false,
            'raw_content_returned' => false,
            'site_clean_certified' => false,
            'external_browser_verified' => false,
            'classification' => 'bounded_local_indicators_not_malware_proof',
        );
    }

    public static function posture( $input = array() ) {
        $valid = self::input( $input, array() );
        if ( is_wp_error( $valid ) ) return $valid;
        $plugins = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
        $updates = function_exists( 'get_site_transient' ) ? get_site_transient( 'update_plugins' ) : false;
        $update_count = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response )
            ? count( $updates->response ) : null;
        return self::base( 'site_posture' ) + array(
            'wordpress_version' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : null,
            'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
            'force_ssl_admin' => function_exists( 'force_ssl_admin' ) ? (bool) force_ssl_admin() : null,
            'file_editor_disabled' => defined( 'DISALLOW_FILE_EDIT' ) && true === DISALLOW_FILE_EDIT,
            'file_modifications_disabled' => defined( 'DISALLOW_FILE_MODS' ) && true === DISALLOW_FILE_MODS,
            'debug_display_enabled' => defined( 'WP_DEBUG_DISPLAY' ) && true === WP_DEBUG_DISPLAY,
            'active_plugin_count' => is_array( $plugins ) ? count( $plugins ) : null,
            'plugin_updates_reported' => $update_count,
            'core_checksum_verified' => false,
            'filesystem_full_coverage' => false,
            'database_full_coverage' => false,
            'hostinger_logs_inspected' => false,
            'next_safe_actions' => array(
                'inspect_security_file_indicators_by_fixed_scope',
                'inspect_security_database_indicators_by_fixed_surface',
                'compare_off_host_http_har_and_origin_html',
                'request_host_logs_and_independent_core_checksums',
            ),
        );
    }

    private static function indicators( $sample, $host ) {
        $flags = array();
        $rules = array(
            'script_tag' => '/<script\b/i',
            'dynamic_script_loader' => '/createElement\s*\(\s*["\x27]script["\x27]/i',
            'powershell_execution_reference' => '/\b(?:powershell(?:\.exe)?|Invoke-Expression|Invoke-RestMethod)\b/i',
            'blockchain_rpc_reference' => '/\b(?:eth_call|polygon-rpc|polygon\.technology)\b/i',
            'javascript_obfuscation_reference' => '/\b(?:atob|fromCharCode)\s*\(/i',
            'redirect_reference' => '/\b(?:location\.replace|window\.location\s*=)\b/i',
        );
        foreach ( $rules as $code => $pattern ) if ( preg_match( $pattern, $sample ) ) $flags[] = $code;
        if ( '' !== $host && false !== stripos( $sample, $host ) ) $flags[] = 'supplied_host_indicator';
        return $flags;
    }

    private static function within( $path, $root ) {
        $path = realpath( $path );
        $root = realpath( $root );
        return false !== $path && false !== $root
            && 0 === strpos( $path, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
    }

    private static function file_row( $path, $root, $scope, $host ) {
        if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) || ! self::within( $path, $root ) ) return null;
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $basename = strtolower( basename( $path ) );
        if ( ! in_array( $extension, array( 'php', 'phtml', 'phar', 'php5', 'js', 'html', 'htm', 'svg' ), true )
            && ! in_array( $basename, array( '.htaccess', '.user.ini' ), true ) ) return null;
        $sample = @file_get_contents( $path, false, null, 0, self::MAX_SAMPLE_BYTES );
        if ( ! is_string( $sample ) ) return null;
        $flags = self::indicators( $sample, $host );
        if ( 'uploads' === $scope && in_array( $extension, array( 'php', 'phtml', 'phar', 'php5' ), true ) )
            $flags[] = 'executable_file_in_uploads';
        if ( ! $flags ) return array( 'sample_bytes' => strlen( $sample ), 'finding' => null );
        $relative = substr( realpath( $path ), strlen( rtrim( realpath( $root ), DIRECTORY_SEPARATOR ) ) + 1 );
        return array(
            'sample_bytes' => strlen( $sample ),
            'finding' => array(
                'relative_file' => str_replace( DIRECTORY_SEPARATOR, '/', $relative ),
                'flags' => array_values( array_unique( $flags ) ),
                'sample_sha256' => hash( 'sha256', $sample ),
                'sample_truncated' => filesize( $path ) > strlen( $sample ),
            ),
        );
    }

    public static function file_indicators( $input = array() ) {
        $valid = self::input( $input, array( 'scope', 'indicator_host' ), 'scope' );
        if ( is_wp_error( $valid ) ) return $valid;
        $scope = $input['scope'];
        if ( ! in_array( $scope, array( 'uploads', 'mu_plugins', 'plugins', 'themes', 'root' ), true ) )
            return self::error( 'invalid_file_scope' );
        if ( ! defined( 'WP_CONTENT_DIR' ) || ! is_dir( WP_CONTENT_DIR ) ) return self::error( 'content_root_unavailable' );
        $host = isset( $input['indicator_host'] ) ? $input['indicator_host'] : '';
        $content = WP_CONTENT_DIR;
        $root = '';
        switch ( $scope ) {
            case 'root': $root = ABSPATH; break;
            case 'uploads':
                $info = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false, false ) : array();
                $root = is_array( $info ) && empty( $info['error'] ) && isset( $info['basedir'] ) ? $info['basedir'] : '';
                break;
            case 'plugins': $root = $content . '/plugins'; break;
            case 'themes': $root = $content . '/themes'; break;
            case 'mu_plugins': $root = $content . '/mu-plugins'; break;
        }
        // Uploads and extension scans must remain inside this WordPress content tree.
        if ( ! is_string( $root ) || '' === $root || ! is_dir( $root ) || is_link( $root )
            || ( 'root' !== $scope && ! self::within( $root, $content ) ) )
            return self::error( 'file_scope_unavailable' );
        $findings = array();
        $visited = 0; $sampled = 0; $bytes = 0; $truncated = false;
        try {
            if ( 'root' === $scope ) {
                $paths = array();
                foreach ( array( 'index.php', 'wp-settings.php', 'wp-config.php', '.htaccess', '.user.ini' ) as $file )
                    $paths[] = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . $file;
                $iterator = $paths;
            } else {
                $directory = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
                $iterator = new RecursiveIteratorIterator( $directory, RecursiveIteratorIterator::LEAVES_ONLY );
                $iterator->setMaxDepth( 4 );
            }
            foreach ( $iterator as $entry ) {
                if ( ++$visited > self::MAX_ENTRIES || $sampled >= self::MAX_FILES
                    || $bytes >= self::MAX_FILES * self::MAX_SAMPLE_BYTES
                    || count( $findings ) >= self::MAX_FINDINGS ) { $truncated = true; break; }
                $path = is_object( $entry ) ? $entry->getPathname() : $entry;
                $row = self::file_row( $path, $root, $scope, $host );
                if ( ! is_array( $row ) ) continue;
                ++$sampled; $bytes += $row['sample_bytes'];
                if ( $row['finding'] ) $findings[] = $row['finding'];
            }
        } catch ( Exception $e ) {
            return self::error( 'file_traversal_unavailable' );
        }
        return self::base( 'file_indicators' ) + array(
            'scope' => $scope, 'entries_visited' => min( $visited, self::MAX_ENTRIES ),
            'files_sampled' => $sampled, 'bytes_sampled' => $bytes,
            'max_file_sample_bytes' => self::MAX_SAMPLE_BYTES,
            'depth_limit' => 4, 'truncated' => $truncated,
            'findings' => $findings,
            'coverage' => 'fixed_scope_bounded_sample_only',
            'no_findings_means_clean' => false,
        );
    }

    /**
     * Compare one explicitly allowlisted WordPress-root file against a known
     * hash, or return its current hash to establish an external evidence chain.
     * A self-reported hash is NOT a trusted baseline or proof of uncompromised
     * core. Never return wp-config.php or another secret-bearing file.
     */
    public static function file_integrity( $input = array() ) {
        $valid = self::input( $input, array( 'target', 'expected_sha256' ), 'target' );
        if ( is_wp_error( $valid ) ) return $valid;
        $target = $input['target'];
        if ( ! in_array( $target, array( 'index.php', 'wp-settings.php', '.htaccess', '.user.ini' ), true ) )
            return self::error( 'invalid_integrity_target' );
        $expected = isset( $input['expected_sha256'] ) ? $input['expected_sha256'] : '';
        if ( '' !== $expected && ( ! is_string( $expected )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) )
            return self::error( 'invalid_integrity_digest' );
        $root = realpath( ABSPATH );
        if ( false === $root ) return self::error( 'integrity_root_unavailable' );
        $path = $root . DIRECTORY_SEPARATOR . $target;
        if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path )
            || ! self::within( $path, $root ) ) return self::error( 'integrity_target_unavailable' );
        $hash = @hash_file( 'sha256', $path );
        if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) )
            return self::error( 'integrity_hash_unavailable' );
        return self::base( 'file_integrity' ) + array(
            'target' => $target, 'sha256' => $hash,
            'compared_to_explicit_baseline' => '' !== $expected,
            'matches_supplied_baseline' => '' === $expected ? null : hash_equals( $expected, $hash ),
            'baseline_trust_verified' => false, 'core_checksum_verified' => false,
            'coverage' => 'single_allowlisted_file_only',
        );
    }

    public static function database_indicators( $input = array() ) {
        $valid = self::input( $input, array( 'surface', 'indicator_host' ), 'surface' );
        if ( is_wp_error( $valid ) ) return $valid;
        $surface = $input['surface'];
        $columns = array(
            'options' => array( 'options', 'option_id', 'option_name', 'option_value' ),
            'posts' => array( 'posts', 'ID', 'post_type', 'post_content' ),
            'postmeta' => array( 'postmeta', 'meta_id', 'meta_key', 'meta_value' ),
        );
        if ( ! isset( $columns[ $surface ] ) ) return self::error( 'invalid_database_surface' );
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) return self::error( 'database_unavailable' );
        list( $property, $id, $label, $value ) = $columns[ $surface ];
        $table = isset( $wpdb->$property ) ? $wpdb->$property : '';
        if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) )
            return self::error( 'database_table_invalid' );
        // No user-controlled SQL or search string, full data export, or mutations.
        $sql = 'SELECT ' . $id . ' AS record_id, ' . $label . ' AS record_label, LEFT('
            . $value . ', ' . self::MAX_DB_VALUE_BYTES . ') AS value_sample FROM ' . $table
            . ' ORDER BY ' . $id . ' DESC LIMIT ' . self::MAX_DB_ROWS;
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) return self::error( 'database_read_failed' );
        $findings = array(); $count = 0;
        $host = isset( $input['indicator_host'] ) ? $input['indicator_host'] : '';
        foreach ( $rows as $row ) {
            if ( ++$count > self::MAX_DB_ROWS ) break;
            if ( ! is_array( $row ) ) continue;
            $sample = isset( $row['value_sample'] ) && is_string( $row['value_sample'] ) ? $row['value_sample'] : '';
            $flags = self::indicators( $sample, $host );
            if ( ! $flags ) continue;
            if ( count( $findings ) >= self::MAX_FINDINGS ) break;
            $findings[] = array(
                'record_id' => isset( $row['record_id'] ) ? (int) $row['record_id'] : 0,
                'label_sha256' => hash( 'sha256', (string) ( $row['record_label'] ?? '' ) ),
                'flags' => $flags,
                'sample_sha256' => hash( 'sha256', $sample ),
            );
        }
        return self::base( 'database_indicators' ) + array(
            'table_class' => $surface, 'rows_sampled' => min( $count, self::MAX_DB_ROWS ),
            'row_limit' => self::MAX_DB_ROWS, 'value_sample_limit' => self::MAX_DB_VALUE_BYTES,
            'truncated_or_incomplete' => count( $rows ) >= self::MAX_DB_ROWS || count( $findings ) >= self::MAX_FINDINGS,
            'findings' => $findings, 'coverage' => 'latest_records_only',
            'no_findings_means_clean' => false,
        );
    }
}
 ),
                ), 'required' => array( 'target' ), 'additionalProperties' => false ) ),
            'mad4b/security-database-indicators' => array( 'Security Database Indicator Sample (read only)', 'database_indicators',
                array( 'type' => 'object', 'properties' => array(
                    'surface' => array( 'type' => 'string', 'enum' => array( 'options', 'posts', 'postmeta' ) ),
                    'indicator_host' => $host,
                ), 'required' => array( 'surface' ), 'additionalProperties' => false ) ),
        );
        // A collision or partial registration cannot grant a usable callback.
        foreach ( array_keys( $definitions ) as $name ) if ( wp_has_ability( $name ) ) return;
        $ok = true;
        foreach ( $definitions as $name => $spec ) {
            $registered = wp_register_ability( $name, array(
                'label' => $spec[0],
                'description' => 'Bounded, redacted, non-authorizing WordPress security evidence; incomplete sample, not malware certification or remediation.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $spec[1] ),
                'permission_callback' => array( __CLASS__, 'can_read' ),
                'input_schema' => $spec[2],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read',
                        'non_authorizing' => true ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                ),
            ) );
            if ( ! is_object( $registered ) || is_wp_error( $registered ) || ! wp_has_ability( $name ) ) $ok = false;
        }
        self::$registered = $ok;
    }

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_security_' . $reason,
            'Security triage denied or unavailable. No scan or mutation was authorized.',
            array( 'read_only' => true, 'mutation_performed' => false ) );
    }

    private static function input( $input, $fields, $required = '' ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), $fields )
            || ( '' !== $required && ( ! isset( $input[ $required ] ) || ! is_string( $input[ $required ] ) ) ) )
            return self::error( 'invalid_input' );
        if ( isset( $input['indicator_host'] ) ) {
            $host = $input['indicator_host'];
            if ( ! is_string( $host ) || strlen( $host ) > 128 || strlen( $host ) < 4
                || ! preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])$/iD', $host )
                || false === strpos( $host, '.' ) || false !== strpos( $host, '..' ) )
                return self::error( 'invalid_indicator_host' );
        }
        if ( ! self::can_read() ) return self::error( 'read_permission_denied' );
        return true;
    }

    private static function base( $surface ) {
        return array(
            'contract' => self::CONTRACT,
            'surface' => $surface,
            'read_only' => true,
            'mutation_performed' => false,
            'network_requests_performed' => false,
            'shell_commands_executed' => false,
            'raw_content_returned' => false,
            'site_clean_certified' => false,
            'external_browser_verified' => false,
            'classification' => 'bounded_local_indicators_not_malware_proof',
        );
    }

    public static function posture( $input = array() ) {
        $valid = self::input( $input, array() );
        if ( is_wp_error( $valid ) ) return $valid;
        $plugins = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
        $updates = function_exists( 'get_site_transient' ) ? get_site_transient( 'update_plugins' ) : false;
        $update_count = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response )
            ? count( $updates->response ) : null;
        return self::base( 'site_posture' ) + array(
            'wordpress_version' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : null,
            'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
            'force_ssl_admin' => function_exists( 'force_ssl_admin' ) ? (bool) force_ssl_admin() : null,
            'file_editor_disabled' => defined( 'DISALLOW_FILE_EDIT' ) && true === DISALLOW_FILE_EDIT,
            'file_modifications_disabled' => defined( 'DISALLOW_FILE_MODS' ) && true === DISALLOW_FILE_MODS,
            'debug_display_enabled' => defined( 'WP_DEBUG_DISPLAY' ) && true === WP_DEBUG_DISPLAY,
            'active_plugin_count' => is_array( $plugins ) ? count( $plugins ) : null,
            'plugin_updates_reported' => $update_count,
            'core_checksum_verified' => false,
            'filesystem_full_coverage' => false,
            'database_full_coverage' => false,
            'hostinger_logs_inspected' => false,
            'next_safe_actions' => array(
                'inspect_security_file_indicators_by_fixed_scope',
                'inspect_security_database_indicators_by_fixed_surface',
                'compare_off_host_http_har_and_origin_html',
                'request_host_logs_and_independent_core_checksums',
            ),
        );
    }

    private static function indicators( $sample, $host ) {
        $flags = array();
        $rules = array(
            'script_tag' => '/<script\b/i',
            'dynamic_script_loader' => '/createElement\s*\(\s*["\x27]script["\x27]/i',
            'powershell_execution_reference' => '/\b(?:powershell(?:\.exe)?|Invoke-Expression|Invoke-RestMethod)\b/i',
            'blockchain_rpc_reference' => '/\b(?:eth_call|polygon-rpc|polygon\.technology)\b/i',
            'javascript_obfuscation_reference' => '/\b(?:atob|fromCharCode)\s*\(/i',
            'redirect_reference' => '/\b(?:location\.replace|window\.location\s*=)\b/i',
        );
        foreach ( $rules as $code => $pattern ) if ( preg_match( $pattern, $sample ) ) $flags[] = $code;
        if ( '' !== $host && false !== stripos( $sample, $host ) ) $flags[] = 'supplied_host_indicator';
        return $flags;
    }

    private static function within( $path, $root ) {
        $path = realpath( $path );
        $root = realpath( $root );
        return false !== $path && false !== $root
            && 0 === strpos( $path, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
    }

    private static function file_row( $path, $root, $scope, $host ) {
        if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) || ! self::within( $path, $root ) ) return null;
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $basename = strtolower( basename( $path ) );
        if ( ! in_array( $extension, array( 'php', 'phtml', 'phar', 'php5', 'js', 'html', 'htm', 'svg' ), true )
            && ! in_array( $basename, array( '.htaccess', '.user.ini' ), true ) ) return null;
        $sample = @file_get_contents( $path, false, null, 0, self::MAX_SAMPLE_BYTES );
        if ( ! is_string( $sample ) ) return null;
        $flags = self::indicators( $sample, $host );
        if ( 'uploads' === $scope && in_array( $extension, array( 'php', 'phtml', 'phar', 'php5' ), true ) )
            $flags[] = 'executable_file_in_uploads';
        if ( ! $flags ) return array( 'sample_bytes' => strlen( $sample ), 'finding' => null );
        $relative = substr( realpath( $path ), strlen( rtrim( realpath( $root ), DIRECTORY_SEPARATOR ) ) + 1 );
        return array(
            'sample_bytes' => strlen( $sample ),
            'finding' => array(
                'relative_file' => str_replace( DIRECTORY_SEPARATOR, '/', $relative ),
                'flags' => array_values( array_unique( $flags ) ),
                'sample_sha256' => hash( 'sha256', $sample ),
                'sample_truncated' => filesize( $path ) > strlen( $sample ),
            ),
        );
    }

    public static function file_indicators( $input = array() ) {
        $valid = self::input( $input, array( 'scope', 'indicator_host' ), 'scope' );
        if ( is_wp_error( $valid ) ) return $valid;
        $scope = $input['scope'];
        if ( ! in_array( $scope, array( 'uploads', 'mu_plugins', 'plugins', 'themes', 'root' ), true ) )
            return self::error( 'invalid_file_scope' );
        if ( ! defined( 'WP_CONTENT_DIR' ) || ! is_dir( WP_CONTENT_DIR ) ) return self::error( 'content_root_unavailable' );
        $host = isset( $input['indicator_host'] ) ? $input['indicator_host'] : '';
        $content = WP_CONTENT_DIR;
        $root = '';
        switch ( $scope ) {
            case 'root': $root = ABSPATH; break;
            case 'uploads':
                $info = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false, false ) : array();
                $root = is_array( $info ) && empty( $info['error'] ) && isset( $info['basedir'] ) ? $info['basedir'] : '';
                break;
            case 'plugins': $root = $content . '/plugins'; break;
            case 'themes': $root = $content . '/themes'; break;
            case 'mu_plugins': $root = $content . '/mu-plugins'; break;
        }
        // Uploads and extension scans must remain inside this WordPress content tree.
        if ( ! is_string( $root ) || '' === $root || ! is_dir( $root ) || is_link( $root )
            || ( 'root' !== $scope && ! self::within( $root, $content ) ) )
            return self::error( 'file_scope_unavailable' );
        $findings = array();
        $visited = 0; $sampled = 0; $bytes = 0; $truncated = false;
        try {
            if ( 'root' === $scope ) {
                $paths = array();
                foreach ( array( 'index.php', 'wp-settings.php', 'wp-config.php', '.htaccess', '.user.ini' ) as $file )
                    $paths[] = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . $file;
                $iterator = $paths;
            } else {
                $directory = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
                $iterator = new RecursiveIteratorIterator( $directory, RecursiveIteratorIterator::LEAVES_ONLY );
                $iterator->setMaxDepth( 4 );
            }
            foreach ( $iterator as $entry ) {
                if ( ++$visited > self::MAX_ENTRIES || $sampled >= self::MAX_FILES
                    || $bytes >= self::MAX_FILES * self::MAX_SAMPLE_BYTES
                    || count( $findings ) >= self::MAX_FINDINGS ) { $truncated = true; break; }
                $path = is_object( $entry ) ? $entry->getPathname() : $entry;
                $row = self::file_row( $path, $root, $scope, $host );
                if ( ! is_array( $row ) ) continue;
                ++$sampled; $bytes += $row['sample_bytes'];
                if ( $row['finding'] ) $findings[] = $row['finding'];
            }
        } catch ( Exception $e ) {
            return self::error( 'file_traversal_unavailable' );
        }
        return self::base( 'file_indicators' ) + array(
            'scope' => $scope, 'entries_visited' => min( $visited, self::MAX_ENTRIES ),
            'files_sampled' => $sampled, 'bytes_sampled' => $bytes,
            'max_file_sample_bytes' => self::MAX_SAMPLE_BYTES,
            'depth_limit' => 4, 'truncated' => $truncated,
            'findings' => $findings,
            'coverage' => 'fixed_scope_bounded_sample_only',
            'no_findings_means_clean' => false,
        );
    }

    public static function database_indicators( $input = array() ) {
        $valid = self::input( $input, array( 'surface', 'indicator_host' ), 'surface' );
        if ( is_wp_error( $valid ) ) return $valid;
        $surface = $input['surface'];
        $columns = array(
            'options' => array( 'options', 'option_id', 'option_name', 'option_value' ),
            'posts' => array( 'posts', 'ID', 'post_type', 'post_content' ),
            'postmeta' => array( 'postmeta', 'meta_id', 'meta_key', 'meta_value' ),
        );
        if ( ! isset( $columns[ $surface ] ) ) return self::error( 'invalid_database_surface' );
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) return self::error( 'database_unavailable' );
        list( $property, $id, $label, $value ) = $columns[ $surface ];
        $table = isset( $wpdb->$property ) ? $wpdb->$property : '';
        if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) )
            return self::error( 'database_table_invalid' );
        // No user-controlled SQL or search string, full data export, or mutations.
        $sql = 'SELECT ' . $id . ' AS record_id, ' . $label . ' AS record_label, LEFT('
            . $value . ', ' . self::MAX_DB_VALUE_BYTES . ') AS value_sample FROM ' . $table
            . ' ORDER BY ' . $id . ' DESC LIMIT ' . self::MAX_DB_ROWS;
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) return self::error( 'database_read_failed' );
        $findings = array(); $count = 0;
        $host = isset( $input['indicator_host'] ) ? $input['indicator_host'] : '';
        foreach ( $rows as $row ) {
            if ( ++$count > self::MAX_DB_ROWS ) break;
            if ( ! is_array( $row ) ) continue;
            $sample = isset( $row['value_sample'] ) && is_string( $row['value_sample'] ) ? $row['value_sample'] : '';
            $flags = self::indicators( $sample, $host );
            if ( ! $flags ) continue;
            if ( count( $findings ) >= self::MAX_FINDINGS ) break;
            $findings[] = array(
                'record_id' => isset( $row['record_id'] ) ? (int) $row['record_id'] : 0,
                'label_sha256' => hash( 'sha256', (string) ( $row['record_label'] ?? '' ) ),
                'flags' => $flags,
                'sample_sha256' => hash( 'sha256', $sample ),
            );
        }
        return self::base( 'database_indicators' ) + array(
            'table_class' => $surface, 'rows_sampled' => min( $count, self::MAX_DB_ROWS ),
            'row_limit' => self::MAX_DB_ROWS, 'value_sample_limit' => self::MAX_DB_VALUE_BYTES,
            'truncated_or_incomplete' => count( $rows ) >= self::MAX_DB_ROWS || count( $findings ) >= self::MAX_FINDINGS,
            'findings' => $findings, 'coverage' => 'latest_records_only',
            'no_findings_means_clean' => false,
        );
    }
}
