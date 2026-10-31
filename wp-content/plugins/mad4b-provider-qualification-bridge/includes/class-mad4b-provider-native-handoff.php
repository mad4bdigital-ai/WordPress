<?php
defined('ABSPATH') || exit;

/**
 * Build an exact native recertification request for an already-authorized
 * executor. Read-only: no approval ticket, dispatch, mutation, or receipt.
 */
final class MAD4B_Provider_Native_Handoff {
    const CONTRACT = 'mad4b.provider-native-recertification-handoff.v1';

    public static function plan($input = array()) {
        if (!is_array($input) || !class_exists('MAD4B_Provider_Qualification_Bridge')
            || !MAD4B_Provider_Qualification_Bridge::allowed($input))
            return new WP_Error('mad4b_handoff_denied', 'Native read authority required.');
        $ability = trim((string)($input['ability'] ?? ''));
        $provider = sanitize_key((string)($input['provider_id'] ?? ''));
        $target = $input['target_input'] ?? null;
        if ($ability === '' || !is_array($target) || !$target
            || strlen((string)wp_json_encode($target)) > 32768)
            return new WP_Error('mad4b_handoff_input_invalid', 'An exact ability and bounded nonempty target_input are required.');
        $selector = array('ability' => $ability);
        if ($provider !== '') $selector['provider_id'] = $provider;
        $plan = MAD4B_Provider_Qualification_Bridge::plan($selector);
        if (is_wp_error($plan)) return $plan;
        if (1 !== (int)($plan['matched_count'] ?? 0))
            return new WP_Error('mad4b_handoff_ambiguous', 'Expected exactly one native capability.');
        $capability = $plan['capabilities'][0] ?? array();
        if (($capability['risk'] ?? '') !== 'bounded_write'
            || empty($capability['reversible'])
            || empty($capability['artifact_identity_bound'])
            || empty($capability['structural_compatible'])
            || !empty($capability['native_write_eligible']))
            return new WP_Error('mad4b_handoff_ineligible', 'Only uncertified reversible bounded writes are eligible.');
        if (($plan['native_provenance_state'] ?? '') !== 'exact_current')
            return new WP_Error('mad4b_handoff_stale', 'Exact build provenance required.');
        if (!class_exists('MAD4B_SCP_Live_Acceptance_Observer'))
            return new WP_Error('mad4b_handoff_provenance_unavailable', 'Canonical observer unavailable.');
        $build = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
        if (!is_array($build) || empty($build['manifest_valid']) || empty($build['runtime_manifest_match'])
            || !empty($build['stale']))
            return new WP_Error('mad4b_handoff_candidate_invalid', 'Runtime candidate not current.');
        $payload = array(
            'provider_id' => (string)$plan['provider_id'],
            'capability_id' => (string)$capability['capability_id'],
            'target_ability' => $ability,
            'expected_candidate_sha' => (string)($build['source_commit_sha'] ?? ''),
            'expected_build_fingerprint' => (string)($build['build_fingerprint'] ?? ''),
            'expected_artifact_fingerprint' => (string)($capability['artifact_fingerprint'] ?? ''),
            'expected_capability_contract_digest' => (string)($capability['capability_contract_digest'] ?? ''),
            'target_input' => $target,
        );
        if (!class_exists('MAD4B_SCP_Provider_Behavioral_Recertification'))
            return new WP_Error('mad4b_handoff_owner_missing', 'Canonical recertification owner unavailable.');
        $preflight = MAD4B_SCP_Provider_Behavioral_Recertification::validate_context($payload);
        if (is_wp_error($preflight))
            return new WP_Error('mad4b_handoff_preflight_blocked', 'Canonical preflight rejected the request.',
                array('native_error' => $preflight->get_error_code()));
        return array(
            'contract' => self::CONTRACT,
            'state' => 'READY_FOR_NATIVE_EXECUTOR',
            'approval_server' => 'mad4b-write',
            'approval_provider' => 'core',
            'approval_ability' => 'mad4b/provider-behavioral-recertify',
            'approval_input' => $payload,
            'required_native_receipt' => 'exact_write_readback_rollback_ticket_terminal',
            'requires_original_execution_identity' => true,
            'native_executor_claim_required' => true,
            'automatic_resume_after_valid_receipt' => true,
            'approval_ticket_created' => false,
            'probe_executed' => false,
            'rollback_verified' => false,
            'dispatch_performed' => false,
            'mutation_performed' => false,
            'read_only' => true,
        );
    }
}
