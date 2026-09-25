#!/usr/bin/env python3
import argparse
import json
import subprocess
from pathlib import Path

REPOSITORY_POLICY_CONTRACT = "mad4b.repository-governance-policy.v1"
BINDING_CONTRACT = "mad4b.feature-boundary-binding.v2"

FEATURE_DIRECTORY_ROOT = "specs/"
RUNTIME_ROOTS = (
    "wp-content/plugins/mad4b-site-control-plane/",
    "wp-content/plugins/mcp-adapter.zip",
)
WORKFLOW_PREFIX_TEMPLATES = ("feature-{feature_id}-",)
TOOL_PREFIXES = ("mad4b_", "verify_")
EXACT_TOOL_NAMES = {"capture-functional-gap-contract-evidence.py"}
SKILL_INVENTORY_SOURCE = "wp-content/plugins/mad4b-site-control-plane/config/skill-seed-manifest.json"
CROSS_FEATURE_DEPENDENCY_ROOTS = ("specs/", ".github/workflows/")
IMMUTABLE_GLOBAL_PATHS = {
    ".specify/feature.json",
    ".specify/memory/constitution.md",
}
REPOSITORY_OWNED_PATHS = {
    ".github/mad4b-repository-governance-policy.json",
    ".github/workflows/mad4b-repository-governance.yml",
    "tools/verify_repository_governance.py",
    "tools/verify_repository_owner_attestation.py",
}

def fail(message):
    raise SystemExit(message)

def load_json(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))

def load_json_from_ref(ref, path):
    raw = subprocess.check_output(["git", "show", f"{ref}:{path}"], text=True)
    return json.loads(raw)

def changed_paths(base):
    return subprocess.check_output(
        ["git", "diff", "--name-only", f"{base}...HEAD"],
        text=True,
    ).splitlines()

def starts_with_any(value, prefixes):
    return any(value.startswith(prefix) for prefix in prefixes)

