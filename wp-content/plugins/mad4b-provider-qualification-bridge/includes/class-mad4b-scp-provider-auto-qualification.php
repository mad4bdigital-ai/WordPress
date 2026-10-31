<?php
defined('ABSPATH') || exit;

/**
 * Generic demand-driven qualification of each exact native provider ability.
 * This is a native-owner execution handoff policy, not an alternative writer,
 * queue, approval authority, provider receipt, or synthetic certification.
 */
final class MAD4B_SCP_Provider_Auto_Qualification {
    const CONTRACT = 'mad4b.provider-auto-qualification.v1';
    const LIMIT = 96;

    public static function plan(array $assessment, $required_ability = '', array $runtime = array()) {
        $provider = (string)($assessment['provider_id'] ?? $assessment['provider'] ?? '');
        $artifact = (array)($assessment['artifact'] ?? array());
        $capabilities = (array)($assessment['capabilities'] ?? array());
        $required_ability = trim((string)$required_ability);
        $out = array();
        $counts = array();
        foreach ($capabilities as $capability_id => $capability) {
            if (count($out) >= self::LIMIT) break;
            if (!is_array($capability)) continue;
            $abilities = array_values(array_filter((array)($capability['mounted_abilities'] ?? array()), 'is_string'));
            if ($required_ability !== '' && !in_array($required_ability, $abilities, true)) continue;
            $risk = (string)($capability['risk'] ?? 'unknown');
            $bound = !empty($capability['artifact_authority_bound']);
            $structural = !empty($capability['structural_compatible']);
            $available = !empty($assessment['available']);
            $reversible = !empty($capability['reversible']);
            $native_write_eligible = !empty($capability['write_eligible']);
            $behavioral = (array)($capability['behavioral_evidence'] ?? array());
            $verified = !empty($behavioral['behavioral_verified']);
            $rollback_verified = !empty($behavioral['rollback_verified']);
            $is_write = $risk !== 'read';
            $state = 'WAITING_DEPENDENCY';
            $next = 'native_provider_discovery';
            $reason = 'provider_not_available';
            if (empty($abilities)) {
                $state = 'SKIPPED_NOT_APPLICABLE';
                $next = 'none';
                $reason = 'ability_not_exposed_by_original_adapter';
            } elseif (!$available) {
                $next = 'native_provider_runtime_reconcile';
            } elseif (!$structural) {
                $state = 'NEEDS_RECONCILIATION';
                $next = 'native_structural_compatibility_reconcile';
                $reason = 'structural_contract_failed';
            } elseif (!$bound && $is_write) {
                $state = 'RECOVERING';
                $next = 'auto_resolve_exact_active_provider_artifact';
                $reason = 'provider_artifact_identity_unresolved';
            } elseif (!$is_write) {
                $state = 'READY';
                $next = 'native_read_execution_and_readback';
                $reason = 'read_compatible_not_execution_receipt';
            } elseif ($native_write_eligible) {
                // The native provider certificate is read, not created here.
                $state = 'READY';
                $next = 'native_mutation_preflight_with_original_grants';
                $reason = 'native_provider_write_certified';
            } elseif ($risk === 'high_risk_write') {
                $state = 'WAITING_PREFLIGHT';
                $next = 'native_canary_and_activation_lifecycle';
                $reason = 'high_risk_native_canary_required';
            } elseif (!$reversible) {
                $state = 'WAITING_PREFLIGHT';
                $next = 'native_provider_nonreversible_review';
                $reason = 'no_declared_reversible_probe';
            } elseif (!$verified || !$rollback_verified) {
                $provenance = (string)($runtime['build_provenance_state'] ?? 'unknown');
                if ($provenance !== 'exact_current') {
                    $state = 'RECOVERING';
                    $next = 'auto_reconcile_native_build_provenance';
                    $reason = 'build_provenance_not_exact';
                } else {
                    $state = 'WAITING_PREFLIGHT';
                    $next = 'native_provider_behavioral_recertify_with_exact_approval';
                    $reason = 'native_reversible_probe_pending';
                }
            } else {
                // Behavioral receipt exists but activation/revalidation is still
                // owned by the canonical compatibility certification.
                $state = 'WAITING_PREFLIGHT';
                $next = 'native_receipt_activation_and_reassessment';
                $reason = 'native_write_activation_pending';
            }
            $out[] = array(
                'provider_id' => $provider,
                'capability_id' => (string)$capability_id,
                'native_abilities' => $abilities,
                'risk' => $risk,
                'state' => $state, 'reason' => $reason,
                'next_native_action' => $next,
                'artifact_fingerprint' => (string)($artifact['runtime_artifact_fingerprint'] ?? ''),
                'capability_contract_digest' => (string)($capability['capability_contract_digest'] ?? ''),
                'structural_compatible' => $structural,
                'artifact_identity_bound' => $bound,
                'reversible' => $reversible,
                'behavioral_verified' => $verified,
                'rollback_verified' => $rollback_verified,
                'native_write_eligible' => $native_write_eligible,
                'required_for_task' => $required_ability !== '',
                'native_executor' => $next,
                'no_automatic_mutation_without_existing_authority' => true,
                'dispatch_performed' => false,
                'blind_retry_allowed' => false,
                'mutation_performed' => false,
            );
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }
        return array(
            'contract' => self::CONTRACT,
            'provider_id' => $provider,
            'required_ability' => $required_ability,
            'matched_count' => count($out),
            'states' => $counts,
            'capabilities' => $out,
            'automatic_discovery' => true,
            'automatic_qualification_planning' => true,
            'automatic_native_dependency_reconciliation' => true,
            'native_handoff_when_authorized' => true,
            'automatic_new_grants' => false,
            'automatic_synthetic_certification' => false,
            'automatic_unapproved_write_probe' => false,
            'runtime_authorized_by_this_plan' => false,
            'mutation_performed' => false,
            'read_only' => true,
        );
    }
}
