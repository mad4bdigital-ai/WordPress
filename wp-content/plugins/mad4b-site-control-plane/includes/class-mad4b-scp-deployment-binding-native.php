<?php
/**
 * One-approval, WordPress-native Staging deployment binding.
 *
 * The secret is generated and stored in the *local host filesystem outside
 * the web document root*, not in WordPress options, uploads or any API.
 * This proves DB-clone separation, NOT independently administered Host
 * attestation or crash/backup certification.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Deployment_Binding_Native {
    const CONTRACT = 'mad4b.native-deployment-binding.v1';
    const STATUS = 'mad4b/deployment-binding-native-status';
    const PLAN = 'mad4b/deployment-binding-native-plan';
    const READBACK = 'mad4b/deployment-binding-native-readback';
    const HANDOFF = 'mad4b/deployment-binding-owner-handoff';
    const APPLY = 'mad4b/deployment-binding-native-apply';
    const CONFIRM = 'CREATE HOST-LOCAL STAGING BINDING';
    const WP_SETUP_ACTION = 'mad4b_complete_staging_host_setup';
    const WP_SETUP_NONCE = 'mad4b_complete_staging_host_setup_v1';
    const FILENAME = 'binding.key';
    private static $request_marker = '';
    private static $wordpress_setup_nonce_verified = false;

    private static function request_marker() {
        if ( '' === self::$request_marker ) self::$request_marker = bin2hex( random_bytes( 16 ) );
        return self::$request_marker;
    }

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
        // Local WordPress plugin update does not run register_activation_hook.
        // The explicit authenticated continuation is available after ZIP/Update Now.
        add_action( 'admin_post_' . self::WP_SETUP_ACTION, array( __CLASS__, 'handle_wordpress_setup' ) );
    }
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $read_schema = array( 'type' => 'object', 'properties' => array(),
            'additionalProperties' => false );
        $verify_schema = array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'expected_site_uuid' => array( 'type' => 'string',
                    'pattern' => '^[a-f0-9-]{36}$' ),
                'minimum_profile_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
            ), 'required' => array( 'expected_site_uuid', 'minimum_profile_revision' ) );
        $write_schema = array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                'expected_profile_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                'expected_site_uuid' => array( 'type' => 'string', 'pattern' => '^[a-f0-9-]{36}$' ),
                'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
                'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM ) ),
                '_mad4b_approval_ticket_id' => array( 'type' => 'string',
                    'pattern' => '^[a-fA-F0-9-]{36}$' ),
            ), 'required' => array( 'expected_plan_sha256', 'expected_profile_digest',
                'expected_site_uuid', 'expected_revision', 'confirmation' ) );
        // Approval planning validates the exact target schema before a ticket
        // exists. Keep the ticket property optional in the declared schema,
        // while apply() still REQUIRES a valid ticket before authorization.
        // Requiring it here deadlocks ticket creation for this ability.
        foreach ( array(
            array( self::STATUS, 'status', $read_schema, true ),
            array( self::PLAN, 'plan', $read_schema, true ),
            array( self::READBACK, 'readback', $verify_schema, true ),
            array( self::HANDOFF, 'handoff', $read_schema, true ),
            array( self::APPLY, 'apply', $write_schema, false ),
        ) as $ability ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability[0] ) ) continue;
            wp_register_ability( $ability[0], array(
                'label' => 'MAD4B Native Host Binding ' . ucfirst( $ability[1] ),
                'description' => 'One approved Staging action; use an existing filesystem boundary outside webroot. No arbitrary path or exposed key.',
                'category' => $ability[3] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $ability[1] ),
                'permission_callback' => $ability[3] ? array( __CLASS__, 'can_read' )
                    : array( __CLASS__, 'can_apply' ),
                'input_schema' => $ability[2],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool',
                        'surface' => $ability[3] ? 'read' : 'write',
                        'production_mutation_allowed' => false ),
                    'annotations' => array( 'readonly' => $ability[3],
                        'destructive' => ! $ability[3], 'idempotent' => $ability[3] ) ),
            ) );
        }
    }
    public static function can_read() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_read();
    }
    public static function can_apply( $input = null ) {
        if ( ! self::can_read() || ! class_exists( 'MAD4B_SCP_Site_Profile_Enrollment' ) )
            return new WP_Error( 'mad4b_native_binding_admin_denied', 'Enrolled Staging owner required.' );
        $allowed = MAD4B_SCP_Site_Profile_Enrollment::can_execute( $input );
        if ( is_wp_error( $allowed ) || true !== $allowed ) return $allowed;
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
            return new WP_Error( 'mad4b_native_binding_client_denied', 'Exact enrolled ChatGPT client required.' );
        if ( ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return new WP_Error( 'mad4b_native_binding_governance_missing', 'Current mutation authority required.' );
        // The immutable governed execution wrapper already owns the
        // one-time approval claim. Its exact callback proof is valid only
        // while the claimed ability runs with identical ticket/input.
        if ( MAD4B_SCP_Authorization::native_binding_callback_is_claimed( $input ) )
            return true;
        // Outside the execution callback this remains a preflight permission
        // check and MUST NOT claim, consume or approve a ticket.
        $decision = MAD4B_SCP_Authorization::authorize_mutation(
            self::APPLY, 'mad4b-write', 'core', is_array( $input ) ? $input : array() );
        return MAD4B_SCP_Authorization::permission_result_from_authorization( $decision );
    }
    private static function error( $code, $message = 'Exact Staging host-local provisioning is unavailable.' ) {
        return new WP_Error( 'mad4b_native_binding_' . $code, $message );
    }
    /**
     * Discover a fixed root, never from request input. Reject WordPress
     * installed beneath the document root when the parent is web-exposed.
     */
    public static function private_location( $site_uuid ) {
        if ( ! is_string( $site_uuid )
            || 1 !== preg_match( '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $site_uuid )
            || ! defined( 'ABSPATH' ) ) return array();
        $root = realpath( ABSPATH );
        $document = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] )
            ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
        if ( false === $root || false === $document || ! is_dir( $root )
            || ! is_file( $root . DIRECTORY_SEPARATOR . 'wp-load.php' ) )
            return array();
        $root = rtrim( $root, DIRECTORY_SEPARATOR );
        $document = rtrim( $document, DIRECTORY_SEPARATOR );
        if ( '' === $root || '' === $document ) return array();
        $parent = dirname( $root );
        if ( $parent === $root
            || $parent === $document
            || 0 === strpos( $parent . DIRECTORY_SEPARATOR, $document . DIRECTORY_SEPARATOR ) )
            return array();
        $subdir = '.mad4b-host-' . substr( hash( 'sha256', $site_uuid . '|' . $root ), 0, 24 );
        return array( 'parent' => $parent, 'dir' => $parent . DIRECTORY_SEPARATOR . $subdir,
            'file' => $parent . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR . self::FILENAME,
            'site_uuid' => $site_uuid );
    }
    private static function profile_snapshot() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return self::error( 'site_profile_missing' );
        $site = MAD4B_SCP_Site_Profile::status();
        $stored = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
        if ( ! is_array( $site ) || ! is_array( $stored ) ||
            'staging' !== ( $site['configured_environment'] ?? '' )
            || 'staging' !== ( $site['wordpress_environment'] ?? '' )
            || 'staging' !== ( $stored['environment'] ?? '' )
            || empty( $site['origin_match'] ) || empty( $site['environment_match'] )
            || empty( $site['authority_ready'] ) || ! empty( $site['mutation_pending_audit'] )
            || (string) ( $site['site_uuid'] ?? '' ) !== (string) ( $stored['site_uuid'] ?? '' )
            || (string) ( $site['canonical_origin'] ?? '' ) !== (string) ( $stored['canonical_origin'] ?? '' )
            || (int) ( $site['revision'] ?? 0 ) < 1
            || (int) $site['revision'] !== (int) ( $stored['revision'] ?? 0 )
            || 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $site['profile_digest'] ?? '' ) ) )
            return self::error( 'foreign_or_unready_staging' );
        $location = self::private_location( (string) $site['site_uuid'] );
        if ( ! $location ) return self::error( 'safe_host_directory_unavailable',
            'WordPress root is not provably separate from the public document root.' );
        return array( 'site' => $site, 'stored' => $stored, 'location' => $location );
    }
    public static function read_host_secret( $site_uuid ) {
        $loc = self::private_location( $site_uuid );
        if ( ! $loc || is_link( $loc['dir'] ) || is_link( $loc['file'] )
            || ! is_dir( $loc['dir'] ) || ! is_file( $loc['file'] ) ) return '';
        $dir_mode = @fileperms( $loc['dir'] );
        $file_mode = @fileperms( $loc['file'] );
        if ( false === $dir_mode || false === $file_mode
            || ( $dir_mode & 0077 ) !== 0 || ( $file_mode & 0077 ) !== 0 )
            return '';
        $secret = @file_get_contents( $loc['file'], false, null, 0, 129 );
        if ( ! is_string( $secret ) || 1 !== preg_match( '/^[a-f0-9]{96}$/D', $secret ) )
            return '';
        return $secret;
    }
    public static function status( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) || $input ) return self::error( 'read_denied' );
        $snap = self::profile_snapshot();
        if ( is_wp_error( $snap ) ) return array(
            'contract' => self::CONTRACT . '.status.v1',
            'state' => 'SAFE_LOCATION_UNAVAILABLE', 'eligible' => false,
            'next_alternative' => 'mad4b/deployment-binding-setup-status',
            'read_only' => true, 'production_authorized' => false );
        $stored = $snap['stored'];
        $existing = (string) ( $stored['deployment_binding_digest'] ?? '' );
        $host = MAD4B_SCP_Site_Profile::deployment_binding_digest();
        $loc = $snap['location'];
        $key_exists = file_exists( $loc['file'] ) || is_link( $loc['file'] );
        $private_exists = file_exists( $loc['dir'] ) || is_link( $loc['dir'] );
        $state = '' !== $existing
            ? ( '' !== $host && hash_equals( $existing, $host )
                ? 'ALREADY_HAS_PROFILE_BINDING' : 'STORED_HOST_BINDING_MISMATCH' )
            : ( '' !== $host ? 'EXTERNAL_HOST_SECRET_ALREADY_CONFIGURED'
                : ( $key_exists ? 'LOCAL_KEY_EXISTS_NOT_REUSABLE'
                    : ( $private_exists ? 'PRIVATE_DIRECTORY_ALREADY_EXISTS' :
                    ( is_writable( $loc['parent'] ) ? 'READY_FOR_OWNER_APPROVAL'
                        : 'HOST_PARENT_NOT_WRITABLE' ) ) ) );
        return array(
            'contract' => self::CONTRACT . '.status.v1', 'state' => $state,
            'eligible' => 'READY_FOR_OWNER_APPROVAL' === $state,
            'site_uuid' => $snap['site']['site_uuid'],
            'profile_revision' => (int) $snap['site']['revision'],
            'next_ability' => 'READY_FOR_OWNER_APPROVAL' === $state ? self::PLAN : '',
            'next_alternative' => 'READY_FOR_OWNER_APPROVAL' === $state ? ''
                : 'mad4b/deployment-binding-setup-status',
            'no_secret_exposed' => true, 'read_only' => true,
            'production_authorized' => false );
    }
    /**
     * Explicit second-request acceptance. A successful apply is never itself
     * a deployment-binding certificate. Read original profile + host-secret
     * digest again, without disclosing either the secret or host path.
     */
    public static function readback( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input )
            || array_diff( array_keys( $input ),
                array( 'expected_site_uuid', 'minimum_profile_revision' ) )
            || 1 !== preg_match( '/^[a-f0-9-]{36}$/D',
                (string) ( $input['expected_site_uuid'] ?? '' ) )
            || ! is_int( $input['minimum_profile_revision'] ?? null )
            || $input['minimum_profile_revision'] < 1 )
            return self::error( 'readback_input_invalid' );
        $state = self::status();
        if ( is_wp_error( $state ) ) return $state;
        $snap = self::profile_snapshot();
        if ( is_wp_error( $snap ) ) return $snap;
        $site = $snap['site'];
        $stored = $snap['stored'];
        $digest = (string) ( $stored['deployment_binding_digest'] ?? '' );
        $observed = (string) MAD4B_SCP_Site_Profile::deployment_binding_digest();
        $writer_fingerprint = (string) ( $stored['native_binding_writer_request_marker_sha256'] ?? '' );
        $has_writer_marker = 1 === preg_match( '/^[a-f0-9]{64}$/D', $writer_fingerprint );
        $independent_request = $has_writer_marker
            && ! hash_equals( $writer_fingerprint, hash( 'sha256', self::request_marker() ) );
        // A pre-upgrade WordPress-native binding was created by this plugin
        // BEFORE request markers existed. The original private-key and
        // Site Profile digests may still be verified; do not demand key
        // rotation or mislabel it as independently observed new-request proof.
        $legacy_binding = ! $has_writer_marker && '' === $writer_fingerprint;
        $verified = ( $independent_request || $legacy_binding )
            && 'ALREADY_HAS_PROFILE_BINDING' === ( $state['state'] ?? '' )
            && hash_equals( (string) $input['expected_site_uuid'],
                (string) ( $site['site_uuid'] ?? '' ) )
            && (int) $site['revision'] >= (int) $input['minimum_profile_revision']
            && ! empty( $site['deployment_binding_bound'] )
            && ! empty( $site['deployment_binding_match'] )
            && preg_match( '/^[a-f0-9]{64}$/D', $digest )
            && hash_equals( $digest, $observed );
        return array( 'contract' => self::CONTRACT . '.readback.v1',
            'state' => $verified
                ? ( $independent_request ? 'WORDPRESS_BINDING_READBACK_PASS'
                    : 'LEGACY_BINDING_SCOPE_VERIFIED_NO_REQUEST_PROOF' )
                : 'BINDING_READBACK_INCOMPLETE',
            'site_uuid' => (string) $site['site_uuid'],
            'profile_revision' => (int) $site['revision'],
            'site_identity_preserved' => $verified,
            'independent_request_observed' => $independent_request,
            'original_binding_without_writer_marker' => $verified && $legacy_binding,
            'functional_scope_ready_for_census' => $verified,
            'requires_reprovisioning' => false,
            'next_ability' => $verified ? 'context/legacy-reconciliation-census'
                : self::STATUS,
            'separate_http_request_required' => true,
            'independent_host_attestation_certified' => false,
            'mariadb_crash_certified' => false, 'production_authorized' => false,
            'read_only' => true, 'mutation_performed' => false );
    }

    /**
     * Safe remote diagnosis of the real write agent. Structural discovery
     * alone cannot authorize an approval ticket or mutate the Site Profile.
     * Never disclose bearer credentials, approval secrets or other agents.
     */
    public static function handoff( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) || $input )
            return self::error( 'handoff_read_denied' );
        $binding = self::status();
        if ( is_wp_error( $binding ) ) return $binding;
        $profile = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        if ( ! is_array( $profile ) || 'staging' !== ( $profile['environment'] ?? '' )
            || empty( $profile['origin_match'] ) || empty( $profile['environment_match'] ) )
            return self::error( 'handoff_site_not_exact_staging' );
        $authority = class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
            ? MAD4B_SCP_Staging_Write_Authority::status() : array();
        if ( is_wp_error( $authority ) ) return $authority;
        $agent_id = is_array( $authority )
            ? (string) ( $authority['agent_public_id'] ?? '' ) : '';
        $blockers = is_array( $authority )
            ? (array) ( $authority['blockers'] ?? array() ) : array();
        $write_ready = is_array( $authority )
            && true === ( $authority['ready'] ?? false )
            && true === ( $authority['candidate_binding_match'] ?? false )
            && empty( $blockers );
        $binding_eligible = 'READY_FOR_OWNER_APPROVAL' === ( $binding['state'] ?? '' );
        $next = ! $binding_eligible ? 'mad4b/deployment-binding-native-status'
            : ( $write_ready ? 'mad4b/approval-plan'
                : 'mad4b/staging-write-grant-reconciliation-plan' );
        return array(
            'contract' => self::CONTRACT . '.owner-handoff.v1',
            'site_uuid' => (string) ( $profile['site_uuid'] ?? '' ),
            'profile_revision' => (int) ( $profile['revision'] ?? 0 ),
            'binding_state' => (string) ( $binding['state'] ?? '' ),
            'binding_eligible' => $binding_eligible,
            'agent_public_id' => preg_match( '/^[a-f0-9-]{36}$/D', $agent_id )
                ? $agent_id : '',
            'write_authority_state' => is_array( $authority )
                ? (string) ( $authority['state'] ?? 'unavailable' ) : 'unavailable',
            'write_authority_ready' => $write_ready,
            'write_authority_blockers' => $blockers,
            'candidate_binding_match' => is_array( $authority )
                && true === ( $authority['candidate_binding_match'] ?? false ),
            'exact_grants_existing' => is_array( $authority )
                ? (int) ( $authority['exact_grants_existing'] ?? 0 ) : 0,
            'write_tool_count' => is_array( $authority )
                ? (int) ( $authority['write_tool_count'] ?? 0 ) : 0,
            'next_read' => $next,
            'required_write_agent_server' => 'mad4b-write',
            'ticket_ability' => self::APPLY,
            'human_owner_ticket_required' => true,
            'owner_approval_generated' => false,
            'cross_site_ticket_reuse_allowed' => false,
            'production_authorized' => false,
            'read_only' => true, 'mutation_performed' => false,
        );
    }

    public static function plan( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) || $input ) return self::error( 'plan_denied' );
        $snap = self::profile_snapshot();
        if ( is_wp_error( $snap ) ) return $snap;
        $status = self::status();
        if ( ! is_array( $status ) || empty( $status['eligible'] ) )
            return self::error( 'preflight_not_eligible',
                'Safe writable host-local directory is unavailable. Use the externally managed Host binding path.' );
        $site = $snap['site'];
        $basis = array(
            'contract' => self::CONTRACT . '.plan.v1',
            'site_uuid' => (string) $site['site_uuid'],
            'profile_digest' => (string) $site['profile_digest'],
            'revision' => (int) $site['revision'],
            'canonical_origin' => (string) $site['canonical_origin'],
            'environment' => (string) $site['configured_environment'],
            'location_fingerprint' => hash( 'sha256', $snap['location']['file'] ),
            'host_secret_unchanged' => '',
        );
        return array( 'contract' => self::CONTRACT . '.plan.v1',
            'plan_sha256' => hash( 'sha256', wp_json_encode( $basis,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            'eligible' => true,
            'expected_site_uuid' => $basis['site_uuid'],
            'expected_profile_digest' => $basis['profile_digest'],
            'expected_revision' => $basis['revision'],
            'confirmation' => self::CONFIRM, 'next_ability' => self::APPLY,
            'one_approved_action' => true, 'no_ssh_or_wp_config_edit' => true,
            'local_file_not_independent_host_attestation' => true,
            'read_only' => true, 'production_authorized' => false );
    }
    /**
     * Create-once with no caller path input; 0700 private folder and 0600
     * secret file. On any failed profile commit, remove our own file
     * only when it is still byte-identical to the created token.
     */
    private static function provision( array $loc ) {
        if ( file_exists( $loc['dir'] ) || is_link( $loc['dir'] ) ||
            ! @mkdir( $loc['dir'], 0700 ) ) return self::error( 'private_directory_creation_failed' );
        @chmod( $loc['dir'], 0700 );
        $mode = @fileperms( $loc['dir'] );
        if ( is_link( $loc['dir'] ) || false === $mode || ( $mode & 0077 ) !== 0 ) {
            @rmdir( $loc['dir'] );
            return self::error( 'private_directory_permissions_failed' );
        }
        try {
            $secret = bin2hex( random_bytes( 48 ) );
        } catch ( Throwable $error ) {
            @rmdir( $loc['dir'] );
            return self::error( 'random_source_unavailable' );
        }
        $stream = @fopen( $loc['file'], 'x' );
        if ( false === $stream ) {
            @rmdir( $loc['dir'] );
            return self::error( 'key_creation_failed' );
        }
        @chmod( $loc['file'], 0600 );
        $permissions = @fileperms( $loc['file'] );
        if ( false === $permissions || ( $permissions & 0077 ) !== 0 ) {
            fclose( $stream ); @unlink( $loc['file'] ); @rmdir( $loc['dir'] );
            return self::error( 'key_permission_failure' );
        }
        $n = @fwrite( $stream, $secret );
        $ok = @fflush( $stream );
        fclose( $stream );
        if ( strlen( $secret ) !== $n || ! $ok
            || ! hash_equals( $secret, self::read_host_secret( $loc['site_uuid'] ) ) ) {
            @unlink( $loc['file'] ); @rmdir( $loc['dir'] );
            return self::error( 'key_persistence_failure' );
        }
        return $secret;
    }
    private static function rollback( array $loc, $secret ) {
        $found = self::read_host_secret( $loc['site_uuid'] );
        if ( is_string( $secret ) && '' !== $found && hash_equals( $secret, $found ) ) {
            @unlink( $loc['file'] ); @rmdir( $loc['dir'] );
        }
    }
    public static function apply( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'expected_plan_sha256', 'expected_profile_digest', 'expected_site_uuid',
                'expected_revision', 'confirmation', '_mad4b_approval_ticket_id' ) )
            || ! is_int( $input['expected_revision'] ?? null )
            || 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_plan_sha256'] ?? '' ) )
            || 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_profile_digest'] ?? '' ) )
            || 1 !== preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $input['expected_site_uuid'] ?? '' ) )
            || self::CONFIRM !== (string) ( $input['confirmation'] ?? '' )
            || 1 !== preg_match( '/^[a-f0-9-]{36}$/Di',
                (string) ( $input['_mad4b_approval_ticket_id'] ?? '' ) ) )
            return self::error( 'plan_ticket_missing' );
        // Direct static calls are NEVER an execution authority. Only the
        // already-claimed immutable WordPress Ability callback may enter
        // the filesystem/profile writer. This prevents both double claims
        // and accidental bypass through PHP or other MCP surfaces.
        if ( ! class_exists( 'MAD4B_SCP_Authorization' )
            || ! MAD4B_SCP_Authorization::native_binding_callback_is_claimed( $input ) )
            return self::error( 'claimed_execution_boundary_required',
                'An exact approved and claimed governed execution callback is required.' );
        $permission = self::can_apply( $input );
        if ( is_wp_error( $permission ) || true !== $permission ) return $permission;
        $plan = self::plan();
        if ( is_wp_error( $plan ) || !hash_equals( (string) $input['expected_plan_sha256'],
            (string) ( $plan['plan_sha256'] ?? '' ) )
            || !hash_equals( (string) $input['expected_profile_digest'],
                (string) ( $plan['expected_profile_digest'] ?? '' ) )
            || !hash_equals( (string) $input['expected_site_uuid'],
                (string) ( $plan['expected_site_uuid'] ?? '' ) )
            || (int) $input['expected_revision'] !== (int) ( $plan['expected_revision'] ?? 0 ) )
            return self::error( 'stale_plan' );
        // No second claim_mutation here. The wrapper has already reserved
        // budget, claimed the one-time ticket, advanced restore epoch, and
        // persisted provider-entry evidence before invoking this callback.
        $current_plan = self::plan();
        if ( is_wp_error( $current_plan ) || ! hash_equals( (string) $plan['plan_sha256'],
            (string) ( $current_plan['plan_sha256'] ?? '' ) ) )
            return self::error( 'recheck_failed' );
        $snap = self::profile_snapshot();
        if ( is_wp_error( $snap ) ) return $snap;
        return self::commit_new_binding( $snap, 'mad4b/deployment-binding-native-enrolled', false );
    }

    /**
     * WordPress activation is a privileged, locally authenticated lifecycle.
     * This is NOT a new remote write ability. It does not issue an approval
     * ticket, enable governed Write or repair a cloned/mismatched host.
     */
    public static function activation_bootstrap() {
        $activation_hook = defined( 'MAD4B_SCP_FILE' ) && function_exists( 'plugin_basename' )
            ? 'activate_' . plugin_basename( MAD4B_SCP_FILE ) : '';
        $trusted = '' !== $activation_hook
            && function_exists( 'current_filter' )
            && current_filter() === $activation_hook
            && function_exists( 'is_admin' ) && is_admin()
            && function_exists( 'current_user_can' )
            && current_user_can( 'activate_plugins' )
            && current_user_can( 'manage_options' )
            && !( defined( 'WP_CLI' ) && WP_CLI )
            && !( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
            && !( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );
        if ( ! $trusted ) return self::error( 'activation_context_required' );
        return self::bootstrap_from_verified_lifecycle( 'mad4b/deployment-binding-native-activated' );
    }

    /**
     * Called only AFTER nonce-verified WordPress Site Profile save and
     * persisted readback. Initial plugin activation may precede enrollment.
     * Never expose this as a WordPress Ability or general-purpose GET action.
     */
    public static function profile_save_bootstrap() {
        $hook = function_exists( 'current_filter' ) ? current_filter() : '';
        $trusted = class_exists( 'MAD4B_SCP_Site_Profile_Admin' )
            && in_array( $hook, array(
                'admin_post_' . MAD4B_SCP_Site_Profile_Admin::ACTION_SAVE,
                'wp_ajax_' . MAD4B_SCP_Site_Profile_Admin::ACTION_SAVE,
                'admin_post_' . MAD4B_SCP_Site_Profile_Admin::ACTION_AUTOPILOT,
            ), true )
            && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
            && function_exists( 'is_admin' ) && is_admin()
            && function_exists( 'current_user_can' )
            && current_user_can( 'manage_options' )
            && current_user_can( 'activate_plugins' )
            && !( defined( 'WP_CLI' ) && WP_CLI );
        if ( ! $trusted ) return self::error( 'profile_setup_context_required' );
        return self::bootstrap_from_verified_lifecycle( 'mad4b/deployment-binding-native-profile-enrolled' );
    }

    /**
     * One POST from WordPress Plugins -> Complete Staging Setup.
     * A safe alternative to deactivation/reactivation after WP-native upgrades.
     * Never creates a remote ticket or grants Governed Write.
     */
    public static function handle_wordpress_setup() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! function_exists( 'is_admin' )
            || ! is_admin() || ! function_exists( 'current_user_can' )
            || ! current_user_can( 'manage_options' ) || ! current_user_can( 'activate_plugins' ) )
            wp_die( 'Authenticated plugin setup POST required.', '', array( 'response' => 403 ) );
        check_admin_referer( self::WP_SETUP_NONCE );
        self::$wordpress_setup_nonce_verified = true;
        $result = self::wordpress_update_bootstrap();
        self::$wordpress_setup_nonce_verified = false;
        $state = is_wp_error( $result ) ? 'blocked' :
            ( 'EXISTING_BINDING_REUSED' === ( $result['state'] ?? '' ) ? 'reused' : 'new_readback_required' );
        $code = is_wp_error( $result ) ? sanitize_key( $result->get_error_code() ) : '';
        $args = array( 'mad4b_native_host_setup' => $state );
        if ( '' !== $code ) $args['mad4b_setup_reason'] = $code;
        wp_safe_redirect( add_query_arg( $args, admin_url( 'plugins.php' ) ) );
        exit;
    }

    /**
     * Called only from the POST+nonce-verified WordPress Admin route; never
     * from update transient, a normal GET, background cron or remote MCP.
     */
    private static function wordpress_update_bootstrap() {
        $hook = function_exists( 'current_filter' ) ? current_filter() : '';
        $trusted = self::$wordpress_setup_nonce_verified
            && 'admin_post_' . self::WP_SETUP_ACTION === $hook
            && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
            && function_exists( 'is_admin' ) && is_admin()
            && function_exists( 'current_user_can' )
            && current_user_can( 'manage_options' )
            && current_user_can( 'activate_plugins' )
            && !( defined( 'WP_CLI' ) && WP_CLI )
            && !( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );
        if ( ! $trusted ) return self::error( 'wordpress_update_context_required' );
        // The installed ZIP itself must be byte-consistent with provenance
        // before a new host identity may be established after an update.
        if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' )
            || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) )
            return self::error( 'wordpress_update_provenance_unavailable' );
        $identity = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
        if ( ! is_array( $identity ) || empty( $identity['runtime_manifest_match'] )
            || ! empty( $identity['stale'] ) || ! empty( $identity['mismatch'] )
            || 1 !== preg_match( '/^[a-f0-9]{40}$/D', (string) ( $identity['source_commit_sha'] ?? '' ) ) )
            return self::error( 'wordpress_update_provenance_unverified' );
        return self::bootstrap_from_verified_lifecycle( 'mad4b/deployment-binding-native-wordpress-updated' );
    }

    /** Rendering and status reads are non-authorizing: no hidden repair on GET. */
    public static function wordpress_setup_action_link( $links ) {
        if ( ! function_exists( 'is_admin' ) || ! is_admin()
            || ! function_exists( 'current_user_can' )
            || ! current_user_can( 'manage_options' )
            || ! current_user_can( 'activate_plugins' ) )
            return $links;
        $status = self::status();
        if ( ! is_array( $status )
            || 'READY_FOR_OWNER_APPROVAL' !== ( $status['state'] ?? '' )
            || empty( $status['eligible'] ) ) return $links;
        $links['mad4b_host_setup'] = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) )
            . '" style="display:inline"><input type="hidden" name="action" value="'
            . esc_attr( self::WP_SETUP_ACTION ) . '">'
            . wp_nonce_field( self::WP_SETUP_NONCE, '_wpnonce', false, false )
            . '<button type="submit" class="button-link">'
            . esc_html__( 'Complete Staging host setup', 'mad4b-site-control-plane' )
            . '</button></form>';
        return $links;
    }

    private static function bootstrap_from_verified_lifecycle( $audit_action ) {
        // The Profile must already be enrolled. A copied database alone is
        // insufficient: foreign origin/host key, old keys or a non-private
        // filesystem location always block rather than mint a new identity.
        $state = self::status();
        if ( is_wp_error( $state ) ) return $state;
        if ( 'ALREADY_HAS_PROFILE_BINDING' === ( $state['state'] ?? '' ) ) {
            return array( 'state' => 'EXISTING_BINDING_REUSED',
                'read_only' => true, 'mutation_performed' => false );
        }
        if ( 'READY_FOR_OWNER_APPROVAL' !== ( $state['state'] ?? '' )
            || empty( $state['eligible'] ) )
            return self::error( 'activation_host_state_blocked' );
        $plan = self::plan();
        if ( is_wp_error( $plan ) || empty( $plan['eligible'] ) )
            return self::error( 'activation_plan_blocked' );
        $snap = self::profile_snapshot();
        if ( is_wp_error( $snap ) ) return $snap;
        // Re-evaluate the exact immutable plan after the snapshot read,
        // so no host path or Site Profile change can be silently adopted.
        $recheck = self::plan();
        if ( is_wp_error( $recheck )
            || ! hash_equals( (string) ( $plan['plan_sha256'] ?? '' ),
                (string) ( $recheck['plan_sha256'] ?? '' ) ) )
            return self::error( 'activation_plan_drift' );
        return self::commit_new_binding( $snap, $audit_action, true );
    }

    /** One auditable, compensating implementation shared by ticket and activation paths. */
    private static function commit_new_binding( array $snap, $audit_action, $activation ) {
        $key = self::provision( $snap['location'] );
        if ( is_wp_error( $key ) ) return $key;
        $before = $snap['stored'];
        $after = $before;
        $after['revision'] = (int) $before['revision'] + 1;
        $after['deployment_binding_digest'] = hash( 'sha256', $key );
        $after['native_binding_writer_request_marker_sha256'] =
            hash( 'sha256', self::request_marker() );
        $after['updated_at'] = gmdate( 'c' );
        $committed = MAD4B_SCP_Site_Profile::commit_record_with_audit(
            $before, $after, $audit_action,
            array( 'site_uuid' => $before['site_uuid'], 'host_file_outside_webroot' => true,
                'no_plaintext_logged' => true, 'identity_preserved' => true,
                'staging_only' => true, 'activation_bootstrap' => $activation ), 'mad4b_native_deployment_binding' );
        if ( is_wp_error( $committed ) ) {
            self::rollback( $snap['location'], $key );
            return $committed;
        }
        MAD4B_SCP_Site_Profile::reset_cache();
        if ( function_exists( 'do_action' ) ) do_action( 'mad4b_scp_site_profile_saved' );
        $after_read = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
        $site_read = MAD4B_SCP_Site_Profile::status();
        $verified = is_array( $after_read ) && $after_read === $after
            && is_array( $site_read )
            && (int) ( $site_read['revision'] ?? 0 ) === $after['revision']
            && hash_equals( $before['site_uuid'], (string) ( $site_read['site_uuid'] ?? '' ) )
            && hash_equals( $before['canonical_origin'],
                (string) ( $site_read['canonical_origin'] ?? '' ) )
            && ! empty( $site_read['deployment_binding_configured'] )
            && ! empty( $site_read['deployment_binding_bound'] )
            && ! empty( $site_read['deployment_binding_match'] );
        unset( $key );
        return array(
            'contract' => self::CONTRACT . '.apply.v1',
            'state' => $verified ? 'BOUND_NEW_REQUEST_READBACK_REQUIRED'
                : 'POSTCOMMIT_RECONCILIATION_REQUIRED',
            'site_uuid_unchanged' => $before['site_uuid'] ===
                (string) ( $site_read['site_uuid'] ?? '' ),
            'current_profile_revision' => (int) ( $site_read['revision'] ?? 0 ),
            'host_local_secret_configured' => $verified,
            'independent_host_attestation_certified' => false,
            'full_brand_acceptance_certified' => false,
            'plaintext_secret_returned' => false,
            'secret_written_to_wordpress_database' => false,
            'production_mutation' => false,
            'activation_bootstrap' => $activation,
            'next_readback' => self::READBACK,
            'new_request_readback_input' => array(
                'expected_site_uuid' => (string) $before['site_uuid'],
                'minimum_profile_revision' => (int) $after['revision'] ),
            'next_scope_diagnostic' => 'mad4b/deployment-mode-status',
        );
    }
}
