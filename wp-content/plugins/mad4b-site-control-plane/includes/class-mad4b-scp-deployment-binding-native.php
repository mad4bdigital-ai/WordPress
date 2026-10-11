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
    const APPLY = 'mad4b/deployment-binding-native-apply';
    const CONFIRM = 'CREATE HOST-LOCAL STAGING BINDING';
    const FILENAME = 'binding.key';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $read_schema = array( 'type' => 'object', 'properties' => array(),
            'additionalProperties' => false );
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
                'expected_site_uuid', 'expected_revision', 'confirmation',
                '_mad4b_approval_ticket_id' ) );
        foreach ( array(
            array( self::STATUS, 'status', $read_schema, true ),
            array( self::PLAN, 'plan', $read_schema, true ),
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
        // Parent must also be strictly outside the document tree, even
        // if a custom ABSPATH points to a subdirectory of public_html.
        if ( 0 === strpos( $parent . DIRECTORY_SEPARATOR, $document . DIRECTORY_SEPARATOR ) )
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
        $state = '' !== $existing ? 'ALREADY_HAS_PROFILE_BINDING'
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
        if ( is_link( $loc['dir'] ) || false === $mode || ( $mode & 0077 ) !== 0 )
            return self::error( 'private_directory_permissions_failed' );
        $secret = bin2hex( random_bytes( 48 ) );
        $stream = @fopen( $loc['file'], 'x' );
        if ( false === $stream ) return self::error( 'key_creation_failed' );
        @chmod( $loc['file'], 0600 );
        $permissions = @fileperms( $loc['file'] );
        if ( false === $permissions || ( $permissions & 0077 ) !== 0 ) {
            fclose( $stream ); @unlink( $loc['file'] );
            return self::error( 'key_permission_failure' );
        }
        $n = @fwrite( $stream, $secret );
        $ok = @fflush( $stream );
        fclose( $stream );
        if ( strlen( $secret ) !== $n || ! $ok
            || ! hash_equals( $secret, self::read_host_secret( $loc['site_uuid'] ) ) ) {
            @unlink( $loc['file'] );
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
        $claim = MAD4B_SCP_Authorization::claim_mutation(
            self::APPLY, 'mad4b-write', 'core', $input );
        if ( is_wp_error( $claim ) || ! is_array( $claim )
            || true !== ( $claim['allowed'] ?? false ) )
            return is_wp_error( $claim ) ? $claim : self::error( 'approval_unavailable' );
        $current_plan = self::plan();
        if ( is_wp_error( $current_plan ) || ! hash_equals( (string) $plan['plan_sha256'],
            (string) ( $current_plan['plan_sha256'] ?? '' ) ) )
            return self::error( 'recheck_failed' );
        $snap = self::profile_snapshot();
        if ( is_wp_error( $snap ) ) return $snap;
        $key = self::provision( $snap['location'] );
        if ( is_wp_error( $key ) ) return $key;
        $before = $snap['stored'];
        $after = $before;
        $after['revision'] = (int) $before['revision'] + 1;
        $after['deployment_binding_digest'] = hash( 'sha256', $key );
        $after['updated_at'] = gmdate( 'c' );
        $committed = MAD4B_SCP_Site_Profile::commit_record_with_audit(
            $before, $after, 'mad4b/deployment-binding-native-enrolled',
            array( 'site_uuid' => $before['site_uuid'], 'host_file_outside_webroot' => true,
                'no_plaintext_logged' => true, 'identity_preserved' => true,
                'staging_only' => true ), 'mad4b_native_deployment_binding' );
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
            'next_readback' => self::STATUS,
            'next_scope_diagnostic' => 'mad4b/deployment-mode-status',
        );
    }
}
