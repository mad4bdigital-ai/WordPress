<?php
defined('ABSPATH') || exit;
/**
 * Global, non-authorizing workflow state projector. Used across MAD4B domains.
 * Native providers alone own admission, execution, retries and readback.
 * A registered ability or an unmet predecessor is never a hard blocker.
 */
final class MAD4B_SCP_Global_Runtime_Lifecycle {
    const CONTRACT = 'mad4b.global-runtime-lifecycle.v1.1';
    const MAX_NODES = 128;
    const STATES = array('PENDING','QUEUED','READY','RUNNING','WAITING_DEPENDENCY',
        'WAITING_PREFLIGHT','RECOVERING','BLOCKED_AUTHORITY','BLOCKED_DEPENDENCY',
        'NEEDS_RECONCILIATION','WAITING_VERIFICATION','CANCELLED','SKIPPED_NOT_APPLICABLE','VERIFIED');

    public static function classify_error($code) {
        $code = is_object($code) && method_exists($code, 'get_error_code')
            ? (string) $code->get_error_code() : (string) $code;
        if (strlen($code) > 180 || !preg_match('/^[a-zA-Z0-9_.:-]+$/D', $code))
            $code = 'unclassified_error';
        if (preg_match('/(commit_uncertain|rollback_uncertain|write_unknown|journal_recovery|readback_failed|reconciliation_required)/i', $code))
            return array('state'=>'NEEDS_RECONCILIATION','retry_write'=>false,'action'=>'read_native_journal_before_any_retry');
        if (preg_match('/(permission|authority|cap_denied|identity_required|certif|grant|unauthoriz|approval_required|binding_stale)/i', $code))
            return array('state'=>'BLOCKED_AUTHORITY','retry_write'=>false,'action'=>'native_authority_remediation');
        if (preg_match('/(wpcli_busy|lock_busy|timeout|rate_limit|backpressure|temporary_unavailable|connection_reset)/i', $code))
            return array('state'=>'RECOVERING','retry_write'=>false,'action'=>'bounded_recheck_then_provider_owned_resume');
        return array('state'=>'BLOCKED_DEPENDENCY','retry_write'=>false,'action'=>'discover_or_repair_exact_dependency');
    }

