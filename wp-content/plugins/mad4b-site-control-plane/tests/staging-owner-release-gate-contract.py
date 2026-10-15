#!/usr/bin/env python3
"""No approval without source-exact CI, owner review and GitHub attestation."""
from pathlib import Path
root = Path(__file__).resolve().parents[4]
workflow = (root/'.github/workflows/mad4b-staging-candidate-publish.yml').read_text()
required = (
    'mad4b-control-plane-package.yml',
    'mad4b-release-verdict.yml',
    'mad4b-admin-performance.yml',
    'mad4b-runtime-integration.yml',
    'gh attestation verify',
    'PUBLISH EXACT STAGING CANDIDATE',
    'required_reviewers',
    'head_sha == $sha',
    'test -n "$run"',
)
for marker in required:
    assert marker in workflow, 'Required exact release gate absent: ' + marker
plugin = root/'wp-content/plugins/mad4b-site-control-plane'
convergence = (plugin/'includes/class-mad4b-scp-staging-write-authority-convergence.php').read_text()
owner = (plugin/'includes/class-mad4b-scp-staging-write-owner-intent.php').read_text()
assert "'owner_intent_sha256' => array(" in convergence
assert "MAD4B_SCP_Staging_Write_Owner_Intent::claim( $plan, $input )" in convergence
assert "self::review()" in owner and "hash_equals(" in owner and "hash_hmac(" in owner
assert "add_option( $claim_key" in owner
print('STAGING_OWNER_RELEASE_GATE: PASS (contract only)')
