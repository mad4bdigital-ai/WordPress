#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile

ROOT = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location(
    "mad4b_repository_governance",
    ROOT / "verify_repository_governance.py",
)
mod = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(mod)

REPOSITORY = "mad4bdigital-ai/WordPress"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


with tempfile.TemporaryDirectory() as td:
    root = Path(td)
    policy = Path(".github/mad4b-repository-governance-policy.json")
    template = Path(".github/mad4b-master-ruleset-template.json")
    ruleset = {
        "id": 23968498,
        "name": "MAD4B master release governance",
        "source_type": "Repository",
        "source": REPOSITORY,
        "updated_at": "2026-09-26T00:00:00Z",
    }
    attestation = {
        "contract": "mad4b.repository-ruleset-attestation.v1",
        "attestation_scope": "owner_issue_comment",
        "attestation_issue_title": "MAD4B Repository Governance Attestations",
        "attestation_author_login": "mad4bdigital-ai",
        "attestation_comment_marker": "MAD4B_RULESET_ATTESTATION",
        "repository": REPOSITORY,
        "ruleset_id": 23968498,
        "ruleset_name": "MAD4B master release governance",
        "ruleset_source_type": "Repository",
        "ruleset_source": REPOSITORY,
        "ruleset_updated_at": "2026-09-26T00:00:00Z",
        "bypass_actor_count": 0,
        "require_extra_approval_for_unattributed_changes": True,
        "policy_sha256": sha256(policy),
        "template_sha256": sha256(template),
        "verified_readback": True,
    }
    result = mod.validate_ruleset_attestation(
        json.dumps(attestation),
        REPOSITORY,
        ruleset,
        json.loads(policy.read_text(encoding="utf-8")),
        policy,
        template,
    )
    assert result["ruleset_id"] == 23968498

    cases = {
        "wrong_scope": ("attestation_scope", "environment"),
        "wrong_issue_title": ("attestation_issue_title", "other"),
        "wrong_author": ("attestation_author_login", "other"),
        "wrong_marker": ("attestation_comment_marker", "OTHER"),
        "stale_updated_at": ("ruleset_updated_at", "2026-09-25T00:00:00Z"),
        "wrong_policy_sha": ("policy_sha256", "0" * 64),
        "wrong_template_sha": ("template_sha256", "1" * 64),
        "nonzero_bypass": ("bypass_actor_count", 1),
        "approval_protection_false": (
            "require_extra_approval_for_unattributed_changes",
            False,
        ),
    }
    for label, (field, value) in cases.items():
        bad = dict(attestation)
        bad[field] = value
        try:
            mod.validate_ruleset_attestation(
                json.dumps(bad),
                REPOSITORY,
                ruleset,
                json.loads(policy.read_text(encoding="utf-8")),
                policy,
                template,
            )
        except SystemExit:
            pass
        else:
            raise AssertionError(f"{label} attestation unexpectedly verified")

    try:
        mod.validate_ruleset_attestation(
            "",
            REPOSITORY,
            ruleset,
            json.loads(policy.read_text(encoding="utf-8")),
            policy,
            template,
        )
    except SystemExit:
        pass
    else:
        raise AssertionError("empty ruleset attestation unexpectedly verified")

print("mad4b.repository-ruleset-attestation.v1: PASS")
print("freshness_binding=ruleset_updated_at")
print("policy_binding=sha256")
print("template_binding=sha256")
print("hidden_protection_binding=bypass+unattributed_approval")
