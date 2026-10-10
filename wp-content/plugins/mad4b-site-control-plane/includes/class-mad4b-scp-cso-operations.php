<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Pure opt-in observability, drift and repair proposals. No worker enrollment. */
final class MAD4B_SCP_CSO_Operations {
    const MAX_CONDITIONS = 20;
    const MONITOR_TTL = 86400;

    public static function monitor_plan( array $input ) {
        $scope = MAD4B_SCP_CSO_Domain_Plans::context( $input ); if ( is_wp_error( $scope ) ) return $scope;
        if ( true !== ( $input['opt_in'] ?? null ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'monitor_explicit_opt_in_required' );
        $conditions = $input['conditions'] ?? null; $interval = $input['interval_seconds'] ?? 300; $ttl = $input['ttl_seconds'] ?? 3600;
        if ( ! is_int( $interval ) || $interval < 60 || $interval > 86400 || ! is_int( $ttl ) || $ttl < $interval || $ttl > self::MONITOR_TTL || ! is_array( $conditions ) || ! $conditions || count( $conditions ) > self::MAX_CONDITIONS ) return MAD4B_SCP_CSO_Domain_Plans::error( 'monitor_bounds_invalid' );
        $clean = array(); $seen = array();
        foreach ( $conditions as $condition ) {
            if ( ! is_array( $condition ) || ! in_array( $condition['metric'] ?? '', array( 'schema_drift', 'provider_certification', 'credential_expiry', 'failed_operations', 'latency_ms', 'external_connectivity' ), true ) || ! in_array( $condition['operator'] ?? '', array( 'eq', 'gt', 'lt' ), true ) || ! is_int( $condition['threshold'] ?? null ) || $condition['threshold'] < 0 || $condition['threshold'] > 100000000 ) return MAD4B_SCP_CSO_Domain_Plans::error( 'monitor_condition_invalid' );
            $row = MAD4B_SCP_CSO_Domain_Plans::select( $condition, array( 'metric', 'operator', 'threshold' ) ); $key = MAD4B_SCP_CSO_Scope::digest( $row );
            if ( is_wp_error( $key ) ) return $key;
            if ( isset( $seen[ $key ] ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'monitor_duplicate_condition' );
            $seen[ $key ] = true; $clean[] = $row;
        }
        usort( $clean, static function( $a, $b ) { return strcmp( $a['metric'] . $a['operator'] . $a['threshold'], $b['metric'] . $b['operator'] . $b['threshold'] ); } );
        $dedup = MAD4B_SCP_CSO_Scope::digest( array( 'scope' => $scope, 'conditions' => $clean, 'interval_seconds' => $interval ) ); if ( is_wp_error( $dedup ) ) return $dedup;
        return MAD4B_SCP_CSO_Domain_Plans::finish( 'monitor_plan', $scope, array(
            'subscription' => array( 'conditions' => $clean, 'interval_seconds' => $interval, 'ttl_seconds' => $ttl, 'dedup_sha256' => $dedup, 'dedup_window_seconds' => $interval, 'expires_at' => time() + $ttl ),
            'connectivity' => self::connectivity( $scope ), 'notification_destinations' => array(), 'notification_dispatch_allowed' => false,
            'subscription_persisted' => false, 'job_scheduled' => false, 'outbound_probe_performed' => false,
            'retention_policy_required' => true, 'alert_payload' => array( 'metric', 'severity', 'state', 'opaque_operation_reference', 'observed_at' ),
            'alerts_create_authority' => false, 'next_step' => 'review_subscription_and_separately_enroll_monitoring_executor' ), array( 'monitoring_executor_not_enrolled' ) );
    }

    /** A recent independently verified handshake never proves continuous uptime. */
    private static function connectivity( array $scope ) {
        $out = array( 'state' => 'UNKNOWN', 'online_claim' => false, 'fresh_external_receipt' => false, 'continuous_uptime_verified' => false, 'observed_at' => '', 'reason' => 'independent_external_receipt_unavailable' );
        if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'external_handshake_attestation_status' ) ) return $out;
        $receipt = MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status(); if ( ! is_array( $receipt ) ) return $out;
        $observed = $receipt['observed_at'] ?? ''; $stamp = is_string( $observed ) && '' !== $observed ? strtotime( $observed . ' UTC' ) : false;
        $fresh = false !== $stamp && $stamp <= time() + 5 && $stamp >= time() - 300;
        $exact = ( $receipt['current_source_commit_sha'] ?? null ) === $scope['source_sha'] && ( $receipt['current_package_manifest_digest'] ?? null ) === $scope['package_sha256'];
        $verified = true === ( $receipt['verified'] ?? null ) && true === ( $receipt['real_external_session'] ?? null ) && true === ( $receipt['package_identity_match'] ?? null ) && true === ( $receipt['build_fingerprint_match'] ?? null ) && $fresh && $exact;
        $out['state'] = $verified ? 'FRESH_EXTERNAL_HANDSHAKE' : 'STALE_OR_UNVERIFIED_EXTERNAL_HANDSHAKE'; $out['fresh_external_receipt'] = $verified;
        $out['observed_at'] = false !== $stamp ? gmdate( 'c', $stamp ) : ''; $out['reason'] = $verified ? 'fresh_independent_external_handshake_observed' : 'current_source_package_and_freshness_required';
        return $out;
    }

