#!/usr/bin/env python3
"""Conservative planner contract and runtime-bound readiness checks."""
from pathlib import Path
p = Path(__file__).resolve().parents[2] / 'wp-content/mu-plugins/529-mad4b-unified-execution-plan.php'
s = p.read_text()
for fact in [
    "const ABILITY = 'mad4b/execution-unified-plan'",
    "'readonly' => true",
    "'production_mutation_allowed' => false",
    "'execution_authorized' => false",
    "'grant_mutation_performed' => false",
    "'mutation_performed' => false",
    "MAD4B_SCP_Impact_Policy::classify(",
    "MAD4B_SCP_Staging_Write_Authority::effective()",
    "MAD4B_SCP_Staging_Write_Authority::reconciliation_plan()",
    "MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()",
    "MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status()",
    "build_provenance_not_current",
    "candidate_binding_not_reconciled",
    "exact_grants_not_current",
    "production_mutation_gate_disabled",
    "exact_grants_missing",
    "next_steps",
    "wp_json_encode( $result, JSON_UNESCAPED_SLASHES )",
]:
    assert fact in s, f'Planner contract missing: {fact}'
for dangerous in ["bind_subject(", "grant_create(", "update_option(", "delete_option(", "wp_insert_post(", "wp_update_post(", "wp_delete_post("]:
    assert dangerous not in s, f'Unexpected mutation operation: {dangerous}'
print('PASS: unified plan read-only MCP/CLI contract, source-bound authority blockers and next steps')
