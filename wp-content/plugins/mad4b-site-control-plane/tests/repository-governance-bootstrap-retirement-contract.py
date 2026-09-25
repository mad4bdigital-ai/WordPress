#!/usr/bin/env python3
from pathlib import Path

governance = Path(".github/workflows/mad4b-repository-governance.yml").read_text(encoding="utf-8")
verdict = Path(".github/workflows/mad4b-release-verdict.yml").read_text(encoding="utf-8")

forbidden_governance = [
    "bootstrap_merge_exception",
    "bootstrap_scope",
    "initial_master_ruleset_only",
    "Repository governance external enforcement is pending; bounded initial bootstrap accepted.",
]
for needle in forbidden_governance:
    if needle in governance:
        raise SystemExit(f"repository governance bootstrap exception not retired: {needle}")

forbidden_verdict = [
    "governance_bootstrap_paths",
    "governance_bootstrap_candidate",
    "governance_bootstrap_pending",
    "bounded initial governance bootstrap",
]
for needle in forbidden_verdict:
    if needle in verdict:
        raise SystemExit(f"release verdict bootstrap exception not retired: {needle}")

one_time_governance_required = [
    "github.event.pull_request.number == 66",
    "github.event.pull_request.head.ref == 'chore/bootstrap-feature-boundary-policy-20260925'",
    'git show "$BASE_SHA:.github/mad4b-repository-governance-policy.json"',
    "verify_feature_boundary_bootstrap_transition.py",
    "policy_path=/tmp/mad4b-bootstrap-base-policy.json",
    "'post_merge_ruleset_apply_required': True",
]
for needle in one_time_governance_required:
    if needle not in governance:
        raise SystemExit(f"one-time feature-boundary bootstrap governance binding missing: {needle}")

one_time_verdict_required = [
    "os.environ.get('PR_NUMBER', '').strip() == '66'",
    "verify_feature_boundary_bootstrap_transition.py",
    "mad4b-release-verdict-bootstrap-base-policy.json",
    "'post_merge_ruleset_apply_required':True",
    "PR #66 on branch chore/bootstrap-feature-boundary-policy-20260925",
]
for needle in one_time_verdict_required:
    if needle not in verdict:
        raise SystemExit(f"one-time feature-boundary bootstrap Release Verdict binding missing: {needle}")

schema_binding_required = [
    "critical_kernel_text.splitlines()",
    "stripped.startswith('name: Schema ')",
    "stripped.endswith(' real MariaDB upgrade')",
    "critical kernel must expose exactly one real MariaDB schema check",
    "schema_check = schema_checks[0]",
    "schema_check,",
]
for needle in schema_binding_required:
    if needle not in verdict:
        raise SystemExit(f"Release Verdict dynamic schema-check binding missing: {needle}")
if "Schema v6 v7 v8 v9 to v11 real MariaDB upgrade" in verdict:
    raise SystemExit("Release Verdict returned to a stale hard-coded schema check name")

required = [
    "repository governance must verify ready for every merge-gate PASS",
    "and governance_ready",
]
for needle in required:
    if needle not in verdict:
        raise SystemExit(f"retired governance enforcement contract missing: {needle}")

print("REPOSITORY_GOVERNANCE_BOOTSTRAP_RETIREMENT_CONTRACT: PASS")
print("legacy_bootstrap_exception=retired")
print("feature_boundary_bootstrap=pr66_exact_branch_base_policy_only")
print("post_merge_ruleset_apply_required=true")
print("schema_check_binding=derived_from_critical_kernel")
print("merge_gate_requires=governance_ready")