    public static function drift_plan( array $input ) {
        $scope = MAD4B_SCP_CSO_Domain_Plans::context( $input ); if ( is_wp_error( $scope ) ) return $scope;
        $name = $input['capability'] ?? ''; $reference = $input['reference'] ?? null; $target = $input['target'] ?? array();
        if ( ! is_string( $name ) || ! is_array( $reference ) || ! is_array( $target ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_reference_required' );
        foreach ( array( 'schema_sha256', 'adapter_sha256', 'binding_sha256' ) as $key ) if ( ! MAD4B_SCP_CSO_Domain_Plans::sha( $reference[ $key ] ?? null ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_fingerprint_invalid' );
        $descriptor = MAD4B_SCP_CSO_Registry::describe( $name, $target ); if ( is_wp_error( $descriptor ) ) return $descriptor;
        foreach ( array( 'schema_sha256', 'adapter_sha256' ) as $key ) if ( ! MAD4B_SCP_CSO_Domain_Plans::sha( $descriptor[ $key ] ?? null ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_current_descriptor_invalid' );
        $schema = ! hash_equals( $reference['schema_sha256'], $descriptor['schema_sha256'] ); $adapter = ! hash_equals( $reference['adapter_sha256'], $descriptor['adapter_sha256'] );
        $identity = ! hash_equals( $reference['binding_sha256'], $scope['binding_sha256'] ); $changed = array(); $observation = 'NOT_REQUESTED'; $target_verified = false; $revision_verified = false;
        if ( isset( $input['desired_values'] ) ) {
            if ( ! is_array( $input['desired_values'] ) || count( $input['desired_values'] ) > 100 || ! is_array( $input['preparation'] ?? null ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_read_preparation_required' );
            $witness = $descriptor['readback'] ?? null;
            if ( ! is_array( $witness ) || true !== ( $witness['independently_read_required'] ?? null ) || ! is_string( $witness['ability_name'] ?? null ) || ! is_array( $witness['capability_binding'] ?? null ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_trusted_native_readback_required' );
            foreach ( array( 'input_map', 'target_map', 'field_map' ) as $map ) if ( ! is_array( $witness[ $map ] ?? null ) || ! $witness[ $map ] || count( $witness[ $map ] ) > 100 ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_typed_readback_mapping_required' );
            $params = array();
            // Target is the original native write input. A client callback/path cannot choose the witness.
            foreach ( $witness['input_map'] as $read_path => $write_path ) {
                $value = self::path_value( $target, $write_path, $found );
                if ( ! $found || ! self::set_path( $params, $read_path, $value ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_target_input_mapping_invalid' );
            }
            if ( ( isset( $input['observation_capability'] ) && $input['observation_capability'] !== $witness['ability_name'] ) || ( isset( $input['observation_input'] ) && $input['observation_input'] !== $params ) || ! empty( $input['observation_path'] ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_untrusted_observation_denied' );
            $read_descriptor = MAD4B_SCP_CSO_Registry::describe( $witness['ability_name'], $params ); if ( is_wp_error( $read_descriptor ) ) return $read_descriptor;
            $a = MAD4B_SCP_CSO_Scope::digest( $witness['capability_binding'] ); $b = MAD4B_SCP_CSO_Scope::digest( $read_descriptor['capability_binding'] ?? null );
            if ( is_wp_error( $a ) || is_wp_error( $b ) || ! hash_equals( $a, $b ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_readback_binding_changed' );
            $observed = MAD4B_SCP_CSO_Domain_Plans::read( $witness['ability_name'], $params, $input['preparation'] ); if ( is_wp_error( $observed ) ) return $observed;
            foreach ( $witness['target_map'] as $read_path => $write_path ) {
                $actual = self::path_value( $observed, $read_path, $read_found ); $expected = self::path_value( $target, $write_path, $write_found );
                if ( ! $read_found || ! $write_found || $actual !== $expected || ! is_scalar( $expected ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_native_target_mismatch' );
            }
            $target_verified = true; self::path_value( $observed, $witness['revision_path'] ?? null, $found );
            if ( ! $found ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_native_revision_missing' );
            if ( isset( $witness['revision_input'] ) ) {
                $actual = self::path_value( $observed, $witness['revision_path'], $read_found ); $expected = self::path_value( $target, $witness['revision_input'], $write_found );
                $revision_verified = $read_found && $write_found && is_scalar( $actual ) && $actual === $expected;
                if ( ! $revision_verified ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_native_revision_changed' );
            }
            foreach ( $input['desired_values'] as $field => $value ) {
                if ( ! self::valid_path( $field ) || ! array_key_exists( $field, $witness['field_map'] ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_unmapped_field_denied' );
                $actual = self::path_value( $observed, $witness['field_map'][ $field ], $found ); if ( ! $found ) return MAD4B_SCP_CSO_Domain_Plans::error( 'drift_native_field_missing' );
                if ( $actual !== $value ) $changed[] = $field;
            }
            sort( $changed, SORT_STRING ); $observation = 'TRUSTED_NATIVE_TARGET_READBACK';
        }
        $invalidated = $schema || $adapter || $identity;
        return MAD4B_SCP_CSO_Domain_Plans::finish( 'drift_plan', $scope, array( 'descriptor' => MAD4B_SCP_CSO_Domain_Plans::descriptor_summary( $descriptor ),
            'schema_changed' => $schema, 'adapter_changed' => $adapter, 'identity_changed' => $identity, 'stale_descriptor_invalidated' => $invalidated,
            'pending_form_reuse_allowed' => ! $invalidated, 'adapter_state_proposal' => $invalidated ? 'SUSPENDED' : 'CURRENT', 'adapter_state_persisted' => false,
            'changed_fields' => $changed, 'changed_field_count' => count( $changed ), 'value_observation' => $observation, 'target_binding_verified' => $target_verified, 'target_revision_verified' => $revision_verified,
            'values_disclosed' => false, 'low_entropy_value_hashes_disclosed' => false, 'repair_authorized' => false,
            'next_step' => $invalidated ? 'rediscover_recertify_and_issue_new_descriptor' : ( $changed ? 'review_scoped_diff_and_generate_new_plan' : 'retain_current_structural_descriptor' ) ), $invalidated ? array( 'schema_adapter_or_site_drift_invalidates_pending_operations' ) : array() );
    }
    private static function valid_path( $path ) { return is_string( $path ) && strlen( $path ) <= 191 && 1 === preg_match( '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,5}$/D', $path ); }
    private static function path_value( $data, $path, &$found ) {
        $found = false; if ( ! self::valid_path( $path ) ) return null;
        foreach ( explode( '.', $path ) as $key ) { if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) return null; $data = $data[ $key ]; }
        $found = true; return $data;
    }
    private static function set_path( array &$data, $path, $value ) {
        if ( ! self::valid_path( $path ) ) return false; $parts = explode( '.', $path ); $last = array_pop( $parts ); $cursor =& $data;
        foreach ( $parts as $key ) { if ( isset( $cursor[ $key ] ) && ! is_array( $cursor[ $key ] ) ) return false; if ( ! isset( $cursor[ $key ] ) ) $cursor[ $key ] = array(); $cursor =& $cursor[ $key ]; }
        if ( array_key_exists( $last, $cursor ) && $cursor[ $last ] !== $value ) return false; $cursor[ $last ] = $value; return true;
    }

    public static function doctor_plan( array $input ) {
        $scope = MAD4B_SCP_CSO_Domain_Plans::context( $input ); if ( is_wp_error( $scope ) ) return $scope;
        $domain = $input['domain'] ?? 'operations';
        $sources = array( 'operations' => 'mad4b/operator-doctor', 'connection' => 'mad4b/connection-doctor', 'acceptance' => 'mad4b/operational-remediation-status', 'runtime' => 'mad4b/runtime-functional-gap-diagnostic' );
        if ( ! is_string( $domain ) || ! isset( $sources[ $domain ] ) || ! is_array( $input['preparation'] ?? null ) || ! is_array( $input['diagnostic_input'] ?? array() ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'doctor_native_preparation_required' );
        $params = $input['diagnostic_input'] ?? array();
        foreach ( array( 'include_live_acceptance', 'include_authoritative_content', 'include_rendered_frontend', 'execute', 'repair' ) as $key ) if ( ! empty( $params[ $key ] ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'doctor_active_probe_denied' );
        $native = MAD4B_SCP_CSO_Domain_Plans::read( $sources[ $domain ], $params, $input['preparation'] ); if ( is_wp_error( $native ) ) return $native;
        $findings = array(); $followups = array();
        foreach ( array_slice( is_array( $native['findings'] ?? null ) ? $native['findings'] : array(), 0, 100 ) as $finding ) if ( is_array( $finding ) ) $findings[] = MAD4B_SCP_CSO_Domain_Plans::select( $finding, array( 'finding_id', 'id', 'severity', 'reason_code', 'code', 'kind' ) );
        foreach ( array_slice( is_array( $native['work_items'] ?? null ) ? $native['work_items'] : array(), 0, 100 ) as $item ) {
            if ( ! is_array( $item ) ) continue; $findings[] = MAD4B_SCP_CSO_Domain_Plans::select( $item, array( 'gate_id', 'owner', 'state', 'status' ) );
            foreach ( array_slice( is_array( $item['paths'] ?? null ) ? $item['paths'] : array(), 0, 10 ) as $path ) {
                if ( ! is_array( $path ) || ! is_string( $path['apply_ability'] ?? null ) || '' === $path['apply_ability'] ) continue;
                $descriptor = MAD4B_SCP_CSO_Registry::describe( $path['apply_ability'] ); $available = is_array( $descriptor ); $lane = $available ? ( $descriptor['lane'] ?? 'unclassified' ) : 'unavailable';
                $registered = $available || true === ( $path['apply_registered'] ?? null );
                $followups[] = array( 'action_id' => is_string( $path['action_id'] ?? null ) ? $path['action_id'] : '', 'capability' => $path['apply_ability'],
                    'registered' => $registered, 'current_descriptor_available' => $available, 'lane' => $lane, 'schema_sha256' => $available ? ( $descriptor['schema_sha256'] ?? '' ) : '',
                    'executor' => in_array( $path['executor'] ?? '', array( 'host', 'developer', 'operator', 'ability', 'browser', 'provider' ), true ) ? $path['executor'] : 'unclassified',
                    'input_schema' => $available ? ( $descriptor['input_schema'] ?? array() ) : array(),
                    'executor_status' => ! $available ? ( $registered ? 'REGISTERED_NATIVE_DESCRIPTOR_BLOCKED' : 'MISSING' ) : ( in_array( $lane, array( 'developer', 'host', 'breakglass', 'none', 'unclassified' ), true ) ? 'SEPARATE_HOST_OR_DEVELOPER_ADMISSION_REQUIRED' : 'SEPARATE_NATIVE_AUTHORIZATION_REQUIRED' ),
                    'readback_capability' => is_string( $path['readback_ability'] ?? null ) ? $path['readback_ability'] : '', 'native_readback_registered' => true === ( $path['readback_registered'] ?? null ),
                    'owner_approval_required' => true, 'execution_allowed' => false, 'independent_postcondition_required' => true );
            }
        }
        $blockers = is_array( $native['plan_integrity_blockers'] ?? null ) ? array_values( $native['plan_integrity_blockers'] ) : array();
        if ( 'connection' === $domain ) $blockers[] = 'independent_public_edge_acceptance_required';
        return MAD4B_SCP_CSO_Domain_Plans::finish( 'doctor_plan', $scope, array( 'domain' => $domain, 'source_capability' => $sources[ $domain ],
            'source_contract' => $native['contract'] ?? '', 'findings' => $findings, 'finding_count' => count( $findings ), 'followups' => $followups,
            'source_plan_sha256' => $native['plan_sha256'] ?? '', 'bounded_passive_diagnostic' => true, 'host_or_developer_disabled_lane_bypass_allowed' => false,
            'fixes_executed' => 0, 'retry_or_requeue_performed' => false, 'next_step' => $findings ? 'review_finding_and_prepare_one_exact_native_remediation' : 'no_findings_observed_in_this_diagnostic_scope' ), $blockers );
    }

    /** Read existing aggregate counters; never enable conversation event collection. */
    public static function metrics_plan( array $input ) {
        $scope = MAD4B_SCP_CSO_Domain_Plans::context( $input ); if ( is_wp_error( $scope ) ) return $scope;
        if ( true !== ( $input['opt_in'] ?? null ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'metrics_explicit_opt_in_required' );
        $hours = $input['hours'] ?? 24; if ( ! is_int( $hours ) || $hours < 1 || $hours > 720 ) return MAD4B_SCP_CSO_Domain_Plans::error( 'metrics_window_invalid' );
        $native = class_exists( 'MAD4B_SCP_Observability' ) ? MAD4B_SCP_Observability::slo_status( $hours ) : null; $stages = array(); $blockers = array(); $available = 0;
        if ( ! is_array( $native ) || 'mad4b.observability.v1' !== ( $native['contract'] ?? null ) ) $blockers[] = 'native_aggregate_metrics_unavailable';
        else foreach ( array_slice( is_array( $native['stages'] ?? null ) ? $native['stages'] : array(), 0, 32, true ) as $name => $stage ) {
            if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9_.-]{0,63}$/D', $name ) || ! is_array( $stage ) ) continue; $row = array();
            foreach ( array( 'samples', 'errors', 'p50_ms', 'p95_ms', 'p99_ms', 'error_ratio', 'burn_rate' ) as $metric ) if ( isset( $stage[ $metric ] ) && ( is_int( $stage[ $metric ] ) || is_float( $stage[ $metric ] ) ) && is_finite( (float) $stage[ $metric ] ) && $stage[ $metric ] >= 0 ) $row[ $metric ] = $stage[ $metric ];
            $row['ready'] = true === ( $stage['ready'] ?? null ); if ( $row['ready'] ) ++$available;
            $row['sampled'] = $row['ready'] && ( $row['samples'] ?? 0 ) > 0; $row['burn_state'] = $row['ready'] && in_array( $stage['burn_state'] ?? '', array( 'healthy', 'warning', 'critical' ), true ) ? $stage['burn_state'] : 'unknown'; $stages[ $name ] = $row;
        }
        if ( ! $available ) $blockers[] = 'no_native_metric_stage_available';
        return MAD4B_SCP_CSO_Domain_Plans::finish( 'ux_metrics_plan', $scope, array( 'hours' => $hours, 'stages' => $stages, 'source' => 'existing_observability_aggregates', 'available_stage_count' => $available,
            'new_event_collection_enabled' => false, 'conversation_values_exported' => false, 'analytics_destination_enrolled' => false,
            'collection_policy' => array( 'allowed_events' => array( 'form_started', 'form_completed', 'form_abandoned', 'operation_retry', 'operation_latency' ), 'labels' => array( 'operation_family', 'state', 'duration_bucket', 'error_class' ), 'secrets_or_field_values_allowed' => false, 'tenant_retention_policy_required' => true ),
            'cost_data_observed' => false, 'completion_or_abandonment_data_observed' => false, 'next_step' => 'approve_privacy_and_retention_policy_before_new_event_collection' ), $blockers );
    }

    public static function accessibility_report( array $input ) {
        $scope = MAD4B_SCP_CSO_Domain_Plans::context( $input, 'forms' ); if ( is_wp_error( $scope ) ) return $scope;
        if ( ! is_string( $input['capability'] ?? null ) || ! is_array( $input['target'] ?? array() ) ) return MAD4B_SCP_CSO_Domain_Plans::error( 'capability_invalid' );
        $descriptor = MAD4B_SCP_CSO_Registry::describe( $input['capability'], $input['target'] ?? array() ); if ( is_wp_error( $descriptor ) ) return $descriptor;
        $fields = is_array( $descriptor['field_metadata'] ?? null ) ? $descriptor['field_metadata'] : array(); $missing = array();
        foreach ( array_slice( $fields, 0, 100, true ) as $id => $field ) if ( ! is_array( $field ) || ! is_string( $field['label'] ?? null ) || '' === trim( $field['label'] ) ) $missing[] = (string) $id;
        $rtl = 1 === preg_match( '/^(ar|fa|he|ur)(?:[_-]|$)/i', $scope['locale'] ); $blockers = array( 'independent_keyboard_screenreader_and_focus_acceptance_required' );
        if ( $missing || ! $fields ) $blockers[] = 'declared_field_labels_missing';
        return MAD4B_SCP_CSO_Domain_Plans::finish( 'form_accessibility_report', $scope, array( 'schema_sha256' => $descriptor['schema_sha256'] ?? '', 'locale' => $scope['locale'],
            'direction' => $rtl ? 'rtl' : 'ltr', 'machine_identifiers_localized' => false, 'declared_field_count' => count( $fields ), 'missing_label_fields' => $missing,
            'structural_labels_ready' => ! $missing && count( $fields ) > 0,
            'required_render_contract' => array( 'associated_labels', 'aria_describedby_help_and_errors', 'keyboard_navigation', 'error_summary_focus', 'no_keyboard_trap', 'unicode_roundtrip', 'logical_css_direction' ),
            'browser_acceptance_verified' => false, 'accessibility_certified' => false ), $blockers );
    }
}