def validate_repository_policy(policy):
    if policy.get("contract") != REPOSITORY_POLICY_CONTRACT:
        fail("REPOSITORY_GOVERNANCE_POLICY_CONTRACT_INVALID")
    if policy.get("target_branch") != "master":
        fail("REPOSITORY_GOVERNANCE_TARGET_INVALID")
    safety = policy.get("single_owner_safety") or {}
    if safety.get("owner_attestation_remains_exact_sha_scoped") is not True:
        fail("REPOSITORY_GOVERNANCE_EXACT_SHA_ATTESTATION_REQUIRED")
    if safety.get("attestation_stale_on_descendant") is not True:
        fail("REPOSITORY_GOVERNANCE_ATTESTATION_MUST_STALE_ON_DESCENDANT")

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--feature-json", required=True)
    ap.add_argument("--repository-policy", required=True)
    ap.add_argument("--base", required=True)
    ap.add_argument("--head-branch", required=True)
    args = ap.parse_args()

    feature = load_json(args.feature_json)
    repository_policy = load_json_from_ref(args.base, args.repository_policy)
    validate_repository_policy(repository_policy)

    feature_id = str(feature.get("feature_id") or "").strip()
    feature_dir = str(feature.get("feature_directory") or "").strip().rstrip("/")
    status = str(feature.get("status") or "")
    if not feature_id.isdigit() or len(feature_id) != 3:
        fail(f"FEATURE_ID_INVALID:{feature_id!r}")
    expected_prefix = f"{FEATURE_DIRECTORY_ROOT}{feature_id}-"
    if not feature_dir.startswith(expected_prefix):
        fail(f"FEATURE_DIRECTORY_OUTSIDE_ID_NAMESPACE:{feature_dir!r}")

    binding = feature.get("implementation_boundary_policy") or {}
    expected_binding = {
        "contract": BINDING_CONTRACT,
        "policy_contract": REPOSITORY_POLICY_CONTRACT,
        "policy_path": args.repository_policy,
        "skill_inventory_source": SKILL_INVENTORY_SOURCE,
        "cross_feature_dependencies_are_exact": True,
        "mutable_metadata_cannot_widen_policy": True,
        "repository_governance_changes_are_separately_governed": True,
    }
    if binding != expected_binding:
        fail("FEATURE_BOUNDARY_BINDING_DRIFT")

    derived_branch_policy = {
        "contract": "mad4b.feature-implementation-branch-policy.v2",
        "implementation_prefixes": [f"feat/{feature_id}-", f"fix/{feature_id}-"],
        "specification_maintenance_prefixes": [f"spec/{feature_id}-"],
        "requires_exact_pr_base_ancestry": True,
        "mutable_metadata_cannot_widen_policy": True,
    }
    if feature.get("implementation_branch_policy") != derived_branch_policy:
        fail("FEATURE_BRANCH_POLICY_DRIFT")

    skill_manifest = load_json(SKILL_INVENTORY_SOURCE)
    if skill_manifest.get("contract") != "mad4b.skill-seed-manifest.v1":
        fail("SKILL_INVENTORY_CONTRACT_INVALID")
    skill_names = {
        str(row.get("name") or "")
        for row in skill_manifest.get("skills", [])
        if isinstance(row, dict) and row.get("name")
    }

    cross = list(feature.get("cross_feature_contract_dependencies") or [])
    if len(cross) != len(set(cross)):
        fail("DUPLICATE_CROSS_FEATURE_DEPENDENCY")
    for path in cross:
        if not isinstance(path, str) or not starts_with_any(path, CROSS_FEATURE_DEPENDENCY_ROOTS):
            fail(f"CROSS_FEATURE_DEPENDENCY_ROOT_FORBIDDEN:{path}")
        if path.startswith(feature_dir + "/"):
            fail(f"CROSS_FEATURE_DEPENDENCY_POINTS_TO_SELF:{path}")

    workflow_prefixes = [
        template.format(feature_id=feature_id)
        for template in WORKFLOW_PREFIX_TEMPLATES
    ]

    def feature_owned(path):
        if path.startswith(feature_dir + "/"):
            return True
        if starts_with_any(path, RUNTIME_ROOTS):
            return True
        if path.startswith(".github/workflows/"):
            name = path.rsplit("/", 1)[-1]
            return starts_with_any(name, workflow_prefixes)
        if path.startswith("tools/"):
            name = path.rsplit("/", 1)[-1]
            return starts_with_any(name, TOOL_PREFIXES) or name in EXACT_TOOL_NAMES
        for name in skill_names:
            if path.startswith(f"plugins/mad4b-wordpress/skills/{name}/"):
                return True
            if path.startswith(f"wp-content/plugins/mad4b-site-control-plane/skill-seeds/{name}/"):
                return True
        return False

    def spec_owned(path):
        if path.startswith(feature_dir + "/"):
            return True
        if path in {
            f".github/workflows/feature-{feature_id}-spec-ci.yml",
            f".github/workflows/feature-{feature_id}-pre-staging-hybrid-audit.yml",
            "tools/mad4b_pre_staging_hybrid_audit.py",
        }:
            return True
        for name in skill_names:
            if path.startswith(f"plugins/mad4b-wordpress/skills/{name}/"):
                return True
            if path.startswith(f"wp-content/plugins/mad4b-site-control-plane/skill-seeds/{name}/"):
                return True
        return False

    changed = changed_paths(args.base)
    immutable = sorted(p for p in changed if p in IMMUTABLE_GLOBAL_PATHS)
    if immutable:
        fail("IMMUTABLE_GLOBAL_METADATA_CHANGED:" + ",".join(immutable))
    repository_owned = sorted(p for p in changed if p in REPOSITORY_OWNED_PATHS)

    implementation_prefixes = tuple(derived_branch_policy["implementation_prefixes"])
    spec_prefixes = tuple(derived_branch_policy["specification_maintenance_prefixes"])

    if status == "specification":
        forbidden = [p for p in changed if p not in REPOSITORY_OWNED_PATHS and not spec_owned(p)]
        mode = "specification_dynamic"
    elif status == "implementation":
        if starts_with_any(args.head_branch, implementation_prefixes):
            forbidden = [p for p in changed if p not in REPOSITORY_OWNED_PATHS and not feature_owned(p) and p not in cross]
            undeclared = [
                p for p in changed
                if p.startswith("specs/")
                and not p.startswith(feature_dir + "/")
                and p not in cross
            ]
            if undeclared:
                fail("UNDECLARED_CROSS_FEATURE_CHANGE:" + ",".join(sorted(undeclared)))
            mode = "implementation_dynamic"
        elif starts_with_any(args.head_branch, spec_prefixes):
            forbidden = [p for p in changed if p not in REPOSITORY_OWNED_PATHS and not spec_owned(p)]
            mode = "implementation_spec_maintenance_dynamic"
        else:
            fail(
                "IMPLEMENTATION_BRANCH_MISMATCH "
                f"expected={implementation_prefixes + spec_prefixes} actual={args.head_branch}"
            )
    else:
        fail(f"UNKNOWN_FEATURE_STATUS:{status!r}")

    if forbidden:
        fail("FORBIDDEN_CHANGE:" + ",".join(sorted(forbidden)))

    print(
        "MAD4B_FEATURE_BOUNDARY_OK "
        f"feature={feature_id} status={status} mode={mode} changed={len(changed)} "
        f"skills={len(skill_names)} cross_feature_dependencies={len(cross)} "
        f"repository_governance_source=exact-pr-base repository_owned_sidecar_changes={len(repository_owned)}"
    )

if __name__ == "__main__":
    main()
