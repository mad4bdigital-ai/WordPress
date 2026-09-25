#!/usr/bin/env python3
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

POLICY_PATH = ".github/mad4b-feature-boundary-root-policy.json"
POLICY_CONTRACT = "mad4b.repository-feature-boundary-root-policy.v1"
OUTPUT_CONTRACT = "mad4b.feature-boundary-verdict.v1"

def fail(code, detail=""):
    raise SystemExit(code + (":" + detail if detail else ""))

def git(*args):
    return subprocess.check_output(["git", *args], text=True).strip()

def load_json_text(raw, code):
    try:
        value = json.loads(raw)
    except Exception as exc:
        fail(code, type(exc).__name__)
    if not isinstance(value, dict):
        fail(code, "not_object")
    return value

def load_from_ref(ref, path):
    try:
        return load_json_text(git("show", f"{ref}:{path}"), "BOUNDARY_JSON_INVALID")
    except subprocess.CalledProcessError:
        fail("BOUNDARY_ROOT_ARTIFACT_MISSING", f"{ref}:{path}")

def stable_json(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)

def validate_policy(policy):
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
    features = policy.get("features")
    if not isinstance(features, dict) or not features:
        fail("BOUNDARY_FEATURE_RULES_MISSING")
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
        missing = [k for k in required if k not in rule]
        if missing:
            fail("BOUNDARY_FEATURE_RULE_INCOMPLETE", f"{feature_id}:{','.join(missing)}")

def choose_feature(policy, branch):
    matches = []
    for feature_id, rule in policy["features"].items():
        prefixes = list(rule["implementation_branch_prefixes"]) + list(rule["specification_branch_prefixes"])
        if any(branch.startswith(prefix) for prefix in prefixes):
            matches.append((feature_id, rule))
    if len(matches) != 1:
        fail("BOUNDARY_BRANCH_FEATURE_AMBIGUOUS", branch)
    return matches[0]

def starts_with_root(path, roots):
    return any(path.startswith(root) for root in roots)

def classify_path(path, policy, rule):
    if path in set(policy.get("repository_owned_exact_paths") or []):
        return "repository_owned_forbidden"
    if path in set(policy.get("immutable_global_paths") or []):
        return "immutable_global_forbidden"
    feature_dir = str(rule["feature_directory"]).rstrip("/") + "/"
    if path.startswith(feature_dir):
        return "feature_spec"
    if starts_with_root(path, rule.get("runtime_roots") or []):
        return "feature_runtime"
    if starts_with_root(path, rule.get("skill_roots") or []):
        return "feature_skill"
    if path in set(rule.get("exact_feature_paths") or []):
        return "feature_exact"
    if path in set(rule.get("exact_cross_feature_dependencies") or []):
        return "declared_cross_feature"
    return "forbidden"

def verify(base, head, branch):
    if not re.fullmatch(r"[a-f0-9]{40}", base or ""):
        fail("BOUNDARY_BASE_SHA_INVALID")
    if not re.fullmatch(r"[a-f0-9]{40}", head or ""):
        fail("BOUNDARY_HEAD_SHA_INVALID")
    try:
        subprocess.check_call(["git", "merge-base", "--is-ancestor", base, head], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except subprocess.CalledProcessError:
        fail("BOUNDARY_BASE_NOT_ANCESTOR")

    raw_policy = git("show", f"{base}:{POLICY_PATH}")
    policy = load_json_text(raw_policy, "BOUNDARY_POLICY_JSON_INVALID")
    validate_policy(policy)
    feature_id, rule = choose_feature(policy, branch)

    feature = load_from_ref(head, rule["feature_json"])
    if str(feature.get("feature_id") or "") != str(feature_id):
        fail("BOUNDARY_FEATURE_IDENTITY_MISMATCH")
    if str(feature.get("feature_directory") or "").rstrip("/") != str(rule["feature_directory"]).rstrip("/"):
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

    changed = git("diff", "--name-only", f"{base}...{head}").splitlines()
    classified = {}
    forbidden = []
    repository_owned = []
    immutable = []
    for changed_path in sorted(filter(None, changed)):
        kind = classify_path(changed_path, policy, rule)
        classified[changed_path] = kind
        if kind == "forbidden":
            forbidden.append(changed_path)
        elif kind == "repository_owned_forbidden":
            repository_owned.append(changed_path)
        elif kind == "immutable_global_forbidden":
            immutable.append(changed_path)

    if repository_owned:
        fail("BOUNDARY_REPOSITORY_OWNED_CHANGE_REQUIRES_SEPARATE_PR", ",".join(repository_owned))
    if immutable:
        fail("BOUNDARY_IMMUTABLE_GLOBAL_CHANGED", ",".join(immutable))
    if forbidden:
        fail("BOUNDARY_FORBIDDEN_CHANGE", ",".join(forbidden))

    changed_digest = hashlib.sha256("\n".join(sorted(classified)).encode()).hexdigest()
    policy_digest = hashlib.sha256(raw_policy.encode()).hexdigest()
    return {
        "contract": OUTPUT_CONTRACT,
        "ready": True,
        "state": "feature_boundary_ready",
        "feature_id": str(feature_id),
        "feature_status": status,
        "base_sha": base,
        "head_sha": head,
        "head_branch": branch,
        "policy_source": "exact_pr_base",
        "policy_sha256": policy_digest,
        "changed_path_count": len(classified),
        "changed_paths_sha256": changed_digest,
        "classification_counts": {
            kind: list(classified.values()).count(kind)
            for kind in sorted(set(classified.values()))
        },
        "repository_owned_changes": [],
        "immutable_global_changes": [],
        "forbidden_changes": [],
    }

def self_test():
    policy = load_json_text(Path(POLICY_PATH).read_text(encoding="utf-8"), "BOUNDARY_SELFTEST_POLICY_INVALID")
    validate_policy(policy)
    rule = policy["features"]["007"]
    cases = {
        "wp-content/plugins/mad4b-site-control-plane/includes/example.php": "feature_runtime",
        "plugins/mad4b-wordpress/skills/wordpress-brand-context-builder/SKILL.md": "feature_skill",
        ".github/workflows/feature-007-spec-ci.yml": "feature_exact",
        "specs/006-agent-governed-reversible-control-plane/data-model.md": "declared_cross_feature",
        ".github/workflows/mad4b-release-verdict.yml": "repository_owned_forbidden",
        ".specify/memory/constitution.md": "immutable_global_forbidden",
        ".github/workflows/unrelated.yml": "forbidden",
    }
    for path, expected in cases.items():
        actual = classify_path(path, policy, rule)
        if actual != expected:
            fail("BOUNDARY_SELFTEST_CLASSIFICATION_FAILED", f"{path}:{actual}!={expected}")
    return {
        "contract": OUTPUT_CONTRACT,
        "self_test": True,
        "ready": True,
        "case_count": len(cases),
    }

def main():
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
        verdict = verify(args.base.lower(), args.head.lower(), args.head_branch)

    encoded = json.dumps(verdict, indent=2, sort_keys=True) + "\n"
    if args.output:
        Path(args.output).write_text(encoded, encoding="utf-8")
    print(encoded, end="")

if __name__ == "__main__":
    main()
