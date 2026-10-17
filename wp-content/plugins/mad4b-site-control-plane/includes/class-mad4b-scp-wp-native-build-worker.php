<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Exact-commit native source admission, bounded downloads, and unverified archive worker. */
trait MAD4B_SCP_WP_Native_Build_Worker {
    private static function try_resume_deadline_job( array $job, array $plan, $dir ) {
        $blocked = in_array( (string) ( $job['blocker'] ?? '' ),
            array( 'job_deadline_expired', 'site_binding_or_deadline_changed' ), true );
        if ( ( $job['state'] ?? '' ) !== 'BLOCKED' || ! $blocked ||
            (int) ( $job['expires_at'] ?? 0 ) >= time() ) return null;
        $site = self::site();
        if ( ( $job['head'] ?? '' ) !== $plan['source_commit_sha'] ||
            ( $job['plan_sha256'] ?? '' ) !== $plan['plan_sha256'] ||
            ( $job['site_uuid'] ?? '' ) !== ( $site['site_uuid'] ?? '' ) ||
            ( $job['profile_digest'] ?? '' ) !== ( $site['profile_digest'] ?? '' ) ||
            ( $job['origin'] ?? '' ) !== ( $site['canonical_origin'] ?? '' ) )
            return new WP_Error( 'mad4b_native_resume_binding_drift',
                'Expired build belongs to another source, plan or Site Profile.' );
        $entries = $job['entries'] ?? array();
        $cursor = $job['cursor'] ?? -1;
        if ( ! is_array( $entries ) || count( $entries ) < 1 ||
            count( $entries ) > self::MAX_FILES || ! is_int( $cursor ) ||
            $cursor < 1 || $cursor > count( $entries ) )
            return new WP_Error( 'mad4b_native_resume_record_invalid',
                'Expired build progress record cannot be safely resumed.' );
        if ( ! is_string( $dir ) || ! is_dir( $dir ) || is_link( $dir ) )
            return new WP_Error( 'mad4b_native_resume_workspace_invalid',
                'Private partial build directory unavailable.' );
        $fp = @fopen( $dir . '/.lock', 'c' );
        if ( ! $fp ) return new WP_Error( 'mad4b_native_resume_lock_unavailable',
            'Existing native worker workspace cannot be locked.' );
        if ( ! flock( $fp, LOCK_EX | LOCK_NB ) ) {
            fclose( $fp );
            return new WP_Error( 'mad4b_native_resume_worker_busy',
                'Original Cron worker is still processing the build.' );
        }
        try {
            $current = self::locked_job_read();
            if ( is_wp_error( $current ) ) return $current;
            if ( ! is_array( $current ) ||
                ( $current['job_id'] ?? '' ) !== ( $job['job_id'] ?? '' ) ||
                ( $current['state'] ?? '' ) !== 'BLOCKED' ||
                ( $current['cursor'] ?? null ) !== $cursor ||
                ( $current['expires_at'] ?? 0 ) >= time() )
                return new WP_Error( 'mad4b_native_resume_state_changed',
                    'Build state changed while acquiring the workspace lock.' );
            for ( $i = 0; $i < $cursor; $i++ ) {
                $item = $entries[ $i ];
                if ( ! is_array( $item ) ) return new WP_Error(
                    'mad4b_native_resume_integrity_failed', 'Malformed saved Git tree entry.' );
                $name = $item['path'] ?? '';
                $bytes = $item['bytes'] ?? -1;
                $sha = $item['sha'] ?? '';
                if ( ! is_string( $name ) || ! preg_match( '~^[A-Za-z0-9._+/-]+$~D', $name ) ||
                    false !== strpos( '/' . $name . '/', '/../' ) ||
                    false !== strpos( '/' . $name . '/', '/./' ) ||
                    ! is_int( $bytes ) || $bytes < 0 || $bytes > self::MAX_BLOB_BYTES ||
                    ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{40}$/D', $sha ) )
                    return new WP_Error( 'mad4b_native_resume_integrity_failed',
                        'Pinned source entry identity invalid.' );
                $file = $dir . '/source/' . $name;
                $source_root = realpath( $dir . '/source' );
                $resolved_file = realpath( $file );
                if ( ! is_string( $source_root ) || ! is_string( $resolved_file ) ||
                    is_link( $dir . '/source' ) || is_link( $file ) ||
                    ! hash_equals( $source_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $name ), $resolved_file ) ||
                    ! is_file( $file ) || filesize( $file ) !== $bytes )
                    return new WP_Error( 'mad4b_native_resume_integrity_failed',
                        'Cached source file is missing, unsafe or modified.' );
                $raw = @file_get_contents( $file );
                if ( ! is_string( $raw ) || strlen( $raw ) !== $bytes ||
                    ! hash_equals( $sha, sha1( 'blob ' . $bytes . "\0" . $raw ) ) )
                    return new WP_Error( 'mad4b_native_resume_integrity_failed',
                        'Cached Git blob digest does not match the pinned tree.' );
            }
            $job['state'] = $cursor === count( $entries ) ? 'PACKAGING' : 'FETCHING';
            $job['expires_at'] = time() + self::JOB_DEADLINE_SECONDS;
            $job['blocker'] = '';
            $job['resume_count'] = (int) ( $job['resume_count'] ?? 0 ) + 1;
            // New operation identity fences a still-pending stale Cron callback.
            $job['job_id'] = bin2hex( random_bytes( 16 ) );
            if ( function_exists( 'wp_cache_delete' ) )
                wp_cache_delete( self::OPTION, 'options' );
            update_option( self::OPTION, $job, false );
            $stored = self::locked_job_read();
            if ( is_wp_error( $stored ) || ! is_array( $stored ) ||
                ( $stored['job_id'] ?? '' ) !== $job['job_id'] ||
                ( $stored['cursor'] ?? -1 ) !== $cursor ||
                ( $stored['state'] ?? '' ) !== $job['state'] )
                return new WP_Error( 'mad4b_native_resume_persist_failed',
                    'Exact resumed job state could not be read back.' );
            if ( ! self::schedule() ) {
                $stored['state'] = 'BLOCKED';
                $stored['blocker'] = 'wp_cron_schedule_failed';
                update_option( self::OPTION, $stored, false );
                return new WP_Error( 'mad4b_native_resume_schedule_failed',
                    'Resumed job could not be scheduled; verified bytes preserved.' );
            }
            return array(
                'contract' => self::CONTRACT . '.resume.v1',
                'state' => $job['state'], 'resumed' => true,
                'head' => $job['head'], 'files_fetched' => $cursor,
                'files_total' => count( $entries ),
                'verified_cached_files' => $cursor,
                'execution' => 'wp_cron_bounded',
                'release_certified' => false, 'production_authorized' => false,
            );
        } finally {
            flock( $fp, LOCK_UN );
            fclose( $fp );
        }
    }

