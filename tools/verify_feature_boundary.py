#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

POLICY_PATH = ".github/mad4b-feature-boundary-root-policy.json"
POLICY_CONTRACT = "mad4b.repository-feature-boundary-root-policy.v1"
OUTPUT_CONTRACT = "mad4b.feature-boundary-verdict.v2"
SHA_RE = re.compile(r"^[a-f0-9]{40}$")


def fail(code: str, detail: str = ""):
    raise SystemExit(code + (":" + detail if detail else ""))


def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], text=True).strip()


def load_json_text(raw: str, code: str) -> dict:
    try:
        value = json.loads(raw)
    except Exception as exc:
        fail(code, type(exc).__name__)
    if not isinstance(value, dict):
        fail(code, "not_object")
    return value


def load_from_ref(ref: str, path: str) -> dict:
    try:
        return load_json_text(
            git("show", f"{ref}:{path}"),
            "BOUNDARY_JSON_INVALID",
        )
    except subprocess.CalledProcessError:
        fail("BOUNDARY_ROOT_ARTIFACT_MISSING", f"{ref}:{path}")


def changed_paths(base: str, head: str) -> list[str]:
    raw = git("diff", "--name-only", f"{base}...{head}")
    return sorted(line for line in raw.splitlines() if line)


def starts_with_any(value: str, prefixes: list[str] | tuple[str, ...]) -> bool:
    return any(value.startswith(prefix) for prefix in prefixes)


def starts_with_root(path: str, roots: list[str]) -> bool:
    return any(path.startswith(root) for root in roots)


