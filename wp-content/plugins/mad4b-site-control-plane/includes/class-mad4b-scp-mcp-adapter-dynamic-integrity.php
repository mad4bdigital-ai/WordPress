<?php
/**
 * Read-only release compatibility for newly updated official MCP Adapter.
 *
 * Missing a static profile is not a runtime failure. A privileged recovery
 * independently validates the installed package against its HTTPS
 * WordPress.org distribution, without adopting a checksum of arbitrary
 * installed code as trusted release evidence.
 */
defined( 'ABSPATH' ) || exit;

final class MAD4B_SCP_MCP_Adapter_Dynamic_Integrity {
    const CONTRACT = 'mad4b.mcp-adapter-dynamic-release-integrity.v1';
    const CACHE_OPTION = 'mad4b_mcp_adapter_dynamic_release_proof_v1';

    private static function root( $plugin_file ) {
        $plugin_file = str_replace( '\\', '/', trim( (string) $plugin_file ) );
        if ( 1 !== preg_match( '#^[a-z0-9._-]+/mcp-adapter\.php$#iD', $plugin_file ) ) return false;
        $base = realpath( WP_PLUGIN_DIR );
        $main = realpath( trailingslashit( WP_PLUGIN_DIR ) . $plugin_file );
        if ( ! $base || ! $main || ! is_file( $main ) || is_link( $main ) ) return false;
        if ( 0 !== strpos( wp_normalize_path( $main ), rtrim( wp_normalize_path( $base ), '/' ) . '/' ) ) return false;
        return dirname( $main );
    }

    private static function installed_hashes( $root, $names ) {
        $result = array();
        $root = rtrim( wp_normalize_path( $root ), '/' );
        foreach ( $names as $relative ) {
            if ( ! is_string( $relative ) || '' === $relative || false !== strpos( $relative, '..' )
                || 1 !== preg_match( '#^[A-Za-z0-9._/\-]+$#D', $relative ) ) return false;
            $file = realpath( $root . '/' . $relative );
            if ( ! $file || ! is_file( $file ) || ! is_readable( $file )
                || 0 !== strpos( wp_normalize_path( $file ), $root . '/' ) ) return false;
            $sha = hash_file( 'sha256', $file );
            if ( ! is_string( $sha ) ) return false;
            $result[ $relative ] = strtolower( $sha );
        }
        ksort( $result );
        return $result;
    }