    private static function invalid($detail) {
        return new WP_Error('mad4b_global_lifecycle_invalid_graph', $detail);
    }
    /**
     * Nodes are topologically ordered, with unique ids. Evidence is optional,
     * sourced only from an original provider; caller-supplied evidence cannot
     * grant any write or dispatch. This method is strictly a projection.
     */
    public static function evaluate(array $nodes, array $evidence = array()) {
        if (!$nodes || count($nodes) > self::MAX_NODES)
            return self::invalid('Graph must have 1..128 stages.');
        $known = array(); $rows = array();
        $groups = array('ready'=>array(), 'waiting'=>array(), 'blocked'=>array(),
            'recovering'=>array(), 'running'=>array(), 'verified'=>array(), 'skipped'=>array());
        foreach ($nodes as $node) {
            if (!is_array($node)) return self::invalid('Invalid stage row.');
            $id = (string) ($node['id'] ?? '');
            $kind = (string) ($node['kind'] ?? 'read');
            $deps = $node['depends_on'] ?? array();
            if (!preg_match('/^[a-z][a-z0-9_:-]{0,63}$/D', $id) || isset($known[$id])
                || !in_array($kind, array('read','write','verify','external','release'), true)
                || !is_array($deps)) return self::invalid('Invalid stage identity or kind.');
            $wait = array();
            foreach ($deps as $dep) {
                if (!is_string($dep) || !isset($known[$dep]))
                    return self::invalid('Non-topological, cyclic or missing predecessor.');
                if (!in_array($known[$dep], array('VERIFIED','SKIPPED_NOT_APPLICABLE'), true))
                    $wait[] = $dep;
            }
            $proof = isset($evidence[$id]) && is_array($evidence[$id]) ? $evidence[$id] : array();
            $observed = (string) ($proof['status'] ?? '');
            $authoritative = !empty($proof['native_source_confirmed']);
            $state = 'READY'; $reason = 'eligible_for_provider_owned_stage';
            $ability = $node['ability_registered'] ?? null;
            // A source receipt must be bound to the exact stage and target.
            $verified = $authoritative && 'verified' === $observed
                && !empty($proof['readback_confirmed'])
                && !empty($proof['exact_target_bound'])
                && !empty($proof['receipt_digest']);
            if ($authoritative && $observed === 'not_applicable' && !empty($proof['exact_target_bound'])
                && !empty($proof['not_applicable_proven'])) {
                $state = 'SKIPPED_NOT_APPLICABLE'; $reason = 'native_scope_not_applicable';
            } elseif ($verified) {
                $state = 'VERIFIED'; $reason = 'exact_native_readback_verified';
            } elseif ($authoritative && in_array($observed, array('commit_uncertain','rollback_uncertain','readback_failed'), true)) {
                $state = 'NEEDS_RECONCILIATION'; $reason = 'uncertain_native_mutation';
            } elseif ($authoritative && $observed === 'denied') {
                $state = 'BLOCKED_AUTHORITY'; $reason = 'exact_native_authority_denial';
            } elseif ($authoritative && $observed === 'missing_dependency') {
                $state = 'BLOCKED_DEPENDENCY'; $reason = 'proven_required_dependency_missing';
            } elseif ($authoritative && in_array($observed, array('busy','recovering','retry_scheduled'), true)) {
                $state = 'RECOVERING'; $reason = 'native_bounded_recovery';
            } elseif ($authoritative && $observed === 'running') {
                $state = 'RUNNING'; $reason = 'native_execution_in_progress';
            } elseif ($authoritative && $observed === 'queued') {
                $state = 'QUEUED'; $reason = 'native_queue_accepted';
            } elseif (!empty($node['condition']) && empty($node['condition_evaluated'])) {
                $state = 'PENDING'; $reason = 'conditional_stage_scope_not_yet_evaluated';
            } elseif (false === $ability && array_key_exists('required',$node) && $node['required'] === false) {
                $state = 'PENDING'; $reason = 'optional_ability_absent_not_hard_blocker';
            } elseif (false === $ability) {
                $state = 'BLOCKED_DEPENDENCY'; $reason = 'registered_ability_missing';
            } elseif ($wait) {
                $state = 'WAITING_DEPENDENCY'; $reason = 'ordinary_predecessor_not_yet_verified';
            } elseif (in_array($kind, array('write','release'), true)) {
                // Never treat eligibility as authority. The original executor
                // must acquire identity, provider admission and CAS itself.
                $state = 'WAITING_PREFLIGHT'; $reason = 'native_execution_requires_exact_principal_and_provider_gates';
            } elseif (null === $ability && !empty($node['requires_source_owner'])) {
                $state = 'WAITING_PREFLIGHT'; $reason = 'original_provider_readback_required';
            }
            $row = array(
                'id'=>$id,'kind'=>$kind,'depends_on'=>array_values($deps),
                'state'=>$state,'reason'=>$reason,'waiting_on'=>$wait,
                'ability_registered'=>$ability,'native_receipt_verified'=>$verified,
                'dispatch_candidate'=> 'READY' === $state,
                'write_authorized'=>false,'write_executed'=>false,
                'requires_native_preflight'=>in_array($kind, array('write','release'), true),
            );
            if (isset($node['label'])) $row['label'] = (string) $node['label'];
            $rows[] = $row; $known[$id] = $state;
            if ($state === 'READY') $groups['ready'][] = $id;
            elseif (in_array($state, array('PENDING','QUEUED','WAITING_DEPENDENCY','WAITING_PREFLIGHT'), true))
                $groups['waiting'][] = $id;
            elseif (in_array($state, array('BLOCKED_AUTHORITY','BLOCKED_DEPENDENCY','NEEDS_RECONCILIATION'), true))
                $groups['blocked'][] = $id;
            elseif ($state === 'RECOVERING') $groups['recovering'][] = $id;
            elseif ($state === 'RUNNING') $groups['running'][] = $id;
            elseif ($state === 'VERIFIED') $groups['verified'][] = $id;
            elseif ($state === 'SKIPPED_NOT_APPLICABLE') $groups['skipped'][] = $id;
        }
        $total=count($rows);
        $completed=count($groups['verified'])+count($groups['skipped']);
        if ($completed===$total) $overall='VERIFIED';
        elseif ($groups['blocked'] && ($groups['ready']||$groups['waiting']||$groups['running']||$groups['recovering']))
            $overall='PARTIAL';
        elseif ($groups['blocked']) $overall='BLOCKED';
        elseif ($groups['running']||$groups['ready']||$groups['recovering']) $overall='ACTIVE';
        else $overall='WAITING';
        return array(
            'contract'=>self::CONTRACT,'state'=>$overall,'steps'=>$rows,
            'ready_steps'=>$groups['ready'],'waiting_steps'=>$groups['waiting'],
            'blocked_steps'=>$groups['blocked'],'recovering_steps'=>$groups['recovering'],
            'running_steps'=>$groups['running'],'verified_steps'=>$groups['verified'],
            'skipped_steps'=>$groups['skipped'],
            'ordinary_wait_is_not_blocking'=>true,
            'native_preflight_not_substituted'=>true,
            'native_write_authority_granted'=>false,
            'automatic_provider_write_performed'=>false,
            'journal_or_cas_modified'=>false,
            'scope'=>'all_mad4b_domains',
        );
    }

