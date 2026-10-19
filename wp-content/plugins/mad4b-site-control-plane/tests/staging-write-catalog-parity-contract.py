#!/usr/bin/env python3
"""Static routing contract: owner decision cannot leak into general operations."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
p=(root/'includes/class-mad4b-scp-remote-operation-parity.php').read_text()
d=(root/'includes/class-mad4b-scp-enrollment-dispatch.php').read_text()
c=(root/'includes/class-mad4b-scp-staging-write-authority-convergence.php').read_text()
o=(root/'includes/class-mad4b-scp-staging-write-owner-intent.php').read_text()
assert 'public static function reviewed_owner_staging_convergence' in p
assert 'MAD4B_SCP_Remote_Operation_Parity::reviewed_owner_staging_convergence' in d
assert "foreach ( self::catalog() as $operation_id => $row )" in p
assert "self::reviewed_owner_staging_convergence( $operation_id, $row )" in p
assert "'owner_intent_sha256' => array(" in c
assert "self::authorize_reviewed_owner_intent( $plan, $input, false )" in c
assert "MAD4B_SCP_Staging_Write_Owner_Intent::validate_claimable( $plan, $proof )" in c
assert "MAD4B_SCP_Staging_Write_Owner_Intent::claim( $plan, array(" in c
assert c.index("::reserve( $plan )") < c.index("MAD4B_SCP_Staging_Write_Owner_Intent::claim( $plan, array(")
assert "MAD4B_SCP_Staging_Write_Owner_Intent::can_remote_attest()" in c
assert "MAD4B_SCP_Staging_Write_Owner_Intent::attest( array(" in c
assert "'owner_review_digest' => array(" in c
# Matching review + signed receipt now corroborate one exact plan. Missing
# proofs and stale reviews must still fail closed; an existing receipt must
# bypass new attestation and retain the validate/claim path asserted above.
assert "'mad4b_staging_owner_proof_required'" in c
assert "'mad4b_staging_owner_review_stale'" in c
assert "if ( '' === $receipt && '' === $review )" in c
assert "if ( '' === $receipt )" in c
assert "'mad4b_staging_owner_proof_ambiguous'" not in c
assert "add_option( $claim_key" in o
assert "'staging' !== MAD4B_SCP_Site_Profile::current_environment()" in o
assert "'owner' === (string) ( $r['remote_caller_role']" in p
assert "'core' === (string) ( $r['trust_class']" in p
assert "'deny' === (string) ( $r['production_policy']" in p
assert "MAD4B_SCP_Execution_Fence::with_governed_child(" in d
print('STAGING_WRITE_CATALOG_PARITY: PASS (source contract only)')

# Only this exact short-lived owner review Ability may bootstrap before Write grants.
servers=(root/'includes/class-mad4b-scp-servers.php').read_text()
assert "MAD4B_SCP_Staging_Write_Owner_Intent::ATTEST_ABILITY" in servers
assert "public static function can_remote_attest(" in o
assert "'chatgpt_direct_step_up'=>true" in o
assert "'production_mutation_allowed'=>false" in o
assert "'owner_intent_only'=>true" in o

assert "REVIEW_ABILITY = 'mad4b/staging-write-owner-review'" in o
assert "'non_authorizing'=>true" in o
assert "'mad4b/staging-write-owner-review'" in servers
