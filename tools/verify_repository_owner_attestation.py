#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import subprocess
from pathlib import Path

CONTRACT = "mad4b.repository-owner-attestation.v1"
SHA_RE = re.compile(r"^[0-9a-f]{40}$")
BODY_RE = re.compile(
    r"^OWNER_ATTEST_SINGLE_OWNER\s*\r?\nexact_head_sha:\s*([0-9a-f]{40})\s*$",
    re.IGNORECASE,
)


def gh_json(endpoint: str):
    raw = subprocess.check_output(
        ["gh", "api", "-H", "Accept: application/vnd.github+json", endpoint],
        text=True,
    )
    return json.loads(raw)


def verify(repository: str, pr_number: int, expected_head: str, policy_path: Path) -> dict:
    expected_head = expected_head.strip().lower()
    if not SHA_RE.fullmatch(expected_head):
        raise ValueError("expected head must be a 40-character lowercase commit SHA")
    policy = json.loads(policy_path.read_text(encoding="utf-8"))
    safety = policy.get("single_owner_safety") or {}
    allowed = safety.get("authorized_owner_logins") or []
    allowed = {str(x).strip().lower() for x in allowed if str(x).strip()}
    if not allowed:
        raise ValueError("repository policy has no authorized owner logins")
    command = str(safety.get("attestation_command") or "")
    if command != "OWNER_ATTEST_SINGLE_OWNER":
        raise ValueError("repository policy owner attestation command mismatch")
    if safety.get("owner_attestation_remains_exact_sha_scoped") is not True:
        raise ValueError("repository policy must require exact-SHA owner attestation")

    pr = gh_json(f"repos/{repository}/pulls/{pr_number}")
    live_head = str(((pr.get("head") or {}).get("sha") or "")).lower()
    if live_head != expected_head:
        raise ValueError(f"pull-request head changed: expected={expected_head} live={live_head}")

    matches = []
    stale = []
    page = 1
    while True:
        rows = gh_json(f"repos/{repository}/issues/{pr_number}/comments?per_page=100&page={page}")
        if not isinstance(rows, list):
            raise ValueError("GitHub comments response is not a list")
        for row in rows:
            if not isinstance(row, dict):
                continue
            actor = str(((row.get("user") or {}).get("login") or "")).lower()
            body = str(row.get("body") or "").strip()
            m = BODY_RE.fullmatch(body)
            if actor not in allowed or not m:
                continue
            attested = m.group(1).lower()
            item = {
                "comment_id": int(row.get("id") or 0),
                "actor": actor,
                "author_association": str(row.get("author_association") or ""),
                "created_at": str(row.get("created_at") or ""),
                "updated_at": str(row.get("updated_at") or ""),
                "attested_head_sha": attested,
            }
            if attested == expected_head:
                matches.append(item)
            else:
                stale.append(item)
        if len(rows) < 100:
            break
        page += 1
        if page > 50:
            raise ValueError("owner attestation comment pagination exceeded safety bound")

    if not matches:
        raise ValueError(
            "no authorized OWNER_ATTEST_SINGLE_OWNER comment matches the exact current PR head"
        )
    matches.sort(key=lambda row: (row["comment_id"], row["updated_at"]))
    selected = matches[-1]
    return {
        "contract": CONTRACT,
        "verified": True,
        "repository": repository,
        "pull_request": pr_number,
        "expected_head_sha": expected_head,
        "live_head_sha": live_head,
        "authorized_owner_logins": sorted(allowed),
        "selected_attestation": selected,
        "matching_attestation_count": len(matches),
        "stale_authorized_attestation_count": len(stale),
        "stale_on_descendant": True,
        "mutation_performed": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", required=True)
    parser.add_argument("--pr-number", required=True, type=int)
    parser.add_argument("--expected-head", required=True)
    parser.add_argument("--policy", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    try:
        result = verify(args.repository, args.pr_number, args.expected_head, args.policy)
    except (ValueError, OSError, json.JSONDecodeError, subprocess.CalledProcessError) as exc:
        args.output.write_text(
            json.dumps(
                {
                    "contract": CONTRACT,
                    "verified": False,
                    "repository": args.repository,
                    "pull_request": args.pr_number,
                    "expected_head_sha": args.expected_head.lower(),
                    "failure_reason": str(exc),
                    "mutation_performed": False,
                },
                indent=2,
                sort_keys=True,
            )
            + "\n",
            encoding="utf-8",
        )
        print(f"OWNER_ATTESTATION: FAIL: {exc}")
        return 1
    args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print("OWNER_ATTESTATION: PASS")
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
