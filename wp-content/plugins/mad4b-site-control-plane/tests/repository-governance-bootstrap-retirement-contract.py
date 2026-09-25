#!/usr/bin/env python3
from pathlib import Path

governance = Path(".github/workflows/mad4b-repository-governance.yml").read_text(encoding="utf-8")
verdict = Path(".github/workflows/mad4b-release-verdict.yml").read_text(encoding="utf-8")
rerun = Path(".github/workflows/mad4b-owner-attestation-rerun.yml").read_text(encoding="utf-8")

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
    "governance_extra_args+=(--allow-bootstrap-hidden-bypass-evidence)",
    "test_verify_repository_governance_attestation.py",
    "test_publish_repository_ruleset_attestation.py",
    "'post_merge_ruleset_apply_required': True",
]
for needle in one_time_governance_required:
    if needle not in governance:
        raise SystemExit(f"one-time feature-boundary bootstrap governance binding missing: {needle}")

one_time_verdict_required = [
    "os.environ.get('PR_NUMBER', '').strip() == '66'",
    "verify_feature_boundary_bootstrap_transition.py",
    "mad4b-release-verdict-bootstrap-base-policy.json",
    "governance_cmd.append('--allow-bootstrap-hidden-bypass-evidence')",
    "'post_merge_ruleset_apply_required':True",
    "PR #66 on branch chore/bootstrap-feature-boundary-policy-20260925",
]
for needle in one_time_verdict_required:
    if needle not in verdict:
        raise SystemExit(f"one-time feature-boundary bootstrap Release Verdict binding missing: {needle}")

for legacy_storage in [
    "MAD4B_RULESET_ATTESTATION",
    "repository-governance",
]:
    if legacy_storage == "repository-governance":
        continue
    if legacy_storage in governance or legacy_storage in verdict:
        raise SystemExit(
            "repository governance workflows returned to legacy environment attestation storage: "
            + legacy_storage
        )

rerun_required = [
    "mad4b-feature-boundary-root.yml/runs?event=pull_request_target&per_page=100",
    "select(any(.pull_requests[]?; .number == $pr))",
    "FEATURE_BOUNDARY_RERUN_REQUESTED",
    "FEATURE_BOUNDARY_ALREADY_SUCCESS",
    "RELEASE_VERDICT_RERUN_REQUESTED",
]
for needle in rerun_required:
    if needle not in rerun:
        raise SystemExit(f"owner-attestation rerun lifecycle missing: {needle}")

actions_expression_safety_required = [
    "base_ref_expression = 'ref:     "tools/capture_exact_head_action_jobs.py",
    "github_actions_runs_jobs_api",
    "elif os.environ.get('GITHUB_EVENT_NAME') == 'push':",
    "required = [name for name in required if name != 'Repository feature boundary']",
]
for needle in actions_job_evidence_required:
    if needle not in verdict:
        raise SystemExit(f"Release Verdict Actions-job evidence binding missing: {needle}")
if "/check-runs?per_page=" in verdict:
    raise SystemExit("Release Verdict returned to the GITHUB_TOKEN-incompatible check-runs API")

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
print("ruleset_attestation_storage=owner_issue_comment")
print("owner_attestation_reruns=release_verdict+failed_feature_boundary")
print("schema_check_binding=derived_from_critical_kernel")
print("critical_job_evidence=github_actions_runs_jobs_api")
print("root_trust_expression_check=interpolation_safe")
print("push_boundary_wait=disabled_pr_only_check")
print("merge_gate_requires=governance_ready")
 + '{{ github.event.pull_request.base.sha }}'",
    "head_ref_expression = 'ref:     "tools/capture_exact_head_action_jobs.py",
    "github_actions_runs_jobs_api",
    "elif os.environ.get('GITHUB_EVENT_NAME') == 'push':",
    "required = [name for name in required if name != 'Repository feature boundary']",
]
for needle in actions_job_evidence_required:
    if needle not in verdict:
        raise SystemExit(f"Release Verdict Actions-job evidence binding missing: {needle}")
if "/check-runs?per_page=" in verdict:
    raise SystemExit("Release Verdict returned to the GITHUB_TOKEN-incompatible check-runs API")

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
print("ruleset_attestation_storage=owner_issue_comment")
print("owner_attestation_reruns=release_verdict+failed_feature_boundary")
print("schema_check_binding=derived_from_critical_kernel")
print("critical_job_evidence=github_actions_runs_jobs_api")
print("push_boundary_wait=disabled_pr_only_check")
print("merge_gate_requires=governance_ready")
 + '{{ github.event.pull_request.head.sha }}'",
    "if base_ref_expression not in boundary_workflow:",
    "if head_ref_expression in boundary_workflow:",
]
for needle in actions_expression_safety_required:
    if needle not in verdict:
        raise SystemExit(f"Release Verdict root-trust expression safety missing: {needle}")
for forbidden in [
    "if 'ref: ${{ github.event.pull_request.base.sha }}' not in boundary_workflow:",
    "if 'ref: ${{ github.event.pull_request.head.sha }}' in boundary_workflow:",
]:
    if forbidden in verdict:
        raise SystemExit(
            "Release Verdict contains an Actions-interpolated root-trust literal: "
            + forbidden
        )

actions_job_evidence_required = [
    "tools/capture_exact_head_action_jobs.py",
    "github_actions_runs_jobs_api",
    "elif os.environ.get('GITHUB_EVENT_NAME') == 'push':",
    "required = [name for name in required if name != 'Repository feature boundary']",
]
for needle in actions_job_evidence_required:
    if needle not in verdict:
        raise SystemExit(f"Release Verdict Actions-job evidence binding missing: {needle}")
if "/check-runs?per_page=" in verdict:
    raise SystemExit("Release Verdict returned to the GITHUB_TOKEN-incompatible check-runs API")

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
print("ruleset_attestation_storage=owner_issue_comment")
print("owner_attestation_reruns=release_verdict+failed_feature_boundary")
print("schema_check_binding=derived_from_critical_kernel")
print("critical_job_evidence=github_actions_runs_jobs_api")
print("push_boundary_wait=disabled_pr_only_check")
print("merge_gate_requires=governance_ready")
