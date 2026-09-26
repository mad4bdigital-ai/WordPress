#!/usr/bin/env python3
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[4]
POLICY = json.loads((ROOT / ".github/mad4b-repository-governance-policy.json").read_text(encoding="utf-8"))
VERIFIER = (ROOT / "tools/verify_repository_owner_attestation.py").read_text(encoding="utf-8")
VERDICT = (ROOT / ".github/workflows/mad4b-release-verdict.yml").read_text(encoding="utf-8")
RERUN = (ROOT / ".github/workflows/mad4b-owner-attestation-rerun.yml").read_text(encoding="utf-8")

safety = POLICY.get("single_owner_safety") or {}
if safety.get("attestation_command") != "OWNER_ATTEST_SINGLE_OWNER":
    raise SystemExit("owner attestation command policy drift")
if safety.get("authorized_owner_logins") != ["mad4bdigital-ai"]:
    raise SystemExit("authorized single-owner login policy drift")
if safety.get("owner_attestation_remains_exact_sha_scoped") is not True:
    raise SystemExit("owner attestation is not exact-SHA scoped")
if safety.get("attestation_stale_on_descendant") is not True:
    raise SystemExit("owner attestation does not stale on descendant")

for marker in (
    "OWNER_ATTEST_SINGLE_OWNER",
    "exact_head_sha:",
    "live_head != expected_head",
    "authorized_owner_logins",
    "stale_on_descendant",
    "mutation_performed",
):
    if marker not in VERIFIER:
        raise SystemExit(f"owner attestation verifier marker missing: {marker}")

for marker in (
    "Verify exact-head owner attestation",
    "verify_repository_owner_attestation.py",
    "owner_attestation_verified",
    "and owner_attestation_ready",
):
    if marker not in VERDICT:
        raise SystemExit(f"release verdict owner gate missing: {marker}")

for marker in (
    "issue_comment:",
    "actions: write",
    "OWNER_ATTEST_SINGLE_OWNER",
    "verify_repository_owner_attestation.py",
    "actions/runs/$run_id/rerun",
):
    if marker not in RERUN:
        raise SystemExit(f"owner attestation rerun contract missing: {marker}")

print("mad4b.repository-owner-attestation.v1: PASS")
