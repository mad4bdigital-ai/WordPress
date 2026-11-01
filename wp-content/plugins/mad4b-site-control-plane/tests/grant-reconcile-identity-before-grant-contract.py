#!/usr/bin/env python3
"""Regression: exact identity and source policy must precede ALL privilege mutations.

This contract intentionally does not change the verified OAuth operation.
MAD4B host SSH/WP-CLI authentication is a separate already-governed
Execution_Authority transport, not a fake OAuth bearer or --user override.
"""
from pathlib import Path
src=Path(__file__).resolve().parents[1]/'includes/class-mad4b-scp-staging-write-authority.php'
s=src.read_text(encoding='utf-8')
def must(text):
    p=s.find(text,s.find('private static function reconcile_under_runtime_fence()'))
    assert p>=0, f'absent source guard: {text}'
    return p
start=must('$preflight_plan = self::reconciliation_plan();')
subject=must("if ( ! empty( $preflight_plan['subject_preflight_blockers'] ) )")
source=must("$reviewed_provider_policy = MAD4B_SCP_Staging_Write_Grant_Reconciliation::allowed_ability_providers();")
deny=must("return self::finish_blocked( $status, 'write_grant_source_policy_preflight_blocked' );")
first_agent=must("MAD4B_SCP_Agent_Registry::create_agent(")
first_binding=must("MAD4B_SCP_Agent_Registry::bind_subject(")
first_revoke=must("MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id(")
first_grant=must("MAD4B_SCP_Agent_Registry::grant_ability(")
assert start < subject < source < deny < min(first_agent,first_binding,first_revoke,first_grant), 'preflight after privilege mutation'
assert "write_identity_preflight_blocked" in s
assert "source_policy_unapproved_missing_count" in s
assert "reviewed_grant_policy_unavailable" in s
authority=src.with_name('class-mad4b-scp-execution-authority.php').read_text(encoding='utf-8')
assert "'host_cli'" in authority and "'oauth_mcp'" in authority
assert "mad4b_actor_registry_binding_required" in authority
assert "0 !== get_current_user_id()" in authority
assert "MAD4B_SCP_CLI_Execution_Context::resolve" in authority
print('PASS identity-before-mutation, exact grant policy and two authenticated transports')
