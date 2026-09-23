#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
binding = (ROOT / 'includes/class-mad4b-scp-staging-write-candidate-binding.php').read_text('utf-8')
authority = (ROOT / 'includes/class-mad4b-scp-staging-write-authority.php').read_text('utf-8')
audit = (ROOT / 'includes/class-mad4b-scp-audit.php').read_text('utf-8')
servers = (ROOT / 'includes/class-mad4b-scp-servers.php').read_text('utf-8')

for marker in [
    "mad4b.staging-write-candidate-binding.v2",
    "mad4b/staging-write-candidate-binding-audit",
    "operation_context",
    "subject_fingerprint",
    "issuer_fingerprint",
    "client_fingerprint",
    "session_fingerprint",
    "mcp_request_context_fingerprint",
    "previous_binding",
    "target_binding",
    "write_snapshot",
    "binding_mutation_performed",
]:
    assert marker in binding, f'missing binding attribution marker: {marker}'

for marker in [
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
