<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * First-party bounded READ-ONLY view of existing Original306 Jobs/Claims.
 *
 * Source-only candidate: bootstrapped in the unshipped Control Plane source,
 * but NOT verified installed on WordPress Production. Does not grant, sign,
 * claim, enqueue, or revive old Jobs.
 */
final class MAD4B_SCP_Original_Job_Readback {
    const CONTRACT = 'mad4b.original305312.readonly-job-claim.v1';
    const ABILITY = 'mad4b/original-job-claim-readback';
    const JOB_PREFIX = 'mad4b_central_broker_job_v1_';
    const CLAIM_PREFIX = 'mad4b_central_broker_claim_v1_';
    const ROUTE_PREFIX = 'mad4b_central_broker_route_gate_v1_';
    const MAX_COMMANDS = 24;
    const MAX_JOB_ROWS = 1000;

    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        if ( function_exists( 'add_action' ) ) {
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 32 );
            self::$booted = true;
        }
    }

    public static function permitted() {
        return current_user_can( 'manage_options' );
    }

    public static function register() {
        if ( ! function_exists( 'wp_register_ability' )
            || ! function_exists( 'wp_has_ability' )
            || wp_has_ability( self::ABILITY ) ) return;
        $hex = array( 'type'=>'string', 'pattern'=>'^[a-f0-9]{64}$' );
        wp_register_ability( self::ABILITY, array(
            'label'=>'Original Job/Claim Readback',
            'description'=>'Read bounded original Job/Claim states by exact immutable command digests, without changing execution authority.',
            'category'=>'mad4b-admin',
            'execute_callback'=>array( __CLASS__, 'read' ),
            'permission_callback'=>array( __CLASS__, 'permitted' ),
            'input_schema'=>array(
                'type'=>'object', 'additionalProperties'=>false,
                'properties'=>array(
                    'site_uuid'=>array( 'type'=>'string', 'minLength'=>36, 'maxLength'=>36 ),
                    'company'=>array( 'type'=>'string', 'pattern'=>'^[a-z][a-z0-9-]{2,47}$' ),
                    'origin'=>array( 'type'=>'string', 'minLength'=>8 ),
                    'environment'=>array( 'type'=>'string', 'enum'=>array( 'staging','production' ) ),
                    'command_sha256'=>array( 'type'=>'array', 'minItems'=>1,
                        'maxItems'=>self::MAX_COMMANDS, 'uniqueItems'=>true,
                        'items'=>$hex ),
                ),
                'required'=>array( 'site_uuid','company','origin','environment','command_sha256' ),
            ),
            'output_schema'=>array( 'type'=>'object', 'additionalProperties'=>true ),
            'meta'=>array(
                'public'=>false, 'show_in_rest'=>false,
                'mcp'=>array( 'public'=>false,'type'=>'tool','surface'=>'read' ),
                'annotations'=>array(
                    'readonly'=>true,'destructive'=>false,'idempotent'=>true,
                ),
            ),
        ) );
    }

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_original_job_readback_' . $reason,
            'Original Job/Claim readback unavailable or not authorized.' );
    }

    private static function scope() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile', false )
            || ! method_exists( 'MAD4B_SCP_Site_Profile', 'status' ) )
            return null;
        $profile = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $profile )
            || ( $profile['configured'] ?? false ) !== true
            || ( $profile['origin_match'] ?? false ) !== true
            || ( $profile['environment_match'] ?? false ) !== true
            || ( $profile['deployment_binding_match'] ?? false ) !== true )
            return null;
        $environment = function_exists( 'wp_get_environment_type' )
            ? wp_get_environment_type() : '';
        $origin = function_exists( 'home_url' )
            ? rtrim( (string) home_url(), '/' ) : '';
        $uuid = strtolower( (string) ( $profile['site_uuid'] ?? '' ) );
        if ( ! in_array( $environment, array( 'staging','production' ), true )
            || ! preg_match( '/^[a-f0-9-]{36}$/D', $uuid )
            || $origin === ''
            || ( $profile['environment'] ?? null ) !== $environment
            || ( $profile['canonical_origin'] ?? null ) !== $origin )
            return null;
        return array(
            'site_uuid'=>$uuid, 'environment'=>$environment, 'origin'=>$origin,
        );
    }

    /**
     * The Original305 Job store belongs to the Central WordPress controller.
     * A target company can differ from the reader's own Site Profile, but
     * the destination must resolve from the ORIGINAL company environment
     * registry and the current reader must be the verified Central owner.
     * Neither an input origin nor a Job payload can enroll a target.
     */
    private static function target_scope( array $input, array $reader ) {
        foreach ( array( 'site_uuid','origin','environment' ) as $field ) {
            if ( ! is_string( $input[$field] ) ) return null;
        }
        $local = true;
        foreach ( array( 'site_uuid','origin','environment' ) as $field ) {
            if ( $input[$field] !== $reader[$field] ) $local = false;
        }
        if ( $local ) return $reader;
        if ( ! class_exists( 'MAD4B_Company_Portfolio_Manager_V1', false )
            || ! is_callable( array( 'MAD4B_Company_Portfolio_Manager_V1', 'staging' ) )
            || MAD4B_Company_Portfolio_Manager_V1::staging() !== true
            || ! class_exists( 'MAD4B_Company_Environment_Registry_V2', false )
            || ! is_callable( array( 'MAD4B_Company_Environment_Registry_V2', 'target' ) ) )
            return null;
        $target = MAD4B_Company_Environment_Registry_V2::target(
            $input['company'], $input['environment']
        );
        if ( ! is_array( $target )
            || ( $target['company_id'] ?? null ) !== $input['company']
            || ( $target['environment'] ?? null ) !== $input['environment']
            || ( $target['site_uuid'] ?? null ) !== $input['site_uuid']
            || ( $target['canonical_origin'] ?? null ) !== $input['origin']
            || ! preg_match( '/^[a-f0-9-]{36}$/D', $input['site_uuid'] )
            || ! preg_match( '#^https://[a-z0-9.-]+$#D', $input['origin'] ) )
            return null;
        return array(
            'site_uuid'=>$target['site_uuid'],
            'environment'=>$target['environment'],
            'origin'=>$target['canonical_origin'],
        );
    }

    public static function read( $input ) {
        if ( ! self::permitted() ) return self::error( 'role_denied' );
        $required = array( 'site_uuid','company','origin','environment','command_sha256' );
        if ( ! is_array( $input )
            || count( array_diff( $required, array_keys( $input ) ) ) !== 0
            || count( array_diff( array_keys( $input ), $required ) ) !== 0 )
            return self::error( 'input_shape_denied' );
        $scope = self::scope();
        if ( $scope === null ) return self::error( 'site_unverified' );
        if ( ! is_string( $input['company'] )
            || ! preg_match( '/^[a-z][a-z0-9-]{2,47}$/D', $input['company'] ) )
            return self::error( 'company_scope_invalid' );
        $target = self::target_scope( $input, $scope );
        if ( $target === null ) return self::error( 'site_scope_mismatch' );
        $digests = $input['command_sha256'];
        if ( ! is_array( $digests ) || count( $digests ) < 1
            || count( $digests ) > self::MAX_COMMANDS
            || array_values( $digests ) !== $digests
            || count( array_unique( $digests ) ) !== count( $digests ) )
            return self::error( 'digest_list_invalid' );
        foreach ( $digests as $digest ) {
            if ( ! is_string( $digest )
                || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) )
                return self::error( 'digest_invalid' );
        }
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'esc_like' )
            || ! isset( $wpdb->options ) )
            return self::error( 'database_reader_missing' );
        // Bounded, indexed option-name scan. Refuse a truncated result rather
        // than falsely declaring a command absent from the original Job log.
        $sql = $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d",
            $wpdb->esc_like( self::JOB_PREFIX ) . '%',
            self::MAX_JOB_ROWS + 1
        );
        $keys = $wpdb->get_col( $sql );
        if ( ! is_array( $keys ) || ! empty( $wpdb->last_error )
            || count( $keys ) > self::MAX_JOB_ROWS )
            return self::error( 'job_store_incomplete' );

        $requested = array_fill_keys( $digests, array() );
        $conflicts = array_fill_keys( $digests, 0 );
        foreach ( $keys as $key ) {
            if ( ! is_string( $key ) || strpos( $key, self::JOB_PREFIX ) !== 0 )
                return self::error( 'job_key_invalid' );
            $job_id = substr( $key, strlen( self::JOB_PREFIX ) );
            if ( ! preg_match( '/^[a-f0-9]{32}$/D', $job_id ) )
                return self::error( 'job_key_invalid' );
            $job = get_option( $key, false );
            if ( ! is_array( $job ) ) return self::error( 'job_store_incomplete' );
            $digest = $job['command_sha256'] ?? null;
            if ( ! is_string( $digest ) || ! array_key_exists( $digest, $requested ) )
                continue;
            if ( ( $job['contract'] ?? null ) !== 'mad4b.central-vps-broker.v1'
                || ( $job['target_scope'] ?? null ) !== 'wordpress'
                || ! in_array( $job['job_type'] ?? null,
                    array( 'signed_vps_wpcli','signed_remote_hostinger_wpcli' ), true )
                || ( $job['company'] ?? null ) !== $input['company']
                || ( $job['site_uuid'] ?? null ) !== $target['site_uuid']
                || ( $job['environment'] ?? null ) !== $target['environment']
                || ( $job['canonical_origin'] ?? null ) !== $target['origin']
                || ( $job['job_id'] ?? null ) !== $job_id ) {
                $conflicts[$digest]++;
                continue;
            }
            $claim = get_option( self::CLAIM_PREFIX . $job_id, false );
            $route = get_option( self::ROUTE_PREFIX . $job_id, false );
            $claim_present = is_array( $claim );
            $claim_exact = $claim_present
                && ( $claim['contract'] ?? null ) === 'mad4b.central-vps-broker.v1'
                && ( $claim['job_id'] ?? null ) === $job_id
                && ( $claim['host_id'] ?? null ) === ( $job['broker_host_id'] ?? null )
                && ( $claim['state'] ?? null ) ===
                    'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
                && ( $claim['claimed_epoch'] ?? null ) ===
                    ( $job['claimed_epoch'] ?? null )
                && ( $claim['production_mutation_performed'] ?? null ) === false;
            $requested[$digest][] = array(
                'state'=>is_string( $job['state'] ?? null )
                    ? substr( $job['state'], 0, 100 ) : 'UNKNOWN',
                'job_expired'=>is_int( $job['expires_epoch'] ?? null )
                    ? time() >= $job['expires_epoch'] : null,
                'job_executed'=>is_bool( $job['executed'] ?? null )
                    ? $job['executed'] : null,
                'claim_present'=>$claim_present,
                'claim_exact'=>$claim_exact,
                'route_reserved'=>is_array( $route ),
            );
        }
        $summary = array();
        foreach ( $digests as $sha ) {
            $records = $requested[$sha];
            $ambiguous = count( $records ) !== 1 || $conflicts[$sha] !== 0;
            $summary[] = array(
                'command_sha256'=>$sha,
                'job_count'=>count( $records ),
                'conflicting_scope_count'=>$conflicts[$sha],
                'state'=>$ambiguous ? 'HOLD__UNVERIFIED_OR_AMBIGUOUS'
                    : 'HOLD__CURRENT_JOB_READ_ONLY',
                'record'=>$ambiguous ? null : $records[0],
            );
        }
        return array(
            'contract'=>self::CONTRACT,
            'state'=>'READ_ONLY__ORIGINAL312_INDEPENDENT_SIGNATURE_REQUIRED',
            'site_uuid'=>$target['site_uuid'],
            'company'=>$input['company'],
            'origin'=>$target['origin'],
            'environment'=>$target['environment'],
            'reader_site_uuid'=>$scope['site_uuid'],
            'reader_origin'=>$scope['origin'],
            'reader_environment'=>$scope['environment'],
            // Bounded observation timestamp only. NOT a signed attestation.
            'observed_epoch'=>time(),
            'commands'=>$summary,
            'job_store_scanned'=>count( $keys ),
            'original312_eligible'=>false,
            'signed_original313_receipt_verified'=>false,
            'job_or_claim_mutated'=>false,
            'authority_created'=>false,
        );
    }
}
