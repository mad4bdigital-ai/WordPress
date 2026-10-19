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


# Migration #430: neither canonical source nor template may weaken existing
# master protections while adding the GitHub-Actions-owned Readiness check.
spec = importlib.util.spec_from_file_location(
    "mad4b_repository_ruleset_template",
    ROOT / "verify_repository_ruleset_template.py",
)
ruleset_mod = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(ruleset_mod)
policy_data = json.loads(Path(".github/mad4b-repository-governance-policy.json").read_text())
template_data = json.loads(Path(".github/mad4b-master-ruleset-template.json").read_text())
expected_checks = {
    ("Repository release verdict", 15368),
    ("Repository feature boundary", 15368),
    ("Production readiness matrix contract", 15368),
}
assert ruleset_mod.required_checks(policy_data) == expected_checks
assert ruleset_mod.verify(template_data, policy_data)["ready"] is True
assert template_data["bypass_actors"] == []
assert template_data["enforcement"] == "active"
assert template_data["conditions"]["ref_name"]["include"] == ["refs/heads/master"]
rules = {row["type"]: row for row in template_data["rules"]}
assert set(rules) == {"deletion", "non_fast_forward", "pull_request", "required_status_checks"}
assert rules["required_status_checks"]["parameters"]["strict_required_status_checks_policy"] is True
assert rules["pull_request"]["parameters"]["allowed_merge_methods"] == ["merge"]

import copy
for label, mutation in (
    ("drop_readiness", lambda d: d["rules"][3]["parameters"]["required_status_checks"].pop()),
    ("wrong_app", lambda d: d["rules"][3]["parameters"]["required_status_checks"][-1].update(integration_id=999)),
    ("weaken_strict", lambda d: d["rules"][3]["parameters"].update(strict_required_status_checks_policy=False)),
    ("create_bypass", lambda d: d["bypass_actors"].append({"actor_id": 1, "actor_type": "OrganizationAdmin", "bypass_mode": "always"})),
):
    bad = copy.deepcopy(template_data)
    mutation(bad)
    try:
        ruleset_mod.verify(bad, policy_data)
    except ValueError:
        pass
    else:
        raise AssertionError(f"readiness required-check migration accepted {label}")
print("MAD4B_READINESS_REQUIRED_CHECK_MIGRATION: PASS")

# Only this exact additive live migration (or a converged 3-check ruleset)
# may reach the privileged PUT. Noncheck fields must remain identical.
mig_spec = importlib.util.spec_from_file_location(
    "mad4b_readiness_ruleset_migration",
    ROOT / "verify_repository_ruleset_readiness_migration.py",
)
mig = importlib.util.module_from_spec(mig_spec)
assert mig_spec.loader is not None
mig_spec.loader.exec_module(mig)
before = copy.deepcopy(template_data)
before.update(id=23968498, source_type="Repository", source=REPOSITORY)
before["rules"][2]["parameters"]["require_extra_approval_for_unattributed_changes"] = True
before["rules"][3]["parameters"]["required_status_checks"] = copy.deepcopy(mig.LEGACY)
assert mig.preflight(before, template_data, policy_data, REPOSITORY)["transition"] == "append_readiness_only"
converged = copy.deepcopy(before)
converged["rules"][3]["parameters"]["required_status_checks"].append(copy.deepcopy(mig.NEW))
assert mig.preflight(converged, template_data, policy_data, REPOSITORY)["transition"] == "already_converged"
for label, mutator in (
    ("wrong_repository", lambda d: d.update(source="untrusted/WordPress")),
    ("missing_bypass_evidence", lambda d: d.pop("bypass_actors")),
    ("missing_unattributed_approval", lambda d: d["rules"][2]["parameters"].pop("require_extra_approval_for_unattributed_changes")),
    ("injected_bypass", lambda d: d.update(bypass_actors=[{"actor_type":"OrganizationAdmin","actor_id":1,"bypass_mode":"always"}])),
    ("removed_release", lambda d: d["rules"][3]["parameters"]["required_status_checks"].pop(0)),
    ("weakened_strict", lambda d: d["rules"][3]["parameters"].update(strict_required_status_checks_policy=False)),
    ("weakened_review", lambda d: d["rules"][2]["parameters"].update(required_review_thread_resolution=False)),
    ("different_target", lambda d: d["conditions"]["ref_name"].update(include=["refs/heads/dev"])),
):
    mutated = copy.deepcopy(before)
    mutator(mutated)
    try:
        mig.preflight(mutated, template_data, policy_data, REPOSITORY)
    except (ValueError, KeyError):
        pass
    else:
        raise AssertionError(f"minimal-delta preflight permitted {label}")
print("MAD4B_READINESS_MINIMAL_DELTA_NEGATIVE_CONTRACT: PASS")
