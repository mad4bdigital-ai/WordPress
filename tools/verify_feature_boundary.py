#!/usr/bin/env python3
import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

GRANTS_PATH = ".github/mad4b-feature-boundary-grants.json"
REPOSITORY_POLICY_PATH = ".github/mad4b-repository-governance-policy.json"
GRANTS_CONTRACT = "mad4b.feature-boundary-grants.v1"
REPOSITORY_POLICY_CONTRACT = "mad4b.repository-governance-policy.v1"
STATUS_CONTRACT = "mad4b.feature-boundary-status.v1"

def fail(code, **details):
    payload={"contract":STATUS_CONTRACT,"ready":False,"failure_code":code}
    payload.update(details)
    raise SystemExit(json.dumps(payload, sort_keys=True))

def git(*args, check=True):
    p=subprocess.run(["git",*args], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if check and p.returncode != 0:
        fail("git_command_failed", args=list(args), stderr=p.stderr.strip())
    return p

def git_show(ref, path):
    p=git("show", f"{ref}:{path}", check=False)
    if p.returncode != 0:
        fail("baseline_owned_file_missing", ref=ref, path=path, stderr=p.stderr.strip())
    return p.stdout

def load_json_text(raw, label):
    try:
        data=json.loads(raw)
    except json.JSONDecodeError as exc:
        fail("json_invalid", label=label, error=str(exc))
    if not isinstance(data,dict):
        fail("json_root_not_object", label=label)
    return data

def starts_with_any(value, prefixes):
    return any(value.startswith(p) for p in prefixes)

def allowed(path, prefixes, exact):
    return path in exact or starts_with_any(path, prefixes)

def validate_grants(grants):
    if grants.get("contract") != GRANTS_CONTRACT:
        fail("grant_contract_mismatch")
    if grants.get("repository_governance_contract") != REPOSITORY_POLICY_CONTRACT:
        fail("grant_repository_contract_mismatch")
    if grants.get("repository_governance_policy_path") != REPOSITORY_POLICY_PATH:
        fail("grant_repository_policy_path_mismatch")
    if grants.get("verifier_path") != "tools/verify_feature_boundary.py":
        fail("grant_verifier_path_mismatch")
    immutable=grants.get("immutable_global_paths")
    required_immutable={
        GRANTS_PATH,
        REPOSITORY_POLICY_PATH,
        ".github/mad4b-master-ruleset-template.json",
        ".github/workflows/mad4b-feature-boundary.yml",
        ".github/workflows/mad4b-release-verdict.yml",
        ".github/workflows/mad4b-repository-governance.yml",
        ".github/workflows/mad4b-owner-attestation-rerun.yml",
        "tools/Apply-Mad4bMasterRuleset.ps1",
        "tools/verify_feature_boundary.py",
        "tools/verify_repository_governance.py",
        "tools/verify_repository_owner_attestation.py",
    }
    if not isinstance(immutable,list) or not required_immutable.issubset(set(immutable)):
        fail("grant_immutable_root_incomplete", missing=sorted(required_immutable-set(immutable or [])))
    features=grants.get("features")
    if not isinstance(features,dict) or not features:
        fail("grant_feature_catalog_empty")
    for fid,row in features.items():
        if not isinstance(fid,str) or not fid.isdigit() or len(fid)!=3 or not isinstance(row,dict):
            fail("grant_feature_identity_invalid", feature_id=fid)
        for field in (
            "feature_directory","implementation_branch_prefixes","specification_branch_prefixes",
            "implementation_allowed_prefixes","implementation_allowed_exact_paths",
            "specification_allowed_prefixes","specification_allowed_exact_paths",
            "cross_feature_contract_dependencies",
        ):
            if field not in row:
                fail("grant_feature_field_missing", feature_id=fid, field=field)
        for field in (
            "implementation_branch_prefixes","specification_branch_prefixes",
            "implementation_allowed_prefixes","implementation_allowed_exact_paths",
            "specification_allowed_prefixes","specification_allowed_exact_paths",
            "cross_feature_contract_dependencies",
        ):
            values=row[field]
            if not isinstance(values,list) or len(values)!=len(set(values)) or not all(isinstance(v,str) and v for v in values):
                fail("grant_feature_list_invalid", feature_id=fid, field=field)

def self_test():
    sample={
        "feature_directory":"specs/007-demo",
        "implementation_branch_prefixes":["feat/007-"],
        "specification_branch_prefixes":["spec/007-"],
        "implementation_allowed_prefixes":["specs/007-demo/","runtime/"],
        "implementation_allowed_exact_paths":[".github/workflows/feature-007-ci.yml"],
        "specification_allowed_prefixes":["specs/007-demo/"],
        "specification_allowed_exact_paths":[".github/workflows/feature-007-ci.yml"],
        "cross_feature_contract_dependencies":["specs/006-contract.md"],
    }
    assert allowed("runtime/a.php", sample["implementation_allowed_prefixes"], sample["implementation_allowed_exact_paths"])
    assert allowed(".github/workflows/feature-007-ci.yml", sample["implementation_allowed_prefixes"], sample["implementation_allowed_exact_paths"])
    assert not allowed(".github/workflows/other.yml", sample["implementation_allowed_prefixes"], sample["implementation_allowed_exact_paths"])
    assert re.match(r"^(?:feat|fix|spec)/([0-9]{3})-", "feat/007-example").group(1) == "007"
    immutable={
        GRANTS_PATH,
        REPOSITORY_POLICY_PATH,
        ".github/mad4b-master-ruleset-template.json",
        ".github/workflows/mad4b-feature-boundary.yml",
        ".github/workflows/mad4b-release-verdict.yml",
        ".github/workflows/mad4b-repository-governance.yml",
        ".github/workflows/mad4b-owner-attestation-rerun.yml",
        "tools/Apply-Mad4bMasterRuleset.ps1",
        "tools/verify_feature_boundary.py",
        "tools/verify_repository_governance.py",
        "tools/verify_repository_owner_attestation.py",
    }
    assert GRANTS_PATH in immutable and ".github/workflows/mad4b-feature-boundary.yml" in immutable and "runtime/a.php" not in immutable
    print("mad4b.feature-boundary-root-of-trust.self-test: PASS")
    return 0

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--base")
    ap.add_argument("--head")
    ap.add_argument("--head-branch")
    ap.add_argument("--output")
    ap.add_argument("--self-test", action="store_true")
    args=ap.parse_args()
    if args.self_test:
        return self_test()
    if not all([args.base,args.head,args.head_branch,args.output]):
        fail("required_arguments_missing")

    if git("merge-base","--is-ancestor",args.base,args.head,check=False).returncode != 0:
        fail("base_not_ancestor", base=args.base, head=args.head)

    grants_raw=git_show(args.base,GRANTS_PATH)
    policy_raw=git_show(args.base,REPOSITORY_POLICY_PATH)
    grants=load_json_text(grants_raw,GRANTS_PATH)
    policy=load_json_text(policy_raw,REPOSITORY_POLICY_PATH)
    validate_grants(grants)
    if policy.get("contract") != REPOSITORY_POLICY_CONTRACT:
        fail("repository_policy_contract_mismatch")
    if policy.get("target_branch") != "master" or policy.get("target_ref") != "refs/heads/master":
        fail("repository_policy_target_mismatch")

    changed=git("diff","--name-only",f"{args.base}...{args.head}").stdout.splitlines()
    branch_match=re.match(r"^(?:feat|fix|spec)/([0-9]{3})-", args.head_branch)
    if not branch_match:
        fail("feature_identity_not_derivable_from_branch", branch=args.head_branch)
    feature_id=branch_match.group(1)
    grant=(grants.get("features") or {}).get(feature_id)
    if not isinstance(grant,dict):
        fail("feature_not_granted", feature_id=feature_id)

    feature_path=f"{grant['feature_directory']}/feature.json"
    feature=load_json_text(git_show(args.head,feature_path),feature_path)
    if str(feature.get("feature_id") or "") != feature_id:
        fail("feature_metadata_id_mismatch", expected=feature_id, actual=feature.get("feature_id"))
    if str(feature.get("feature_directory") or "") != grant["feature_directory"]:
        fail("feature_directory_mismatch")

    boundary=feature.get("implementation_boundary_policy") or {}
    expected_boundary={
        "contract":"mad4b.feature-boundary-binding.v2",
        "policy_contract":REPOSITORY_POLICY_CONTRACT,
        "policy_path":REPOSITORY_POLICY_PATH,
        "skill_inventory_source":"wp-content/plugins/mad4b-site-control-plane/config/skill-seed-manifest.json",
        "cross_feature_dependencies_are_exact":True,
        "mutable_metadata_cannot_widen_policy":True,
        "repository_governance_files_are_immutable":True,
        "grant_catalog_path":GRANTS_PATH,
    }
    if boundary != expected_boundary:
        fail("feature_boundary_binding_drift")

    branch_policy=feature.get("implementation_branch_policy") or {}
    if branch_policy.get("contract") != "mad4b.feature-implementation-branch-policy.v2":
        fail("feature_branch_policy_contract_mismatch")
    if branch_policy.get("implementation_prefixes") != grant["implementation_branch_prefixes"]:
        fail("implementation_branch_prefix_drift")
    if branch_policy.get("specification_maintenance_prefixes") != grant["specification_branch_prefixes"]:
        fail("specification_branch_prefix_drift")
    if branch_policy.get("requires_exact_pr_base_ancestry") is not True or branch_policy.get("mutable_metadata_cannot_widen_policy") is not True:
        fail("feature_branch_policy_not_fail_closed")

    declared_cross=feature.get("cross_feature_contract_dependencies") or []
    if sorted(declared_cross) != sorted(grant["cross_feature_contract_dependencies"]):
        fail("cross_feature_dependency_grant_drift", declared=declared_cross, granted=grant["cross_feature_contract_dependencies"])

    if starts_with_any(args.head_branch,grant["implementation_branch_prefixes"]):
        mode="implementation"
        prefixes=grant["implementation_allowed_prefixes"]
        exact=set(grant["implementation_allowed_exact_paths"]) | set(grant["cross_feature_contract_dependencies"])
    elif starts_with_any(args.head_branch,grant["specification_branch_prefixes"]):
        mode="specification"
        prefixes=grant["specification_allowed_prefixes"]
        exact=set(grant["specification_allowed_exact_paths"]) | set(grant["cross_feature_contract_dependencies"])
    else:
        fail("feature_branch_not_granted", branch=args.head_branch)

    immutable=set(grants["immutable_global_paths"])
    touched_immutable=sorted(p for p in changed if p in immutable)
    if touched_immutable:
        fail("repository_owned_root_modified_by_feature", paths=touched_immutable)

    forbidden=sorted(p for p in changed if not allowed(p,prefixes,exact))
    if forbidden:
        fail("feature_boundary_violation", paths=forbidden)

    grant_sha=hashlib.sha256(grants_raw.encode("utf-8")).hexdigest()
    policy_sha=hashlib.sha256(policy_raw.encode("utf-8")).hexdigest()
    status={
        "contract":STATUS_CONTRACT,
        "ready":True,
        "feature_id":feature_id,
        "mode":mode,
        "base":args.base,
        "head":args.head,
        "head_branch":args.head_branch,
        "changed_path_count":len(changed),
        "grant_catalog_sha256":grant_sha,
        "repository_policy_sha256":policy_sha,
        "immutable_global_paths":sorted(immutable),
        "cross_feature_dependencies":sorted(grant["cross_feature_contract_dependencies"]),
    }
    Path(args.output).write_text(json.dumps(status,indent=2,sort_keys=True)+"\n",encoding="utf-8")
    print(json.dumps(status,sort_keys=True))
    return 0

if __name__ == "__main__":
    sys.exit(main())
