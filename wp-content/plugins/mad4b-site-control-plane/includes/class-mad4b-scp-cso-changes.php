<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact proposals. All effects use the registered dispatcher and existing native authority. */
final class MAD4B_SCP_CSO_Changes {
    const CONTRACT = 'mad4b.cso01.change-plan.v1';
    const RECEIPT = 'mad4b.cso01.operation-receipt.v1';
    const PURPOSE = 'mad4b.cso01.change-plan';
    const UNDO_PURPOSE = 'mad4b.cso01.compensation-plan';
    const UNDO_CONTRACT = 'mad4b.cso01.compensation-plan.v1';

    public static function plan( array $sealed_form, array $values, $target_revision ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'forms' ) ) return self::error( 'forms_disabled' );
        if ( ! is_string( $target_revision ) || '' === $target_revision || strlen( $target_revision ) > 191 ) return self::error( 'revision_required' );
        $v = MAD4B_SCP_CSO_Forms::validate( $sealed_form, $values ); if ( is_wp_error( $v ) ) return $v;
        $form = MAD4B_SCP_CSO_Forms::inspect( $sealed_form ); if ( is_wp_error( $form ) ) return $form;
        $d = $form['descriptor'];
        if ( true !== ( $d['writable_by_adapter'] ?? null ) || true === ( $d['read_only'] ?? null ) ) return self::error( 'supported_native_write_required' );
        $w = self::witness( $d, $v['input'] ); if ( is_wp_error( $w ) ) return $w;
        if ( ( $v['input'][ $w['revision_input'] ] ?? null ) !== $target_revision ) return self::error( 'revision_binding_required' );
        $before = self::observe( $w, $v['input'] ); if ( is_wp_error( $before ) ) return $before;
        if ( $before['revision'] !== $target_revision ) return self::error( 'target_revision_drift' );
        $old_values = array(); $diff = array(); $changed = false;
        foreach ( $values as $field => $value ) {
            if ( ! isset( $w['field_map'][ $field ] ) ) return self::error( 'unverifiable_field_denied' );
            $old = self::path( $before['data'], $w['field_map'][ $field ], $exists ); if ( ! $exists ) return self::error( 'readback_field_missing' );
            $old_values[ $field ] = $old; $changed = $changed || ! self::same( $old, $value );
            $public = ( $d['field_metadata'][ $field ]['sensitivity'] ?? 'SITE_INTERNAL' ) === 'PUBLIC';
            $diff[] = array( 'field' => $field, 'before' => $public ? $old : '[redacted]', 'after' => $public ? $value : '[redacted]' );
        }
        if ( ! $changed ) return self::error( 'no_changes' );
        $p = array( 'contract' => self::CONTRACT, 'operation_id' => wp_generate_uuid4(), 'form' => $sealed_form, 'ability_name' => $v['ability_name'],
            'input' => $v['input'], 'values' => $values, 'before_values' => $old_values, 'target' => $v['target'], 'expected_revision' => $target_revision,
            'scope' => $v['scope'], 'schema_sha256' => $d['schema_sha256'], 'input_schema_sha256' => $d['preparation']['expected_input_schema_sha256'],
            'adapter_sha256' => $d['adapter_sha256'], 'capability_binding' => $d['capability_binding'], 'readback' => $w,
            'expires_at' => min( self::now() + 300, $v['expires_at'] ), 'authorizing' => false, 'mutation_performed' => false );
        $p['plan_sha256'] = MAD4B_SCP_CSO_Scope::digest( $p ); if ( is_wp_error( $p['plan_sha256'] ) ) return $p['plan_sha256'];
        $seal = MAD4B_SCP_CSO_Scope::seal( $p, self::PURPOSE ); if ( is_wp_error( $seal ) ) return $seal;
        $preview = self::envelope( $p['ability_name'], $p['input'], $d['preparation'], $p['input_schema_sha256'] ); if ( is_wp_error( $preview ) ) return $preview;
        return array( 'contract' => self::CONTRACT, 'state' => 'PLANNED', 'sealed_plan' => $seal, 'plan_sha256' => $p['plan_sha256'], 'operation_id' => $p['operation_id'],
            'diff' => $diff, 'expires_at' => $p['expires_at'], 'expected_revision' => $target_revision, 'approval_required' => 'CURRENT_CENTRAL_POLICY',
            'prepared_dispatch' => array( 'ability_name' => 'mad4b/write-execute', 'input' => $preview ), 'coordination_entry_required' => true,
            'transport_authority_required' => 'EXISTING_NATIVE_DISPATCHER_POLICY', 'authorizing' => false, 'mutation_performed' => false, 'secret_values_present' => false );
    }
    public static function inspect( array $sealed_plan, $fresh = true ) {
        $p = MAD4B_SCP_CSO_Scope::unseal( $sealed_plan, self::PURPOSE ); if ( is_wp_error( $p ) ) return $p;
        if ( ! is_bool( $fresh ) || ( $p['contract'] ?? '' ) !== self::CONTRACT || ! is_int( $p['expires_at'] ?? null ) || ( $fresh && $p['expires_at'] <= self::now() )
            || ! is_array( $p['scope'] ?? null ) || ! is_array( $p['form'] ?? null ) || ! is_array( $p['input'] ?? null ) || ! is_array( $p['values'] ?? null )
            || ! is_array( $p['before_values'] ?? null ) || ! is_array( $p['readback'] ?? null ) || false !== ( $p['authorizing'] ?? null ) || false !== ( $p['mutation_performed'] ?? null ) ) return self::error( 'plan_invalid_or_expired' );
        $binding = self::check_digest( $p ); if ( is_wp_error( $binding ) ) return $binding;
        $scope = MAD4B_SCP_CSO_Scope::assert_current( $p['scope'] ); if ( is_wp_error( $scope ) ) return $scope;
        if ( $fresh ) { $v = MAD4B_SCP_CSO_Forms::validate( $p['form'], $p['values'] ); if ( is_wp_error( $v ) ) return $v; if ( ! self::same( $v['input'], $p['input'] ) ) return self::error( 'input_drift' ); }
        $d = MAD4B_SCP_CSO_Registry::describe( $p['ability_name'], $p['input'] ); if ( is_wp_error( $d ) ) return $d;
        if ( true !== ( $d['writable_by_adapter'] ?? null ) || ! self::descriptor_matches( $p, $d ) ) return self::error( 'descriptor_drift' );
        $w = self::witness( $d, $p['input'] ); if ( is_wp_error( $w ) ) return $w;
        return self::same( $w, $p['readback'] ) ? $p : self::error( 'readback_descriptor_drift' );
    }
    /** Existing planner alone creates a pending ticket. This never grants or approves it. */
    public static function approval_plan( array $sealed_plan, $reason = 'Review the exact CSO proposal.' ) {
        $p = self::inspect( $sealed_plan ); if ( is_wp_error( $p ) ) return $p;
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'single_write' ) || ! self::reason( $reason ) ) return self::error( 'approval_planning_denied' );
        foreach ( array( 'MAD4B_SCP_Identity_Context', 'MAD4B_SCP_Agent_Registry', 'MAD4B_SCP_Servers' ) as $class ) if ( ! class_exists( $class ) ) return self::error( 'approval_identity_unavailable' );
        $identity = MAD4B_SCP_Identity_Context::current(); if ( is_wp_error( $identity ) ) return $identity;
        $agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity ); if ( is_wp_error( $agent ) ) return $agent;
        $provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $p['ability_name'] ); if ( ! is_string( $provider ) || '' === $provider ) return self::error( 'approval_provider_unbound' );
        $input = array( 'agent_public_id' => $agent['public_id'], 'server_id' => 'mad4b-write', 'ability' => $p['ability_name'], 'provider' => $provider, 'input' => $p['input'], 'reason' => $reason );
        $d = MAD4B_SCP_CSO_Registry::describe( 'mad4b/approval-plan', $input ); if ( is_wp_error( $d ) ) return $d;
        $envelope = self::envelope( 'mad4b/approval-plan', $input, $d['preparation'], $d['preparation']['expected_input_schema_sha256'] ); if ( is_wp_error( $envelope ) ) return $envelope;
        try { $result = self::dispatch( $envelope ); } catch ( Throwable $e ) { return self::error( 'pending_ticket_outcome_unknown' ); }
        if ( is_wp_error( $result ) ) return $result; $ticket = $result['result']['ticket_id'] ?? null;
        if ( ! is_string( $ticket ) || '' === $ticket ) return self::error( 'pending_ticket_unconfirmed' );
        return array( 'state' => 'PLANNED', 'ticket_id' => $ticket, 'plan_sha256' => $p['plan_sha256'], 'approval_granted' => false, 'authorizing' => false, 'target_mutation_performed' => false );
    }
    public static function commit( array $sealed_plan, array $governance ) {
        $p = self::inspect( $sealed_plan ); if ( is_wp_error( $p ) ) return $p;
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'single_write' ) ) return self::error( 'writes_disabled' );
        if ( true !== ( $governance['user_confirmed'] ?? null ) || ( $governance['expected_plan_sha256'] ?? '' ) !== $p['plan_sha256'] ) return self::error( 'explicit_confirmation_required' );
        $envelope = self::envelope( $p['ability_name'], $p['input'], $governance, $p['input_schema_sha256'] ); if ( is_wp_error( $envelope ) ) return $envelope;
        $claim = self::claim( $p ); if ( is_wp_error( $claim ) ) return $claim;
        if ( ! empty( $claim['replayed'] ) ) return self::replay( $p, $claim, self::verify( $sealed_plan ), 'SUCCEEDED' );
        $created = MAD4B_SCP_CSO_Journal::begin( $p, array( 'ability_name' => $p['ability_name'] ) ); if ( is_wp_error( $created ) ) return $created;
        $before = self::observe( $p['readback'], $p['input'] );
        if ( is_wp_error( $before ) || $before['revision'] !== $p['expected_revision'] ) return self::no_effect( $p, $claim, 'PLANNED', is_wp_error( $before ) ? $before->get_error_code() : 'target_revision_drift' );
        $scope = MAD4B_SCP_CSO_Scope::assert_current( $p['scope'] ); if ( is_wp_error( $scope ) ) return self::no_effect( $p, $claim, 'PLANNED', $scope->get_error_code() );
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'PLANNED', 'RUNNING' ); if ( is_wp_error( $saved ) ) return $saved;
        $envelope['idempotency_key'] = 'cso:' . $p['operation_id'];
        try { $result = self::dispatch( $envelope ); } catch ( Throwable $e ) { $result = self::error( 'provider_outcome_unknown' ); }
        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data();
            if ( is_array( $data ) && false === ( $data['target_execution_entered'] ?? null ) && 'not_started' === ( $data['mutation_state'] ?? '' ) ) return self::no_effect( $p, $claim, 'RUNNING', $result->get_error_code() );
            return self::uncertain( $p, 'RUNNING', $result->get_error_code() );
        }
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'RUNNING', 'VERIFYING' ); if ( is_wp_error( $saved ) ) return self::receipt( $p, 'UNCERTAIN', false, true, 'journal_persistence_uncertain' );
        $proof = self::verify( $sealed_plan );
        if ( is_wp_error( $proof ) || true !== ( $proof['verified'] ?? null ) ) return self::uncertain( $p, 'VERIFYING', 'independent_readback_unconfirmed' );
        $receipt = self::receipt( $p, 'SUCCEEDED', true, true, '' ); $receipt['postcondition_digest'] = $proof['postcondition_digest']; $receipt['independent_verifier'] = $p['readback']['ability_name'];
        $id = $result['result']['mutation_id'] ?? ''; if ( self::mutation_id( $id ) ) $receipt['mutation_id'] = $id;
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'VERIFYING', 'SUCCEEDED', array( 'verified' => true, 'mutation_id' => $receipt['mutation_id'] ?? '' ) );
        if ( is_wp_error( $saved ) ) return self::receipt( $p, 'UNCERTAIN', false, true, 'journal_persistence_uncertain' );
        $closed = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, $receipt ); return is_wp_error( $closed ) ? self::receipt( $p, 'UNCERTAIN', false, true, 'idempotency_completion_uncertain' ) : $receipt;
    }
    /** A postcondition observation is separate from a durable execution receipt. */
    public static function verify( array $sealed_plan ) {
        $p = self::inspect( $sealed_plan, false ); if ( is_wp_error( $p ) ) return $p;
        $observed = self::observe( $p['readback'], $p['input'] ); if ( is_wp_error( $observed ) ) return $observed;
        $matched = $observed['revision'] !== $p['expected_revision'];
        foreach ( $p['values'] as $field => $v ) { $actual = self::path( $observed['data'], $p['readback']['field_map'][ $field ], $exists ); if ( ! $exists || ! self::same( $actual, $v ) ) $matched = false; }
        return self::observation( $p, $observed['revision'], $matched );
    }
    public static function history( array $sealed_plan ) {
        $p = self::inspect( $sealed_plan, false ); if ( is_wp_error( $p ) ) return $p;
        $read = self::observe( $p['readback'], $p['input'] ); if ( is_wp_error( $read ) ) return $read;
        $journal = MAD4B_SCP_CSO_Journal::inspect( $p ); return is_wp_error( $journal ) ? $journal : MAD4B_SCP_CSO_Journal::redact_history( $journal );
    }
    public static function reconcile( array $sealed_plan, $reconciliation_ref ) {
        $p = self::inspect( $sealed_plan, false ); if ( is_wp_error( $p ) ) return $p;
        if ( ! is_string( $reconciliation_ref ) || '' === $reconciliation_ref || strlen( $reconciliation_ref ) > 191 ) return self::error( 'reconciliation_ref_required' );
        $journal = MAD4B_SCP_CSO_Journal::inspect( $p ); if ( is_wp_error( $journal ) ) return $journal;
        if ( ! in_array( $journal['state'], array( 'RUNNING', 'VERIFYING', 'UNCERTAIN' ), true ) ) return self::error( 'reconciliation_state_denied' );
        $proof = self::verify( $sealed_plan ); if ( is_wp_error( $proof ) ) return $proof;
        if ( ! $proof['verified'] ) return self::receipt( $p, 'UNCERTAIN', false, true, 'no_effect_not_proven' );
        $receipt = self::receipt( $p, 'SUCCEEDED', true, true, '' ); $receipt['postcondition_digest'] = $proof['postcondition_digest'];
        // Existing independent native reconciliation verifier remains mandatory. No permissive filter is installed.
        $closed = MAD4B_SCP_Durable_Execution::complete_idempotency_from_reconciliation( self::scope_key( $p ), 'cso:' . $p['operation_id'], $p['plan_sha256'], $reconciliation_ref, $receipt ); if ( is_wp_error( $closed ) ) return $closed;
        if ( 'VERIFYING' !== $journal['state'] ) { $saved = MAD4B_SCP_CSO_Journal::transition( $p, $journal['state'], 'VERIFYING' ); if ( is_wp_error( $saved ) ) return $saved; }
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'VERIFYING', 'SUCCEEDED', array( 'verified' => true ) ); return is_wp_error( $saved ) ? $saved : $receipt;
    }
    /** Native supported Undo is a fresh separately approved operation, never reverse SQL. */
    public static function undo_plan( array $sealed_plan, $mutation_id, $reason = 'Undo this verified CSO mutation.' ) {
        $original = self::inspect( $sealed_plan, false ); if ( is_wp_error( $original ) ) return $original;
        $proof = self::verify( $sealed_plan ); if ( is_wp_error( $proof ) || ! $proof['verified'] ) return self::error( 'undo_target_drift' );
        $journal = MAD4B_SCP_CSO_Journal::inspect( $original ); if ( is_wp_error( $journal ) ) return $journal;
        $last = $journal['events'][ count( $journal['events'] ) - 1 ]['safe_metadata'];
        if ( 'SUCCEEDED' !== $journal['state'] || ! self::mutation_id( $mutation_id ) || ( $last['mutation_id'] ?? '' ) !== $mutation_id || ! self::reason( $reason ) ) return self::error( 'undo_mutation_binding' );
        $record = self::native_mutation( $mutation_id ); if ( is_wp_error( $record ) ) return $record;
        if ( ! self::undo_record( $record, $original ) ) return self::error( 'undo_unsupported_or_expired' );
        $observed = self::observe( $original['readback'], $original['input'] ); if ( is_wp_error( $observed ) ) return $observed;
        $form = MAD4B_SCP_CSO_Forms::schema( 'mad4b/mutation-undo', array( 'mutation_id' => $mutation_id ) ); if ( is_wp_error( $form ) ) return $form;
        $values = array( 'reason' => $reason ); $v = MAD4B_SCP_CSO_Forms::validate( $form['sealed_form'], $values ); if ( is_wp_error( $v ) ) return $v;
        $d = MAD4B_SCP_CSO_Registry::describe( 'mad4b/mutation-undo', $v['input'] ); if ( is_wp_error( $d ) ) return $d;
        if ( true === $d['read_only'] || true !== $d['native_route_available'] ) return self::error( 'native_undo_unavailable' );
        $p = array( 'contract' => self::UNDO_CONTRACT, 'operation_id' => wp_generate_uuid4(), 'original_plan' => $sealed_plan, 'original_operation_id' => $original['operation_id'],
            'mutation_id' => $mutation_id, 'ability_name' => 'mad4b/mutation-undo', 'form' => $form['sealed_form'], 'input' => $v['input'], 'values' => $values,
            'target' => array( 'mutation_id' => $mutation_id ), 'expected_revision' => $observed['revision'], 'scope' => $original['scope'], 'schema_sha256' => $d['schema_sha256'],
            'input_schema_sha256' => $d['preparation']['expected_input_schema_sha256'], 'adapter_sha256' => $d['adapter_sha256'], 'capability_binding' => $d['capability_binding'],
            'expires_at' => min( self::now() + 300, $v['expires_at'] ), 'authorizing' => false, 'mutation_performed' => false );
        $p['plan_sha256'] = MAD4B_SCP_CSO_Scope::digest( $p ); if ( is_wp_error( $p['plan_sha256'] ) ) return $p['plan_sha256'];
        $seal = MAD4B_SCP_CSO_Scope::seal( $p, self::UNDO_PURPOSE ); if ( is_wp_error( $seal ) ) return $seal;
        return array( 'state' => 'PLANNED', 'sealed_compensation' => $seal, 'sealed_form' => $form['sealed_form'], 'values' => $values, 'plan_sha256' => $p['plan_sha256'],
            'operation_id' => $p['operation_id'], 'original_operation_id' => $original['operation_id'], 'native_ability' => 'mad4b/mutation-undo', 'separate_approval_required' => true,
            'independent_restore_readback_required' => true, 'reversal_verified' => false, 'authorizing' => false, 'mutation_performed' => false );
    }
    public static function compensate( array $sealed_compensation, array $governance ) {
        $p = self::inspect_compensation( $sealed_compensation, true ); if ( is_wp_error( $p ) ) return $p;
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'single_write' ) || true !== ( $governance['user_confirmed'] ?? null ) || ( $governance['expected_plan_sha256'] ?? '' ) !== $p['plan_sha256'] ) return self::error( 'compensation_confirmation_required' );
        $envelope = self::envelope( 'mad4b/mutation-undo', $p['input'], $governance, $p['input_schema_sha256'] ); if ( is_wp_error( $envelope ) ) return $envelope;
        $claim = self::claim( $p ); if ( is_wp_error( $claim ) ) return $claim;
        if ( ! empty( $claim['replayed'] ) ) return self::replay( $p, $claim, self::verify_compensation( $sealed_compensation ), 'COMPENSATED' );
        $created = MAD4B_SCP_CSO_Journal::begin( $p, array( 'ability_name' => 'mad4b/mutation-undo', 'receipt_ref' => $p['original_operation_id'] ) ); if ( is_wp_error( $created ) ) return $created;
        $original = self::inspect( $p['original_plan'], false ); if ( is_wp_error( $original ) ) return self::no_effect( $p, $claim, 'PLANNED', 'original_descriptor_changed' );
        $current = self::observe( $original['readback'], $original['input'] ); $record = self::native_mutation( $p['mutation_id'] );
        if ( is_wp_error( $current ) || $current['revision'] !== $p['expected_revision'] || is_wp_error( $record ) || ! self::undo_record( $record, $original ) ) return self::no_effect( $p, $claim, 'PLANNED', 'undo_drift_or_expiry' );
        $scope = MAD4B_SCP_CSO_Scope::assert_current( $p['scope'] ); if ( is_wp_error( $scope ) ) return self::no_effect( $p, $claim, 'PLANNED', 'undo_scope_changed' );
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'PLANNED', 'COMPENSATING' ); if ( is_wp_error( $saved ) ) return $saved;
        $envelope['idempotency_key'] = 'cso:' . $p['operation_id'];
        try { $result = self::dispatch( $envelope ); } catch ( Throwable $e ) { $result = self::error( 'undo_outcome_unknown' ); }
        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data(); if ( is_array( $data ) && false === ( $data['target_execution_entered'] ?? null ) && 'not_started' === ( $data['mutation_state'] ?? '' ) ) return self::no_effect( $p, $claim, 'COMPENSATING', $result->get_error_code() );
            return self::uncertain( $p, 'COMPENSATING', 'undo_outcome_unknown' );
        }
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'COMPENSATING', 'VERIFYING' ); if ( is_wp_error( $saved ) ) return self::receipt( $p, 'UNCERTAIN', false, true, 'undo_journal_uncertain' );
        $proof = self::verify_compensation( $sealed_compensation ); if ( is_wp_error( $proof ) || ! $proof['verified'] ) return self::uncertain( $p, 'VERIFYING', 'independent_restore_unconfirmed' );
        $receipt = self::receipt( $p, 'COMPENSATED', true, true, '' ); $receipt['original_operation_id'] = $p['original_operation_id']; $receipt['mutation_id'] = $p['mutation_id']; $receipt['postcondition_digest'] = $proof['postcondition_digest'];
        $saved = MAD4B_SCP_CSO_Journal::transition( $p, 'VERIFYING', 'COMPENSATED', array( 'verified' => true, 'mutation_id' => $p['mutation_id'] ) ); if ( is_wp_error( $saved ) ) return self::receipt( $p, 'UNCERTAIN', false, true, 'undo_journal_uncertain' );
        $closed = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, $receipt ); return is_wp_error( $closed ) ? self::receipt( $p, 'UNCERTAIN', false, true, 'undo_idempotency_uncertain' ) : $receipt;
    }
    public static function verify_compensation( array $sealed_compensation ) {
        $p = self::inspect_compensation( $sealed_compensation, false ); if ( is_wp_error( $p ) ) return $p;
        $original = self::inspect( $p['original_plan'], false ); if ( is_wp_error( $original ) ) return $original;
        $observed = self::observe( $original['readback'], $original['input'] ); if ( is_wp_error( $observed ) ) return $observed;
        $record = self::native_mutation( $p['mutation_id'] ); if ( is_wp_error( $record ) ) return $record;
        $match = 'undone' === ( $record['status'] ?? '' ) && ( $record['ability_name'] ?? '' ) === $original['ability_name'] && $observed['revision'] !== $p['expected_revision'];
        foreach ( $original['before_values'] as $field => $v ) { $actual = self::path( $observed['data'], $original['readback']['field_map'][ $field ], $exists ); if ( ! $exists || ! self::same( $v, $actual ) ) $match = false; }
        return self::observation( $p, $observed['revision'], $match );
    }
    private static function inspect_compensation( array $seal, $fresh ) {
        $p = MAD4B_SCP_CSO_Scope::unseal( $seal, self::UNDO_PURPOSE ); if ( is_wp_error( $p ) ) return $p;
        if ( ( $p['contract'] ?? '' ) !== self::UNDO_CONTRACT || ( $p['ability_name'] ?? '' ) !== 'mad4b/mutation-undo' || ! is_int( $p['expires_at'] ?? null ) || ( $fresh && $p['expires_at'] <= self::now() ) || ! is_array( $p['scope'] ?? null ) || ! self::mutation_id( $p['mutation_id'] ?? '' ) ) return self::error( 'undo_plan_invalid' );
        $bound = self::check_digest( $p ); if ( is_wp_error( $bound ) ) return $bound;
        $scope = MAD4B_SCP_CSO_Scope::assert_current( $p['scope'] ); if ( is_wp_error( $scope ) ) return $scope;
        if ( $fresh ) { $v = MAD4B_SCP_CSO_Forms::validate( $p['form'], $p['values'] ); if ( is_wp_error( $v ) ) return $v; if ( ! self::same( $v['input'], $p['input'] ) ) return self::error( 'undo_input_drift' ); }
        $d = MAD4B_SCP_CSO_Registry::describe( 'mad4b/mutation-undo', $p['input'] ); if ( is_wp_error( $d ) || ! self::descriptor_matches( $p, $d ) ) return self::error( 'undo_descriptor_drift' );
        $original = self::inspect( $p['original_plan'], false ); if ( is_wp_error( $original ) || $original['operation_id'] !== $p['original_operation_id'] || ! self::same( $p['scope'], $original['scope'] ) ) return self::error( 'undo_original_binding' );
        return $p;
    }
    private static function native_mutation( $id ) {
        $input = array( 'mutation_id' => $id ); $d = MAD4B_SCP_CSO_Registry::describe( 'mad4b/mutation-get', $input ); if ( is_wp_error( $d ) ) return $d;
        $r = MAD4B_SCP_CSO_Registry::read( 'mad4b/mutation-get', $input, $d['preparation'] ); if ( is_wp_error( $r ) ) return $r;
        return is_array( $r['result']['mutation'] ?? null ) ? $r['result']['mutation'] : self::error( 'mutation_readback_invalid' );
    }
    private static function undo_record( array $record, array $p ) { return ( $record['ability_name'] ?? '' ) === $p['ability_name'] && ! empty( $record['reversible'] ) && 'verified' === ( $record['status'] ?? '' ) && strtotime( ( $record['undo_expires_at'] ?? '' ) . ' UTC' ) > self::now(); }
    public static function witness( array $d, array $input ) {
        $w = $d['readback'] ?? null;
        if ( ! is_array( $w ) || ! is_string( $w['ability_name'] ?? null ) || $w['ability_name'] === $d['ability_name'] || ! is_array( $w['input_map'] ?? null )
            || ! is_array( $w['field_map'] ?? null ) || ! is_array( $w['target_map'] ?? null ) || ! $w['target_map'] || ! self::is_path( $w['revision_path'] ?? null )
            || ! self::is_path( $w['revision_input'] ?? null ) || false !== strpos( $w['revision_input'], '.' ) || count( $w['input_map'] ) > 32 || count( $w['field_map'] ) > 64 ) return self::error( 'native_readback_required' );
        $params = array();
        foreach ( $w['input_map'] as $key => $source ) { if ( ! self::is_path( $key ) || false !== strpos( $key, '.' ) || ! self::is_path( $source ) ) return self::error( 'readback_map_invalid' ); $params[ $key ] = self::path( $input, $source, $exists ); if ( ! $exists ) return self::error( 'readback_input_unbound' ); }
        foreach ( $w['target_map'] as $path => $source ) { if ( ! self::is_path( $path ) || ! self::is_path( $source ) ) return self::error( 'readback_map_invalid' ); self::path( $input, $source, $exists ); if ( ! $exists ) return self::error( 'readback_target_unbound' ); }
        foreach ( $w['field_map'] as $field => $path ) if ( ! self::is_path( $field ) || ! self::is_path( $path ) ) return self::error( 'readback_map_invalid' );
        $r = MAD4B_SCP_CSO_Registry::describe( $w['ability_name'], $params ); if ( is_wp_error( $r ) ) return $r;
        if ( true !== $r['read_only'] || 'read' !== $r['lane'] || ! self::same( $d['scope'], $r['scope'] ) ) return self::error( 'independent_readback_required' );
        $w['reader_schema_sha256'] = $r['schema_sha256']; $w['reader_adapter_sha256'] = $r['adapter_sha256']; $w['reader_capability_binding'] = $r['capability_binding']; return $w;
    }
    public static function observe( array $w, array $input ) {
        $params = array(); foreach ( $w['input_map'] as $key => $source ) { $params[ $key ] = self::path( $input, $source, $exists ); if ( ! $exists ) return self::error( 'readback_input_unbound' ); }
        $d = MAD4B_SCP_CSO_Registry::describe( $w['ability_name'], $params ); if ( is_wp_error( $d ) ) return $d;
        if ( true !== $d['read_only'] || 'read' !== $d['lane'] || $d['schema_sha256'] !== $w['reader_schema_sha256'] || $d['adapter_sha256'] !== $w['reader_adapter_sha256'] || ! self::same( $d['capability_binding'], $w['reader_capability_binding'] ) ) return self::error( 'readback_descriptor_drift' );
        $read = MAD4B_SCP_CSO_Registry::read( $w['ability_name'], $params, $d['preparation'] ); if ( is_wp_error( $read ) ) return $read;
        $data = $read['result'] ?? null; if ( ! is_array( $data ) || true !== MAD4B_SCP_CSO_Scope::safe_data( $data ) ) return self::error( 'readback_invalid' );
        foreach ( $w['target_map'] as $path => $source ) { $actual = self::path( $data, $path, $exists ); $expected = self::path( $input, $source, $bound ); if ( ! $exists || ! $bound || ! self::same( $actual, $expected ) ) return self::error( 'readback_wrong_target' ); }
        $revision = self::path( $data, $w['revision_path'], $exists ); return $exists && is_string( $revision ) && '' !== $revision && strlen( $revision ) <= 191 ? array( 'data' => $data, 'revision' => $revision ) : self::error( 'readback_revision_missing' );
    }
    public static function envelope( $name, array $input, array $g, $sha ) {
        $required = array( 'expected_input_schema_sha256', 'expected_execution_lane', 'expected_classification_sha256', 'expected_authority_scope_sha256', 'preparation_receipt' );
        $allowed = array_merge( $required, array( 'scope', 'schema_sha256', 'adapter_sha256', 'capability_binding', 'user_confirmed', 'expected_plan_sha256', '_mad4b_approval_ticket_id', '_mad4b_context_receipt' ) );
        if ( array_diff( array_keys( $g ), $allowed ) ) return self::error( 'governance_field_denied' ); $out = array( 'ability_name' => $name, 'input' => $input );
        foreach ( $required as $key ) { if ( ! is_string( $g[ $key ] ?? null ) || '' === $g[ $key ] || strlen( $g[ $key ] ) > 4096 ) return self::error( 'current_preparation_required' ); $out[ $key ] = $g[ $key ]; }
        if ( $out['expected_input_schema_sha256'] !== $sha || ! in_array( $out['expected_execution_lane'], array( 'write', 'content', 'admin' ), true ) ) return self::error( 'prepared_target_mismatch' );
        foreach ( array( '_mad4b_approval_ticket_id', '_mad4b_context_receipt' ) as $key ) if ( array_key_exists( $key, $g ) ) $out[ $key ] = $g[ $key ]; return $out;
    }
    private static function dispatch( array $envelope ) {
        if ( ! function_exists( 'wp_get_ability' ) || ! is_object( $ability = wp_get_ability( 'mad4b/write-execute' ) ) || ! method_exists( $ability, 'execute' ) ) return self::error( 'governed_dispatch_unavailable' );
        $call = static function () use ( $ability, $envelope ) { return $ability->execute( $envelope ); };
        return class_exists( 'MAD4B_SCP_Execution_Fence' ) && MAD4B_SCP_Execution_Fence::has_active_frame() ? MAD4B_SCP_Execution_Fence::with_governed_child( 'mad4b/write-execute', $envelope, $call, 'fixed_dispatch' ) : $call();
    }
    private static function claim( array $p ) { return class_exists( 'MAD4B_SCP_Durable_Execution' ) ? MAD4B_SCP_Durable_Execution::begin_idempotency( self::scope_key( $p ), 'cso:' . $p['operation_id'], $p['plan_sha256'], 3600 ) : self::error( 'durable_execution_required' ); }
    private static function replay( array $p, array $claim, $proof, $expected ) {
        $stored = $claim['result'] ?? null; $j = MAD4B_SCP_CSO_Journal::inspect( $p );
        if ( is_array( $stored ) && ( $stored['state'] ?? '' ) === 'DENIED' && ! is_wp_error( $j ) && $j['state'] === 'DENIED' ) { $stored['replayed'] = true; return $stored; }
        if ( is_wp_error( $proof ) || true !== ( $proof['verified'] ?? null ) || ! is_array( $stored ) || ( $stored['state'] ?? '' ) !== $expected || is_wp_error( $j ) || $j['state'] !== $expected ) return self::receipt( $p, 'UNCERTAIN', false, true, 'replay_readback_or_journal_unconfirmed' );
        $stored['replayed'] = true; return $stored;
    }
    private static function no_effect( array $p, array $claim, $state, $reason ) { $r = self::receipt( $p, 'DENIED', false, false, $reason ); $saved = MAD4B_SCP_CSO_Journal::transition( $p, $state, 'DENIED', array( 'reason_code' => $r['reason_code'] ) ); if ( is_wp_error( $saved ) ) return $saved; $closed = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, $r ); return is_wp_error( $closed ) ? $closed : $r; }
    private static function uncertain( array $p, $state, $reason ) { MAD4B_SCP_CSO_Journal::transition( $p, $state, 'UNCERTAIN', array( 'reason_code' => substr( $reason, 0, 191 ) ) ); return self::receipt( $p, 'UNCERTAIN', false, true, $reason ); }
    private static function scope_key( array $p ) { return hash_hmac( 'sha256', serialize( array( 'cso_operation', $p['scope'], $p['ability_name'], $p['target'] ) ), wp_salt( 'auth' ) ); }
    private static function receipt( array $p, $state, $verified, $mutated, $reason ) { return array( 'contract' => self::RECEIPT, 'operation_id' => $p['operation_id'], 'site_uuid' => $p['scope']['site_uuid'], 'source_sha' => $p['scope']['source_sha'], 'state' => $state, 'verified' => $verified, 'postcondition_digest' => '', 'mutation_performed' => $mutated, 'reason_code' => substr( $reason, 0, 191 ), 'authorizing' => false, 'secret_values_present' => false, 'blind_retry_allowed' => false, 'reconciliation_required' => 'UNCERTAIN' === $state ); }
    private static function observation( array $p, $revision, $matched ) { return array( 'operation_id' => $p['operation_id'], 'state' => $matched ? 'OBSERVED_MATCH' : 'UNCERTAIN', 'verified' => $matched, 'execution_proven' => false, 'postcondition_digest' => $matched ? hash_hmac( 'sha256', serialize( array( $p['operation_id'], $revision, true ) ), wp_salt( 'auth' ) ) : '', 'authorizing' => false, 'mutation_performed' => false, 'blind_retry_allowed' => false ); }
    private static function check_digest( array $p ) { $material = $p; unset( $material['plan_sha256'] ); $sha = MAD4B_SCP_CSO_Scope::digest( $material ); return is_string( $sha ) && $sha === ( $p['plan_sha256'] ?? null ) ? true : self::error( 'plan_binding_invalid' ); }
    private static function descriptor_matches( array $p, array $d ) { return $d['schema_sha256'] === $p['schema_sha256'] && $d['adapter_sha256'] === $p['adapter_sha256'] && self::same( $d['capability_binding'], $p['capability_binding'] ) && ( $d['preparation']['expected_input_schema_sha256'] ?? '' ) === $p['input_schema_sha256']; }
    private static function mutation_id( $id ) { return is_string( $id ) && 1 === preg_match( '/^[a-f0-9-]{36,64}$/D', $id ); }
    private static function reason( $v ) { return is_string( $v ) && strlen( $v ) >= 3 && strlen( $v ) <= 500 && true === MAD4B_SCP_CSO_Scope::safe_data( $v ); }
    public static function path( $data, $path, &$exists = null ) { $exists = true; foreach ( explode( '.', $path ) as $part ) { if ( ! is_array( $data ) || ! array_key_exists( $part, $data ) ) { $exists = false; return null; } $data = $data[ $part ]; } return $data; }
    public static function is_path( $v ) { return is_string( $v ) && strlen( $v ) <= 191 && 1 === preg_match( '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,7}$/D', $v ); }
    public static function same( $a, $b ) { $x = MAD4B_SCP_CSO_Scope::digest( $a ); $y = MAD4B_SCP_CSO_Scope::digest( $b ); return is_string( $x ) && is_string( $y ) && hash_equals( $x, $y ); }
    public static function now() { return class_exists( 'MAD4B_SCP_Time_Policy' ) ? (int) MAD4B_SCP_Time_Policy::now_epoch() : time(); }
    public static function error( $reason ) { return new WP_Error( 'mad4b_cso_change_' . $reason, 'A current exact plan, native verifier and existing governed authority are required.', array( 'authorizing' => false, 'mutation_performed' => false, 'blind_retry_allowed' => false ) ); }
}
