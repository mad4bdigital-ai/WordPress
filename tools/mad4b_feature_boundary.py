#!/usr/bin/env python3
import argparse
import json
import subprocess
from pathlib import Path

POLICY_CONTRACT = "mad4b.feature-boundary-policy.v1"
BINDING_CONTRACT = "mad4b.feature-boundary-binding.v1"

def fail(message):
    raise SystemExit(message)

def load_json(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))

def changed_paths(base):
    return subprocess.check_output(
        ["git","diff","--name-only",f"{base}...HEAD"],
        text=True,
    ).splitlines()

def starts_with_any(value, prefixes):
    return any(value.startswith(prefix) for prefix in prefixes)

def validate_policy(policy):
    if policy.get("contract") != POLICY_CONTRACT:
        fail("FEATURE_BOUNDARY_POLICY_CONTRACT_INVALID")
    if policy.get("metadata_cannot_widen_policy") is not True:
        fail("FEATURE_BOUNDARY_POLICY_MUST_FAIL_CLOSED")
    if policy.get("cross_feature_dependencies_are_exact") is not True:
        fail("FEATURE_BOUNDARY_CROSS_FEATURE_DEPENDENCIES_MUST_BE_EXACT")

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--feature-json",required=True)
    ap.add_argument("--policy",required=True)
    ap.add_argument("--base",required=True)
    ap.add_argument("--head-branch",required=True)
    args=ap.parse_args()

    feature=load_json(args.feature_json)
    policy=load_json(args.policy)
    validate_policy(policy)

    feature_id=str(feature.get("feature_id") or "").strip()
    feature_dir=str(feature.get("feature_directory") or "").strip().rstrip("/")
    status=str(feature.get("status") or "")
    if not feature_id.isdigit() or len(feature_id) != 3:
        fail(f"FEATURE_ID_INVALID:{feature_id!r}")
    expected_prefix=f"{policy['feature_directory_root']}{feature_id}-"
    if not feature_dir.startswith(expected_prefix):
        fail(f"FEATURE_DIRECTORY_OUTSIDE_ID_NAMESPACE:{feature_dir!r}")

    binding=feature.get("implementation_boundary_policy") or {}
    expected_binding={
        "contract":BINDING_CONTRACT,
        "policy_contract":POLICY_CONTRACT,
        "policy_path":args.policy,
        "skill_inventory_source":policy["skill_inventory_source"],
        "cross_feature_dependencies_are_exact":True,
        "mutable_metadata_cannot_widen_policy":True,
    }
    if binding != expected_binding:
        fail("FEATURE_BOUNDARY_BINDING_DRIFT")

    derived_branch_policy={
        "contract":"mad4b.feature-implementation-branch-policy.v2",
        "implementation_prefixes":[f"feat/{feature_id}-",f"fix/{feature_id}-"],
        "specification_maintenance_prefixes":[f"spec/{feature_id}-"],
        "requires_exact_pr_base_ancestry":True,
        "mutable_metadata_cannot_widen_policy":True,
    }
    if feature.get("implementation_branch_policy") != derived_branch_policy:
        fail("FEATURE_BRANCH_POLICY_DRIFT")

    skill_manifest=load_json(policy["skill_inventory_source"])
    if skill_manifest.get("contract") != "mad4b.skill-seed-manifest.v1":
        fail("SKILL_INVENTORY_CONTRACT_INVALID")
    skill_names={
        str(row.get("name") or "")
        for row in skill_manifest.get("skills",[])
        if isinstance(row,dict) and row.get("name")
    }

    cross=list(feature.get("cross_feature_contract_dependencies") or [])
    if len(cross) != len(set(cross)):
        fail("DUPLICATE_CROSS_FEATURE_DEPENDENCY")
    for path in cross:
        if not isinstance(path,str) or not starts_with_any(path,policy["cross_feature_dependency_roots"]):
            fail(f"CROSS_FEATURE_DEPENDENCY_ROOT_FORBIDDEN:{path}")
        if path.startswith(feature_dir + "/"):
            fail(f"CROSS_FEATURE_DEPENDENCY_POINTS_TO_SELF:{path}")

    workflow_prefixes=[
        template.format(feature_id=feature_id)
        for template in policy["workflow_prefix_templates"]
    ]

    def feature_owned(path):
        if path.startswith(feature_dir + "/"):
            return True
        if starts_with_any(path,policy["runtime_roots"]):
            return True
        if path in policy["exact_repository_files"]:
            return True
        if path.startswith(".github/workflows/"):
            name=path.rsplit("/",1)[-1]
            return starts_with_any(name,workflow_prefixes)
        if path.startswith("tools/"):
            name=path.rsplit("/",1)[-1]
            return starts_with_any(name,policy["tool_prefixes"]) or name in policy["exact_tool_names"]
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

    changed=changed_paths(args.base)
    immutable=[p for p in changed if p in policy["immutable_global_paths"]]
    if immutable:
        fail("IMMUTABLE_GLOBAL_METADATA_CHANGED:" + ",".join(sorted(immutable)))

    implementation_prefixes=tuple(derived_branch_policy["implementation_prefixes"])
    spec_prefixes=tuple(derived_branch_policy["specification_maintenance_prefixes"])

    if status == "specification":
        forbidden=[p for p in changed if not spec_owned(p)]
        mode="specification_dynamic"
    elif status == "implementation":
        if starts_with_any(args.head_branch,implementation_prefixes):
            forbidden=[p for p in changed if not feature_owned(p) and p not in cross]
            undeclared=[
                p for p in changed
                if p.startswith("specs/")
                and not p.startswith(feature_dir + "/")
                and p not in cross
            ]
            if undeclared:
                fail("UNDECLARED_CROSS_FEATURE_CHANGE:" + ",".join(sorted(undeclared)))
            mode="implementation_dynamic"
        elif starts_with_any(args.head_branch,spec_prefixes):
            forbidden=[p for p in changed if not spec_owned(p)]
            mode="implementation_spec_maintenance_dynamic"
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
        f"skills={len(skill_names)} cross_feature_dependencies={len(cross)}"
    )

if __name__ == "__main__":
    main()
