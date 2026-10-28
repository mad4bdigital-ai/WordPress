#!/usr/bin/env python3
"""Review-only contract of DFSB's exact-source GitHub attestation producer.

The checks do NOT sign, certify, deploy, publish or approve any artifact.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
path = ROOT / ".github/workflows/dfsb-neutral-attested-candidate.yml"
workflow = path.read_text(encoding="utf-8")
required = (
    "actions/attest-build-provenance@v2",
    "gh attestation verify ",
    "id-token: write",
    "attestations: write",
    "contents: read",
    "pull-requests: read",
    "issues: read",
    "pull-requests: read",
    "issues: read",
    "on:\n  push:\n    branches: [master]",
    "if: github.ref == 'refs/heads/master'",
    "OWNER_ATTEST_SINGLE_OWNER",
    "git merge-base --is-ancestor",
    "git worktree add --detach",
    "verify_candidate.php",
    "EXPECTED_SHA256: '0cdc9ea4e99117b4ca80a127fb3d89c54e6b595587f1f64fe136199d106533fb'",
    "SOURCE_SHA: '8b391bb8af0e2505d9a9c5296d37d9ce420e54e7'",
    "PLUGIN_TREE: 'c8545970917f17e46cb4d4476371840821f950a7'",
    "SIGNED_REVIEW_ARTIFACT_NOT_CERTIFIED",
    '"staging_apply_authorized":false',
    '"production_authorized":false',
    '"safe_boot_override":false',
)
for phrase in required:
    assert phrase in workflow, f"missing release control: {phrase}"
for forbidden in (
    "gh release create",
    "plugin-package-apply",
    "DFSB_FORCE_FULL_BOOT",
    "wp option update",
    "package_certification: certified",
    "source_reviewed_artifact_certified",
):
    assert forbidden not in workflow, f"unsafe release authority: {forbidden}"
assert workflow.count("actions/attest-build-provenance@v2") == 1
assert workflow.count("gh attestation verify ") == 1
assert workflow.count("OWNER_ATTEST_SINGLE_OWNER") == 1
assert workflow.count("gh api ") == 2
assert "pull_request:" not in workflow, "Do not issue attestation from unmerged PR"
assert "persist-credentials: false" in workflow
assert "${ " not in workflow, "GitHub Actions expression has a missing opening brace"
for expression in (
    "${{ github.token }}",
    "${{ runner.temp }}",
    "${{ steps.provenance.outputs.bundle-path }}",
):
    assert expression in workflow, "GitHub Action expression missing: " + expression

print("DFSB_ATTESTED_REVIEW_SOURCE_CONTRACT: PASS")
