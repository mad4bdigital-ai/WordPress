<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * No side-effect CSO change planning. A WordPress-native, separately granted
 * write executor must later consume an immutable plan. This class does not
 * execute Abilities or claim that provider approval has occurred.
 */
final class MAD4B_SCP_CSO_Changes {
    const CONTRACT = 'mad4b.cso.change-plan.v1';

    public static function plan( $form, $values, $target_revision ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'single_write' ) ||
            ! MAD4B_SCP_CSO_Scope::enabled( 'forms' ) ||
            ! MAD4B_SCP_CSO_Scope::first_party_session() ||
            ! is_array( $form ) || array_diff( array_keys( $form ),
                array( 'provider_id', 'target', 'descriptor_sha256' ) ) ||
            ! is_string( $form['provider_id'] ?? null ) ||
            ! is_array( $form['target'] ?? null ) ||
            ! is_string( $form['descriptor_sha256'] ?? null ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $form['descriptor_sha256'] ) ||
            ! is_array( $values ) || count( $values ) > 32 ||
            ! is_string( $target_revision ) ||
            strlen( $target_revision ) < 8 || strlen( $target_revision ) > 128 ||
            ! MAD4B_SCP_CSO_Scope::safe_data( $values ) )
            return MAD4B_SCP_CSO_Scope::error( 'CHANGE_INPUT_OR_PRIVATE_SESSION_INVALID' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        $snapshot = MAD4B_SCP_CSO_Storage_Adapters::snapshot(
            $form['provider_id'], $form['target'] );
        if ( is_wp_error( $snapshot ) ) return $snapshot;
        $descriptor = $snapshot['descriptor'];
        if ( ! hash_equals( $form['descriptor_sha256'], $descriptor['descriptor_sha256'] ) ||
            ! hash_equals( $target_revision, $snapshot['revision'] ) )
            return MAD4B_SCP_CSO_Scope::error( 'CHANGE_STALE_REVISION_OR_DESCRIPTOR' );
        $defined = array();
        foreach ( $descriptor['fields'] as $field ) {
            if ( ! is_array( $field ) || ! is_string( $field['key'] ?? null ) ||
                ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D', $field['key'] ) ||
                isset( $defined[ $field['key'] ] ) ||
                ! in_array( $field['type'] ?? null,
                    array( 'string','number','integer','boolean' ), true ) )
                return MAD4B_SCP_CSO_Scope::error( 'CHANGE_UNSUPPORTED_FIELDS' );
            $defined[ $field['key'] ] = $field;
        }
        if ( ! $values ) return MAD4B_SCP_CSO_Scope::error( 'CHANGE_VALUES_REQUIRED' );
        $issues = MAD4B_SCP_CSO01_Read_Foundation::validate_values(
            $descriptor['fields'], $values );
        if ( is_wp_error( $issues ) || empty( $issues['valid'] ) )
            return MAD4B_SCP_CSO_Scope::error( 'CHANGE_TYPED_VALIDATION_FAILED' );
        $changes = array();
        foreach ( $values as $field => $value ) {
            if ( ! isset( $defined[ $field ] ) ||
                ! empty( $defined[ $field ]['readonly'] ) ||
                ! array_key_exists( $field, $snapshot['values'] ) )
                return MAD4B_SCP_CSO_Scope::error( 'CHANGE_FIELD_UNAUTHORIZED' );
            if ( $snapshot['values'][ $field ] === $value ) continue;
            $changes[] = array(
                'field' => $field,
                'before_sha256' => MAD4B_SCP_CSO_Scope::digest(
                    $snapshot['values'][ $field ] ),
                'after_sha256' => MAD4B_SCP_CSO_Scope::digest( $value ) );
        }
        if ( ! $changes ) return MAD4B_SCP_CSO_Scope::error( 'CHANGE_NO_DIFF' );
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return MAD4B_SCP_CSO_Scope::error( 'CHANGE_SCOPE_CHANGED' );
        $material = array(
            'contract' => self::CONTRACT,
            'scope_fingerprint' => $scope['binding_sha256'],
            'actor_sha256' => $scope['actor_sha256'],
            'provider_id' => $form['provider_id'],
            'target' => $form['target'],
            'native_write_ability' => $descriptor['native_write_ability'],
            'descriptor_sha256' => $descriptor['descriptor_sha256'],
            'expected_revision' => $target_revision,
            'values' => $values,
            'field_changes' => $changes,
            'expires_at' => time() + 300,
            'write_authorized' => false,
        );
        $proof = MAD4B_SCP_CSO_Scope::seal( $material, self::CONTRACT );
        if ( is_wp_error( $proof ) ) return $proof;
        return array(
            'contract' => self::CONTRACT,
            'plan' => $proof, 'plan_sha256' => MAD4B_SCP_CSO_Scope::digest( $material ),
            'field_changes' => $changes, 'write_authorized' => false,
            'mutation_performed' => false, 'requires_native_approval' => true,
            'next_safe_action' => 'execute_through_existing_certified_native_write_ability' );
    }

    /**
     * The generic REST gateway cannot be the final WordPress write executor.
     * A future provider implementation must issue an exact operation grant and
     * execute under the original native callback/Execution Fence.
     */
    public static function commit( $plan, $governance ) {
        return MAD4B_SCP_CSO_Native_Executor::commit( $plan, $governance );
    }

    public static function approval_plan( $plan, $reason, $agent_public_id = '' ) {
        return MAD4B_SCP_CSO_Native_Executor::approval_plan( $plan, $reason, $agent_public_id );
    }

    public static function verify( $plan ) {
        if ( ! is_array( $plan ) )
            return MAD4B_SCP_CSO_Scope::error( 'CHANGE_PROOF_INVALID' );
        $material = MAD4B_SCP_CSO_Scope::unseal( $plan, self::CONTRACT );
        if ( is_wp_error( $material ) || ! is_array( $material ) ||
            ( $material['contract'] ?? '' ) !== self::CONTRACT ||
            ( $material['expires_at'] ?? 0 ) < time() )
            return MAD4B_SCP_CSO_Scope::error( 'CHANGE_PROOF_EXPIRED_OR_INVALID' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) || ! hash_equals(
            (string) ( $material['scope_fingerprint'] ?? '' ),
            (string) ( $scope['binding_sha256'] ?? '' ) ) ||
            ! hash_equals( (string) ( $material['actor_sha256'] ?? '' ),
                (string) ( $scope['actor_sha256'] ?? '' ) ) )
            return MAD4B_SCP_CSO_Scope::error( 'CHANGE_SCOPE_CHANGED' );
        $snapshot = MAD4B_SCP_CSO_Storage_Adapters::snapshot(
            $material['provider_id'], $material['target'] );
        if ( is_wp_error( $snapshot ) ) return $snapshot;
        $matches = true;
        foreach ( $material['values'] as $field => $desired ) {
            if ( ! array_key_exists( $field, $snapshot['values'] ) ||
                $snapshot['values'][ $field ] !== $desired ) $matches = false;
        }
        return array( 'contract' => self::CONTRACT . '.verification.v1',
            'values_match' => $matches, 'independent_readback' => false,
            'execution_receipt_verified' => false, 'mutation_performed' => false,
            'authorized_to_commit' => false );
    }

    public static function history( $input ) {
        return MAD4B_SCP_CSO_Scope::error( 'CHANGE_HISTORY_JOURNAL_NOT_CERTIFIED' );
    }

    public static function undo_plan( $input ) {
        return MAD4B_SCP_CSO_Scope::error( 'CHANGE_REVERSAL_NOT_CERTIFIED' );
    }
}