def validate_policy(policy: dict) -> None:
    if policy.get("contract") != POLICY_CONTRACT:
        fail("BOUNDARY_POLICY_CONTRACT_INVALID")
    if policy.get("target_branch") != "master":
        fail("BOUNDARY_POLICY_TARGET_INVALID")
    if policy.get("source_of_truth") != "repository_owned_exact_base":
        fail("BOUNDARY_POLICY_SOURCE_INVALID")
    if policy.get("metadata_cannot_widen_policy") is not True:
        fail("BOUNDARY_POLICY_MUST_FAIL_CLOSED")
    if policy.get("repository_owned_changes_require_separate_governance_pr") is not True:
        fail("BOUNDARY_REPOSITORY_OWNERSHIP_SEPARATION_REQUIRED")
    if policy.get("policy_path") != POLICY_PATH:
        fail("BOUNDARY_POLICY_PATH_DRIFT")
    if policy.get("verifier_path") != "tools/verify_feature_boundary.py":
        fail("BOUNDARY_VERIFIER_PATH_DRIFT")

    governance_prefixes = policy.get("governance_branch_prefixes")
    if (
        not isinstance(governance_prefixes, list)
        or not governance_prefixes
        or any(not isinstance(x, str) or not x for x in governance_prefixes)
        or len(set(governance_prefixes)) != len(governance_prefixes)
    ):
        fail("BOUNDARY_GOVERNANCE_BRANCH_POLICY_INVALID")

    safety = policy.get("single_owner_safety") or {}
    allowed = safety.get("authorized_owner_logins") or []
    if (
        not isinstance(allowed, list)
        or not allowed
        or any(not isinstance(x, str) or not x.strip() for x in allowed)
    ):
        fail("BOUNDARY_OWNER_ALLOWLIST_INVALID")
    if safety.get("attestation_command") != "OWNER_ATTEST_SINGLE_OWNER":
        fail("BOUNDARY_OWNER_ATTESTATION_COMMAND_DRIFT")
    if safety.get("owner_attestation_remains_exact_sha_scoped") is not True:
        fail("BOUNDARY_OWNER_ATTESTATION_MUST_BE_EXACT_SHA")

    repository_paths = policy.get("repository_owned_exact_paths")
    mandatory_repository_paths = {
        POLICY_PATH,
        ".github/workflows/mad4b-feature-boundary-root.yml",
        "tools/verify_feature_boundary.py",
        "tools/verify_repository_owner_attestation.py",
        ".github/workflows/mad4b-release-verdict.yml",
        ".github/mad4b-repository-governance-policy.json",
    }
    if (
        not isinstance(repository_paths, list)
        or len(repository_paths) != len(set(repository_paths))
        or not mandatory_repository_paths.issubset(set(repository_paths))
    ):
        fail("BOUNDARY_REPOSITORY_PATH_POLICY_INVALID")

    immutable = policy.get("immutable_global_paths")
    if (
        not isinstance(immutable, list)
        or len(immutable) != len(set(immutable))
        or not immutable
    ):
        fail("BOUNDARY_IMMUTABLE_GLOBAL_POLICY_INVALID")

    features = policy.get("features")
    if not isinstance(features, dict) or not features:
        fail("BOUNDARY_FEATURE_RULES_MISSING")
    seen_prefixes: list[tuple[str, str]] = []
    for feature_id, rule in features.items():
        if not re.fullmatch(r"\d{3}", str(feature_id)) or not isinstance(rule, dict):
            fail("BOUNDARY_FEATURE_RULE_INVALID", str(feature_id))
        required = (
            "feature_directory",
            "feature_json",
            "implementation_branch_prefixes",
            "specification_branch_prefixes",
            "runtime_roots",
            "skill_roots",
            "exact_feature_paths",
            "exact_cross_feature_dependencies",
        )
        missing = [key for key in required if key not in rule]
        if missing:
            fail(
                "BOUNDARY_FEATURE_RULE_INCOMPLETE",
                f"{feature_id}:{','.join(missing)}",
            )
        prefixes = list(rule["implementation_branch_prefixes"]) + list(
            rule["specification_branch_prefixes"]
        )
        if not prefixes or any(not isinstance(x, str) or not x for x in prefixes):
            fail("BOUNDARY_FEATURE_BRANCH_POLICY_INVALID", str(feature_id))
        for prefix in prefixes:
            seen_prefixes.append((str(feature_id), prefix))

    for i, (feature_a, prefix_a) in enumerate(seen_prefixes):
        for feature_b, prefix_b in seen_prefixes[i + 1 :]:
            if feature_a != feature_b and (
                prefix_a.startswith(prefix_b) or prefix_b.startswith(prefix_a)
            ):
                fail(
                    "BOUNDARY_FEATURE_BRANCH_PREFIX_OVERLAP",
                    f"{feature_a}:{prefix_a}|{feature_b}:{prefix_b}",
                )


def matching_features(policy: dict, branch: str) -> list[tuple[str, dict]]:
    matches = []
    for feature_id, rule in policy["features"].items():
        prefixes = list(rule["implementation_branch_prefixes"]) + list(
            rule["specification_branch_prefixes"]
        )
        if starts_with_any(branch, prefixes):
            matches.append((str(feature_id), rule))
    return matches


def classify_feature_path(path: str, rule: dict) -> str:
    feature_dir = str(rule["feature_directory"]).rstrip("/") + "/"
    if path.startswith(feature_dir):
        return "feature_spec"
    if starts_with_root(path, list(rule.get("runtime_roots") or [])):
        return "feature_runtime"
    if starts_with_root(path, list(rule.get("skill_roots") or [])):
        return "feature_skill"
    if path in set(rule.get("exact_feature_paths") or []):
        return "feature_exact"
    if path in set(rule.get("exact_cross_feature_dependencies") or []):
        return "declared_cross_feature"
    return "forbidden"


def feature_governed_path(policy: dict, path: str) -> bool:
    for rule in policy["features"].values():
        if classify_feature_path(path, rule) != "forbidden":
            return True
    return False


