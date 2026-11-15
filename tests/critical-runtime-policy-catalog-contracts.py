#!/usr/bin/env python3
"""Nonauthorizing regression for reviewed policy catalogs.

This is intentionally a narrow, source-bound review. It tests fail-closed
configuration at the policy boundary, not WordPress runtime authorization or
independent site acceptance. Every expected invariant is mutation-tested.
"""
import copy
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / "wp-content/plugins/mad4b-site-control-plane/config"

# Do not freeze environment-specific routing policy: the Company/Site Profile
# and its trusted registry own that decision, not this source review.
RULES = {
    "adapter-registration-policy.json": {
        ("unknown_plugin_mutation",): "deny",
        ("unknown_plugin_discovery",): "read_only",
        ("new_adapter_without_review",): "candidate_only",
        ("approval_model",): "source_reviewed_manifest_no_runtime_self_enrollment",
        ("default",): "inventory_only_source_review_required",
    },
    "central-staging-source.json": {
        ("evidence_transport",): "signed_server_readback",
        ("signature_algorithm",): "RS256",
        ("production_approval",): "separate_governed_native_owner_batch",
    },
    "cross-plugin-sources.json": {
        ("default_execution",): "deny_until_exact_artifact_certified",
        ("registration_policy",): "reviewed_repository_manifest_only",
    },
    "deployment-mode-dependencies.json": {
        ("invariants", "never_promote_unbound_site"): True,
        ("invariants", "never_select_tenant_from_request"): True,
        ("invariants", "never_select_brand_from_request"): True,
        ("invariants", "no_auto_promotion_on_provider_failure"): True,
        ("invariants", "no_cross_blog_context_leak"): True,
        ("invariants", "no_production_mutation_without_separate_grant"): True,
        ("invariants", "no_credential_materialization"): True,
        ("invariants", "site_cache_cannot_cross_blog"): True,
        ("invariants", "optional_provider_detection_is_not_certification"): True,
        ("failure_classification", "missing_required_identity"): "BLOCKED",
        ("failure_classification", "ambiguous_multi_brand"): "SELECT_BRAND_WITH_GOVERNED_REGISTRY_NOT_AUTO_GUESS",
    },
    "egress-policy.json": {
        ("default_unknown_purpose",): "deny",
        ("proxy_trust",): "explicit_allowlist_only",
        ("dns_change_policy",): "deny_within_request_scope",
        ("transport_error_policy",): "fail_closed_no_tls_downgrade",
    },
    "mcp-protocol-profiles.json": {
        ("certified_adapter", "exact_package_governed"): True,
        ("successor_certification", "state"): "not_certified",
        ("successor_certification", "auto_adopt"): False,
        ("successor_certification", "dual_protocol_regression_required"): True,
    },
    "mcp-provider-handshake-inheritance.json": {
        ("inherited_defaults", "require_real_initialize"): True,
        ("inherited_defaults", "require_real_tools_list"): True,
        ("inherited_defaults", "require_authenticated_principal"): True,
        ("inherited_defaults", "require_tool_schema_identity"): True,
        ("inherited_defaults", "require_source_site_binding"): True,
        ("inherited_defaults", "require_fresh_receipt"): True,
        ("inherited_defaults", "allow_shared_oauth_session_inheritance"): False,
        ("inherited_defaults", "allow_automatic_adapter_class_rebinding"): False,
        ("inherited_defaults", "allow_auto_mcp_enablement"): False,
        ("inherited_defaults", "allow_write_from_local_registry_only"): False,
    },
    "operation-registry.json": {
        ("default_mutation_policy",): "deny",
        ("generic_shell",): False,
        ("arbitrary_operation_ids",): False,
        ("dynamic_provider_autopilot", "auto_materialize_candidate_code"): False,
        ("dynamic_provider_autopilot", "auto_register_generated_adapter"): False,
        ("dynamic_provider_autopilot", "auto_write_certification"): False,
        ("dynamic_provider_autopilot", "auto_create_authority"): False,
        ("dynamic_provider_autopilot", "auto_enable_mutation"): False,
        ("dynamic_provider_autopilot", "unknown_provider_default"): "deny_write",
        ("dynamic_provider_autopilot", "environments", "production"): "observe_propose_only",
    },
    "runtime-release-policy.json": {
        ("pair_certification_required",): True,
        ("exact_provider_profile_required",): True,
        ("runtime_class_provenance_required",): True,
        ("transport_revalidation_required",): True,
        ("catalog_parity_required",): True,
        ("rollback", "adapter_same_request_disk_readback"): True,
        ("rollback", "transition_pair_must_remain_supported"): True,
        ("rollback", "automatic_production_rollback"): False,
        ("authority", "production_auto_apply"): False,
    },
    "semantic-content-field-contracts.json": {
        ("fallback_evidence_authorizing",): False,
        ("default_unknown_policy",): "review_if_brand_evidence",
    },
    "staging-deployment-handoff.json": {
        ("executor", "dry_run_default"): True,
        ("executor", "human_approval_required_for_apply"): True,
        ("executor", "exact_capability_envelope_required"): True,
        ("executor", "exact_target_allowlist_required"): True,
        ("executor", "caller_supplied_credentials_allowed"): False,
        ("executor", "caller_supplied_path_allowed"): False,
        ("executor", "caller_supplied_url_allowed"): False,
        ("apply", "backup_before_replace"): True,
        ("apply", "atomic_replace_required"): True,
        ("apply", "same_cycle_readback_required"): True,
        ("apply", "rollback_on_failed_readback"): True,
        ("apply", "production_requires_separate_explicit_authority"): True,
        ("forbidden", "unenrolled_target"): True,
        ("forbidden", "implicit_production_write"): True,
        ("forbidden", "raw_sql_side_channel"): True,
        ("forbidden", "caller_supplied_credentials"): True,
        ("secrets_included",): False,
    },
    "wordpress-addon-catalog.json": {
        ("defaults", "production_authorized"): False,
        ("defaults", "unknown_provider_version_behavior"): "FAIL_CLOSED",
        ("defaults", "missing_extension_point_behavior"): "FAIL_CLOSED",
        ("defaults", "provider_update_invalidation"): True,
        ("defaults", "addon_update_invalidation"): True,
        ("defaults", "exact_pair_certification_required"): True,
    },
}


