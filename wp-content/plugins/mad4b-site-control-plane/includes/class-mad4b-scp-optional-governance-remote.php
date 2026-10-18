<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WordPress-native, named optional-governance remote operations.
 * Read-only plan/verify and one Staging-only exact-approved mutation.
 * This deliberately cannot change real authority, release or recovery gates.
 */
final class MAD4B_SCP_Optional_Governance_Remote {
    const PLAN = 'mad4b/optional-governance-plan';
    const APPLY = 'mad4b/optional-governance-apply';
    const VERIFY = 'mad4b/optional-governance-verify';
    const CONFIRM = 'APPLY REVIEWED OPTIONAL ADVISORY PREFERENCE';
    const PLAN_CONTRACT = 'mad4b.optional-governance.remote-plan.v1';
    const RECEIPT_CONTRACT = 'mad4b.optional-governance.remote-receipt.v1';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 38 );
    }

    private static function preview_schema( $apply = false ) {
        $properties = array(
            'policy_id' => array( 'type'=>'string', 'pattern'=>'^[a-z][a-z0-9_]{2,79}$' ),
            'mode' => array( 'type'=>'string',
                'enum'=>array( 'inherit', 'enforced', 'advisory', 'off' ) ),
        );
        $required = array( 'policy_id', 'mode' );
        if ( $apply ) {
            $properties['expected_plan_sha256'] = array( 'type'=>'string',
                'pattern'=>'^[a-f0-9]{64}$' );
            $properties['confirmation'] = array( 'type'=>'string',
                'enum'=>array( self::CONFIRM ) );
            $required[] = 'expected_plan_sha256';
            $required[] = 'confirmation';
        }
        return array( 'type'=>'object', 'properties'=>$properties,
            'required'=>$required, 'additionalProperties'=>false );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( array(
            array( self::PLAN, 'Plan Optional Advisory Change', 'plan', true ),
            array( self::VERIFY, 'Verify Optional Advisory Preference', 'verify', true ),
            array( self::APPLY, 'Apply Approved Optional Advisory Preference', 'apply', false ),
        ) as $operation ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $operation[0] ) ) continue;
            $readonly = $operation[3];
            wp_register_ability( $operation[0], array(
                'label'=>$operation[1],
                'description'=>$readonly ? 'Read-only advisory preview/readback.' :
                    'Staging-only exact-plan, enrolled-owner, OAuth step-up and ticket-governed advisory option mutation.',
                'category'=>$readonly ? 'mad4b-read' : 'mad4b-governance',
                'execute_callback'=>array( __CLASS__, $operation[2] ),
                'permission_callback'=>$readonly
                    ? array( 'MAD4B_SCP_Policy', 'can_read' )
                    : array( __CLASS__, 'can_apply' ),
                'input_schema'=>self::preview_schema( ! $readonly ),
                'output_schema'=>array( 'type'=>'object', 'additionalProperties'=>true ),
                'meta'=>array( 'public'=>false, 'show_in_rest'=>false,
                    'mcp'=>array( 'public'=>false, 'type'=>'tool',
                        'surface'=>$readonly ? 'read' : 'write',
                        'generic_remote_admin'=>false, 'production_mutation_allowed'=>false ),
                    'annotations'=>array( 'readonly'=>$readonly,
                        'destructive'=>! $readonly, 'idempotent'=>$readonly ) ),
            ) );
        }
    }

    private static function normalize( $input, $for_apply = false ) {
        if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_optional_remote_input_invalid', 'Expected a typed input object.' );
        $allowed = $for_apply
            ? array( 'policy_id', 'mode', 'expected_plan_sha256', 'confirmation' )
            : array( 'policy_id', 'mode' );
        if ( array_diff( array_keys( $input ), $allowed ) )
            return new WP_Error( 'mad4b_optional_remote_extra_keys', 'Remote policy input contains undeclared fields.' );
        $id = $input['policy_id'] ?? '';
        $mode = $input['mode'] ?? '';
        if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_]{2,79}$/D', $id )
            || ! is_string( $mode )
            || ! in_array( $mode, array( 'inherit', 'enforced', 'advisory', 'off' ), true ) )
            return new WP_Error( 'mad4b_optional_remote_policy_invalid', 'Invalid policy or advisory mode.' );
        return array( 'policy_id'=>$id, 'mode'=>$mode );
    }

    public static function plan( $input = array() ) {
        $values = self::normalize( $input );
        if ( is_wp_error( $values ) ) return $values;
        $status = MAD4B_SCP_Optional_Governance::status();
        if ( empty( $status['valid'] ) ) return new WP_Error(
            'mad4b_optional_remote_catalog_unavailable', 'Current policy state is not valid.' );
        $row = null;
        $all = array();
        foreach ( $status['gates'] as $gate ) {
            $all[$gate['id']] = $gate['configured_mode'];
            if ( $gate['id'] === $values['policy_id'] ) $row = $gate;
        }
        if ( null === $row ) return new WP_Error(
            'mad4b_optional_remote_unknown_policy', 'Policy is not in the validated optional catalog.' );
        $all[$values['policy_id']] = $values['mode'];
        ksort( $all, SORT_STRING );
        $site = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
        if ( '' === $site ) return new WP_Error( 'mad4b_optional_site_missing', 'Site origin is unavailable.' );
        $plan = array(
            'contract'=>self::PLAN_CONTRACT,
            'operation'=>'update_optional_operator_notice',
            'policy_id'=>$values['policy_id'], 'target_mode'=>$values['mode'],
            'previous_mode'=>$row['configured_mode'],
            'operating_mode'=>$status['operating_mode'],
            'revision'=>$status['revision'],
            'catalog_sha256'=>$status['definitions_sha256'],
            'site_origin_sha256'=>hash( 'sha256', $site ),
            'actor_id'=>function_exists('get_current_user_id') ? get_current_user_id() : 0,
            'blog_id'=>function_exists('get_current_blog_id') ? get_current_blog_id() : 0,
            'new_modes'=>$all, 'scope'=>'operator_advisory_only',
            'owner_approval_required'=>true,
            'authorization_created'=>false, 'production_authorized'=>false,
            'mutation_performed'=>false
        );
        $plan['plan_sha256'] = hash( 'sha256',
            (string) wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        return $plan;
    }

    public static function verify( $input = array() ) {
        $values = self::normalize( $input );
        if ( is_wp_error( $values ) ) return $values;
        $status = MAD4B_SCP_Optional_Governance::status();
        if ( empty( $status['valid'] ) ) return new WP_Error(
            'mad4b_optional_verify_unavailable', 'Cannot verify an invalid policy catalog or option state.' );
        foreach ( $status['gates'] as $row ) {
            if ( $row['id'] !== $values['policy_id'] ) continue;
            return array( 'contract'=>'mad4b.optional-governance.remote-verify.v1',
                'policy_id'=>$values['policy_id'],
                'expected_mode'=>$values['mode'],
                'actual_mode'=>$row['configured_mode'],
                'effective_mode'=>$row['mode'],
                'revision'=>$status['revision'],
                'catalog_sha256'=>$status['definitions_sha256'],
                'verified'=>$row['configured_mode'] === $values['mode'],
                'authorization_created'=>false, 'production_authorized'=>false,
                'mutation_performed'=>false );
        }
        return new WP_Error( 'mad4b_optional_verify_unknown', 'Unknown optional policy.' );
    }

    public static function can_apply( $input = null ) {
        if ( ! current_user_can( 'manage_options' ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile', false ) ||
            'staging' !== (string) MAD4B_SCP_Site_Profile::current_environment() ||
            ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) ||
            ! class_exists( 'MAD4B_SCP_Policy', false ) ||
            ! MAD4B_SCP_Policy::can_mutate() )
            return new WP_Error( 'mad4b_optional_remote_staging_authority_denied',
                'Enrolled Staging owner with governed Write is required.' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
                MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ||
            ! class_exists( 'MAD4B_SCP_Local_OAuth_Server', false ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ||
            ! class_exists( 'MAD4B_SCP_Authorization', false ) )
            return new WP_Error( 'mad4b_optional_remote_step_up_missing',
                'Exact owner ChatGPT OAuth step-up and mutation authorizer are required.' );
        return MAD4B_SCP_Authorization::authorize_mutation(
            self::APPLY, 'mad4b-write', 'core', is_array( $input ) ? $input : array() );
    }

    public static function apply( $input = array() ) {
        $values = self::normalize( $input, true );
        if ( is_wp_error( $values ) ||
            ! is_string( $input['expected_plan_sha256'] ?? null ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $input['expected_plan_sha256'] ) ||
            ! is_string( $input['confirmation'] ?? null ) ||
            ! hash_equals( self::CONFIRM, $input['confirmation'] ) )
            return new WP_Error( 'mad4b_optional_remote_confirmation_denied', 'Exact typed confirmation required.' );
        $allowed = self::can_apply( $input );
        if ( is_wp_error( $allowed ) || true !== $allowed ) return $allowed;
        $plan = self::plan( $values );
        if ( is_wp_error( $plan ) ||
            ! hash_equals( (string) ( $plan['plan_sha256'] ?? '' ),
                $input['expected_plan_sha256'] ) )
            return new WP_Error( 'mad4b_optional_remote_plan_stale',
                'The current exact site, actor, policy, mode or revision differs from the reviewed plan.' );
        $receipt = MAD4B_SCP_Optional_Governance::persist_modes(
            $plan['new_modes'], $plan['revision'], $plan['catalog_sha256'] );
        if ( is_wp_error( $receipt ) ) return $receipt;
        $checked = self::verify( $values );
        if ( is_wp_error( $checked ) || empty( $checked['verified'] )
            || (int) $checked['revision'] !== (int) $receipt['revision'] )
            return new WP_Error( 'mad4b_optional_remote_outcome_uncertain',
                'Advisory option may have changed; inspect persisted readback and audit before retrying.' );
        return array( 'contract'=>self::RECEIPT_CONTRACT,
            'policy_id'=>$values['policy_id'], 'mode'=>$values['mode'],
            'plan_sha256'=>$plan['plan_sha256'], 'revision'=>$receipt['revision'],
            'readback_verified'=>true, 'audit_recorded'=>true,
            'preferences_mutated'=>true, 'authority_created'=>false,
            'production_authorized'=>false );
    }
}