def base_result(
    *,
    state: str,
    base: str,
    head: str,
    branch: str,
    policy_raw: str,
    changed: list[str],
    owner_attestation_required: bool,
) -> dict:
    return {
        "contract": OUTPUT_CONTRACT,
        "ready": True,
        "state": state,
        "base_sha": base,
        "head_sha": head,
        "head_branch": branch,
        "policy_source": "exact_pr_base",
        "policy_sha256": hashlib.sha256(policy_raw.encode()).hexdigest(),
        "changed_path_count": len(changed),
        "changed_paths_sha256": hashlib.sha256(
            "\n".join(changed).encode()
        ).hexdigest(),
        "owner_attestation_required": owner_attestation_required,
        "owner_attestation_scope": "exact_head" if owner_attestation_required else "none",
        "mutation_performed": False,
    }


def verify(base: str, head: str, branch: str) -> dict:
    if not SHA_RE.fullmatch(base or ""):
        fail("BOUNDARY_BASE_SHA_INVALID")
    if not SHA_RE.fullmatch(head or ""):
        fail("BOUNDARY_HEAD_SHA_INVALID")
    try:
        subprocess.check_call(
            ["git", "merge-base", "--is-ancestor", base, head],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    except subprocess.CalledProcessError:
        fail("BOUNDARY_BASE_NOT_ANCESTOR")

    try:
        raw_policy = git("show", f"{base}:{POLICY_PATH}")
    except subprocess.CalledProcessError:
        fail("BOUNDARY_ROOT_ARTIFACT_MISSING", f"{base}:{POLICY_PATH}")
    policy = load_json_text(raw_policy, "BOUNDARY_POLICY_JSON_INVALID")
    validate_policy(policy)
    changed = changed_paths(base, head)

    repository_paths = set(policy["repository_owned_exact_paths"])
    immutable_paths = set(policy["immutable_global_paths"])
    repository_owned = [path for path in changed if path in repository_paths]
    immutable = [path for path in changed if path in immutable_paths]

    if immutable:
        fail("BOUNDARY_IMMUTABLE_GLOBAL_CHANGED", ",".join(immutable))

    if repository_owned:
        governance_prefixes = list(policy["governance_branch_prefixes"])
        if not starts_with_any(branch, governance_prefixes):
            fail(
                "BOUNDARY_REPOSITORY_OWNED_CHANGE_REQUIRES_GOVERNANCE_BRANCH",
                branch,
            )
        mixed_scope = [path for path in changed if path not in repository_paths]
        if mixed_scope:
            fail(
                "BOUNDARY_GOVERNANCE_PR_MIXED_SCOPE",
                ",".join(mixed_scope),
            )
        result = base_result(
            state="repository_governance_boundary_ready",
            base=base,
            head=head,
            branch=branch,
            policy_raw=raw_policy,
            changed=changed,
            owner_attestation_required=True,
        )
        result.update(
            {
                "mode": "repository_governance",
                "repository_owned_changes": repository_owned,
                "forbidden_changes": [],
            }
        )
        return result

    matches = matching_features(policy, branch)
    if len(matches) > 1:
        fail(
            "BOUNDARY_BRANCH_FEATURE_AMBIGUOUS",
            ",".join(feature_id for feature_id, _ in matches),
        )
    if not matches:
        governed = [path for path in changed if feature_governed_path(policy, path)]
        if governed:
            fail(
                "BOUNDARY_GOVERNED_PATH_REQUIRES_FEATURE_BRANCH",
                ",".join(governed),
            )
        result = base_result(
            state="not_applicable",
            base=base,
            head=head,
            branch=branch,
            policy_raw=raw_policy,
            changed=changed,
            owner_attestation_required=False,
        )
        result.update(
            {
                "mode": "not_applicable",
                "repository_owned_changes": [],
                "forbidden_changes": [],
            }
        )
        return result

    feature_id, rule = matches[0]
    feature = load_from_ref(head, str(rule["feature_json"]))
    if str(feature.get("feature_id") or "") != feature_id:
        fail("BOUNDARY_FEATURE_IDENTITY_MISMATCH")
    if str(feature.get("feature_directory") or "").rstrip("/") != str(
        rule["feature_directory"]
    ).rstrip("/"):
        fail("BOUNDARY_FEATURE_DIRECTORY_DRIFT")

    status = str(feature.get("status") or "")
    implementation = tuple(rule["implementation_branch_prefixes"])
    specification = tuple(rule["specification_branch_prefixes"])
    if status == "implementation":
        if not (branch.startswith(implementation) or branch.startswith(specification)):
            fail("BOUNDARY_IMPLEMENTATION_BRANCH_MISMATCH", branch)
    elif status == "specification":
        if not branch.startswith(specification):
            fail("BOUNDARY_SPECIFICATION_BRANCH_MISMATCH", branch)
    else:
        fail("BOUNDARY_FEATURE_STATUS_INVALID", status)

    classified: dict[str, str] = {}
    forbidden: list[str] = []
    for changed_path in changed:
        kind = classify_feature_path(changed_path, rule)
        classified[changed_path] = kind
        if kind == "forbidden":
            forbidden.append(changed_path)
    if forbidden:
        fail("BOUNDARY_FORBIDDEN_CHANGE", ",".join(forbidden))

    result = base_result(
        state="feature_boundary_ready",
        base=base,
        head=head,
        branch=branch,
        policy_raw=raw_policy,
        changed=changed,
        owner_attestation_required=False,
    )
    result.update(
        {
            "mode": "feature",
            "feature_id": feature_id,
            "feature_status": status,
            "classification_counts": {
                kind: list(classified.values()).count(kind)
                for kind in sorted(set(classified.values()))
            },
            "repository_owned_changes": [],
            "immutable_global_changes": [],
            "forbidden_changes": [],
        }
    )
    return result


def self_test() -> dict:
    policy = load_json_text(
        Path(POLICY_PATH).read_text(encoding="utf-8"),
        "BOUNDARY_SELFTEST_POLICY_INVALID",
    )
    validate_policy(policy)
    rule = policy["features"]["007"]
    cases = {
        "wp-content/plugins/mad4b-site-control-plane/includes/example.php": "feature_runtime",
        "plugins/mad4b-wordpress/skills/wordpress-brand-context-builder/SKILL.md": "feature_skill",
        ".github/workflows/feature-007-spec-ci.yml": "feature_exact",
        "specs/006-agent-governed-reversible-control-plane/data-model.md": "declared_cross_feature",
        ".github/workflows/unrelated.yml": "forbidden",
    }
    for path, expected in cases.items():
        actual = classify_feature_path(path, rule)
        if actual != expected:
            fail(
                "BOUNDARY_SELFTEST_CLASSIFICATION_FAILED",
                f"{path}:{actual}!={expected}",
            )
    for root_path in (
        POLICY_PATH,
        ".github/workflows/mad4b-release-verdict.yml",
        "tools/verify_repository_owner_attestation.py",
    ):
        if root_path not in set(policy["repository_owned_exact_paths"]):
            fail("BOUNDARY_SELFTEST_ROOT_PATH_MISSING", root_path)
    return {
        "contract": OUTPUT_CONTRACT,
        "self_test": True,
        "ready": True,
        "case_count": len(cases) + 3,
        "governance_mode_supported": True,
        "owner_attestation_for_governance": True,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base")
    parser.add_argument("--head")
    parser.add_argument("--head-branch")
    parser.add_argument("--output")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    if args.self_test:
        verdict = self_test()
    else:
        if not all([args.base, args.head, args.head_branch]):
            fail("BOUNDARY_REQUIRED_ARGUMENT_MISSING")
        verdict = verify(
            args.base.lower(),
            args.head.lower(),
            args.head_branch,
        )

    encoded = json.dumps(verdict, indent=2, sort_keys=True) + "\n"
    if args.output:
        Path(args.output).write_text(encoded, encoding="utf-8")
    print(encoded, end="")


if __name__ == "__main__":
    main()
