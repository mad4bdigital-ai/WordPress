#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("verify_feature_boundary", HERE / "verify_feature_boundary.py")
mod = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(mod)

BASE = "b" * 40
HEAD = "h" * 40
BRANCH = "feat/007-example"
FEATURE_PATH = "specs/007-content-intelligence-workflow-platform/feature.json"

POLICY = {
    "contract": "mad4b.repository-governance-policy.v1",
    "target_branch": "master",
    "single_owner_safety": {
        "owner_attestation_remains_exact_sha_scoped": True,
        "attestation_stale_on_descendant": True,
    },
}
GRANT_CATALOG = {
    "contract": "mad4b.repository-feature-boundary-grants.v1",
    "default_policy": "deny",
    "grants": {
        "007": {
            "feature_directory": "specs/007-content-intelligence-workflow-platform",
            "allowed_branch_kinds": ["feat", "fix", "spec"],
            "allowed_path_prefixes": [
                "specs/007-content-intelligence-workflow-platform/",
                "wp-content/plugins/mad4b-site-control-plane/",
            ],
            "allowed_exact_paths": [
                ".github/workflows/feature-007-spec-ci.yml",
                "tools/mad4b_recovery_plane.py",
            ],
            "cross_feature_exact_paths": [
                "specs/006-agent-governed-reversible-control-plane/data-model.md",
            ],
            "self_certifying_paths_forbidden": [
                ".github/workflows/mad4b-site-control-plane.yml",
                ".github/workflows/mad4b-control-plane-package.yml",
            ],
            "repository_governance_mutation_allowed": False,
            "metadata_cannot_widen_grant": True,
            "release_critical_workflows_must_be_baseline_owned": True,
        }
    },
}
FEATURE = {
    "feature_id": "007",
    "feature_directory": "specs/007-content-intelligence-workflow-platform",
    "status": "implementation",
    "implementation_boundary_policy": {
        "contract": "mad4b.feature-boundary-binding.v2",
        "policy_contract": "mad4b.repository-governance-policy.v1",
        "policy_path": ".github/mad4b-repository-governance-policy.json",
        "skill_inventory_source": "wp-content/plugins/mad4b-site-control-plane/config/skill-seed-manifest.json",
        "grant_catalog_path": ".github/mad4b-feature-boundary-grants.json",
        "cross_feature_dependencies_are_exact": True,
        "mutable_metadata_cannot_widen_policy": True,
        "repository_governance_files_are_immutable": True,
    },
    "implementation_branch_policy": {
        "contract": "mad4b.feature-implementation-branch-policy.v2",
        "implementation_prefixes": ["feat/007-", "fix/007-"],
        "specification_maintenance_prefixes": ["spec/007-"],
        "requires_exact_pr_base_ancestry": True,
        "mutable_metadata_cannot_widen_policy": True,
    },
    "cross_feature_contract_dependencies": [
        "specs/006-agent-governed-reversible-control-plane/data-model.md",
    ],
}
SKILLS = {
    "contract": "mad4b.skill-seed-manifest.v1",
    "skills": [{"name": "wordpress-brand-context-builder"}],
}

def fake_load(ref, path):
    if path == mod.REPOSITORY_POLICY_PATH:
        return POLICY
    if path == mod.GRANT_CATALOG_PATH:
        return GRANT_CATALOG
    if path == FEATURE_PATH:
        return FEATURE
    if path == mod.SKILL_INVENTORY_SOURCE:
        return SKILLS
    raise AssertionError(path)

