#!/usr/bin/env python3
"""Offline invariant checks for bounded Brand Core recovery and external-source rights."""
from pathlib import Path

P = Path(__file__).resolve().parents[1]
def load(p):
    return (P / p).read_text(encoding="utf-8")
machine = load("includes/class-mad4b-scp-brand-context-reconstruction.php")
adapter = load("includes/adapters/class-mad4b-scp-context-adapter.php")
skill = load("includes/class-mad4b-scp-skill-abilities.php")
authority = load("includes/class-mad4b-scp-context-authority.php")
tests = load("tests/brand-context-reconstruction-state-machine-runtime.php")
def check(cond, msg):
    if not cond: raise AssertionError(msg)

for state in (
    'READY', 'OWNER_AUTHORITY_REQUIRED', 'HUMAN_ARBITRATION',
    'SOURCE_RECOVERY', 'RESCAN_REQUIRED', 'NORMALIZATION_REQUIRED',
    'MATERIALIZATION_RECONCILE', 'EVIDENCE_COLLECTION',
    'WAIT_DEPENDENCY', 'DESTINATION_RECOVERY', 'HUMAN_DRAFT_PREPARATION',
    'DRAFT_PREPARATION', 'HUMAN_REVIEW', 'RIGHTS_REVIEW', 'CIRCUIT_OPEN',
):
    check(state in machine, "Missing machine state: "+state)
for phrase in (
    'synthetic_authority_allowed', 'automated_rights_approval_allowed',
    'review_separation_of_duties', 'assistant_fallback',
    'assistant_certification_verified', 'scenario_is_hypothetical',
    'retry_limit_per_stage', 'recover_before_recreate',
    'writes_require_separate_approval',
    'context/materialize-brand-draft', 'context/reconcile-brand-materialization',
    'context/brand-draft-create', 'human_reset_with_new_evidence',
):
    check(phrase in machine, "Missing reconstruction guard: "+phrase)
check(adapter.count("'context/brand-reconstruction-plan'") == 2,
      "Recovery planner absent from capability list or registration")
check("brand_reconstruction_plan(" in adapter, "Recovery handler missing")
check("is_wp_error( $convergence )" in machine,
      "Provider/gap preflight must stop on error")
check(machine.count("'mutation_performed' => false") == 1,
      "Planning cannot pretend to mutate")
for phrase in (
    "'mad4b/external-source-rights-preflight'",
    "'commercial_reuse_authorized' => false",
    "'supplier_rights_verified' => false",
    "'licensed_media_verified' => false",
    "'public_source_implies_license' => false",
    "signed_distribution_or_resale_authorization",
):
    check(phrase in skill, "Missing rights fence: "+phrase)
check("mad4b_context_ai_brand_strategy_source_human_review_required" in authority,
      "AI may not approve strategy classified from an operational doc")
check("! $named_authority && $operational_title" in authority,
      "Technical operations title must not imply any Brand Core authority")
check("mad4b_context_ai_brand_core_operational_human_review_required" in authority,
      "AI cannot self-approve technical files as Voice or Editorial Guidelines")
check("Scenario mismatch" in tests and "Retry breaker failed" in tests,
      "Missing scenario and retry negative regression coverage")
for function in ('private static function add(', 'public static function skill_get(',
                 'public static function skills_list(', 'public static function skill_context_preflight('):
    check(skill.count(function) == 1, "Duplicate PHP method/fatal parse regression: "+function)
check(skill.count("final class MAD4B_SCP_Skill_Abilities") == 1,
      "Duplicate PHP class")
print("PASS Brand Core reconstruction/assistants/rights: source ownership, 15 states, refusal and retries")
