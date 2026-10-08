<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g6-contracts.php';

/** Registered PHP adapters are deployment-reviewed; request data can never register one. */
interface MAD4B_SCP_G6_Model_Routing_Adapter {
    public function descriptor();
}
final class MAD4B_SCP_G6_Provider_Routing {
    const CONTRACT = 'mad4b.g6-provider-routing-review.v1';
    private static $adapters = array();

    public static function register( MAD4B_SCP_G6_Model_Routing_Adapter $adapter ) {
        $d = $adapter->descriptor();
        if ( ! is_array( $d ) || ! isset( $d['provider_id'] ) || ! MAD4B_SCP_G6_Contracts::id( $d['provider_id'] ) )
            return MAD4B_SCP_G6_Contracts::error( 'routing_descriptor', 'Reviewed provider descriptor is missing its identity.' );
        $id = $d['provider_id'];
        if ( isset( self::$adapters[ $id ] ) || count( self::$adapters ) >= 16 )
            return MAD4B_SCP_G6_Contracts::error( 'routing_collision', 'Provider identity already exists or routing is full.' );
        self::$adapters[ $id ] = $adapter;
        return true;
    }

    public static function review( $input = array() ) {
        $owner = MAD4B_SCP_G6_Contracts::owner();
        if ( is_wp_error( $owner ) ) return $owner;
        $bound = MAD4B_SCP_G6_Contracts::untrusted_arguments( $input );
        if ( is_wp_error( $bound ) ) return $bound;
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'intent', 'privacy_class', 'region', 'maximum_cost_micro', 'context_sha256' ) ) )
            return MAD4B_SCP_G6_Contracts::error( 'routing_schema', 'Only a typed model-selection review is admitted.' );
        $intent = isset( $input['intent'] ) ? $input['intent'] : '';
        if ( ! in_array( $intent, array( 'content', 'translation', 'image', 'video', 'audio', 'answer', 'form_proposal' ), true ) )
            return MAD4B_SCP_G6_Contracts::error( 'routing_intent', 'Unsupported model intent.' );
        $privacy = isset( $input['privacy_class'] ) ? $input['privacy_class'] : '';
        if ( ! in_array( $privacy, array( 'public', 'internal', 'restricted' ), true ) )
            return MAD4B_SCP_G6_Contracts::error( 'routing_privacy', 'Explicit privacy class required.' );
        $region = isset( $input['region'] ) ? $input['region'] : '';
        if ( ! is_string( $region ) || ! preg_match( '/^[a-z]{2}(-[a-z0-9]{2,12})?$/D', $region ) )
            return MAD4B_SCP_G6_Contracts::error( 'routing_region', 'Explicit residency is required.' );
        $max = isset( $input['maximum_cost_micro'] ) ? $input['maximum_cost_micro'] : null;
        if ( ! is_int( $max ) || $max < 0 || $max > 1000000000 )
            return MAD4B_SCP_G6_Contracts::error( 'routing_cost', 'An exact cost ceiling is required.' );
        if ( ! isset( $input['context_sha256'] ) || ! MAD4B_SCP_G6_Contracts::sha( $input['context_sha256'] ) )
            return MAD4B_SCP_G6_Contracts::error( 'routing_context', 'Exact context binding is required.' );
        $binding = MAD4B_SCP_G6_Contracts::binding( $input['context_sha256'] );
        if ( is_wp_error( $binding ) ) return $binding;

        $options = array();
        foreach ( self::$adapters as $id => $adapter ) {
            $d = $adapter->descriptor();
            $reasons = array();
            // Descriptors originate in reviewed PHP, but execution certification is independent.
            if ( ! is_array( $d ) || $id !== ( isset( $d['provider_id'] ) ? $d['provider_id'] : '' ) ) $reasons[] = 'provider_identity_drift';
            if ( ! isset( $d['capabilities'] ) || ! is_array( $d['capabilities'] ) || ! in_array( $intent, $d['capabilities'], true ) ) $reasons[] = 'capability_missing';
            if ( ! isset( $d['regions'] ) || ! is_array( $d['regions'] ) || ! in_array( $region, $d['regions'], true ) ) $reasons[] = 'residency_not_proven';
            if ( ! isset( $d['privacy_classes'] ) || ! is_array( $d['privacy_classes'] ) || ! in_array( $privacy, $d['privacy_classes'], true ) ) $reasons[] = 'privacy_not_admitted';
            if ( ! isset( $d['worst_case_cost_micro'] ) || ! is_int( $d['worst_case_cost_micro'] ) || $d['worst_case_cost_micro'] < 0 || $d['worst_case_cost_micro'] > $max ) $reasons[] = 'cost_cap_not_proven';
            if ( empty( $d['account_bound'] ) || empty( $d['consent_valid'] ) || empty( $d['runtime_certified'] ) ) $reasons[] = 'account_consent_or_certification_missing';
            if ( ! isset( $d['artifact_sha256'] ) || ! MAD4B_SCP_G6_Contracts::sha( $d['artifact_sha256'] ) ) $reasons[] = 'artifact_missing';
            if ( ! isset( $d['generation_sha256'] ) || ! MAD4B_SCP_G6_Contracts::sha( $d['generation_sha256'] ) ) $reasons[] = 'generation_missing';
            elseif ( ! hash_equals( $binding['generation_sha256'], $d['generation_sha256'] ) ) $reasons[] = 'provider_generation_stale';
            $options[] = array(
                'provider_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $id ),
                'descriptor_sha256' => MAD4B_SCP_G6_Contracts::digest( $d ),
                'blockers' => array_values( array_unique( $reasons ) ),
                'review_eligible' => empty( $reasons ),
                'execution_admitted' => false,
                'cost_or_rights_inferred' => false,
            );
        }
        $out = array(
            'contract' => self::CONTRACT,
            'binding_sha256' => MAD4B_SCP_G6_Contracts::digest( $binding ),
            'request_sha256' => MAD4B_SCP_G6_Contracts::digest( $input ),
            'options' => $options,
            'selected_provider' => null,
            'automatic_fallback' => false,
            'model_invoked' => false,
            'external_charge_performed' => false,
            'next_action' => 'exact_provider_consent_and_governed_execution_required',
            'authorizing' => false,
        );
        $out['review_sha256'] = MAD4B_SCP_G6_Contracts::digest( $out );
        return $out;
    }
}
