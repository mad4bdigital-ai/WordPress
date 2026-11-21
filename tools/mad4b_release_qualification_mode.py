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
