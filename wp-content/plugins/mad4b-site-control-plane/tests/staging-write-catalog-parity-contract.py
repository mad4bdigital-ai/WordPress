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
assert "MAD4B_SCP_Staging_Write_Owner_Intent::claim( $plan, $input )" in c
assert "add_option( $claim_key" in o
assert "'staging' !== MAD4B_SCP_Site_Profile::current_environment()" in o
assert "'owner' === (string) ( $r['remote_caller_role']" in p
assert "'core' === (string) ( $r['trust_class']" in p
assert "'deny' === (string) ( $r['production_policy']" in p
assert "MAD4B_SCP_Execution_Fence::with_governed_child(" in d
print('STAGING_WRITE_CATALOG_PARITY: PASS (source contract only)')