def run_case(changed, *, feature=None, grant_catalog=None, expect_error=None, branch=BRANCH):
    current_feature = feature if feature is not None else FEATURE
    current_grants = grant_catalog if grant_catalog is not None else GRANT_CATALOG

    def loader(ref, path):
        if path == mod.REPOSITORY_POLICY_PATH:
            return POLICY
        if path == mod.GRANT_CATALOG_PATH:
            return current_grants
        if path == FEATURE_PATH:
            return current_feature
        if path == mod.SKILL_INVENTORY_SOURCE:
            return SKILLS
        raise AssertionError(path)

    with patch.object(mod, "run", return_value=""), \
         patch.object(mod, "changed_paths", return_value=list(changed)), \
         patch.object(mod, "find_feature_json", return_value=FEATURE_PATH), \
         patch.object(mod, "load_json_at", side_effect=loader):
        try:
            result = mod.verify(BASE, HEAD, branch)
        except RuntimeError as exc:
            if expect_error is None:
                raise
            if expect_error not in str(exc):
                raise AssertionError(f"expected {expect_error!r}, got {exc!r}") from exc
            return
        if expect_error is not None:
            raise AssertionError(f"expected failure {expect_error!r}, got PASS: {result}")
        assert result["ready"] is True
        assert result["trusted_verifier_source"] == "base"
        assert result["pull_request_code_executed"] is False
        assert result["grant_source"] == "base"

run_case([
    "specs/007-content-intelligence-workflow-platform/feature.json",
    "wp-content/plugins/mad4b-site-control-plane/includes/example.php",
    ".github/workflows/feature-007-spec-ci.yml",
    "specs/006-agent-governed-reversible-control-plane/data-model.md",
])

run_case(
    [".github/mad4b-repository-governance-policy.json"],
    expect_error="REPOSITORY_ROOT_OF_TRUST_CHANGED_FROM_FEATURE",
)

run_case(
    [".github/workflows/mad4b-site-control-plane.yml"],
    expect_error="SELF_CERTIFYING_RELEASE_CRITICAL_CHANGE",
)

widened = dict(FEATURE)
widened["cross_feature_contract_dependencies"] = list(FEATURE["cross_feature_contract_dependencies"]) + [
    ".github/workflows/ungranted.yml"
]
run_case(
    ["specs/007-content-intelligence-workflow-platform/feature.json"],
    feature=widened,
    expect_error="CROSS_FEATURE_DEPENDENCY_GRANT_DRIFT",
)

bad_catalog = dict(GRANT_CATALOG)
bad_catalog["default_policy"] = "allow"
run_case(
    ["specs/007-content-intelligence-workflow-platform/feature.json"],
    grant_catalog=bad_catalog,
    expect_error="FEATURE_BOUNDARY_GRANT_DEFAULT_MUST_DENY",
)

bad_binding = dict(FEATURE)
bad_binding["implementation_boundary_policy"] = dict(FEATURE["implementation_boundary_policy"])
bad_binding["implementation_boundary_policy"]["policy_path"] = ".github/feature-owned-policy.json"
run_case(
    ["specs/007-content-intelligence-workflow-platform/feature.json"],
    feature=bad_binding,
    expect_error="FEATURE_BOUNDARY_BINDING_DRIFT",
)

governance_result = run_case(
    [".github/workflows/mad4b-release-verdict.yml"],
    branch="chore/governance-release-root-hardening",
)
# run_case returns None only for expected failures; repeat directly for result assertions.
with patch.object(mod, "run", return_value=""), \
     patch.object(mod, "changed_paths", return_value=[".github/workflows/mad4b-release-verdict.yml"]), \
     patch.object(mod, "load_json_at", side_effect=fake_load):
    governance_result = mod.verify(BASE, HEAD, "chore/governance-release-root-hardening")
assert governance_result["mode"] == "repository_governance_change"
assert governance_result["owner_attestation_required"] is True
assert governance_result["pull_request_code_executed"] is False

run_case(
    [".github/workflows/mad4b-release-verdict.yml"],
    branch="chore/ordinary-maintenance",
    expect_error="REPOSITORY_ROOT_CHANGE_BRANCH_FORBIDDEN",
)

with patch.object(mod, "run", return_value=""), \
     patch.object(mod, "changed_paths", return_value=["README.md"]):
    ordinary = mod.verify(BASE, HEAD, "chore/documentation")
assert ordinary["mode"] == "non_feature_branch"
assert ordinary["owner_attestation_required"] is False

print("mad4b.repository-feature-boundary.v1: PASS")
