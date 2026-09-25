#!/usr/bin/env python3
from __future__ import annotations

import json
import tempfile
from pathlib import Path
import importlib.util

ROOT = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "owner_attestation",
    ROOT / "verify_repository_owner_attestation.py",
)
mod = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(mod)

HEAD = "a" * 40
OTHER = "b" * 40
OWNER = "mad4bdigital-ai"

def policy_file(tmp: Path) -> Path:
    path = tmp / "policy.json"
    path.write_text(
        json.dumps(
            {
                "single_owner_safety": {
                    "authorized_owner_logins": [OWNER],
                    "attestation_command": "OWNER_ATTEST_SINGLE_OWNER",
                    "owner_attestation_remains_exact_sha_scoped": True,
                    "attestation_stale_on_descendant": True,
                }
            }
        ),
        encoding="utf-8",
    )
    return path

def comment(actor: str, sha: str, cid: int = 1) -> dict:
    return {
        "id": cid,
        "user": {"login": actor},
        "body": f"OWNER_ATTEST_SINGLE_OWNER\nexact_head_sha: {sha}",
        "author_association": "OWNER",
        "created_at": "2026-09-25T00:00:00Z",
        "updated_at": "2026-09-25T00:00:00Z",
    }

def run_case(comments, live_head=HEAD):
    def fake(endpoint: str):
        if "/pulls/" in endpoint:
            return {"head": {"sha": live_head}}
        if "/issues/" in endpoint and "/comments" in endpoint:
            return comments
        raise AssertionError(f"unexpected endpoint: {endpoint}")
    old = mod.gh_json
    mod.gh_json = fake
    try:
        with tempfile.TemporaryDirectory() as td:
            return mod.verify("mad4bdigital-ai/WordPress", 66, HEAD, policy_file(Path(td)))
    finally:
        mod.gh_json = old

result = run_case([comment(OWNER, OTHER, 1), comment(OWNER, HEAD, 2)])
assert result["verified"] is True
assert result["expected_head_sha"] == HEAD
assert result["live_head_sha"] == HEAD
assert result["selected_attestation"]["comment_id"] == 2
assert result["stale_authorized_attestation_count"] == 1
assert result["stale_on_descendant"] is True

for bad_comments in (
    [comment("someone-else", HEAD)],
    [comment(OWNER, OTHER)],
    [],
):
    try:
        run_case(bad_comments)
    except ValueError as exc:
        assert "no authorized OWNER_ATTEST_SINGLE_OWNER" in str(exc)
    else:
        raise AssertionError("invalid attestation unexpectedly verified")

try:
    run_case([comment(OWNER, HEAD)], live_head=OTHER)
except ValueError as exc:
    assert "pull-request head changed" in str(exc)
else:
    raise AssertionError("head drift unexpectedly verified")

print("mad4b.repository-owner-attestation.v1: PASS")
