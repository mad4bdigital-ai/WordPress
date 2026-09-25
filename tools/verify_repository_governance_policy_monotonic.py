#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
from pathlib import Path


def fail(message: str) -> None:
    raise SystemExit(message)


def load(path: Path) -> dict:
    data=json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data,dict):
        fail(f"policy is not an object: {path}")
    return data


def rows(policy: dict) -> set[tuple[str,int]]:
    out=set()
    for row in ((policy.get("required_status_checks") or {}).get("contexts") or []):
        if isinstance(row,dict):
            name=str(row.get("context") or "")
            integration=int(row.get("integration_id") or 0)
            if name and integration>0:
                out.add((name,integration))
    return out


def main() -> int:
    ap=argparse.ArgumentParser()
    ap.add_argument("--base-policy",type=Path,required=True)
    ap.add_argument("--candidate-policy",type=Path,required=True)
    args=ap.parse_args()
    base=load(args.base_policy)
    cur=load(args.candidate_policy)

    if cur.get("contract") != base.get("contract") or cur.get("contract") != "mad4b.repository-governance-policy.v1":
        fail("repository governance contract may not drift")
    for key in ("target_branch","target_ref","required_ruleset_enforcement"):
        if cur.get(key) != base.get(key):
            fail(f"repository governance target/enforcement drift: {key}")
    if base.get("require_no_bypass_actors") is True and cur.get("require_no_bypass_actors") is not True:
        fail("repository governance may not weaken no-bypass policy")

    base_types=set(base.get("required_rule_types") or [])
    cur_types=set(cur.get("required_rule_types") or [])
    if not base_types.issubset(cur_types):
        fail("repository governance may not remove required rule types")

    bp=base.get("pull_request") or {}
    cp=cur.get("pull_request") or {}
    if int(cp.get("required_approving_review_count",-1)) < int(bp.get("required_approving_review_count",0)):
        fail("repository governance may not reduce required approvals")
    if bool(bp.get("require_last_push_approval")) and not bool(cp.get("require_last_push_approval")):
        fail("repository governance may not disable last-push approval")
    if bool(bp.get("required_review_thread_resolution")) and not bool(cp.get("required_review_thread_resolution")):
        fail("repository governance may not disable review-thread resolution")
    base_methods=set(bp.get("allowed_merge_methods") or [])
    cur_methods=set(cp.get("allowed_merge_methods") or [])
    if not cur_methods or not cur_methods.issubset(base_methods):
        fail("repository governance may not broaden merge methods")

    br=base.get("required_status_checks") or {}
    cr=cur.get("required_status_checks") or {}
    if bool(br.get("strict_required_status_checks_policy",True)) and not bool(cr.get("strict_required_status_checks_policy",True)):
        fail("repository governance may not disable strict required status checks")
    if not rows(base).issubset(rows(cur)):
        fail("repository governance may not remove or unpin required status checks")

    bs=base.get("single_owner_safety") or {}
    cs=cur.get("single_owner_safety") or {}
    for key in (
        "owner_attestation_remains_exact_sha_scoped",
        "attestation_stale_on_descendant",
        "required_by_repository_release_verdict",
    ):
        if bool(bs.get(key)) and not bool(cs.get(key)):
            fail(f"repository governance may not weaken owner attestation: {key}")
    if bs.get("attestation_command") and cs.get("attestation_command") != bs.get("attestation_command"):
        fail("repository governance owner attestation command may not drift")
    base_owners={str(x).strip().lower() for x in (bs.get("authorized_owner_logins") or []) if str(x).strip()}
    cur_owners={str(x).strip().lower() for x in (cs.get("authorized_owner_logins") or []) if str(x).strip()}
    if base_owners and not base_owners.issubset(cur_owners):
        fail("repository governance may not remove authorized owners through a feature PR")

    print("mad4b.repository-governance-policy.monotonic.v1: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
