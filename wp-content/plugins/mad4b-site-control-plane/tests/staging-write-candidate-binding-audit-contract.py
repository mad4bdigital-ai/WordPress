#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
binding = (ROOT / 'includes/class-mad4b-scp-staging-write-candidate-binding.php').read_text('utf-8')
authority = (ROOT / 'includes/class-mad4b-scp-staging-write-authority.php').read_text('utf-8')
audit = (ROOT / 'includes/class-mad4b-scp-audit.php').read_text('utf-8')
servers = (ROOT / 'includes/class-mad4b-scp-servers.php').read_text('utf-8')
identity = (ROOT / 'includes/class-mad4b-scp-identity-context.php').read_text('utf-8')
oauth = (ROOT / 'includes/class-mad4b-scp-oauth-resource-bridge.php').read_text('utf-8')

for marker in [
    "mad4b.staging-write-candidate-binding.v2",
    "mad4b/staging-write-candidate-binding-audit",
    "operation_context",
    "subject_fingerprint",
    "issuer_fingerprint",
    "client_fingerprint",
    "session_fingerprint",
    "mcp_request_context_fingerprint",
    "operation_basis",
    "grant_reconciliation",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::CONTRACT",
    "grant_reconciliation_confirmation",
    "previous_binding",
    "reviewed_previous_binding",
    "pre_bind_persisted_binding",
    "audit_binding_snapshot",
    "target_binding",
    "write_snapshot",
]:
    assert marker in binding, f'missing binding attribution marker: {marker}'

assert "mad4b.staging-write-grant-reconciliation.v1" not in binding, 'candidate binding must not hard-code the retired grant-reconciliation v1 contract'\n\nfor marker in [
    "mad4b.governed-write-authority-candidate-binding.v2",
    "stored_package_manifest_digest",
    "stored_artifact_identity",
    "identity_completeness",
    "mad4b/staging-write-candidate-binding-authorized",
    "mad4b/staging-write-candidate-binding-complete",
    "mad4b/staging-write-candidate-binding-noop",
    "mad4b/staging-write-candidate-binding-rollback",
    "mutation_performed",
    "binding_mutation_performed",
    "previous_binding",
    "new_binding",
    "normalize_candidate_binding_context",
    "mad4b_candidate_binding_actor_attribution_mismatch",
    "START TRANSACTION",
    "FOR UPDATE",
    "transaction_committed",
    "transaction_rolled_back",
    "transactional_table",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::CONTRACT",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::CONFIRMATION",
]:
    assert marker in authority, f'missing primitive audit invariant: {marker}'

primitive = authority.split("public static function bind_candidate_identity", 1)[1].split("private static function current_candidate_identity", 1)[0]
for marker in [
    "normalize_candidate_binding_context",
    "MAD4B_SCP_Audit::storage_status",
    "candidate_binding_audit",
    "package_manifest_digest",
    "artifact_identity",
    "grant_rows_fingerprint",
    "write_inventory_fingerprint",
]:
    assert marker in primitive, f'candidate-binding primitive lacks mandatory guard: {marker}'

# Candidate binding must not publish uncommitted authority state through the
# WordPress options/object-cache path. The option row is updated directly under
# SELECT ... FOR UPDATE and its cache is invalidated only after COMMIT.
assert "update_option(" not in primitive, 'candidate-binding primitive must not use update_option inside its SQL transaction'
assert "$wpdb->update(" in primitive, 'candidate-binding primitive must persist the authority option through the transactional DB path'
commit_helper = authority.split("private static function commit_candidate_binding_transaction", 1)[1].split("public static function bind_candidate_identity", 1)[0]
assert "COMMIT" in commit_helper
assert "reset_authority_option_cache" in commit_helper
assert commit_helper.index("COMMIT") < commit_helper.index("reset_authority_option_cache"), 'authority option cache must not be invalidated before COMMIT'
assert commit_helper.index("reset_authority_option_cache") < commit_helper.index("transaction_committed"), 'committed audit dispatch must observe the post-COMMIT cache state'

assert "mad4b/staging-write-candidate-binding-audit" in servers
core_write = servers[servers.index('private static function core_write_candidates'):servers.index('private static function registered_adapter_write_candidates')]
assert "mad4b/staging-write-candidate-binding-audit" not in core_write, 'read-only binding audit leaked into normal write inventory'
assert "mad4b/staging-write-candidate-bind" not in core_write, 'binding bootstrap leaked into normal write inventory'

for marker in [
    "candidate_binding_events",
    "mad4b.staging-write-candidate-binding-audit.v1",
    "chain_valid",
    "head_consistent",
]:
    assert marker in audit, f'missing bounded audit lookup marker: {marker}'


for marker in [
    "issuer_fingerprint",
    "client_fingerprint",
    "session_fingerprint",
    "mad4b_identity_attribution_fingerprint_invalid",
]:
    assert marker in identity, f'missing safe identity attribution marker: {marker}'

for marker in [
    "issuer_fingerprint",
    "client_fingerprint",
    "session_fingerprint",
    "claims['client_id']",
    "claims['azp']",
    "claims['jti']",
    "mad4b_oauth_client_claim_mismatch",
]:
    assert marker in oauth, f'missing OAuth attribution derivation marker: {marker}'

assert '$subject_fingerprint . "\\0" . $issuer_fingerprint . "\\0" . $client_fingerprint . "\\0" . $session_fingerprint . "\\0" . $correlation_id . "\\0" . $transport' in binding, 'request-context fingerprint does not bind the full safe OAuth attribution tuple'

# Raw credential/session material must never be introduced into candidate-binding summaries.
for forbidden in [
    "'bearer_token'",
    "'access_token'",
    "'refresh_token'",
    "'raw_subject'",
    "'raw_session_id'",
    "token_instance_fingerprint",
]:
    assert forbidden not in binding
    assert forbidden not in authority

print('mad4b.staging-write-candidate-binding-audit.contract.v1: PASS')
