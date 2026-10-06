#!/usr/bin/env python3
from pathlib import Path
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]
graph = (ROOT / "includes/class-mad4b-scp-runtime-evidence-graph.php").read_text(encoding="utf-8")
classifier = (ROOT / "includes/class-mad4b-scp-runtime-policy-classifier.php").read_text(encoding="utf-8")
competitive = (ROOT / "includes/class-mad4b-scp-competitive-evidence.php").read_text(encoding="utf-8")
summary_php = (ROOT / "config/competitive-evidence-summary.php").read_text(encoding="utf-8")
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

req(
    graph,
    "mad4b.runtime-evidence-graph.v2",
    "mad4b.runtime-evidence-generation.v2",
    "mad4b.runtime-evidence-graph-diff.v2",
    "mad4b_runtime_graph_generation_mismatch",
    "mad4b_runtime_graph_cross_site_rejected",
    "mad4b_runtime_graph_before_oversized",
    "collector_contracts",
    "collector_observed_count",
    "'providers'=>array('method'=>'providers')",
    "'schemas'=>array('method'=>'schemas','source'=>'abilities')",
    "collection_status",
    "edge_status",
    "last_edge_observed_count",
    "source_trustworthy_for_absence",
    "sources_complete",
    "mad4b_runtime_graph_collection_source_status_mismatch",
    "mad4b_runtime_graph_edge_source_status_mismatch",
    "impact_trustworthy",
    "observed_count",
    "emitted_count",
    "trustworthy_for_absence",
    "uncertain_added",
    "uncertain_removed",
    "comparison_trustworthy_for_absence",
    "observation_phase",
    "rest_api_initialized",
    "within_soft_budget",
    "cache_hit",
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
    "'relation'=>'contains_component'",
    "'relation'=>'bound_to_provider'",
    "'relation'=>'declares_schema'",
    "transitive_impact",
    "MAD4B_SCP_Dependency_Impact_Graph::inspect",
    "'semantic_dimensions'=>array('provider','component','capability','operation','schema','precondition','effect','reversal','evidence')",
    "required_capability",
    "data_classification",
    "output_schema_sha256",
    "output_schema_secret_bearing",
    "resource_schema_version",
    "resource_constraints_sha256",
    "resource_values_exposed",
    "privilege_inferred_from_resources",
    "'arguments_read'=>false",
)
deny(graph, "call_user_func(", "call_user_func_array(", "wp_remote_get(", "wp_remote_post(", "$wpdb->query(", "$wpdb->get_results(", "grant_ability(")

req(
    classifier,
    "mad4b.runtime-policy-classifier.v2",
    "mad4b.runtime-policy-proposal.v2",
    "mad4b.runtime-policy-conformance-receipt.v1",
    "mad4b.runtime-policy-review-overlay.v2",
    "conformance_issuer_untrusted",
    "mad4b_scp_runtime_policy_conformance_verifiers",
    "trusted_conformance_verifiers",
    "callback_owned_by_control_plane",
    "conformance_verifier_untrusted",
    "conformance_signature_verification_failed",
    "verifier_provenance",
    "conformance_binding_mismatch",
    "conformance_provider_binding_mismatch",
    "conformance_receipt_digest_mismatch",
    "secret_output_schema_blocks_auto_classification",
    "privileged_capability_blocks_auto_classification",
    "sensitive_data_classification_blocks_auto_classification",
    "trusted_conformance_receipt_missing",
    "output_schema_digest_missing",
    "'confidence_is_safety_proof'=>false",
    "'operation_names_create_authority'=>false",
    "'schema_infers_privilege'=>false",
    "'annotations_create_authority'=>false",
    "'grants_changed'=>false",
    "'mounts_changed'=>false",
    "operation_name_cannot_prove_read_safety",
    "http_get_cannot_prove_read_safety",
    "risk_downgrade_rejected",
    "schema_digest_missing",
    "execution_boundary_unverified",
    "'authority_delta'=>array('grants'=>0,'mounts'=>0,'scopes'=>0,'certifications'=>0)",
    "mad4b/runtime-policy-review-record",
    "mad4b_runtime_policy_review_graph_stale",
    "mad4b_runtime_policy_review_proposal_stale",
    "mad4b_runtime_policy_review_stale",
    "mad4b_runtime_policy_review_store_tampered",
    "mad4b_runtime_policy_review_audit_not_ready",
    "mad4b_runtime_policy_review_lock_lost",
    "previous_review_sha256",
    "MAX_REVIEW_HISTORY",
    "review_record_digest",
    "'creates_certification'=>false",
    "'authority_effect'=>'none'",
)
deny(classifier, "grant_ability(", "register_defaults()", "wp_remote_", "$wpdb->", "MAD4B_SCP_Servers::register")

req(
    competitive,
    "mad4b.competitive-evidence-summary.v2",
    "config/competitive-evidence-summary.php",
    "MAX_PACKAGES",
    "MAX_CAPABILITIES",
    "MAX_SOURCES_PER_CAPABILITY",
    "direct_web_resource",
    "mad4b_ce_status",
    "mad4b_ce_class",
    "mad4b_ce_q",
    "Static or marketed evidence never proves runtime parity or creates access.",
    "snapshot_generation_sha256",
    "task_ids",
    "evidence_ids",
    "summary_sha256",
    "mad4b_competitive_evidence_digest_mismatch",
    "mad4b_competitive_evidence_static_runtime_claim",
    "evidence_sources",
    "mad4b_foundation_paths",
    "acceptance_requirements",
    "runtime_parity_claimed",
    "authority_created",
)
deny(competitive, "competitive-evidence-summary.json", "wp_remote_", "grant_ability(", "$wpdb->")
req(summary_php, "if ( ! defined( 'ABSPATH' ) ) {", "http_response_code( 404 )", "MAD4B_JSON", "json_decode")
deny(summary_php, "competitive-evidence-summary.json")

php = shutil.which("php")
assert php, "php executable is required for direct-resource denial verification"
direct = subprocess.run([php, str(ROOT / "config/competitive-evidence-summary.php")], capture_output=True, text=True, check=False)
assert direct.returncode == 0, f"direct evidence resource execution returned {direct.returncode}"
assert direct.stdout == "" and direct.stderr == "", "direct evidence resource execution disclosed output"

req(
    plugin,
    "class-mad4b-scp-runtime-evidence-graph.php",
    "class-mad4b-scp-runtime-policy-classifier.php",
    "class-mad4b-scp-competitive-evidence.php",
)

print("mad4b.g1-runtime-evidence-contract.v2: PASS")
