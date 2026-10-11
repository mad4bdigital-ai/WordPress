<?php
/**
 * Optional Staging-only, PHP/WordPress-native source packaging worker.
 *
 * Source bytes are fetched from one immutable GitHub commit/tree and checked
 * against each Git blob's identity. Nothing fetched is executed or installed.
 * A local ZIP is BUILT_UNVERIFIED, never a release/CI certificate.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_WP_Native_Build {
    const CONTRACT = 'mad4b.wp-native-source-build.v1';
    const OPTION = 'mad4b_wp_native_source_build_v1';
    const HOOK = 'mad4b_wp_native_source_build_tick';
    const PREFIX = 'mad4b/wp-native-build-';
    const CONFIRM = 'BUILD UNVERIFIED STAGING SOURCE';
    const REPO = 'mad4bdigital-ai/WordPress';
    const PLUGIN = 'wp-content/plugins/mad4b-site-control-plane';
    const MAX_FILES = 3500;
    const MAX_SOURCE_BYTES = 14680064;
    const MAX_BLOB_BYTES = 1048576;
    const MAX_ZIP_BYTES = 16777216;
    const BATCH = 4;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 38 );
        add_action( self::HOOK, array( __CLASS__, 'tick' ) );
    }

    private static function schema( $properties = array(), $required = array() ) {
        return array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => $properties, 'required' => $required );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $sha = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' );
        $defs = array(
            array( 'capabilities', 'Read bounded native build capabilities', 'capabilities', true, self::schema() ),
            array( 'plan', 'Plan Staging native source package', 'plan', true,
                self::schema( array( 'expected_head' => $sha ), array( 'expected_head' ) ) ),
            array( 'status', 'Read native build status', 'status', true, self::schema() ),
            array( 'export-chunk', 'Export owner-only bounded package chunk', 'export_chunk', true,
                self::schema( array( 'expected_head' => $sha,
                    'offset' => array( 'type' => 'integer', 'minimum' => 0 ) ),
                    array( 'expected_head', 'offset' ) ) ),
            array( 'start', 'Start opt-in Staging source package build', 'start', false,
                self::schema( array( 'expected_head' => $sha,
                    'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM ) ) ),
                    array( 'expected_head', 'expected_plan_sha256', 'confirmation' ) ) ),
        );
        foreach ( $defs as $def ) {
            $name = self::PREFIX . $def[0];
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            wp_register_ability( $name, array(
                'label' => $def[1], 'description' => 'Optional Staging-only PHP package worker; never runs downloaded source or certifies a release.',
                'category' => $def[3] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $def[2] ),
                'permission_callback' => $def[3] ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( __CLASS__, 'can_start' ),
                'input_schema' => $def[4],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool',
                        'surface' => $def[3] ? 'read' : 'admin',
                        'production_mutation_allowed' => false ),
                    'annotations' => array( 'readonly' => $def[3],
                        'destructive' => ! $def[3], 'idempotent' => $def[3] ) ),
            ) );
        }
    }

    private static function site() {
        return class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
    }

    private static function exact_staging() {
        $s = self::site();
        return is_array( $s ) && ( $s['environment'] ?? '' ) === 'staging'
            && ( $s['configured_environment'] ?? '' ) === 'staging'
            && ! empty( $s['authority_ready'] ) && ! empty( $s['origin_match'] )
            && ! empty( $s['site_uuid'] ) && ! empty( $s['profile_digest'] )
            && ! empty( $s['canonical_origin'] )
            && function_exists( 'wp_get_environment_type' )
            && 'staging' === wp_get_environment_type()
            && class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' )
            && MAD4B_SCP_WordPress_Native_Opt_In::enabled();
    }

    private static function private_root() {
        $tmp = realpath( sys_get_temp_dir() );
        $web = realpath( ABSPATH );
        $document = ! empty( $_SERVER['DOCUMENT_ROOT'] )
            ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
        if ( ! $tmp || ! is_dir( $tmp ) || ! is_writable( $tmp ) ) return false;
        foreach ( array( $web, $document ) as $root ) {
            if ( $root && ( $tmp === $root ||
                0 === strpos( $tmp, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) )
                return false;
        }
        return $tmp;
    }

    public static function capabilities( $input = array() ) {
        $root = self::private_root();
        $checks = array(
            'exact_staging_opt_in' => self::exact_staging(),
            'php_ziparchive' => class_exists( 'ZipArchive' ),
            'private_temp_outside_webroot' => false !== $root,
            'safe_https_client' => function_exists( 'wp_safe_remote_get' ),
            'wp_cron' => function_exists( 'wp_schedule_single_event' ),
            'source_runtime_execution_supported' => false,
            'independent_release_attestation_local' => false,
        );
        $blockers = array();
        foreach ( $checks as $k => $ok ) {
            if ( ! $ok && ! in_array( $k, array(
                'source_runtime_execution_supported', 'independent_release_attestation_local' ), true ) )
                $blockers[] = $k;
        }
        return array( 'contract' => self::CONTRACT . '.capabilities.v1',
            'eligible' => empty( $blockers ), 'checks' => $checks,
            'blockers' => $blockers, 'worker' => 'wordpress_php_cron',
            'build_state_on_success' => 'BUILT_UNVERIFIED', 'github_ci_required' => false,
            'external_native_tests_required' => true, 'independent_attestation_required' => true,
            'production_authorized' => false, 'read_only' => true );
    }

    public static function plan( $input = array() ) {
        $sha = $input['expected_head'] ?? '';
        if ( ! is_array( $input ) || count( $input ) !== 1 ||
            ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{40}$/D', $sha ) )
            return new WP_Error( 'mad4b_native_build_input', 'An exact commit SHA is required.' );
        $cap = self::capabilities();
        $site = self::site();
        $binding = array( 'contract' => self::CONTRACT, 'head' => $sha,
            'site_uuid' => $site['site_uuid'] ?? '', 'profile_digest' => $site['profile_digest'] ?? '',
            'origin' => $site['canonical_origin'] ?? '' );
        return array( 'contract' => self::CONTRACT . '.plan.v1',
            'source_commit_sha' => $sha,
            'plan_sha256' => hash( 'sha256', wp_json_encode( $binding ) ),
            'eligible' => $cap['eligible'], 'blockers' => $cap['blockers'],
            'execution' => 'wp_cron_bounded', 'source_executable_code_loaded' => false,
            'build_only' => true, 'release_certified' => false,
            'production_authorized' => false, 'authorizing' => false );
    }

    public static function can_start( $input = array() ) {
        if ( ! self::exact_staging() || ! function_exists( 'current_user_can' ) ||
            ! current_user_can( 'manage_options' ) ||
            ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) ||
            ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_mutate() )
            return new WP_Error( 'mad4b_native_owner_required', 'Enrolled Staging owner required.' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
                MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ||
            ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ||
            ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return new WP_Error( 'mad4b_native_step_up_required', 'Owner OAuth Step-up required.' );
        return MAD4B_SCP_Authorization::authorize_mutation(
            self::PREFIX . 'start', 'mad4b-admin', 'core', $input );
    }

    private static function root_for( $sha ) {
        $base = self::private_root();
        $site = self::site();
        return $base ? $base . DIRECTORY_SEPARATOR . 'mad4b-native-'
            . substr( hash( 'sha256', ( $site['site_uuid'] ?? '' ) . ':' . $sha ), 0, 28 ) : false;
    }

    public static function start( $input = array() ) {
        if ( ! is_array( $input ) ||
            array_diff( array_keys( $input ), array( 'expected_head', 'expected_plan_sha256', 'confirmation' ) ) ||
            ( $input['confirmation'] ?? '' ) !== self::CONFIRM )
            return new WP_Error( 'mad4b_native_start_input', 'Exact opt-in confirmation required.' );
        $plan = self::plan( array( 'expected_head' => $input['expected_head'] ?? '' ) );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! hash_equals( $plan['plan_sha256'], (string) ( $input['expected_plan_sha256'] ?? '' ) ) ||
            ! $plan['eligible'] ) return new WP_Error( 'mad4b_native_plan_stale', 'Plan changed or environment ineligible.' );
        $current = get_option( self::OPTION, array() );
        if ( is_array( $current ) && ( $current['expires_at'] ?? 0 ) < time() &&
            in_array( $current['state'] ?? '', array( 'DISCOVERING', 'FETCHING', 'PACKAGING' ), true ) ) {
            $current['state'] = 'BLOCKED';
            $current['blocker'] = 'job_deadline_expired';
            update_option( self::OPTION, $current, false );
        }
        if ( is_array( $current ) && in_array( $current['state'] ?? '', array(
            'DISCOVERING', 'FETCHING', 'PACKAGING' ), true ) )
            return new WP_Error( 'mad4b_native_build_busy', 'Existing build must finish or expire.' );
        $dir = self::root_for( $plan['source_commit_sha'] );
        if ( ! $dir || ( file_exists( $dir ) && is_link( $dir ) ) )
            return new WP_Error( 'mad4b_native_storage_unsafe', 'Safe private storage unavailable.' );
        if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) )
            return new WP_Error( 'mad4b_native_storage_failed', 'Cannot create private build workspace.' );
        @chmod( $dir, 0700 );
        $s = self::site();
        $job = array( 'state' => 'DISCOVERING', 'head' => $plan['source_commit_sha'],
            'plan_sha256' => $plan['plan_sha256'], 'site_uuid' => $s['site_uuid'],
            'profile_digest' => $s['profile_digest'], 'origin' => $s['canonical_origin'],
            'cursor' => 0, 'entries' => array(), 'total_bytes' => 0,
            'started_at' => time(), 'expires_at' => time() + 7200,
            'build_state' => 'NOT_RUN', 'production_authorized' => false );
        update_option( self::OPTION, $job, false );
        self::schedule();
        return array( 'contract' => self::CONTRACT . '.start.v1',
            'state' => 'DISCOVERING', 'head' => $job['head'],
            'execution' => 'wp_cron_bounded', 'release_certified' => false,
            'production_authorized' => false );
    }

    private static function schedule() {
        if ( function_exists( 'wp_schedule_single_event' ) &&
            ( ! function_exists( 'wp_next_scheduled' ) || ! wp_next_scheduled( self::HOOK ) ) )
            wp_schedule_single_event( time() + 3, self::HOOK );
    }

    private static function github( $suffix, $limit = 2097152 ) {
        $url = 'https://api.github.com/repos/' . self::REPO . '/' . $suffix;
        $r = wp_safe_remote_get( $url, array( 'timeout' => 12, 'redirection' => 0,
            'sslverify' => true, 'limit_response_size' => $limit + 1,
            'headers' => array( 'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'MAD4B-WP-Native-Source-Builder' ) ) );
        if ( is_wp_error( $r ) || (int) wp_remote_retrieve_response_code( $r ) !== 200 )
            return new WP_Error( 'mad4b_native_github_unavailable', 'Exact GitHub object unavailable.' );
        $body = wp_remote_retrieve_body( $r );
        if ( ! is_string( $body ) || strlen( $body ) > $limit )
            return new WP_Error( 'mad4b_native_github_oversize', 'GitHub response exceeded budget.' );
        $json = json_decode( $body, true );
        return is_array( $json ) ? $json :
            new WP_Error( 'mad4b_native_github_invalid', 'Malformed GitHub JSON.' );
    }

    private static function discover( &$job ) {
        $head = $job['head'];
        $commit = self::github( 'git/commits/' . $head, 400000 );
        if ( is_wp_error( $commit ) ) return $commit;
        if ( ( $commit['sha'] ?? '' ) !== $head ||
            ! preg_match( '/^[a-f0-9]{40}$/D', $commit['tree']['sha'] ?? '' ) )
            return new WP_Error( 'mad4b_native_commit_mismatch', 'Git commit identity mismatch.' );
        $tree = $commit['tree']['sha'];
        foreach ( explode( '/', self::PLUGIN ) as $part ) {
            $directory = self::github( 'git/trees/' . $tree, 300000 );
            if ( is_wp_error( $directory ) ) return $directory;
            $match = null;
            foreach ( $directory['tree'] ?? array() as $entry ) {
                if ( ( $entry['path'] ?? '' ) === $part && ( $entry['type'] ?? '' ) === 'tree' ) {
                    $match = $entry['sha'];
                    break;
                }
            }
            if ( ! is_string( $match ) || ! preg_match( '/^[a-f0-9]{40}$/D', $match ) )
                return new WP_Error( 'mad4b_native_subtree_missing', 'Plugin subtree not found.' );
            $tree = $match;
        }
        $listing = self::github( 'git/trees/' . $tree . '?recursive=1', 2000000 );
        if ( is_wp_error( $listing ) ) return $listing;
        if ( ! empty( $listing['truncated'] ) || ( $listing['sha'] ?? '' ) !== $tree )
            return new WP_Error( 'mad4b_native_tree_truncated', 'Exact plugin tree unavailable.' );
        $entries = array();
        $total = 0;
        $names = array();
        foreach ( $listing['tree'] ?? array() as $item ) {
            $name = $item['path'] ?? '';
            if ( ( $item['type'] ?? '' ) === 'tree' ) continue;
            if ( ( $item['type'] ?? '' ) !== 'blob' ||
                ! in_array( $item['mode'] ?? '', array( '100644', '100755' ), true ) ||
                ! is_string( $name ) || ! preg_match( '~^[A-Za-z0-9._+/-]+$~D', $name ) ||
                false !== strpos( '/' . $name . '/', '/../' ) ||
                false !== strpos( '/' . $name . '/', '/./' ) )
                return new WP_Error( 'mad4b_native_unsafe_entry', 'Nonregular or unsafe source path.' );
            if ( 0 === strpos( $name, 'tests/' ) || $name === 'MAD4B-BUILD-PROVENANCE.json' ) continue;
            $key = strtolower( $name );
            $size = $item['size'] ?? -1;
            if ( isset( $names[ $key ] ) || ! is_int( $size ) ||
                $size < 0 || $size > self::MAX_BLOB_BYTES ||
                ! preg_match( '/^[a-f0-9]{40}$/D', $item['sha'] ?? '' ) )
                return new WP_Error( 'mad4b_native_blob_invalid', 'Duplicate, unbounded or invalid source blob.' );
            $names[ $key ] = true;
            $total += $size;
            if ( count( $entries ) >= self::MAX_FILES || $total > self::MAX_SOURCE_BYTES )
                return new WP_Error( 'mad4b_native_budget_exceeded', 'Source file budget exceeded.' );
            $entries[] = array( 'path' => $name, 'sha' => $item['sha'],
                'bytes' => $size );
        }
        if ( empty( $entries ) )
            return new WP_Error( 'mad4b_native_source_empty', 'Plugin source contains no files.' );
        usort( $entries, static function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
        $job['entries'] = $entries;
        $job['total_bytes'] = $total;
        $job['cursor'] = 0;
        $job['state'] = 'FETCHING';
        return true;
    }

    private static function fetch_batch( &$job, $dir ) {
        $n = count( $job['entries'] );
        for ( $i = 0; $i < self::BATCH && $job['cursor'] < $n; $i++ ) {
            $entry = $job['entries'][ $job['cursor'] ];
            $blob = self::github( 'git/blobs/' . $entry['sha'], 1700000 );
            if ( is_wp_error( $blob ) ) return $blob;
            if ( ( $blob['encoding'] ?? '' ) !== 'base64' ||
                ( $blob['sha'] ?? '' ) !== $entry['sha'] ||
                ! is_string( $blob['content'] ?? null ) )
                return new WP_Error( 'mad4b_native_blob_untrusted', 'Blob encoding or identity changed.' );
            $raw = base64_decode( preg_replace( '/\s+/', '', $blob['content'] ), true );
            if ( ! is_string( $raw ) || strlen( $raw ) !== $entry['bytes'] ||
                ! hash_equals( $entry['sha'],
                    sha1( 'blob ' . strlen( $raw ) . "\0" . $raw ) ) )
                return new WP_Error( 'mad4b_native_blob_hash', 'Git blob content failed identity check.' );
            $dest = $dir . '/source/' . $entry['path'];
            $parent = dirname( $dest );
            if ( ! is_dir( $parent ) && ! @mkdir( $parent, 0700, true ) )
                return new WP_Error( 'mad4b_native_write_failed', 'Cannot stage source.' );
            if ( is_link( $dest ) || ( file_exists( $dest ) && ! is_file( $dest ) ) ||
                file_put_contents( $dest, $raw, LOCK_EX ) !== strlen( $raw ) )
                return new WP_Error( 'mad4b_native_write_failed', 'Failed to stage exact Git blob.' );
            @chmod( $dest, 0600 );
            $job['cursor']++;
        }
        if ( $job['cursor'] === $n ) $job['state'] = 'PACKAGING';
        return true;
    }

    private static function package( &$job, $dir ) {
        $rows = array();
        $canonical = '';
        $php_parsed = 0;
        $root = $dir . '/source/';
        foreach ( $job['entries'] as $entry ) {
            $path = $root . $entry['path'];
            if ( ! is_file( $path ) || is_link( $path ) ||
                filesize( $path ) !== $entry['bytes'] ||
                ! hash_equals( $entry['sha'], self::git_blob_hash( $path ) ) )
                return new WP_Error( 'mad4b_native_stage_drift', 'Staged Git source changed.' );
            if ( substr( $entry['path'], -4 ) === '.php' ) {
                if ( ! defined( 'TOKEN_PARSE' ) )
                    return new WP_Error( 'mad4b_native_php_parser_missing', 'PHP parser unavailable.' );
                try {
                    token_get_all( file_get_contents( $path ), TOKEN_PARSE );
                } catch ( ParseError $e ) {
                    return new WP_Error( 'mad4b_native_php_parse_error', 'Source PHP parse screening failed.' );
                }
                $php_parsed++;
            }
            $digest = hash_file( 'sha256', $path );
            $rows[] = array( 'path' => $entry['path'],
                'bytes' => $entry['bytes'], 'sha256' => $digest );
            $canonical .= $entry['path'] . "\0" . $entry['bytes'] . "\0" . $digest . "\n";
        }
        $manifest = hash( 'sha256', $canonical );
        $main = file_get_contents( $root . 'mad4b-site-control-plane.php' );
        if ( ! is_string( $main ) || ! preg_match( '/^ \* Version: ([0-9A-Za-z._-]+)/m', $main, $m ) )
            return new WP_Error( 'mad4b_native_version_missing', 'Plugin version not found.' );
        $policy = json_decode( file_get_contents( $root . 'config/runtime-release-policy.json' ), true );
        $profiles = json_decode( file_get_contents( $root . 'config/certified-provider-profiles.json' ), true );
        $adapter = $policy['target_adapter_version'] ?? '';
        $adapter_sha = $profiles['providers']['mcp_adapter'][ $adapter ]['archive_sha256'] ?? '';
        if ( ( $policy['contract'] ?? '' ) !== 'mad4b.runtime-release-policy.v1' ||
            ! is_string( $adapter ) || $adapter === '' ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $adapter_sha ) )
            return new WP_Error( 'mad4b_native_adapter_profile', 'Certified adapter profile absent.' );
        $fingerprint = hash( 'sha256', "mad4b.build-fingerprint.v1\n" .
            $m[1] . "\n" . $job['head'] . "\n" . $manifest . "\n" .
            $adapter . "\n" . $adapter_sha . "\n" );
        $provenance = array(
            'contract' => 'mad4b.build-provenance.v1',
            'control_plane_version' => $m[1], 'source_commit_sha' => $job['head'],
            'build_fingerprint' => $fingerprint, 'package_manifest_digest' => $manifest,
            'build_workflow' => 'MAD4B WordPress Native Build - Unverified',
            'build_run_id' => '', 'artifact_identity' => 'mad4b-site-control-plane-' . $m[1] . '-' . $job['head'],
            'mcp_adapter_version' => $adapter, 'mcp_adapter_sha256' => $adapter_sha,
            'package_files' => $rows, 'manifest_file_count' => count( $rows ),
            'archive_file_count' => count( $rows ) + 1,
            'manifest_excluded_files' => array( 'MAD4B-BUILD-PROVENANCE.json' ),
            'manifest_excludes_self' => true, 'generated_at_is_identity_neutral' => true,
            'package_producer_metadata_external' => true,
            'archive_format_contract' => 'mad4b.deterministic-zip.v1',
            'archive_compression' => 'stored',
            'canonical_archive_identity' => 'mad4b-site-control-plane-' . $m[1] . '-' . $job['head'],
        );
        // PHP ZipArchive is not assumed byte-identical to the Python canonical
        // builder. Independent canonical replay and signature remain required.
        $zip_file = $dir . '/candidate.zip';
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) )
            return new WP_Error( 'mad4b_native_zip_failed', 'Cannot open private package ZIP.' );
        $assets = array();
        foreach ( $job['entries'] as $entry ) $assets[] = $entry['path'];
        $assets[] = 'MAD4B-BUILD-PROVENANCE.json';
        sort( $assets, SORT_STRING );
        foreach ( $assets as $name ) {
            $key = 'mad4b-site-control-plane/' . $name;
            $ok = $name === 'MAD4B-BUILD-PROVENANCE.json'
                ? $zip->addFromString( $key,
                    wp_json_encode( $provenance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" )
                : $zip->addFile( $root . $name, $key );
            if ( ! $ok || ! $zip->setCompressionName( $key, ZipArchive::CM_STORE ) ) {
                $zip->close();
                return new WP_Error( 'mad4b_native_zip_entry_failed', 'ZIP member rejected.' );
            }
            if ( method_exists( $zip, 'setMtimeName' ) ) $zip->setMtimeName( $key, 315532800 );
            if ( method_exists( $zip, 'setExternalAttributesName' ) )
                $zip->setExternalAttributesName( $key, ZipArchive::OPSYS_UNIX, (0100644 << 16) );
        }
        if ( ! $zip->close() || ! is_file( $zip_file ) ||
            filesize( $zip_file ) > self::MAX_ZIP_BYTES )
            return new WP_Error( 'mad4b_native_zip_budget', 'ZIP incomplete or exceeds upload budget.' );
        $verify = new ZipArchive();
        if ( true !== $verify->open( $zip_file, ZipArchive::RDONLY ) )
            return new WP_Error( 'mad4b_native_zip_verify', 'ZIP cannot be reopened.' );
        $valid = $verify->numFiles === count( $assets );
        $verify->close();
        if ( ! $valid ) return new WP_Error( 'mad4b_native_zip_verify', 'Archive entry count mismatch.' );
        $job['state'] = 'BUILT_UNVERIFIED';
        $job['build_state'] = 'BUILT_UNVERIFIED';
        $job['archive_sha256'] = hash_file( 'sha256', $zip_file );
        $job['archive_bytes'] = filesize( $zip_file );
        $job['build_fingerprint'] = $fingerprint;
        $job['package_manifest_digest'] = $manifest;
        $job['version'] = $m[1];
        $job['php_files_token_parsed'] = $php_parsed;
        $job['finished_at'] = time();
        return true;
    }

    private static function git_blob_hash( $path ) {
        $raw = file_get_contents( $path );
        return is_string( $raw ) ? sha1( 'blob ' . strlen( $raw ) . "\0" . $raw ) : '';
    }

    public static function tick() {
        if ( ! self::exact_staging() ) return;
        $job = get_option( self::OPTION, array() );
        if ( ! is_array( $job ) || ! in_array( $job['state'] ?? '',
            array( 'DISCOVERING', 'FETCHING', 'PACKAGING' ), true ) ) return;
        $site = self::site();
        if ( ( $site['site_uuid'] ?? '' ) !== ( $job['site_uuid'] ?? '' ) ||
            ( $site['profile_digest'] ?? '' ) !== ( $job['profile_digest'] ?? '' ) ||
            ( $site['canonical_origin'] ?? '' ) !== ( $job['origin'] ?? '' ) ||
            time() > ( $job['expires_at'] ?? 0 ) ) {
            $job['state'] = 'BLOCKED';
            $job['blocker'] = 'site_binding_or_deadline_changed';
            update_option( self::OPTION, $job, false );
            return;
        }
        $dir = self::root_for( $job['head'] );
        if ( ! $dir || ! is_dir( $dir ) || is_link( $dir ) ) return;
        $fp = @fopen( $dir . '/.lock', 'c' );
        if ( ! $fp ) return;
        if ( ! flock( $fp, LOCK_EX | LOCK_NB ) ) { fclose( $fp ); return; }
        try {
            if ( $job['state'] === 'DISCOVERING' ) $out = self::discover( $job );
            elseif ( $job['state'] === 'FETCHING' ) $out = self::fetch_batch( $job, $dir );
            else $out = self::package( $job, $dir );
            if ( is_wp_error( $out ) ) {
                $job['state'] = 'BLOCKED';
                $job['blocker'] = $out->get_error_code();
            }
            update_option( self::OPTION, $job, false );
        } finally {
            flock( $fp, LOCK_UN );
            fclose( $fp );
        }
        if ( in_array( $job['state'], array( 'DISCOVERING', 'FETCHING', 'PACKAGING' ), true ) )
            self::schedule();
    }

    public static function status( $input = array() ) {
        $job = get_option( self::OPTION, array() );
        if ( ! is_array( $job ) || ! isset( $job['head'] ) )
            return array( 'contract' => self::CONTRACT . '.status.v1',
                'state' => 'NOT_RUN', 'production_authorized' => false );
        return array( 'contract' => self::CONTRACT . '.status.v1',
            'state' => $job['state'], 'head' => $job['head'],
            'files_total' => count( $job['entries'] ?? array() ),
            'files_fetched' => $job['cursor'] ?? 0,
            'archive_sha256' => $job['archive_sha256'] ?? '',
            'archive_bytes' => $job['archive_bytes'] ?? 0,
            'build_fingerprint' => $job['build_fingerprint'] ?? '',
            'package_manifest_digest' => $job['package_manifest_digest'] ?? '',
            'blocker' => $job['blocker'] ?? '',
            'php_files_token_parsed' => $job['php_files_token_parsed'] ?? 0,
            'php_token_parse_screening_only' => true,
            'canonical_archive_parity_verified' => false,
            'native_php_cli_tests_passed' => false,
            'independent_attestation_verified' => false,
            'release_certified' => false, 'production_authorized' => false,
            'read_only' => true );
    }

    public static function export_chunk( $input = array() ) {
        if ( ! self::exact_staging() || ! function_exists( 'current_user_can' ) ||
            ! current_user_can( 'manage_options' ) ||
            ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) )
            return new WP_Error( 'mad4b_native_export_denied', 'Staging owner required.' );
        $job = get_option( self::OPTION, array() );
        $offset = $input['offset'] ?? -1;
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'expected_head', 'offset' ) ) ||
            ! is_int( $offset ) || $offset < 0 ||
            ( $job['state'] ?? '' ) !== 'BUILT_UNVERIFIED' ||
            ( $input['expected_head'] ?? '' ) !== ( $job['head'] ?? '' ) )
            return new WP_Error( 'mad4b_native_export_not_ready', 'Bound exact source ZIP required.' );
        $dir = self::root_for( $job['head'] );
        $path = $dir ? $dir . '/candidate.zip' : '';
        if ( ! $path || ! is_file( $path ) || is_link( $path ) ||
            filesize( $path ) !== ( $job['archive_bytes'] ?? -1 ) ||
            ! hash_equals( (string) $job['archive_sha256'], hash_file( 'sha256', $path ) ) )
            return new WP_Error( 'mad4b_native_export_drift', 'Package integrity changed.' );
        $size = filesize( $path );
        if ( $offset > $size )
            return new WP_Error( 'mad4b_native_offset_invalid', 'Offset outside package.' );
        $fd = fopen( $path, 'rb' );
        if ( ! $fd || fseek( $fd, $offset ) !== 0 )
            return new WP_Error( 'mad4b_native_export_io', 'Package chunk unavailable.' );
        $bytes = fread( $fd, min( 8192, $size - $offset ) );
        fclose( $fd );
        if ( ! is_string( $bytes ) )
            return new WP_Error( 'mad4b_native_export_io', 'Package chunk unavailable.' );
        return array( 'contract' => self::CONTRACT . '.export.v1',
            'head' => $job['head'], 'offset' => $offset, 'bytes' => strlen( $bytes ),
            'next_offset' => $offset + strlen( $bytes ), 'total_bytes' => $size,
            'archive_sha256' => $job['archive_sha256'],
            'data_base64' => base64_encode( $bytes ),
            'build_state' => 'BUILT_UNVERIFIED', 'certified' => false,
            'production_authorized' => false );
    }
}
