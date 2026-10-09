#!/usr/bin/env python3
"""Source-level invariants for governed create-draft -> verify -> separate publish.

Supplemental to PHP fixture; not a substitute for runtime acceptance.
"""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
ADAPTER=(ROOT/"includes/adapters/class-mad4b-scp-dynamic-content-adapter.php").read_text(encoding="utf-8")
SKILLS=(ROOT/"includes/class-mad4b-scp-skill-abilities.php").read_text(encoding="utf-8")
ABILITIES=(ROOT/"includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
def require(b,msg):
    if not b:
        raise AssertionError(msg)

for text in (
    "'contract'=>'mad4b.dynamic-content-publication-handoff.v1'",
    "'publication_requested'=>$live_state_requested",
    "'draft_replan_required'=>$live_state_requested",
    "'draft_replan_override'=>$live_state_requested?array('post'=>array('post_status'=>'draft')):array()",
    "'requires_exact_draft_plan'=>true",
    "'requires_signed_context_receipt'=>true",
    "'requires_exact_mutation_approval'=>true",
    "'requires_acceptance_receipt_verification'=>true",
    "'mad4b/publication-verification-evaluate'",
    "'origin_and_edge_observations_required'=>true",
    "'required_inputs'=>array('required_dimensions','origin','public_edge'",

    "'requires_separate_exact_mutation_approval'=>true",
    "'independent_readback_required'=>true",
    "'policy_bypass_allowed'=>false",
    "'production_mutation_authorized'=>false",
    "'publication_handoff'=>$publication_handoff",
    "'code'=>'separate_publication_required'",
    "'mad4b_dynamic_direct_publication_denied'",
    "verify_publication_acceptance(",
    "verify_publication_transition(",
):
    require(text in ADAPTER, "Missing independent publication safety/handoff contract: "+text)

for text in (
    "'mad4b/skill-context-preflight'",
    "'skill_body_exposed' => false",
    "'context_assets_exposed' => false",
    "'context_receipt_issued' => false",
    "'read_only' => true",
    "'authorizing' => false",
    "'mad4b_required_brand_context_unavailable'",
    "'mad4b_skill_target_ambiguous'",
):
    require(text in SKILLS, "Missing fail-closed Context preflight: "+text)
require("'dynamic_acceptance_sha256'" in ABILITIES,
        "Separate update-post must retain acceptance receipt SHA binding")
require("'expected_modified_gmt'" in ABILITIES,
        "Separate update-post must retain exact post version guard")
print("PASS governed tour publication handoff: draft-only, brand context, acceptance and separate approval")
