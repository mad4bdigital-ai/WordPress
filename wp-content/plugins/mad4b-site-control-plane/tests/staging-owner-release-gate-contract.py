#!/usr/bin/env python3
"""No approval without source-exact CI, owner review and GitHub attestation."""
from pathlib import Path
root = Path(__file__).resolve().parents[4]
# The source-admission cut does not publish protected GitHub workflows.
# Always verify the exact reviewed candidate as a non-authorizing fixture.
# Once baseline owners install the real workflow, require byte parity as
# well as the same mandatory release gates: no silent workflow divergence.
candidate = root/'specs/007-content-intelligence-workflow-platform/contracts/reviewed-workflow-candidates/mad4b-staging-candidate-publish.yml'
published = root/'.github/workflows/mad4b-staging-candidate-publish.yml'
assert candidate.is_file(), 'Required baseline-owned workflow candidate fixture absent'
candidate_bytes = candidate.read_bytes()
if published.is_file():
    assert published.read_bytes() == candidate_bytes, 'Published Staging workflow differs from reviewed candidate'
    workflow = published.read_text(encoding='utf-8')
    publish_state = 'BASELINE_PUBLISHED_EXACT'
else:
    workflow = candidate_bytes.decode('utf-8')
    publish_state = 'SOURCE_CANDIDATE_ONLY_NON_AUTHORIZING'
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
# A read-only owner preflight precedes reservation; one-shot claim follows.
assert "self::authorize_reviewed_owner_intent( $plan, $input, false )" in convergence
assert "MAD4B_SCP_Staging_Write_Owner_Intent::validate_claimable( $plan, $proof )" in convergence
assert convergence.index("self::authorize_reviewed_owner_intent( $plan, $input, false )") < convergence.index("::reserve( $plan )")
assert convergence.index("::reserve( $plan )") < convergence.index("MAD4B_SCP_Staging_Write_Owner_Intent::claim( $plan, array(")

assert "MAD4B_SCP_Staging_Write_Owner_Intent::claim( $plan, array(" in convergence
assert "MAD4B_SCP_Staging_Write_Owner_Intent::can_remote_attest()" in convergence
assert "MAD4B_SCP_Staging_Write_Owner_Intent::attest( array(" in convergence
# A matching review may accompany an existing signed receipt. Preserve
# missing/stale proof rejection and never mint a replacement receipt for it.
assert "'mad4b_staging_owner_proof_required'" in convergence
assert "'mad4b_staging_owner_review_stale'" in convergence
assert "if ( '' === $receipt && '' === $review )" in convergence
assert "if ( '' === $receipt )" in convergence
assert "'mad4b_staging_owner_proof_ambiguous'" not in convergence
assert "self::review()" in owner and "hash_equals(" in owner and "hash_hmac(" in owner
assert "add_option( $claim_key" in owner
print('STAGING_OWNER_RELEASE_GATE: PASS (contract only; '+publish_state+')')