    private static function start_under_admission( array $plan, array $input ) {
        $fresh_plan = self::plan( array( 'expected_head' => $plan['source_commit_sha'] ) );
        $permission = self::can_start( $input );
        if ( is_wp_error( $permission ) || true !== $permission ) return $permission;
        if ( is_wp_error( $fresh_plan ) || ! $fresh_plan['eligible'] ||
            ! hash_equals( $plan['plan_sha256'], $fresh_plan['plan_sha256'] ) )
            return new WP_Error( 'mad4b_native_plan_changed_during_admission',
                'Staging source plan changed before atomic admission.' );
        $current = self::locked_job_read( true );
        if ( is_wp_error( $current ) ) return $current;
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
        if ( is_array( $current ) && ( $current['state'] ?? '' ) === 'BLOCKED' &&
            in_array( (string) ( $current['blocker'] ?? '' ),
                array( 'job_deadline_expired', 'site_binding_or_deadline_changed' ), true ) ) {
            $resumed = self::try_resume_deadline_job( $current, $plan, $dir );
            if ( null !== $resumed ) return $resumed;
        }
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
            'started_at' => time(), 'expires_at' => time() + self::JOB_DEADLINE_SECONDS,
            'build_state' => 'NOT_RUN', 'production_authorized' => false,
            'job_id' => bin2hex( random_bytes( 16 ) ) );
        // A request-local Options cache must not override the fresh SQL
        // observation made under the site-global admission lease.
        if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( self::OPTION, 'options' );
        update_option( self::OPTION, $job, false );
        $persisted = self::locked_job_read();
        if ( is_wp_error( $persisted ) || ! is_array( $persisted ) ||
            ( $persisted['job_id'] ?? '' ) !== $job['job_id'] ||
            ( $persisted['head'] ?? '' ) !== $job['head'] ||
            ( $persisted['plan_sha256'] ?? '' ) !== $job['plan_sha256'] ||
            ( $persisted['state'] ?? '' ) !== 'DISCOVERING' )
            return new WP_Error( 'mad4b_native_job_persist_failed',
                'Exact source build job could not be persisted.' );
        if ( ! self::schedule() ) {
            $persisted['state'] = 'BLOCKED';
            $persisted['blocker'] = 'wp_cron_schedule_failed';
            update_option( self::OPTION, $persisted, false );
            return new WP_Error( 'mad4b_native_schedule_failed',
                'Build schedule could not be confirmed.' );
        }
        return array( 'contract' => self::CONTRACT . '.start.v1',
            'state' => 'DISCOVERING', 'head' => $job['head'],
            'execution' => 'wp_cron_bounded', 'release_certified' => false,
            'production_authorized' => false );
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
        $started = microtime( true );
        $deadline = $started + self::fetch_time_budget();
        $completed = 0;
        for ( $i = 0; $i < self::BATCH && $job['cursor'] < $n; $i++ ) {
            $remaining = $deadline - microtime( true );
            // Leave time for persisting state and scheduling the next Cron
            // event. Never truncate a request already fetching a Git blob.
            if ( $completed > 0 && $remaining < 4 ) break;
            $entry = $job['entries'][ $job['cursor'] ];
            $segments = array_map( 'rawurlencode',
                explode( '/', self::PLUGIN . '/' . $entry['path'] ) );
            $url = 'https://raw.githubusercontent.com/' . self::REPO .
                '/' . $job['head'] . '/' . implode( '/', $segments );
            $timeout = max( 2, min( 12, (int) floor( $remaining - 2 ) ) );
            $response = wp_safe_remote_get( $url, array(
                'timeout' => $timeout, 'redirection' => 0, 'sslverify' => true,
                'limit_response_size' => self::MAX_BLOB_BYTES + 1,
                'headers' => array( 'User-Agent' => 'MAD4B-WP-Native-Source-Builder' ),
            ) );
            if ( is_wp_error( $response ) ||
                (int) wp_remote_retrieve_response_code( $response ) !== 200 )
                return new WP_Error( 'mad4b_native_raw_unavailable',
                    'Pinned raw source unavailable.' );
            $raw = wp_remote_retrieve_body( $response );
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
            $completed++;
            if ( 0 === $completed % self::FETCH_CHECKPOINT_INTERVAL ) {
                // This runs inside the existing exclusive per-source lock.
                // Commit actual progress before starting another slow request.
                if ( ! update_option( self::OPTION, $job, false ) )
                    return new WP_Error( 'mad4b_native_checkpoint_failed',
                        'Worker cannot persist verified fetch progress.' );
            }
        }
        $job['last_fetch_count'] = $completed;
        $job['last_fetch_elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
        $job['fetch_tick_count'] = (int) ( $job['fetch_tick_count'] ?? 0 ) + 1;
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
            'build_workflow' => 'MAD4B Canonical Package Builder',
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
        $provenance_json = self::canonical_json( $provenance );
        if ( is_wp_error( $provenance_json ) ) return $provenance_json;
        $zip_file = $dir . '/candidate.zip';
        $out = self::canonical_archive( $root, $job['entries'],
            $provenance_json, $zip_file );
        if ( is_wp_error( $out ) ) return $out;
        $job['state'] = 'BUILT_UNVERIFIED';
        $job['build_state'] = 'BUILT_UNVERIFIED';
        $job['archive_sha256'] = hash_file( 'sha256', $zip_file );
        $job['archive_bytes'] = filesize( $zip_file );
        $job['build_fingerprint'] = $fingerprint;
        $job['package_manifest_digest'] = $manifest;
        $job['version'] = $m[1];
        $receipt = array(
            'contract' => 'mad4b.deterministic-control-plane-package.v1',
            'archive_format_contract' => 'mad4b.deterministic-zip.v1',
            'archive_compression' => 'stored',
            'source_commit_sha' => $job['head'],
            'control_plane_version' => $m[1],
            'package_manifest_digest' => $manifest,
            'build_fingerprint' => $fingerprint,
            'archive_sha256' => $job['archive_sha256'],
            'archive_bytes' => $job['archive_bytes'],
            'manifest_file_count' => count( $rows ),
            'archive_file_count' => count( $rows ) + 1,
            'manifest_excluded_files' => array( 'MAD4B-BUILD-PROVENANCE.json' ),
            'package_file_count' => count( $rows ) + 1,
            'package_file_count_semantics' => 'archive_file_count_including_provenance',
            'producer_metadata_external' => true,
            'deterministic_archive' => true,
        );
        $receipt_json = self::canonical_json( $receipt );
        if ( is_wp_error( $receipt_json ) )
            return $receipt_json;
        $receipt_path = $dir . '/CANONICAL-PACKAGE-RECEIPT.json';
        if ( is_link( $receipt_path ) ||
            file_put_contents( $receipt_path, $receipt_json, LOCK_EX ) !== strlen( $receipt_json ) )
            return new WP_Error( 'mad4b_native_receipt_write', 'Canonical receipt unavailable.' );
        $job['canonical_receipt'] = $receipt;
        $job['canonical_receipt_sha256'] = hash( 'sha256', $receipt_json );
        $job['php_files_token_parsed'] = $php_parsed;
        $job['finished_at'] = time();
        return true;
    }
}