def unique_object(pairs):
    out = {}
    for key, value in pairs:
        if key in out:
            raise AssertionError("Duplicate JSON object key")
        out[key] = value
    return out


def validate(name, data):
    for path, expected in RULES[name].items():
        node = data
        for field in path:
            if not isinstance(node, dict) or field not in node:
                raise AssertionError(f"{name}: missing {'/'.join(path)}")
            node = node[field]
        if type(node) is not type(expected) or node != expected:
            raise AssertionError(f"{name}: unsafe {'/'.join(path)}")
    if name == "deployment-mode-dependencies.json":
        # A future optional provider may be added, but cannot silently
        # weaken any existing mandatory invariant.
        assert all(v is True for v in data["invariants"].values())
    if name == "staging-deployment-handoff.json":
        assert all(v is True for v in data["forbidden"].values())


def set_path(obj, path, value):
    node = obj
    for part in path[:-1]:
        node = node[part]
    node[path[-1]] = value


def main():
    checks = 0
    for name, rules in sorted(RULES.items()):
        doc = json.loads((CONFIG / name).read_text(encoding="utf-8"),
                         object_pairs_hook=unique_object)
        validate(name, doc)
        for path, expected in rules.items():
            mutant = copy.deepcopy(doc)
            bad = not expected if type(expected) is bool else "__unsafe_bypass__"
            set_path(mutant, path, bad)
            try:
                validate(name, mutant)
            except AssertionError:
                checks += 1
            else:
                raise AssertionError(f"Mutation evaded guard: {name} {path}")
    assert checks >= 70
    print(f"CRITICAL_RUNTIME_POLICY_CATALOG_CONTRACTS: PASS ({len(RULES)} sources, {checks} negative mutations)")


if __name__ == "__main__":
    main()
