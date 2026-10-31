#!/usr/bin/env python3
"""Non-authorizing rollout and operator handoff source invariants."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]/'includes'
a=(root/'class-mad4b-scp-central-company-rollout.php').read_text()
b=(root/'class-mad4b-scp-central-operator-handoff.php').read_text()
for src in (a,b):
 assert "'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' )" in src
 assert "'production_mutation_authorized' => false" in src
 assert "'read_only' => true" in src
 assert "function_exists( 'wp_register_ability' )" in src
assert "(string) ( $receipt['environment'] ?? '' ) !== 'staging'" in a
assert "(string) ( $site['environment'] ?? '' ) !== 'production'" in a
assert "(string) ( $site['enrolled_environment'] ?? '' ) !== 'production'" in a
assert "(string) ( $receipt['rollout_scope'] ?? '' ) !== 'company_production'" in a
assert "( $company['company_environment'] ?? '' ) !== 'production'" in b
assert "( $receipt['rollout_scope'] ?? '' ) !== 'company_production'" in b
print('CENTRAL_ROLLOUT_HANDOFF_POLICY: PASS read-only planning and scoped receipt boundaries')
