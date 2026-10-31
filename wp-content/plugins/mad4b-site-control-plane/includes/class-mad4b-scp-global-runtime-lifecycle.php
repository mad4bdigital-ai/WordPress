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
        'NEEDS_RECONCILIATION','SKIPPED_NOT_APPLICABLE','VERIFIED');

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
}
