<?php
/**
 * Bounded OPcache convergence before normal Control Plane PHP classes load.
 * Runs from the already-enrolled, site-bound managed MU bootstrap; never a grant
 * writer, updater, generic filesystem executor or a global OPcache reset.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Runtime_Cache_Convergence_MU {
    const CONTRACT = 'mad4b.runtime-cache-convergence.v1';
    const RECEIPT = 'mad4b_scp_runtime_cache_convergence_v1';
    const FILE_LIMIT = 5000;
    const BYTE_LIMIT = 536870912;

    public static function converge( $root, $environment, $site_uuid, $invalidation = null ) {
        $result = array(
            'contract' => self::CONTRACT, 'state' => 'blocked',
            'files_checked' => 0, 'php_invalidated' => 0,
            'next_request_required' => false, 'mutation_authorized' => false,
            'global_reset_used' => false,
        );
        // Cache coherence is environment-neutral. It is not a mutation
        // authority; the enrolled MU/host binding decides eligibility.
        if ( ! in_array( $environment, array( 'staging', 'local', 'development', 'production' ), true )
            || ! preg_match( '/^[a-f0-9-]{36}$/D', (string) $site_uuid )
            || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) )
            return $result;

        $root = realpath( $root );
        if ( false === $root || ! is_dir( $root ) || is_link( $root ) ) return $result;
        $manifest = $root . '/MAD4B-BUILD-PROVENANCE.json';
        if ( ! is_file( $manifest ) || is_link( $manifest ) || filesize( $manifest ) > 2097152 ) return $result;
        $data = json_decode( (string) file_get_contents( $manifest ), true );
        if ( ! is_array( $data ) || 'mad4b.build-provenance.v1' !== ( $data['contract'] ?? '' )
            || ! preg_match( '/^[a-f0-9]{40}$/D', (string) ( $data['source_commit_sha'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $data['package_manifest_digest'] ?? '' ) )
            || ! isset( $data['package_files'] ) || ! is_array( $data['package_files'] )
            || ! count( $data['package_files'] ) || count( $data['package_files'] ) > self::FILE_LIMIT
            || (int) ( $data['manifest_file_count'] ?? -1 ) !== count( $data['package_files'] ) )
            return $result;

        // Site and path are part of the receipt: an alternate webroot/tenant
        // can never reuse a successful convergence from another deployment.
        $binding = hash( 'sha256', $site_uuid . "\n" . $environment . "\n" . $root );
        $identity = hash( 'sha256', $binding . "\n" . $data['source_commit_sha'] . "\n" . $data['package_manifest_digest'] );
        $receipt = get_option( self::RECEIPT, null );
        if ( is_array( $receipt ) && self::CONTRACT === ( $receipt['contract'] ?? '' )
            && hash_equals( $identity, (string) ( $receipt['identity'] ?? '' ) ) ) {
            $result['state'] = 'current';
            return $result;
        }
        $canonical = '';
        $files = array();
        $total = 0;
        $seen = array();
        $prefix = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
        foreach ( $data['package_files'] as $row ) {
            if ( ! is_array( $row ) ) return $result;
            $relative = (string) ( $row['path'] ?? '' );
            $size = $row['bytes'] ?? -1;
            $digest = (string) ( $row['sha256'] ?? '' );
            if ( '' === $relative || strlen( $relative ) > 1024
                || preg_match( '/[\x00-\x1f]/', $relative )
                || false !== strpos( '/' . $relative . '/', '/../' )
                || false !== strpos( '/' . $relative . '/', '/./' )
                || '/' === $relative[0] || isset( $seen[ $relative ] )
                || ! is_int( $size ) || $size < 0
                || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) return $result;
            $seen[ $relative ] = true;
            $target = realpath( $prefix . $relative );
            if ( false === $target || is_link( $prefix . $relative ) || ! is_file( $target )
                || 0 !== strpos( str_replace( '\\', '/', $target ), $prefix ) ) return $result;
            $actual_size = filesize( $target );
            $total += $actual_size;
            if ( $actual_size !== $size || $total > self::BYTE_LIMIT
                || ! hash_equals( $digest, hash_file( 'sha256', $target ) ) ) return $result;
            $canonical .= $relative . "\0" . $size . "\0" . $digest . "\n";
            if ( 1 === preg_match( '/\\.php$/i', $relative ) ) $files[] = $target;
            $result['files_checked']++;
        }
        if ( ! hash_equals( $data['package_manifest_digest'], hash( 'sha256', $canonical ) )
            || ! $files ) return $result;

        $cache_disabled = false;
        if ( null === $invalidation ) {
            // A disabled opcode cache cannot hold an old executable generation.
            $cache_disabled = function_exists( 'opcache_get_status' )
                && false === opcache_get_status( false );
            if ( ! $cache_disabled ) {
                if ( ! function_exists( 'opcache_invalidate' ) && ! function_exists( 'wp_opcache_invalidate' ) )
                    return $result;
                $invalidation = static function ( $file ) {
                    return function_exists( 'wp_opcache_invalidate' )
                        ? false !== wp_opcache_invalidate( $file, true )
                        : false !== opcache_invalidate( $file, true );
                };
            }
        }
        if ( ! $cache_disabled ) {
            foreach ( $files as $file ) {
                if ( ! call_user_func( $invalidation, $file ) ) return $result;
                $result['php_invalidated']++;
            }
        }
        $record = array( 'contract' => self::CONTRACT, 'identity' => $identity );
        if ( ! update_option( self::RECEIPT, $record, false ) ) {
            // WordPress returns false for unchanged values; no such receipt existed.
            return $result;
        }
        $result['state'] = $cache_disabled ? 'current' : 'revalidated';
        $result['next_request_required'] = ! $cache_disabled; // Earlier required classes may be resident.
        return $result;
    }
}
