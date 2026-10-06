#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
graph = (ROOT / "includes/class-mad4b-scp-runtime-evidence-graph.php").read_text(encoding="utf-8")
classifier = (ROOT / "includes/class-mad4b-scp-runtime-policy-classifier.php").read_text(encoding="utf-8")
competitive = (ROOT / "includes/class-mad4b-scp-competitive-evidence.php").read_text(encoding="utf-8")
plugin = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

def normalized(text):
    return "".join(text.split())

def req(text, *needles):
    haystack = normalized(text)
    for needle in needles:
        assert normalized(needle) in haystack, f"missing marker: {needle}"

def deny(text, *needles):
    haystack = normalized(text)
    for needle in needles:
        assert normalized(needle) not in haystack, f"forbidden marker: {needle}"

req(graph,
    "mad4b.runtime-evidence-graph.v1",
    "mad4b.runtime-evidence-generation.v1",
    "mad4b.runtime-evidence-graph-diff.v1",
    "'callbacks_executed'=>false",
    "'unknown_plugin_code_executed'=>false",
    "'unknown_endpoints_invoked'=>false",
    "'secret_values_read'=>false",
    "'writes_performed'=>false",
    "did_action('rest_api_init')<=0",
    "'authority_inferred_from_method'=>false",
    "'schema_read'=>false",
    "'row_values_read'=>false",
    "'candidate_package'=>array('state'=>'descriptive_only'",
    "'isolation_policy'=>'removed_or_changed_only_fail_closed'",
    "'hooks'=>self::hooks()",
    "'mcp_descriptors'=>self::mcp_descriptors($ability_nodes)",
    "'edges'=>$edges",
    "'affected_workflows'=>array_values(array_unique($affected_workflows))",
    "'semantic_dimensions'=>array('provider','component','capability','operation','schema','precondition','effect','reversal','evidence')",
)
deny(graph, "call_user_func(", "call_user_func_array(", "wp_remote_get(", "wp_remote_post(", "$wpdb->query(", "$wpdb->get_results(", "grant_ability(")

req(classifier,
    "mad4b.runtime-policy-classifier.v1",
    "mad4b.runtime-policy-proposal.v1",
    "'confidence_is_safety_proof'=>false",
    "'operation_names_create_authority'=>false",
    "'schema_infers_privilege'=>false",
    "'annotations_create_authority'=>false",
    "'grants_changed'=>false",
    "'mounts_changed'=>false",
    "operation_name_cannot_prove_read_safety",
    "http_get_cannot_prove_read_safety",
    "risk_downgrade_rejected",
    "actual_conformance_missing",
    "secret_schema_blocks_zero_effect_auto_classification",
    "'authority_delta'=>array('grants'=>0,'mounts'=>0,'scopes'=>0,'certifications'=>0)",
    "mad4b.runtime-policy-review-overlay.v1",
    "mad4b/runtime-policy-review-record",
    "mad4b_runtime_policy_review_graph_stale",
    "mad4b_runtime_policy_review_proposal_stale",
    "mad4b_runtime_policy_review_stale",
    "'creates_certification'=>false",
    "'authority_effect'=>'none'",
)
deny(classifier, "grant_ability(", "register_defaults()", "wp_remote_", "$wpdb->", "MAD4B_SCP_Servers::register")

req(competitive,
    "mad4b.competitive-evidence-summary.v1",
    "Static or marketed evidence never proves runtime parity or creates access.",
    "snapshot_generation_sha256", "task_ids", "evidence_ids",
    "summary_sha256",
    "mad4b_competitive_evidence_digest_mismatch",
    "mad4b_competitive_evidence_static_runtime_claim",
    "evidence_sources",
    "mad4b_foundation_paths",
    "acceptance_requirements",
    "runtime_parity_claimed",
    "authority_created",
)
deny(competitive, "wp_remote_", "grant_ability(", "$wpdb->")
req(plugin,
    "class-mad4b-scp-runtime-evidence-graph.php",
    "class-mad4b-scp-runtime-policy-classifier.php",
    "class-mad4b-scp-competitive-evidence.php",
)
print("mad4b.g1-runtime-evidence-contract.v1: PASS")
