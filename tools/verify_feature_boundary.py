#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import subprocess
from pathlib import Path

CONTRACT = "mad4b.repository-feature-boundary.v1"
REPOSITORY_POLICY_CONTRACT = "mad4b.repository-governance-policy.v1"
BINDING_CONTRACT = "mad4b.feature-boundary-binding.v2"
FEATURE_BRANCH_RE = re.compile(r"^(?P<kind>feat|fix|spec)/(?P<feature_id>[0-9]{3})-")
FEATURE_DIRECTORY_ROOT = "specs/"
RUNTIME_ROOTS = (
    "wp-content/plugins/mad4b-site-control-plane/",
    "wp-content/plugins/mcp-adapter.zip",
)
WORKFLOW_PREFIX_TEMPLATES = ("feature-{feature_id}-", "mad4b-")
TOOL_PREFIXES = ("mad4b_", "verify_")
EXACT_TOOL_NAMES = {"capture-functional-gap-contract-evidence.py"}
SKILL_INVENTORY_SOURCE = "wp-content/plugins/mad4b-site-control-plane/config/skill-seed-manifest.json"
REPOSITORY_POLICY_PATH = ".github/mad4b-repository-governance-policy.json"
GRANT_CATALOG_PATH = ".github/mad4b-feature-boundary-grants.json"
GRANT_CATALOG_CONTRACT = "mad4b.repository-feature-boundary-grants.v1"
OBSOLETE_SELF_POLICY = ".github/mad4b-feature-boundary-policy.json"
IMMUTABLE_FEATURE_PATHS = {
    ".specify/feature.json",
    ".specify/memory/constitution.md",
    REPOSITORY_POLICY_PATH,
    ".github/workflows/mad4b-repository-governance.yml",
    ".github/workflows/mad4b-release-verdict.yml",
    ".github/workflows/mad4b-owner-attestation-rerun.yml",
    ".github/workflows/mad4b-feature-boundary-root.yml",
    "tools/verify_repository_governance.py",
    "tools/verify_repository_owner_attestation.py",
    "tools/verify_feature_boundary.py",
    GRANT_CATALOG_PATH,
    OBSOLETE_SELF_POLICY,
}


def run(*args: str) -> str:
    return subprocess.check_output(list(args), text=True).strip()


def fail(message: str) -> None:
    raise RuntimeError(message)


def read_at(ref: str, path: str) -> str:
    raw = subprocess.check_output(["git", "show", f"{ref}:{path}"])
    if len(raw) > 2 * 1024 * 1024:
        fail(f"FEATURE_BOUNDARY_FILE_TOO_LARGE:{path}")
    return raw.decode("utf-8")


