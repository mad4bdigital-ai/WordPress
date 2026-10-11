#!/usr/bin/env python3
"""Exact-source, no-CI guard: explicit request-only OAuth permission preflight."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
src = (root / 'includes/class-mad4b-scp-enrollment-dispatch.php').read_text()
tests = (
    'public static function managed_skills_permission_model( $transport, $evaluated, $allowed, $error_code',
    'private static function live_managed_skills_permission_preflight()',
    "MAD4B_SCP_Remote_Operation_Parity::can_execute_chatgpt_direct_step_up()",
    "MAD4B_SCP_Transport_Context::current_server_id()",
    "'request_permission_preflight' => 'managed_skills_reconciliation' === $operation_id",
    "'same_request_only' => true",
    "'execution_authorized' => false",
    "'execution_performed' => false",
    "'authority_granted' => false",
    "'mutation_performed' => false",
    "'blind_retry_allowed' => false",
    "'production_mutation_allowed' => false",
    "'mad4b_remote_operation_step_up_scope_required'",
    "'mad4b_remote_operation_chatgpt_client_required'",
    "'request_permission_denied_unclassified'",
)
for t in tests:
    assert t in src, 'Missing permission guard: ' + t
pure_start = src.index('public static function managed_skills_permission_model(')
pure_end = src.index('\n\tprivate static function live_managed_skills_permission_preflight(', pure_start)
pure = src[pure_start:pure_end]
for forbidden in ('update_option(', 'delete_option(', 'wp_remote_', 'wp_schedule_', 'wp_register_ability(', 'grant_ability(', 'shell_exec(', '$wpdb'):
    assert forbidden not in pure, 'Permission projection must not mutate: ' + forbidden
assert src.count("'request_permission_preflight' => 'managed_skills_reconciliation' === $operation_id") == 1
assert (root / 'tests/enrollment-request-permission-runtime.php').exists()
print('mad4b.enrollment-request-permission-contract: PASS')
