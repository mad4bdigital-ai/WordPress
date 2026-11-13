#!/usr/bin/env python3
"""Read-only exact-source admission of registered site owners to Original Company Code Release.

Composes the existing company_code_release target policy and original Git proof.
It NEVER changes the allowlist, issues a grant, or calls Apply/Rollback.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
from typing import Callable

import verify

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import company_code_release as release

CONTRACT = "mad4b.site-owner-original-code-release.admission.v1"
SHA = re.compile(r"^[a-f0-9]{40}$")


def source_at_commit(repo: Path, sha: str, path: str) -> bytes:
    if not SHA.fullmatch(sha):
        raise ValueError("INVALID_COMMIT_SHA")
    # The path has already been checked against the exact registry contract.
    return release.git_bytes(repo, sha, path)


def assess(
    *,
    repo: Path,
    commit: str,
    site: str,
    company: str,
    environment: str,
    policy: dict,
    original_identity_reader: Callable[[str, str], dict] | None = None,
) -> dict:
    """Create a non-authorizing admission report for an exact registered site variant."""
    manifest = repo / verify.REGISTRY
    registry = json.loads(manifest.read_text(encoding="utf-8"),
                          object_pairs_hook=verify.unique_object_pairs)
    if not isinstance(registry, dict):
        raise verify.InvalidProvenance("INVALID_SOURCE_OWNER_REGISTRY")
    # Verify the locally reviewed candidate against its immutable Git tree.
    variants = []
    for cap in registry.get("capabilities", []):
        for variant in cap.get("variants", []):
            if variant.get("site") == site and variant.get("environment") == environment:
                variants.append({
                    "capability": cap["capability"], "symbol": cap["runtime_symbol"],
                    **variant,
                })
    if not variants:
        raise verify.InvalidProvenance("SITE_ENVIRONMENT_NOT_ENROLLED_IN_OWNER_REGISTRY")

    # The original Code Release requires policy-enrolled company + environment.
    try:
        entry = release.target_binding(policy, company, environment)
    except release.Denied as exc:
        return {
            "contract": CONTRACT,
            "state": "HOLD_ORIGINAL_TARGET_NOT_ENROLLED",
            "site": site, "company": company, "environment": environment,
            "candidate_commit": commit,
            "variant_count": len(variants),
            "blockers": ["PINNED_COMPANY_TARGET_NOT_ENROLLED"],
            "release_execution_allowed": False, "broker_grant_issued": False,
            "production_mutated": False,
        }

    root = Path(entry["root"])
    # Same authority binding as the original release executor: observe the
    # original native site resolver, not a guessed company/site name mapping.
    identity_verified = False
    identity_blocker = None
    if original_identity_reader is None:
        identity_blocker = "ORIGINAL_NATIVE_IDENTITY_READBACK_NOT_SUPPLIED"
    else:
        try:
            observed = original_identity_reader(company, environment)
            identity_verified = (
                isinstance(observed, dict)
                and observed.get("contract") ==
                    "mad4b.faore.native-operator-dynamic-target.v1"
                and observed.get("company_id") == company
                and observed.get("environment") == environment
                and observed.get("site_uuid") == entry["site_uuid"]
                and observed.get("canonical_origin") == entry["origin"]
                and observed.get("target_container") == entry["container"]
                and observed.get("read_only") is True
                and observed.get("authorization_created") is False
                and observed.get("native_execution_performed") is False
            )
        except (OSError, ValueError, RuntimeError,
                subprocess.TimeoutExpired):
            identity_verified = False
        if not identity_verified:
            identity_blocker = "ORIGINAL_NATIVE_SITE_IDENTITY_NOT_VERIFIED"
    # The registry must be valid and each exact runtime preimage unchanged.
    verify.validate(repo, manifest, {site: root})
    blockers: list[str] = []
    if identity_blocker:
        blockers.append(identity_blocker)
    pinned_git_root = Path(policy["source_git_root"]).resolve()
    candidate_root = repo.resolve()
    if candidate_root != pinned_git_root:
        blockers.append("PINNED_GIT_SOURCE_ROOT_DIFFERS_FROM_REVIEWED_CANDIDATE")
    if not SHA.fullmatch(commit):
        raise verify.InvalidProvenance("COMMIT_SHA_INVALID")
    # Compare all owner artifacts with the exact *Git* commit, not mutable files.
    verified = 0
    for variant in variants:
        source = source_at_commit(repo, commit, variant["source_artifact"])
        if hashlib.sha256(source).hexdigest() != variant["sha256"]:
            raise verify.InvalidProvenance("COMMITTED_OWNER_SOURCE_HASH_DRIFT")
        verified += 1

    # An unmodified static policy can accept only its exact explicit files.
    # Catalog expansion is attempted only if the commit belongs to the PINNED
    # release source repository, through the original catalog validator.
    allowlist = set(entry["allowed_targets"])
    if candidate_root == pinned_git_root:
        try:
            allowlist = release.registered_release_targets(policy, entry, commit)
        except (release.Denied, OSError, subprocess.TimeoutExpired):
            blockers.append("ORIGINAL_RELEASE_CATALOG_NOT_VERIFIED")
    statuses = []
    for v in variants:
        target = v["runtime_path"]
        explicit = target in allowlist
        shape = release.permitted_target(target)
        # A source-only site-owned artifact is not a source-target file in the
        # original runtime Git tree. Do not silently substitute its path.
        committed_target = False
        if candidate_root == pinned_git_root:
            try:
                original = source_at_commit(repo, commit, target)
                committed_target = hashlib.sha256(original).hexdigest() == v["sha256"]
            except (release.Denied, subprocess.TimeoutExpired, OSError):
                pass
        blockers_for_target = []
        if not explicit:
            blockers_for_target.append("TARGET_NOT_IN_ORIGINAL_SIGNED_POLICY_OR_CATALOG")
        if not shape:
            blockers_for_target.append("TARGET_PATH_OUTSIDE_ORIGINAL_EXECUTOR_SCOPE")
        if not committed_target:
            blockers_for_target.append("ORIGINAL_GIT_TARGET_ARTIFACT_NOT_ENROLLED")
        statuses.append({
            "capability": v["capability"],
            "target": target,
            "original_target_allowed": explicit,
            "original_target_path_supported": shape,
            "original_git_target_verified": committed_target,
            "eligible_for_original_plan": not blockers_for_target,
            "blockers": blockers_for_target,
        })
    if any(item["blockers"] for item in statuses):
        blockers.append("COMPONENT_TARGET_ENROLLMENT_INCOMPLETE")
    # A checked candidate is deliberately NOT a signed claim/grant or a
    # standalone Original313 independent readback.
    blockers.extend(["EXACT_ORIGINAL_312_JOB_GRANT_NOT_PROVIDED",
                     "ORIGINAL_313_INDEPENDENT_READBACK_NOT_PROVIDED"])
    return {
        "contract": CONTRACT, "state": "HOLD_SIGNED_ORIGINAL_RELEASE_ADMISSION",
        "site": site, "company": company, "environment": environment,
        "candidate_commit": commit,
        "source_committed_variants_verified": verified,
        "runtime_preimages_verified": len(statuses),
        "source_git_root_pinned_to_candidate": candidate_root == pinned_git_root,
        "original_native_site_identity_verified": identity_verified,
        "targets": statuses,
        "blockers": list(dict.fromkeys(blockers)),
        "release_execution_allowed": False, "broker_grant_issued": False,
        "production_mutated": False,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--candidate-repo", type=Path, required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--site", required=True)
    parser.add_argument("--company", required=True)
    parser.add_argument("--environment", required=True)
    args = parser.parse_args()
    try:
        # A real operator must use the pinned root-owned Original policy.
        policy = release.trusted_config(release.POLICY_PATH)
        result = assess(repo=args.candidate_repo, commit=args.commit,
                        site=args.site, company=args.company,
                        environment=args.environment, policy=policy,
                        original_identity_reader=release.native_target_readback)
        print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    except (verify.InvalidProvenance, release.Denied, ValueError,
            OSError, subprocess.TimeoutExpired) as exc:
        print(json.dumps({
            "contract": CONTRACT, "state": "DENIED",
            "reason": type(exc).__name__ + ":" + str(exc)[:180],
            "release_execution_allowed": False,
        }, ensure_ascii=False))
        raise SystemExit(2) from exc


if __name__ == "__main__":
    main()
