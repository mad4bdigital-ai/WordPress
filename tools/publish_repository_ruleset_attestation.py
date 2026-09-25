#!/usr/bin/env python3
"""Publish a verified ruleset attestation to the owner-authored governance ledger."""

from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

CONTRACT = "mad4b.repository-ruleset-attestation-publication.v1"
ATTESTATION_CONTRACT = "mad4b.repository-ruleset-attestation.v1"


def gh_json(endpoint: str, *, method: str = "GET", fields: dict[str, str] | None = None):
    cmd = [
        "gh",
        "api",
        "-H",
        "Accept: application/vnd.github+json",
        "-H",
        "X-GitHub-Api-Version: 2026-03-10",
    ]
    if method != "GET":
        cmd += ["--method", method]
    if fields:
        for key, value in fields.items():
            cmd += ["--raw-field", f"{key}={value}"]
    cmd.append(endpoint)
    raw = subprocess.check_output(cmd, text=True, stderr=subprocess.PIPE)
    return json.loads(raw) if raw.strip() else {}


def load(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"expected JSON object: {path}")
    return data


def list_owner_ledgers(repository: str, title: str, owner_login: str) -> list[dict]:
    found: list[dict] = []
    page = 1
    while True:
        rows = gh_json(f"repos/{repository}/issues?state=all&per_page=100&page={page}")
        if not isinstance(rows, list):
            raise ValueError("GitHub issues response is not a list")
        for row in rows:
            if not isinstance(row, dict) or "pull_request" in row:
                continue
            if str(row.get("title") or "") != title:
                continue
            if str(((row.get("user") or {}).get("login") or "")).lower() != owner_login:
                continue
            found.append(row)
        if len(rows) < 100:
            break
        page += 1
        if page > 50:
            raise ValueError("governance ledger issue pagination exceeded safety bound")
    return found


def publish(repository: str, policy: dict, attestation: dict) -> dict:
    config = policy.get("ruleset_attestation") or {}
    expected = {
        "scope": "owner_issue_comment",
        "issue_title": "MAD4B Repository Governance Attestations",
        "authorized_author_login": "mad4bdigital-ai",
        "comment_marker": "MAD4B_RULESET_ATTESTATION",
        "contract": ATTESTATION_CONTRACT,
        "require_zero_bypass_actors": True,
        "bind_ruleset_updated_at": True,
        "bind_policy_sha256": True,
        "bind_template_sha256": True,
    }
    if config != expected:
        raise ValueError("ruleset attestation publication policy drift")

    owner_login = expected["authorized_author_login"]
    identity = gh_json("user")
    authenticated_login = str(identity.get("login") or "").lower()
    if authenticated_login != owner_login:
        raise ValueError(
            "ruleset attestation publication requires the authorized owner identity: "
            f"expected={owner_login} actual={authenticated_login or '<empty>'}"
        )

    if attestation.get("contract") != ATTESTATION_CONTRACT:
        raise ValueError("ruleset attestation contract mismatch")
    for field, value in {
        "attestation_scope": "owner_issue_comment",
        "attestation_issue_title": expected["issue_title"],
        "attestation_author_login": owner_login,
        "attestation_comment_marker": expected["comment_marker"],
        "repository": repository,
        "bypass_actor_count": 0,
        "require_extra_approval_for_unattributed_changes": True,
        "verified_readback": True,
    }.items():
        if attestation.get(field) != value:
            raise ValueError(
                f"ruleset attestation publication binding mismatch: {field}"
            )

    ledgers = list_owner_ledgers(repository, expected["issue_title"], owner_login)
    if len(ledgers) > 1:
        raise ValueError(
            "multiple owner-authored governance attestation ledgers exist; "
            "manual reconciliation is required"
        )
    if not ledgers:
        created = gh_json(
            f"repos/{repository}/issues",
            method="POST",
            fields={
                "title": expected["issue_title"],
                "body": (
                    "Repository-owned append-only evidence ledger for MAD4B governance "
                    "ruleset attestations. Entries are valid only when authored by the "
                    "authorized owner and when their exact ruleset/policy/template bindings "
                    "match the current governed state."
                ),
            },
        )
        if str(((created.get("user") or {}).get("login") or "")).lower() != owner_login:
            raise ValueError("created governance ledger is not owner-authored")
        ledgers = [created]

    ledger = ledgers[0]
    issue_number = int(ledger.get("number") or 0)
    if issue_number < 1:
        raise ValueError("governance ledger issue number is invalid")

    compact = json.dumps(attestation, sort_keys=True, separators=(",", ":"))
    body = expected["comment_marker"] + "\n" + compact
    comment = gh_json(
        f"repos/{repository}/issues/{issue_number}/comments",
        method="POST",
        fields={"body": body},
    )
    comment_id = int(comment.get("id") or 0)
    comment_author = str(((comment.get("user") or {}).get("login") or "")).lower()
    if comment_id < 1 or comment_author != owner_login:
        raise ValueError("published ruleset attestation comment identity mismatch")
    if str(comment.get("body") or "").strip() != body:
        raise ValueError("published ruleset attestation comment body mismatch")

    readback = gh_json(f"repos/{repository}/issues/comments/{comment_id}")
    if int(readback.get("id") or 0) != comment_id:
        raise ValueError("ruleset attestation comment readback id mismatch")
    if str(((readback.get("user") or {}).get("login") or "")).lower() != owner_login:
        raise ValueError("ruleset attestation comment readback author mismatch")
    if str(readback.get("body") or "").strip() != body:
        raise ValueError("ruleset attestation comment readback body mismatch")

    return {
        "contract": CONTRACT,
        "published": True,
        "repository": repository,
        "authenticated_owner_login": authenticated_login,
        "ledger_issue_number": issue_number,
        "ledger_issue_author_login": owner_login,
        "comment_id": comment_id,
        "comment_author_login": owner_login,
        "comment_marker": expected["comment_marker"],
        "ruleset_id": int(attestation.get("ruleset_id") or 0),
        "ruleset_updated_at": str(attestation.get("ruleset_updated_at") or ""),
        "mutation_performed": True,
        "readback_verified": True,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", required=True)
    parser.add_argument("--policy", required=True, type=Path)
    parser.add_argument("--attestation", required=True, type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    try:
        result = publish(
            args.repository,
            load(args.policy),
            load(args.attestation),
        )
    except (
        ValueError,
        OSError,
        json.JSONDecodeError,
        subprocess.CalledProcessError,
    ) as exc:
        print(f"RULESET_ATTESTATION_PUBLICATION: FAIL: {exc}")
        return 1

    encoded = json.dumps(result, indent=2, sort_keys=True) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(encoded, encoding="utf-8")
    print("RULESET_ATTESTATION_PUBLICATION: PASS")
    print(encoded, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
