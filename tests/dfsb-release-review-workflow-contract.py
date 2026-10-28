#!/usr/bin/env python3
"""Fail-closed guard against drifting versioned DFSB release-review workflow."""
from pathlib import Path
w = (Path(__file__).resolve().parents[1] / '.github/workflows/dfsb-release-review-attestation.yml').read_text()
need = (
    'workflow_dispatch:', 'contents: read', 'issues: read',
    'pull-requests: read', 'attestations: write', 'id-token: write',
    "if: github.ref == 'refs/heads/master'", 'git merge-base --is-ancestor',
    'OWNER_ATTEST_SINGLE_OWNER', 'user.login == "mad4bdigital-ai"',
    'build_release_candidate.py', 'verify_candidate.php',
    'dfsb-release-boot-authority.php', 'cmp "$out/first.zip" "$out/second.zip"',
    'actions/attest-build-provenance@v2', '--bundle "$bundle"',
    '--signer-workflow "$signer"', '--source-ref refs/heads/master',
    'github-verification.json', 'bundle-verification.json', 'release-boundary.json',
    '"provider_certified":false', '"staging_authorized":false',
    '"production_authorized":false', '"safe_boot_override":false',
)
for x in need:
    assert x in w, f"Missing exact-release-review safeguard: {x}"
assert 'pull_request:' not in w
assert w.count('actions/attest-build-provenance@v2') == 1
assert w.count('gh attestation verify ') == 2
for forbidden in ('contents: write', 'gh release create', 'wp plugin install',
                  'DFSB_FORCE_FULL_BOOT', 'plugin-package-apply',
                  'package_certification: certified', 'certified-providers.json'):
    assert forbidden not in w, f"Unsafe publication authority in review workflow: {forbidden}"
assert '${ ' not in w, 'malformed GitHub Actions expression'
print('DFSB_VERSIONED_RELEASE_REVIEW_CONTRACT: PASS')
