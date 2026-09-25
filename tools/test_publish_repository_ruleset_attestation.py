#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import importlib.util
import json
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "mad4b_ruleset_attestation_publisher",
    HERE / "publish_repository_ruleset_attestation.py",
)
mod = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(mod)

REPOSITORY = "mad4bdigital-ai/WordPress"
OWNER = "mad4bdigital-ai"
TITLE = "MAD4B Repository Governance Attestations"
MARKER = "MAD4B_RULESET_ATTESTATION"

policy = {
    "contract": "mad4b.repository-governance-policy.v1",
    "required_repository_ruleset_name": "MAD4B master release governance",
    "required_rule_types": [
        "deletion",
        "non_fast_forward",
        "pull_request",
        "required_status_checks",
    ],
    "required_status_checks": {
        "contexts": [
            {"context": "Repository release verdict", "integration_id": 15368},
            {"context": "Repository feature boundary", "integration_id": 15368},
        ]
    },
    "ruleset_attestation": {
        "scope": "owner_issue_comment",
        "issue_title": TITLE,
        "authorized_author_login": OWNER,
        "comment_marker": MARKER,
        "contract": "mad4b.repository-ruleset-attestation.v1",
        "require_zero_bypass_actors": True,
        "bind_ruleset_updated_at": True,
        "bind_policy_sha256": True,
        "bind_template_sha256": True,
    },
}

with tempfile.TemporaryDirectory() as td:
    root = Path(td)
    policy_path = root / "policy.json"
    template_path = root / "template.json"
    policy_path.write_text(json.dumps(policy, sort_keys=True), encoding="utf-8")
    template_path.write_text(
        json.dumps({"name": "MAD4B master release governance"}, sort_keys=True),
        encoding="utf-8",
    )

    attestation = {
        "contract": "mad4b.repository-ruleset-attestation.v1",
        "attestation_scope": "owner_issue_comment",
        "attestation_issue_title": TITLE,
        "attestation_author_login": OWNER,
        "attestation_comment_marker": MARKER,
        "repository": REPOSITORY,
        "ruleset_id": 23968498,
        "ruleset_name": "MAD4B master release governance",
        "ruleset_source_type": "Repository",
        "ruleset_source": REPOSITORY,
        "ruleset_updated_at": "2026-09-26T00:00:00Z",
        "bypass_actor_count": 0,
        "require_extra_approval_for_unattributed_changes": True,
        "policy_sha256": hashlib.sha256(policy_path.read_bytes()).hexdigest(),
        "template_sha256": hashlib.sha256(template_path.read_bytes()).hexdigest(),
        "required_status_checks": policy["required_status_checks"]["contexts"],
        "rule_types": policy["required_rule_types"],
        "verified_readback": True,
    }

    state = {"comment_body": "", "mode": "normal"}

    def fake_gh_json(endpoint: str, *, method: str = "GET", fields=None):
        fields = fields or {}
        if endpoint == "user":
            login = "someone-else" if state["mode"] == "wrong_owner" else OWNER
            return {"login": login}
        if endpoint.startswith(f"repos/{REPOSITORY}/issues?"):
            if state["mode"] == "duplicate_ledgers":
                return [
                    {"number": 10, "title": TITLE, "user": {"login": OWNER}},
                    {"number": 11, "title": TITLE, "user": {"login": OWNER}},
                ]
            return []
        if endpoint == f"repos/{REPOSITORY}/issues" and method == "POST":
            return {"number": 77, "title": fields.get("title"), "user": {"login": OWNER}}
        if endpoint == f"repos/{REPOSITORY}/issues/77/comments" and method == "POST":
            state["comment_body"] = str(fields.get("body") or "")
            return {"id": 123, "body": state["comment_body"], "user": {"login": OWNER}}
        if endpoint == f"repos/{REPOSITORY}/issues/comments/123":
            body = state["comment_body"]
            if state["mode"] == "readback_mismatch":
                body += "\nTAMPERED"
            return {"id": 123, "body": body, "user": {"login": OWNER}}
        raise AssertionError(f"unexpected fake GitHub endpoint: {method} {endpoint}")

    mod.gh_json = fake_gh_json

    result = mod.publish(
        REPOSITORY,
        policy,
        dict(attestation),
        policy_path,
        template_path,
    )
    assert result["published"] is True
    assert result["readback_verified"] is True
    assert result["authenticated_owner_login"] == OWNER
    assert result["ledger_issue_number"] == 77
    assert result["comment_id"] == 123
    assert state["comment_body"].startswith(MARKER + "\n")

    def expect_failure(mode: str, payload=None, message: str = ""):
        state["mode"] = mode
        state["comment_body"] = ""
        try:
            mod.publish(
                REPOSITORY,
                policy,
                dict(attestation if payload is None else payload),
                policy_path,
                template_path,
            )
        except ValueError as exc:
            if message and message not in str(exc):
                raise AssertionError(f"{mode}: unexpected error: {exc}") from exc
        else:
            raise AssertionError(f"{mode}: publication unexpectedly succeeded")
        finally:
            state["mode"] = "normal"

    expect_failure(
        "duplicate_ledgers",
        message="multiple owner-authored governance attestation ledgers exist",
    )
    expect_failure(
        "wrong_owner",
        message="requires the authorized owner identity",
    )

    tampered_hash = dict(attestation)
    tampered_hash["policy_sha256"] = "0" * 64
    expect_failure(
        "normal",
        payload=tampered_hash,
        message="publication binding mismatch: policy_sha256",
    )

    missing_checks = dict(attestation)
    missing_checks["required_status_checks"] = missing_checks["required_status_checks"][:1]
    expect_failure(
        "normal",
        payload=missing_checks,
        message="required-status-check binding mismatch",
    )

    expect_failure(
        "readback_mismatch",
        message="comment readback body mismatch",
    )

print("mad4b.repository-ruleset-attestation-publication.v1: PASS")
print("owner_identity=exact")
print("ledger_identity=unique")
print("policy_template_binding=exact")
print("comment_readback=exact")
