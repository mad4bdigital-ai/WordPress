#!/usr/bin/env python3
"""Quality/release-package qualification policy distinct from repo governance.

Optional permits a *manually* approved Staging workflow without requiring an
already certified distribution artifact. It never authorizes an apply, new
owner grant, token, site mutation, Production publication or GitHub merge.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

CONTRACT = "mad4b.repository-release-qualification-mode.v1"
MODES = frozenset(("optional", "required"))
RETAINED = (
    "repository_merge_gate", "owner_attestation",
    "repository_feature_boundary", "site_identity",
    "signed_execution_authority", "production_separate_authorization",
)
class ReleaseQualificationDenied(ValueError):
    pass

# Only release qualification artifacts become advisory in manual Staging.
# Repository governance, signed authority, feature boundaries and functional
# host contracts are never included in this list.
ARTIFACT_QUALIFICATION_CHECKS = frozenset((
    "Build reviewed General Distribution installation kit",
    "Live evidence contracts + exact artifact",
    "Content Hub exact-head package",
))

def partition_checks(required: list[str], mode: str) -> tuple[list[str], list[str]]:
    if mode not in MODES or not isinstance(required, list) or any(
        type(name) is not str or not name for name in required
    ):
        raise ReleaseQualificationDenied("REQUIRED_CHECKS_POLICY_INVALID")
    if mode == "required":
        return list(required), []
    remaining = [name for name in required
                 if name not in ARTIFACT_QUALIFICATION_CHECKS]
    advisory = [name for name in required
                if name in ARTIFACT_QUALIFICATION_CHECKS]
    # Existing reviewed bootstrap and master-push scopes are intentionally
    # different from ordinary PR scopes. Keep their native control-plane
    # contract or repository governance gate; never strip any non-artifact job.
    governance_baselines = {
        "Repository governance policy", "Control-plane contract guard",
    }
    if not set(required).intersection(governance_baselines):
        raise ReleaseQualificationDenied("CRITICAL_REPOSITORY_GOVERNANCE_MISSING")
    if not set(remaining).intersection(governance_baselines):
        raise ReleaseQualificationDenied("CRITICAL_REPOSITORY_GOVERNANCE_REMOVED")
    if any(name not in ARTIFACT_QUALIFICATION_CHECKS
           for name in set(required).difference(remaining)):
        raise ReleaseQualificationDenied("NON_ARTIFACT_CHECK_REMOVAL_DENIED")
    return remaining, advisory


def load_policy(path: Path) -> dict[str, Any]:
    row = json.loads(path.read_text(encoding="utf-8"))
    if (type(row) is not dict
        or set(row) != {"contract", "qualification_mode", "optional_scope",
                        "automatic_publication_when_optional", "retained_requirements"}
        or row["contract"] != CONTRACT
        or type(row["qualification_mode"]) is not str
        or row["qualification_mode"] not in MODES
        or row["optional_scope"] != "manual_staging_only"
        or row["automatic_publication_when_optional"] is not False
        or type(row["retained_requirements"]) is not list
        or tuple(row["retained_requirements"]) != RETAINED):
        raise ReleaseQualificationDenied("RELEASE_QUALIFICATION_POLICY_INVALID")
    return row

def evaluate(verdict: dict[str, Any], policy: dict[str, Any]) -> dict[str, Any]:
    if type(verdict) is not dict or verdict.get("contract") != "mad4b.repository-release-verdict.v1":
        raise ReleaseQualificationDenied("RELEASE_VERDICT_MISSING")
    if verdict.get("repository_merge_gate_ready") is not True:
        raise ReleaseQualificationDenied("REPOSITORY_GOVERNANCE_STILL_REQUIRED")
    mode = policy["qualification_mode"]
    artifact_needed = bool(
        verdict.get("artifact_required") or verdict.get("content_hub_artifact_required"))
    ready = verdict.get("repository_release_ready") is True
    if mode == "required" and artifact_needed and not ready:
        raise ReleaseQualificationDenied("EXACT_RELEASE_ARTIFACT_NOT_CERTIFIED")
    return {
        "contract": "mad4b.release-qualification-evaluation.v1",
        "qualification_mode": mode,
        "manual_staging_source_review_allowed": True,
        "release_artifact_verified": ready,
        "release_artifact_required_for_manual_staging": mode == "required" and artifact_needed,
        "automatic_publication_allowed": mode == "required" and ready,
        "site_execution_authorized": False,
        "production_authorized": False,
        "owner_attestation_bypassed": False,
        "mutation_performed": False,
    }

def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--policy", type=Path, required=True)
    ap.add_argument("--verdict", type=Path, required=True)
    ap.add_argument("--github-output", type=Path)
    args = ap.parse_args()
    try:
        policy = load_policy(args.policy)
        verdict = json.loads(args.verdict.read_text(encoding="utf-8"))
        result = evaluate(verdict, policy)
    except (OSError, ValueError, KeyError, TypeError) as exc:
        print("RELEASE_QUALIFICATION: DENIED: "+str(exc))
        return 1
    if args.github_output:
        with args.github_output.open("a", encoding="utf-8") as f:
            f.write("qualification_mode="+result["qualification_mode"]+"\n")
    print("RELEASE_QUALIFICATION: "+json.dumps(result, sort_keys=True))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
