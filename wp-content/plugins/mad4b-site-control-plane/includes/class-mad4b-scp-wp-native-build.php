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
        // Enroll in the existing adapter registry before the canonical Abilities
        // lifecycle; the Servers class dynamically discovers the declared
        // Admin mutation and its original governed Write execution lane.
        add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_adapter' ), 10, 1 );
        // Also enroll immediately when the registry was created before this
        // module's boot order. Registration is idempotent by adapter id.
        if ( class_exists( 'MAD4B_SCP_Adapter_Registry', false ) &&
            method_exists( 'MAD4B_SCP_Adapter_Registry', 'instance' ) ) {
            self::register_adapter( MAD4B_SCP_Adapter_Registry::instance() );
        }
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 38 );
        add_action( self::HOOK, array( __CLASS__, 'tick' ) );
    }

    public static function register_adapter( $registry ) {
        if ( ! class_exists( 'MAD4B_SCP_Adapter_Base', false ) ||
            ! class_exists( 'MAD4B_SCP_Adapter_Registry', false ) ||
            ! $registry instanceof MAD4B_SCP_Adapter_Registry ) return;
        require_once __DIR__ . '/adapters/class-mad4b-scp-wp-native-build-adapter.php';
        $registry->register( new MAD4B_SCP_WP_Native_Build_Adapter() );
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
                        'non_authorizing' => $def[3],
                        'production_mutation_allowed' => false ),
                    'annotations' => array( 'readonly' => $def[3],
                        'destructive' => false, 'idempotent' => true ) ),
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
            'php_native_zip_writer' => function_exists( 'pack' ) && function_exists( 'crc32' ),
            'php_token_parser' => function_exists( 'token_get_all' ) && defined( 'TOKEN_PARSE' ),
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
        $decision = MAD4B_SCP_Authorization::authorize_mutation(
            self::PREFIX . 'start', 'mad4b-admin', 'core', $input );
        if ( is_wp_error( $decision ) ) return $decision;
        return is_array( $decision ) && ! empty( $decision['allowed'] ) ? true :
            new WP_Error( 'mad4b_native_authorization_denied', 'Exact authorization required.' );
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
        // Recheck real owner, OAuth Step-up and exact central write authority in
        // the execution callback, not only in the Ability permission hook.
        $access = self::can_start( $input );
        if ( is_wp_error( $access ) || true !== $access ) return $access;
        $plan = self::plan( array( 'expected_head' => $input['expected_head'] ?? '' ) );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! hash_equals( $plan['plan_sha256'], (string) ( $input['expected_plan_sha256'] ?? '' ) ) ||
            ! $plan['eligible'] ) return new WP_Error( 'mad4b_native_plan_stale', 'Plan changed or environment ineligible.' );
        // Serialize admission across ALL source heads on this WordPress site.
        // The existing SQL INSERT IGNORE mutex is fail-closed and releases by
        // its exact owner token; a worker's per-source flock is insufficient.
        if ( ! class_exists( 'MAD4B_SCP_Batch_Atomic_Mutex', false ) )
            require_once __DIR__ . '/class-mad4b-scp-batch-atomic-mutex.php';
        $lease = MAD4B_SCP_Batch_Atomic_Mutex::acquire( 'wp-native-build', 'begin' );
        if ( is_wp_error( $lease ) )
            return new WP_Error( 'mad4b_native_admission_locked',
                'Atomic Staging build admission unavailable or held; reconcile the current reservation.' );
        try {
            $outcome = self::start_under_admission( $plan, $input );
        } catch ( Throwable $error ) {
            // A partial start must be reconciled before any write retry.
            $outcome = new WP_Error( 'mad4b_native_admission_uncertain',
                'Start encountered an internal error; inspect durable job state before retry.' );
        } finally {
            $unlocked = MAD4B_SCP_Batch_Atomic_Mutex::release( $lease );
        }
        if ( is_wp_error( $unlocked ) )
            return new WP_Error( 'mad4b_native_admission_release_uncertain',
                'Build admission owner release was not confirmed; independently reconcile before retry.' );
        return $outcome;
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

    private static function schedule() {
        if ( ! function_exists( 'wp_schedule_single_event' ) ) return false;
        // A pending event is already a valid scheduling outcome. Otherwise
        // return the actual WordPress scheduler result; never silently report
        // success after a failed or disabled cron dispatch.
        if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::HOOK ) )
            return true;
        $result = wp_schedule_single_event( time() + 3, self::HOOK, array(), true );
        return ! is_wp_error( $result ) && true === $result;
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
            // Fetch pinned raw bytes, avoiding the low unauthenticated REST blob quota.
            // The Git tree's object SHA is still independently verified below.
            $segments = array_map( 'rawurlencode',
                explode( '/', self::PLUGIN . '/' . $entry['path'] ) );
            $url = 'https://raw.githubusercontent.com/' . self::REPO .
                '/' . $job['head'] . '/' . implode( '/', $segments );
            $response = wp_safe_remote_get( $url, array(
                'timeout' => 12, 'redirection' => 0, 'sslverify' => true,
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

    /** Python canonical builder parity: sorted JSON keys and two-space indentation. */
    private static function canonical_json( $data ) {
        $walk = static function ( $value ) use ( &$walk ) {
            if ( ! is_array( $value ) ) return $value;
            $keys = array_keys( $value );
            if ( $keys !== range( 0, count( $keys ) - 1 ) ) ksort( $value, SORT_STRING );
            foreach ( $value as $key => $item ) $value[ $key ] = $walk( $item );
            return $value;
        };
        $encoded = json_encode( $walk( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) )
            return new WP_Error( 'mad4b_native_json_encode', 'Canonical JSON encoding failed.' );
        return preg_replace_callback( '/^( +)/m', static function ( $m ) {
            return str_repeat( ' ', (int) ( strlen( $m[1] ) / 2 ) );
        }, $encoded ) . "\n";
    }

    /**
     * Minimal deterministic ZIP_STORED writer reproducing Python's ZipInfo:
     * 1980-01-01, UNIX 0644 regular files, no extras or comments.
     * This never executes source, uses shell, or trusts a built package.
     */
    private static function canonical_archive( $root, array $entries,
        $provenance_json, $target ) {
        if ( ! is_string( $provenance_json ) || strlen( $provenance_json ) > 2097152 )
            return new WP_Error( 'mad4b_native_provenance_oversize', 'Bounded provenance required.' );
        $paths = array();
        $expected = array();
        foreach ( $entries as $e ) {
            $paths[] = $e['path'];
            $expected[ $e['path'] ] = $e;
        }
        $paths[] = 'MAD4B-BUILD-PROVENANCE.json';
        sort( $paths, SORT_STRING );
        $temp = $target . '.tmp';
        if ( is_link( $target ) || is_link( $temp ) )
            return new WP_Error( 'mad4b_native_zip_symlink', 'Unsafe ZIP output path.' );
        $fp = @fopen( $temp, 'wb' );
        if ( ! $fp ) return new WP_Error( 'mad4b_native_zip_io', 'Private ZIP cannot be written.' );
        $central = '';
        $offset = 0;
        $count = 0;
        $error = '';
        foreach ( $paths as $path ) {
            $name = 'mad4b-site-control-plane/' . $path;
            $data = $path === 'MAD4B-BUILD-PROVENANCE.json'
                ? $provenance_json : @file_get_contents( $root . $path );
            if ( ! is_string( $data ) || strlen( $name ) > 65535 ) {
                $error = 'bad_archive_source'; break;
            }
            $size = strlen( $data );
            if ( $path !== 'MAD4B-BUILD-PROVENANCE.json' &&
                ( ! isset( $expected[ $path ] ) ||
                $size !== (int) $expected[ $path ]['bytes'] ||
                ! hash_equals( (string) $expected[ $path ]['sha'],
                    sha1( 'blob ' . $size . "\0" . $data ) ) ) ) {
                $error = 'staged_source_changed_during_zip'; break;
            }
            $crc = (int) sprintf( '%u', crc32( $data ) );
            $local = pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33,
                $crc, $size, $size, strlen( $name ), 0 );
            $header = $local . $name;
            $bytes = strlen( $header ) + $size;
            if ( $offset + $bytes > self::MAX_ZIP_BYTES ) {
                $error = 'archive_budget_exceeded'; break;
            }
            if ( fwrite( $fp, $header ) !== strlen( $header ) ||
                fwrite( $fp, $data ) !== $size ) {
                $error = 'archive_write_failed'; break;
            }
            $central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50,
                788, 20, 0, 0, 0, 33, $crc, $size, $size,
                strlen( $name ), 0, 0, 0, 0, 0x81a40000, $offset ) . $name;
            $offset += $bytes;
            $count++;
        }
        if ( ! $error ) {
            $end = pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count,
                strlen( $central ), $offset, 0 );
            if ( $offset + strlen( $central ) + strlen( $end ) > self::MAX_ZIP_BYTES ||
                fwrite( $fp, $central . $end ) !== strlen( $central ) + strlen( $end ) )
                $error = 'archive_directory_failed';
        }
        fclose( $fp );
        if ( $error ) {
            @unlink( $temp );
            return new WP_Error( 'mad4b_native_zip_failed', $error );
        }
        if ( ! @rename( $temp, $target ) ) {
            @unlink( $temp );
            return new WP_Error( 'mad4b_native_zip_rename', 'Cannot publish private archive.' );
        }
        return true;
    }

    private static function git_blob_hash( $path ) {
        $raw = file_get_contents( $path );
        return is_string( $raw ) ? sha1( 'blob ' . strlen( $raw ) . "\0" . $raw ) : '';
    }

    /**
     * Read the persisted job under its filesystem lock, bypassing the
     * request-local WordPress option cache. A second get_option() alone is not
     * a cross-process fresh read when a worker already cached old state.
     */
    private static function locked_job_read( $allow_missing = false ) {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) ||
            ! is_string( $wpdb->options ) ||
            ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->options ) ||
            ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ||
            ! function_exists( 'maybe_unserialize' ) )
            return new WP_Error( 'mad4b_native_job_db_unavailable',
                'Current worker state cannot be read independently of cache.' );
        try {
            $sql = $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                self::OPTION );
            $raw = $wpdb->get_var( $sql );
        } catch ( Throwable $error ) {
            return new WP_Error( 'mad4b_native_job_db_read_failed',
                'Current worker state unavailable; no mutation attempted.' );
        }
        if ( null === $raw && $allow_missing ) return array();
        if ( ! is_string( $raw ) )
            return new WP_Error( 'mad4b_native_job_db_missing',
                'Current worker state is missing from persistent storage.' );
        $value = maybe_unserialize( $raw );
        return is_array( $value ) ? $value :
            new WP_Error( 'mad4b_native_job_db_invalid',
                'Current worker state is not a valid record.' );
    }

    public static function tick() {
        if ( ! self::exact_staging() ) return;
        $observed = get_option( self::OPTION, array() );
        if ( ! is_array( $observed ) || ! preg_match( '/^[a-f0-9]{40}$/D',
            (string) ( $observed['head'] ?? '' ) ) ||
            ! in_array( $observed['state'] ?? '',
                array( 'DISCOVERING', 'FETCHING', 'PACKAGING' ), true ) ) return;
        $dir = self::root_for( $observed['head'] );
        if ( ! $dir || ! is_dir( $dir ) || is_link( $dir ) ) return;
        $fp = @fopen( $dir . '/.lock', 'c' );
        if ( ! $fp ) return;
        if ( ! flock( $fp, LOCK_EX | LOCK_NB ) ) { fclose( $fp ); return; }
        try {
            // The pre-lock read is ONLY used to locate the workspace. Workers
            // must reload canonical state AFTER obtaining its per-source lock;
            // otherwise a delayed worker can roll back another tick's cursor
            // or execute work belonging to a superseding job.
            $job = self::locked_job_read();
            if ( is_wp_error( $job ) ) return;
            if ( ! is_array( $job ) ||
                ( $job['head'] ?? '' ) !== $observed['head'] ||
                ( isset( $observed['job_id'] ) && ( $job['job_id'] ?? '' ) !== $observed['job_id'] ) ||
                ! in_array( $job['state'] ?? '',
                    array( 'DISCOVERING', 'FETCHING', 'PACKAGING' ), true ) ) return;
            $site = self::site();
            if ( ! self::exact_staging() ||
                ( $site['site_uuid'] ?? '' ) !== ( $job['site_uuid'] ?? '' ) ||
                ( $site['profile_digest'] ?? '' ) !== ( $job['profile_digest'] ?? '' ) ||
                ( $site['canonical_origin'] ?? '' ) !== ( $job['origin'] ?? '' ) ||
                time() > ( $job['expires_at'] ?? 0 ) ) {
                $job['state'] = 'BLOCKED';
                $job['blocker'] = 'site_binding_or_deadline_changed';
                update_option( self::OPTION, $job, false );
                return;
            }
            try {
                if ( $job['state'] === 'DISCOVERING' ) $out = self::discover( $job );
                elseif ( $job['state'] === 'FETCHING' ) $out = self::fetch_batch( $job, $dir );
                else $out = self::package( $job, $dir );
                if ( is_wp_error( $out ) ) {
                    $job['state'] = 'BLOCKED';
                    $job['blocker'] = $out->get_error_code();
                }
            } catch ( Throwable $error ) {
                // No raw exception text, potentially secret URL, or source
                // content is written to the MCP status or WordPress option.
                $job['state'] = 'BLOCKED';
                $job['blocker'] = 'native_worker_internal_exception';
                $job['internal_exception_class'] = get_class( $error );
            }
            if ( in_array( $job['state'], array(
                'DISCOVERING', 'FETCHING', 'PACKAGING' ), true ) ) {
                if ( ! self::schedule() ) {
                    $job['state'] = 'BLOCKED';
                    $job['blocker'] = 'wp_cron_schedule_failed';
                }
            }
            update_option( self::OPTION, $job, false );
        } finally {
            flock( $fp, LOCK_UN );
            fclose( $fp );
        }
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
            'canonical_receipt' => $job['canonical_receipt'] ?? array(),
            'canonical_receipt_sha256' => $job['canonical_receipt_sha256'] ?? '',
            'blocker' => $job['blocker'] ?? '',
            'php_files_token_parsed' => $job['php_files_token_parsed'] ?? 0,
            'php_token_parse_screening_only' => true,
            'canonical_archive_format' => 'python_zipinfo_stored_unix_0644_v1',
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
            ( 0 === $offset && ! hash_equals( (string) $job['archive_sha256'],
                hash_file( 'sha256', $path ) ) ) )
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
            'recipient_must_verify_full_archive_sha256' => true,
            'production_authorized' => false );
    }
}
