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
CROSS_FEATURE_DEPENDENCY_ROOTS = ("specs/", ".github/workflows/")
REPOSITORY_POLICY_PATH = ".github/mad4b-repository-governance-policy.json"
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
    if run("git", "merge-base", "--is-ancestor", base, head) != "":
        pass
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
    expected_prefix = f"{FEATURE_DIRECTORY_ROOT}{feature_id}-"
    if not feature_dir.startswith(expected_prefix):
        fail(f"FEATURE_DIRECTORY_OUTSIDE_ID_NAMESPACE:{feature_dir!r}")
    if feature_json_path != feature_dir + "/feature.json":
        fail("FEATURE_DIRECTORY_METADATA_PATH_MISMATCH")

    binding = feature.get("implementation_boundary_policy") or {}
    expected_binding = {
        "contract": BINDING_CONTRACT,
        "policy_contract": REPOSITORY_POLICY_CONTRACT,
        "policy_path": REPOSITORY_POLICY_PATH,
        "skill_inventory_source": SKILL_INVENTORY_SOURCE,
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
    for path in cross:
        if not isinstance(path, str) or not starts_with_any(path, CROSS_FEATURE_DEPENDENCY_ROOTS):
            fail(f"CROSS_FEATURE_DEPENDENCY_ROOT_FORBIDDEN:{path}")
        if path.startswith(feature_dir + "/"):
            fail(f"CROSS_FEATURE_DEPENDENCY_POINTS_TO_SELF:{path}")

    immutable = sorted(p for p in changed if p in IMMUTABLE_FEATURE_PATHS)
    if immutable:
        fail("REPOSITORY_ROOT_OF_TRUST_CHANGED_FROM_FEATURE:" + ",".join(immutable))

    workflow_prefixes = tuple(t.format(feature_id=feature_id) for t in WORKFLOW_PREFIX_TEMPLATES)

    def feature_owned(path: str) -> bool:
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

    def spec_owned(path: str) -> bool:
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

    if status == "specification":
        if branch_kind != "spec":
            fail(f"FEATURE_STATUS_BRANCH_MISMATCH:status={status}:branch_kind={branch_kind}")
        forbidden = [p for p in changed if not spec_owned(p)]
        mode = "specification"
    elif status == "implementation":
        if branch_kind in {"feat", "fix"}:
            forbidden = [p for p in changed if not feature_owned(p) and p not in cross]
            undeclared = [
                p for p in changed
                if p.startswith("specs/")
                and not p.startswith(feature_dir + "/")
                and p not in cross
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