    /**
     * A read-only local layout check never claims upstream provenance.
     * HTTPS source comparison is only performed on a separate authorized
     * maintenance request with verify_official_release=true.
     */
    public static function inspect( $plugin_file, $version, $verify_official_release = false ) {
        $version = trim( (string ) $version );
        $out = array(
            'contract' => self::CONTRACT,
            'state' => 'inspection',
            'ready' => false,
            'profile_present' => false,
            'source_verified' => false,
            'official_package_verified' => false,
            'runtime_write_authorized' => false,
            'expected_count' => 0,
            'verified_count' => 0,
            'mismatch_count' => 0,
            'mismatches' => array(),
            'advisories' => array( 'static_version_profile_absent_nonblocking' ),
            'installed_version' => $version,
            'external_fetch_performed' => false,
        );
        // PERMANENT UPDATE BEHAVIOR: no artificial major/minor/patch ceiling.
        // Future versions are checked against the installed plugin header and
        // actual runtime capabilities; a version string never grants authority.
        if ( strlen( $version ) > 80 ||
            1 !== preg_match( '/^(?:0|[1-9][0-9]*)(?:\\.(?:0|[1-9][0-9]*)){2}(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?(?:\\+[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D', $version ) ) {
            $out['state'] = 'invalid_installed_version';
            $out['mismatch_count'] = 1;
            return $out;
        }
        $root = self::root( $plugin_file );
        if ( ! $root ) {
            $out['state'] = 'plugin_identity_or_root_invalid';
            $out['mismatch_count'] = 1;
            return $out;
        }
        $main = $root . '/mcp-adapter.php';
        $header = function_exists( 'get_file_data' )
            ? get_file_data( $main, array( 'Version' => 'Version' ), 'plugin' ) : array();
        $from_disk = trim( (string) ( $header['Version'] ?? '' ) );
        if ( ! hash_equals( $version, $from_disk ) ) {
            $out['state'] = 'installed_version_identity_mismatch';
            $out['mismatch_count'] = 1;
            return $out;
        }
        $core = array(
            'mcp-adapter.php',
            'includes/Plugin.php',
            'includes/Core/McpAdapter.php',
            'includes/Core/McpServer.php',
        );
        $core_hashes = self::installed_hashes( $root, $core );
        if ( ! is_array( $core_hashes ) || count( $core_hashes ) !== count( $core ) ) {
            $out['state'] = 'runtime_core_file_missing';
            $out['mismatch_count'] = 1;
            return $out;
        }
        $out['expected_count'] = count( $core );
        $out['verified_count'] = count( $core );
        $out['ready'] = true;
        $out['state'] = 'updated_release_layout_ready_source_unverified';
        $out['layout_checked'] = true;

        $cached = get_option( self::CACHE_OPTION, array() );
        if ( is_array( $cached ) && ( $cached['version'] ?? '' ) === $version
            && ! empty( $cached['upstream_sha256'] )
            && ! empty( $cached['verified_hashes'] ) && is_array( $cached['verified_hashes'] )
            && (int) ( $cached['verified_at'] ?? 0 ) > 0 ) {
            $expected = $cached['verified_hashes'];
            $actual = self::installed_hashes( $root, array_keys( $expected ) );
            if ( ! is_array( $actual ) || $actual !== $expected ) {
                $out['ready'] = false;
                $out['state'] = 'official_package_file_mismatch';
                $out['mismatch_count'] = 1;
                $out['mismatches'][] = array( 'reason' => 'installed_files_differ_from_verified_official_release' );
                return $out;
            }
            $out['ready'] = true;
            $out['source_verified'] = true;
            $out['official_package_verified'] = true;
            $out['state'] = 'official_release_files_verified_dynamically';
            $out['expected_count'] = count( $expected );
            $out['verified_count'] = count( $actual );
            $out['upstream_sha256'] = $cached['upstream_sha256'];
            $out['verified_at'] = (int) $cached['verified_at'];
            $out['advisories'] = array();
            return $out;
        }
        if ( ! $verify_official_release ) return $out;

        if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
            && method_exists( 'MAD4B_SCP_MCP_Request_Scope', 'current_request_is_protocol_hotpath' )
            && MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) {
            $out['advisories'][] = 'external_package_check_not_allowed_on_mcp_hotpath';
            return $out;
        }
        if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'wp_remote_get' ) ) {
            $out['advisories'][] = 'official_package_verifier_unavailable';
            return $out;
        }
        $url = 'https://downloads.wordpress.org/plugin/mcp-adapter.' . $version . '.zip';
        $out['external_fetch_performed'] = true;
        $res = wp_remote_get( $url, array(
            'timeout' => 20,
            'redirection' => 2,
            'sslverify' => true,
            'limit_response_size' => 12000000,
        ) );
        if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
            $out['advisories'][] = 'official_package_not_available_for_dynamic_verification';
            return $out;
        }
        $zip_bytes = wp_remote_retrieve_body( $res );
        if ( ! is_string( $zip_bytes ) || strlen( $zip_bytes ) < 1000
            || strlen( $zip_bytes ) > 12000000 || substr( $zip_bytes, 0, 2 ) !== 'PK' ) {
            $out['advisories'][] = 'official_package_unusable';
            return $out;
        }
        $temporary = wp_tempnam( 'mad4b-mcp-official-' . $version . '.zip' );
        if ( ! $temporary ) {
            $out['advisories'][] = 'official_package_temporary_file_unavailable';
            return $out;
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ( false === file_put_contents( $temporary, $zip_bytes )
                || true !== ( $opened = $zip->open( $temporary ) ) ) {
                $out['advisories'][] = 'official_package_archive_invalid';
                return $out;
            }
            $files = array();
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $name = $zip->getNameIndex( $i );
                if ( ! is_string( $name ) || substr( $name, -1 ) === '/' ) continue;
                if ( 0 !== strpos( $name, 'mcp-adapter/' ) ) {
                    $out['state'] = 'official_package_unexpected_root';
                    $out['ready'] = false;
                    return $out;
                }
                $relative = substr( $name, 12 );
                if ( '' === $relative || false !== strpos( $relative, '..' )
                    || 1 !== preg_match( '#^[A-Za-z0-9._/\-]+$#D', $relative ) ) {
                    $out['state'] = 'official_package_invalid_entry';
                    $out['ready'] = false;
                    return $out;
                }
                $stream = $zip->getStream( $name );
                if ( ! is_resource( $stream ) ) {
                    $out['state'] = 'official_package_unreadable_entry';
                    $out['ready'] = false;
                    return $out;
                }
                $ctx = hash_init( 'sha256' );
                hash_update_stream( $ctx, $stream );
                fclose( $stream );
                $files[ $relative ] = hash_final( $ctx );
            }
            if ( count( $files ) < 20 || count( $files ) > 2500 ) {
                $out['state'] = 'official_package_member_count_invalid';
                $out['ready'] = false;
                return $out;
            }
            ksort( $files );
            $observed = self::installed_hashes( $root, array_keys( $files ) );
            if ( ! is_array( $observed ) || $observed !== $files ) {
                $out['ready'] = false;
                $out['state'] = 'official_package_file_mismatch';
                $out['mismatch_count'] = 1;
                $out['mismatches'][] = array( 'reason' => 'installed_files_differ_from_official_download' );
                return $out;
            }
            $receipt = array(
                'version' => $version,
                'upstream_sha256' => hash( 'sha256', $zip_bytes ),
                'verified_hashes' => $files,
                'verified_at' => time(),
            );
            if ( ! update_option( self::CACHE_OPTION, $receipt, false )
                && get_option( self::CACHE_OPTION, array() ) !== $receipt ) {
                $out['advisories'][] = 'verified_release_cache_write_failed';
                return $out;
            }
            $out['source_verified'] = true;
            $out['official_package_verified'] = true;
            $out['state'] = 'official_release_files_verified_dynamically';
            $out['expected_count'] = count( $files );
            $out['verified_count'] = count( $observed );
            $out['upstream_sha256'] = $receipt['upstream_sha256'];
            $out['verified_at'] = $receipt['verified_at'];
            $out['advisories'] = array();
            return $out;
        } finally {
            if ( $opened === true ) $zip->close();
            @unlink( $temporary );
        }
    }
}
