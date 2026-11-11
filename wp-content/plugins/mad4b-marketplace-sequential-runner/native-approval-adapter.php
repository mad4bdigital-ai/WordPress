<?php
/** CLI-only, local-only MAD4B native coding approval adapter. */
if ( PHP_SAPI !== 'cli' ) { http_response_code(403); exit; }
require '/var/www/html/wp-load.php';
function mad4b_marketplace_answer($value, $exit = 0) {
    echo wp_json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
    exit($exit);
}
$input = json_decode(stream_get_contents(STDIN), true);
if (!is_array($input) || !in_array(($input['action'] ?? ''), ['validate','claim','finalize'], true)) {
    mad4b_marketplace_answer(['status'=>'BLOCKED','reason'=>'invalid_request'], 2);
}
if (wp_get_environment_type() !== 'local' ||
    !class_exists('MAD4B_SCP_Approval_Tickets') ||
    !class_exists('MAD4B_SCP_Agent_Registry') ||
    !class_exists('MAD4B_Marketplace_Sequential_Runner')) {
    mad4b_marketplace_answer(['status'=>'BLOCKED','reason'=>'local_native_authority_unavailable'], 2);
}
$run = MAD4B_Marketplace_Sequential_Runner::status()['run'] ?? [];
if (($run['stage'] ?? '') !== '001-identity-membership' ||
    ($run['run_id'] ?? '') !== ($input['run_id'] ?? '') ||
    empty($run['approval_receipt']) ||
    empty($run['acceptance_verified'])) {
    mad4b_marketplace_answer(['status'=>'BLOCKED','reason'=>'stage00_contract_freeze_or_stage01_admission_missing'], 2);
}
$ticket = (string)($input['ticket_id'] ?? '');
if (!preg_match('/^[a-f0-9-]{36}$/D', $ticket)) {
    mad4b_marketplace_answer(['status'=>'BLOCKED','reason'=>'ticket_id_invalid'], 2);
}
if ($input['action'] === 'finalize') {
    $status = ($input['terminal_status'] ?? '') === 'used' ? 'used' : 'failed';
    $out = MAD4B_SCP_Approval_Tickets::finalize_claim($ticket, $status, 'marketplace_coding_execution');
} else {
    $agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id((string)($input['agent_public_id'] ?? ''));
    if (!$agent || ($agent['status'] ?? '') !== 'enabled') {
        mad4b_marketplace_answer(['status'=>'BLOCKED','reason'=>'agent_not_enabled'], 2);
    }
    $scope = $input['scope_input'] ?? null;
    $manifest_hash = MAD4B_Marketplace_Sequential_Runner::stage_hash(1);
    if (!is_array($scope) || ($scope['contract'] ?? '') !== 'mad4b.marketplace-isolated-code-proposal.v1' ||
        ($scope['run_id'] ?? '') !== ($run['run_id'] ?? '') ||
        ($scope['stage'] ?? '') !== '001-identity-membership' ||
        ($scope['spec_sha256'] ?? '') !== $manifest_hash ||
        ($scope['scope'] ?? '') !== 'isolated_code_proposal_only' ||
        !preg_match('/^[a-f0-9]{64}$/D', (string)($scope['source_sha256'] ?? ''))) {
        mad4b_marketplace_answer(['status'=>'BLOCKED','reason'=>'intent_scope_mismatch'], 2);
    }
    $target = hash('sha256', wp_json_encode($scope, JSON_UNESCAPED_SLASHES));
    $params = [$ticket, $agent, 'mad4b-local', 'mad4b/marketplace-code-proposal',
        'mad4b-marketplace', $target, $scope, 'mutation'];
    $out = call_user_func_array(
        ['MAD4B_SCP_Approval_Tickets', $input['action'] === 'claim' ? 'claim_exact' : 'validate_exact'],
        $params
    );
}
if (is_wp_error($out)) mad4b_marketplace_answer(['status'=>'BLOCKED','reason'=>$out->get_error_code()], 2);
mad4b_marketplace_answer([
    'status'=>'VERIFIED', 'phase'=>$input['action'],
    'ticket_id'=>$ticket, 'run_id'=>$run['run_id']
]);