def load_json_at(ref: str, path: str) -> dict:
    try:
        data = json.loads(read_at(ref, path))
    except (subprocess.CalledProcessError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        fail(f"FEATURE_BOUNDARY_JSON_INVALID:{path}:{exc}")
    if not isinstance(data, dict):
        fail(f"FEATURE_BOUNDARY_JSON_OBJECT_REQUIRED:{path}")
    return data


def changed_paths(base: str, head: str) -> list[str]:
    return [p for p in run("git", "diff", "--name-only", f"{base}...{head}").splitlines() if p]


def starts_with_any(value: str, prefixes) -> bool:
    return any(value.startswith(prefix) for prefix in prefixes)


def validate_repository_policy(base: str) -> dict:
    policy = load_json_at(base, REPOSITORY_POLICY_PATH)
    if policy.get("contract") != REPOSITORY_POLICY_CONTRACT:
        fail("REPOSITORY_GOVERNANCE_POLICY_CONTRACT_INVALID")
    if policy.get("target_branch") != "master":
        fail("REPOSITORY_GOVERNANCE_TARGET_INVALID")
    safety = policy.get("single_owner_safety") or {}
    if safety.get("owner_attestation_remains_exact_sha_scoped") is not True:
        fail("REPOSITORY_GOVERNANCE_EXACT_SHA_ATTESTATION_REQUIRED")
    if safety.get("attestation_stale_on_descendant") is not True:
        fail("REPOSITORY_GOVERNANCE_ATTESTATION_MUST_STALE_ON_DESCENDANT")
    return policy


def feature_grant(base: str, feature_id: str) -> dict:
    catalog = load_json_at(base, GRANT_CATALOG_PATH)
    if catalog.get("contract") != GRANT_CATALOG_CONTRACT:
        fail("FEATURE_BOUNDARY_GRANT_CATALOG_CONTRACT_INVALID")
    if catalog.get("default_policy") != "deny":
        fail("FEATURE_BOUNDARY_GRANT_DEFAULT_MUST_DENY")
    grants = catalog.get("grants") or {}
    grant = grants.get(feature_id)
    if not isinstance(grant, dict):
        fail(f"FEATURE_BOUNDARY_GRANT_MISSING:{feature_id}")
    if grant.get("repository_governance_mutation_allowed") is not False:
        fail("FEATURE_BOUNDARY_GRANT_GOVERNANCE_MUTATION_MUST_DENY")
    if grant.get("metadata_cannot_widen_grant") is not True:
        fail("FEATURE_BOUNDARY_GRANT_METADATA_WIDENING_MUST_DENY")
    if grant.get("release_critical_workflows_must_be_baseline_owned") is not True:
        fail("FEATURE_BOUNDARY_RELEASE_CRITICAL_BASELINE_OWNERSHIP_REQUIRED")
    blocked = grant.get("self_certifying_paths_forbidden") or []
    if not isinstance(blocked, list) or any(not isinstance(path, str) or not path for path in blocked):
        fail("FEATURE_BOUNDARY_SELF_CERTIFYING_PATHS_INVALID")
    return grant


def find_feature_json(head: str, feature_id: str) -> str:
    names = run("git", "ls-tree", "-r", "--name-only", head, "--", "specs/").splitlines()
    matches = [
        p for p in names
        if p.startswith(f"{FEATURE_DIRECTORY_ROOT}{feature_id}-") and p.endswith("/feature.json")
    ]
    if len(matches) != 1:
        fail(f"FEATURE_METADATA_DISCOVERY_INVALID:id={feature_id}:matches={len(matches)}")
    return matches[0]


def verify(base: str, head: str, head_branch: str) -> dict:
    ancestry = subprocess.run(
        ["git", "merge-base", "--is-ancestor", base, head],
        text=True,
        capture_output=True,
    )
    if ancestry.returncode != 0:
        fail(f"FEATURE_BASE_NOT_ANCESTOR:base={base}:head={head}")
    match = FEATURE_BRANCH_RE.match(head_branch)
    changed = changed_paths(base, head)
    if not match:
        return {
            "contract": CONTRACT,
            "ready": True,
            "mode": "non_feature_branch",
            "base_sha": base,
            "head_sha": head,
            "head_branch": head_branch,
            "changed_file_count": len(changed),
            "feature_id": "",
            "forbidden": [],
            "immutable_changed": [],
            "trusted_verifier_source": "base",
        }

    feature_id = match.group("feature_id")
    branch_kind = match.group("kind")
    validate_repository_policy(base)
    feature_json_path = find_feature_json(head, feature_id)
    feature = load_json_at(head, feature_json_path)
    feature_dir = str(feature.get("feature_directory") or "").strip().rstrip("/")
    status = str(feature.get("status") or "").strip()
    grant = feature_grant(base, feature_id)
    granted_feature_dir = str(grant.get("feature_directory") or "").strip().rstrip("/")
    if not granted_feature_dir or feature_dir != granted_feature_dir:
        fail(f"FEATURE_DIRECTORY_NOT_GRANTED:metadata={feature_dir!r}:grant={granted_feature_dir!r}")
    expected_prefix = f"{FEATURE_DIRECTORY_ROOT}{feature_id}-"
    if not feature_dir.startswith(expected_prefix):
        fail(f"FEATURE_DIRECTORY_OUTSIDE_ID_NAMESPACE:{feature_dir!r}")
    if feature_json_path != feature_dir + "/feature.json":
        fail("FEATURE_DIRECTORY_METADATA_PATH_MISMATCH")
    allowed_branch_kinds = set(str(x) for x in (grant.get("allowed_branch_kinds") or []))
    if branch_kind not in allowed_branch_kinds:
        fail(f"FEATURE_BRANCH_KIND_NOT_GRANTED:{branch_kind}")

    binding = feature.get("implementation_boundary_policy") or {}
    expected_binding = {
        "contract": BINDING_CONTRACT,
        "policy_contract": REPOSITORY_POLICY_CONTRACT,
        "policy_path": REPOSITORY_POLICY_PATH,
        "skill_inventory_source": SKILL_INVENTORY_SOURCE,
        "grant_catalog_path": GRANT_CATALOG_PATH,
        "cross_feature_dependencies_are_exact": True,
        "mutable_metadata_cannot_widen_policy": True,
        "repository_governance_files_are_immutable": True,
    }
    if binding != expected_binding:
        fail("FEATURE_BOUNDARY_BINDING_DRIFT")

    expected_branch_policy = {
        "contract": "mad4b.feature-implementation-branch-policy.v2",
        "implementation_prefixes": [f"feat/{feature_id}-", f"fix/{feature_id}-"],
        "specification_maintenance_prefixes": [f"spec/{feature_id}-"],
        "requires_exact_pr_base_ancestry": True,
        "mutable_metadata_cannot_widen_policy": True,
    }
    if feature.get("implementation_branch_policy") != expected_branch_policy:
        fail("FEATURE_BRANCH_POLICY_DRIFT")

    skill_manifest = load_json_at(head, SKILL_INVENTORY_SOURCE)
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
    granted_cross = sorted(str(x) for x in (grant.get("cross_feature_exact_paths") or []))
    if sorted(cross) != granted_cross:
        fail("CROSS_FEATURE_DEPENDENCY_GRANT_DRIFT")

    immutable = sorted(p for p in changed if p in IMMUTABLE_FEATURE_PATHS)
    if immutable:
        fail("REPOSITORY_ROOT_OF_TRUST_CHANGED_FROM_FEATURE:" + ",".join(immutable))
    self_certifying = sorted(
        p for p in changed
        if p in set(str(x) for x in (grant.get("self_certifying_paths_forbidden") or []))
    )
    if self_certifying:
        fail("SELF_CERTIFYING_RELEASE_CRITICAL_CHANGE:" + ",".join(self_certifying))

    allowed_prefixes = tuple(str(x) for x in (grant.get("allowed_path_prefixes") or []))
    allowed_exact = set(str(x) for x in (grant.get("allowed_exact_paths") or []))
    granted_cross_set = set(granted_cross)

    def feature_owned(path: str) -> bool:
        return path in allowed_exact or starts_with_any(path, allowed_prefixes)

    def spec_owned(path: str) -> bool:
        if path.startswith(feature_dir + "/"):
            return True
        return path in allowed_exact and (
            path == f".github/workflows/feature-{feature_id}-spec-ci.yml"
            or path == f".github/workflows/feature-{feature_id}-pre-staging-hybrid-audit.yml"
            or path == "tools/mad4b_pre_staging_hybrid_audit.py"
            or "/skills/" in path
        )

    if status == "specification":
        if branch_kind != "spec":
            fail(f"FEATURE_STATUS_BRANCH_MISMATCH:status={status}:branch_kind={branch_kind}")
        forbidden = [p for p in changed if not spec_owned(p)]
        mode = "specification"
    elif status == "implementation":
        if branch_kind in {"feat", "fix"}:
            forbidden = [p for p in changed if not feature_owned(p) and p not in granted_cross_set]
            undeclared = [
                p for p in changed
                if p.startswith("specs/")
                and not p.startswith(feature_dir + "/")
                and p not in granted_cross_set
            ]
            if undeclared:
                fail("UNDECLARED_CROSS_FEATURE_CHANGE:" + ",".join(sorted(undeclared)))
            mode = "implementation"
        elif branch_kind == "spec":
            forbidden = [p for p in changed if not spec_owned(p)]
            mode = "implementation_spec_maintenance"
        else:
            fail(f"IMPLEMENTATION_BRANCH_MISMATCH:{head_branch}")
    else:
        fail(f"UNKNOWN_FEATURE_STATUS:{status!r}")

    if forbidden:
        fail("FORBIDDEN_CHANGE:" + ",".join(sorted(forbidden)))

    return {
        "contract": CONTRACT,
        "ready": True,
        "mode": mode,
        "base_sha": base,
        "head_sha": head,
        "head_branch": head_branch,
        "feature_id": feature_id,
        "feature_json": feature_json_path,
        "changed_file_count": len(changed),
        "skill_inventory_count": len(skill_names),
        "cross_feature_dependency_count": len(cross),
        "grant_catalog_path": GRANT_CATALOG_PATH,
        "grant_source": "base",
        "allowed_path_prefix_count": len(allowed_prefixes),
        "allowed_exact_path_count": len(allowed_exact),
        "self_certifying_paths_forbidden": sorted(str(x) for x in (grant.get("self_certifying_paths_forbidden") or [])),
        "forbidden": [],
        "immutable_changed": [],
        "trusted_verifier_source": "base",
        "pull_request_code_executed": False,
        "repository_governance_files_are_immutable": True,
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", required=True)
    ap.add_argument("--head", required=True)
    ap.add_argument("--head-branch", required=True)
    ap.add_argument("--output", type=Path)
    args = ap.parse_args()
    try:
        result = verify(args.base, args.head, args.head_branch)
    except (RuntimeError, subprocess.CalledProcessError) as exc:
        result = {
            "contract": CONTRACT,
            "ready": False,
            "base_sha": args.base,
            "head_sha": args.head,
            "head_branch": args.head_branch,
            "failure_reason": str(exc),
            "trusted_verifier_source": "base",
            "pull_request_code_executed": False,
        }
        if args.output:
            args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print("FEATURE_BOUNDARY: FAIL:", exc)
        return 1
    if args.output:
        args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print("FEATURE_BOUNDARY: PASS")
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