    /**
     * Map *canonical owner observations* to a common state without enqueue,
     * dispatch, claim, provider fallback or certificate changes. Values are
     * read from native sources by their existing callers, not remote inputs.
     */
    public static function observe_native_lane($source, array $observed) {
        if (!in_array($source, array('scheduler_admission','scheduler_backlog',
            'remote_work_queue','adaptive_dispatcher','recovery_route'), true))
            return self::invalid('Unknown native lane owner.');
        $state='WAITING_DEPENDENCY'; $reason='native_progress_wait';
        $next='inspect_canonical_owner'; $record='';
        if ($source==='scheduler_admission') {
            $decision=(string)($observed['decision']??'');
            $reasons=array_values(array_filter((array)($observed['reasons']??array()),'is_string'));
            if (in_array($decision,array('ADMIT','RESERVED_LANE'),true)) {
                $state='WAITING_PREFLIGHT';$reason='scheduler_admitted_not_dispatched';$next='native_enqueue_with_exact_binding';
            } elseif ($decision==='QUEUE') {
                $state='QUEUED';$reason='concurrency_or_provider_backpressure';$next='native_queue_admission';
            } elseif ($decision==='DENY' && in_array('queued_quota_exhausted',$reasons,true)) {
                $state='RECOVERING';$reason='queue_capacity_exhausted';$next='await_bounded_capacity_release';
            } elseif (in_array($decision,array('DENY','FAIL_CLOSED'),true)) {
                $state='BLOCKED_AUTHORITY';$reason='native_scheduler_denial';$next='resolve_native_admission_reasons';
            }
        } elseif (in_array($source,array('scheduler_backlog','remote_work_queue'),true)) {
            $record=strtolower((string)($observed['effective_status']??$observed['status']??''));
            if (in_array($record,array('queued','pending','already_queued'),true)) {
                $state='QUEUED';$reason='native_durable_queue_wait';$next='native_fair_claim';
            } elseif (in_array($record,array('leased','claimed'),true)) {
                $state='RUNNING';$reason='original_worker_claim_active';$next='observe_native_worker';
            } elseif (in_array($record,array('reconciling','reconciliation_required'),true)) {
                $state='NEEDS_RECONCILIATION';$reason='native_lease_or_write_uncertain';$next='original_provider_readback_before_retry';
            } elseif ($record==='completed') {
                $state='WAITING_VERIFICATION';$reason='queue_completion_not_provider_readback';$next='verify_original_provider_postcondition';
            } elseif (in_array($record,array('cancelled_no_effect','expired'),true)) {
                $state='CANCELLED';$reason='native_queue_terminal_no_verified_effect';$next='no_automatic_replay';
            }
        } elseif ($source==='adaptive_dispatcher') {
            $failure=(string)($observed['failure_state']??'');
            if (!empty($observed['write_uncertain']) || in_array($failure,
                array('TRANSPORT_RESULT_INDETERMINATE','PREVIOUS_WRITE_REQUIRES_INDEPENDENT_READBACK'),true)) {
                $state='NEEDS_RECONCILIATION';$reason='reported_mutation_outcome_unknown';$next='original_mutation_journal_readback';
            } elseif (in_array($failure,array('HOST_SAFETY_REJECTION_NO_ALTERNATE_EXECUTION',
                'AUTHORITY_DENIAL_NO_ALTERNATE_EXECUTION'),true)) {
                $state='BLOCKED_AUTHORITY';$reason='native_or_host_authority_denial';$next='inspect_original_denial_no_alternate_mutation';
            } elseif (!empty($observed['dependency_blocker'])) {
                $state='BLOCKED_DEPENDENCY';$reason='canonical_dependency_planner_unavailable';$next='repair_exact_planner';
            } elseif (!empty($observed['route_eligible'])) {
                $state='WAITING_PREFLIGHT';$reason='canonical_dispatch_route_selected';$next='native_schema_pinned_execution_revalidation';
            } else {
                $state='WAITING_DEPENDENCY';$reason='route_discovery_or_preparation_required';$next='discover_registered_canonical_dispatch';
            }
        } else {
            if (!empty($observed['authority_blockers'])) {
                $state='BLOCKED_AUTHORITY';$reason='observed_authority_denial';$next='inspect_original_authority';
            } elseif (!empty($observed['recommended_route'])) {
                $state='RECOVERING';$reason='diagnostic_fallback_route_available';$next='inspect_recommended_native_route';
            } elseif (!empty($observed['blockers'])) {
                $state='RECOVERING';$reason='diagnose_bounded_recovery_route';$next='read_native_recovery_status';
            } else {
                $state='READY';$reason='no_reported_recovery_blocker';$next='continue_normal_native_workflow';
            }
        }
        return array('contract'=>self::CONTRACT.'.native-lane.v1','owner'=>$source,
            'native_status'=>$record,'state'=>$state,'reason'=>$reason,
            'next_native_action'=>$next,'ordinary_wait_is_not_blocking'=>in_array($state,
                array('QUEUED','WAITING_DEPENDENCY','WAITING_PREFLIGHT','WAITING_VERIFICATION','RUNNING','RECOVERING'),true),
            'alternate_mutation_authorized'=>false,'dispatch_performed'=>false,
            'blind_write_retry_allowed'=>false,'grants_created'=>false,
            'mutation_performed'=>false);
    }
}
