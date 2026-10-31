#!/usr/bin/env python3
"""Enforce CI-only recovery planning versus independently authorized remote plugin mutation."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]/'includes'
r=(root/'class-mad4b-scp-plugin-update-recovery.php').read_text()
a=(root/'class-mad4b-scp-remote-plugin-update.php').read_text()
assert "const DISCOVER_ABILITY = 'mad4b/plugin-update-recovery-discover'" in r
assert "const PLAN_ABILITY = 'mad4b/plugin-update-recovery-plan'" in r
assert "'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' )" in r
assert "'production_allowed' => false" in r and "'mutation_performed' => false" in r
assert "'authorizing' => false" in r
assert "wp_register_ability(\n\t\t\t\t'mad4b/plugin-remote-update-apply'" in a
assert "'permission_callback' => array( __CLASS__, 'can_apply' )" in a
assert "MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/plugin-remote-update-apply'" in a
assert "MAD4B_SCP_Policy::can_mutate()" in a
assert "mad4b_production_native_batch_required" in a
assert "'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false )" in a
print('PASS: recovery remains nonauthorizing; remote apply needs mutation and authorization')
