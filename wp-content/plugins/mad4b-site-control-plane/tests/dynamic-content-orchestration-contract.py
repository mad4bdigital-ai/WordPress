from pathlib import Path

root = Path(__file__).resolve().parents[1]
adapter = (root / "includes/adapters/class-mad4b-scp-dynamic-content-adapter.php").read_text(encoding="utf-8")
pipeline = (root / "includes/class-mad4b-scp-dynamic-content-pipeline.php").read_text(encoding="utf-8")
admin = (root / "includes/class-mad4b-scp-dynamic-content-pipeline-admin.php").read_text(encoding="utf-8")
transport = (root / "includes/class-mad4b-scp-transport-context.php").read_text(encoding="utf-8")
authority = (root / "includes/class-mad4b-scp-staging-write-authority.php").read_text(encoding="utf-8")
reconciliation = (root / "includes/class-mad4b-scp-staging-write-grant-reconciliation.php").read_text(encoding="utf-8")
impact = (root / "includes/class-mad4b-scp-impact-policy.php").read_text(encoding="utf-8")
reversible = (root / "includes/class-mad4b-scp-reversible-adapter-mutations.php").read_text(encoding="utf-8")
registry = (root / "includes/class-mad4b-scp-adapter-registry.php").read_text(encoding="utf-8")
plugin = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
readme = (root / "README.md").read_text(encoding="utf-8")
build = (root / "MAD4B-RUNTIME-BUILD.txt").read_text(encoding="utf-8")

def require(text, marker, label):
    if marker not in text:
        raise SystemExit(f"{label} missing: {marker}")

def forbid(text, marker, label):
    if marker in text:
        raise SystemExit(f"{label} forbidden: {marker}")

for marker in [
    "MAD4B_SCP_Dynamic_Content_Adapter",
    "mad4b/content-model-discover",
    "mad4b/content-bundle-readback",
    "mad4b/content-orchestration-plan",
    "mad4b/content-apply-bundle",
    "mad4b/content-pipeline-status",
    "mad4b/content-pipeline-settings-update",
    "get_post_types(array(),'objects')",
    "get_object_taxonomies($name,'objects')",
    "get_registered_meta_keys('post',$name)",
    "include_observed_meta",
    "observed_meta_keys",
    "term_offset",
    "has_more_terms",
    "next_term_offset",
    "mad4b_dynamic_readback_taxonomy_invalid",
    "effective_capabilities",
    "mad4b_scp_dynamic_content_observed_meta_key_visible",
    "current_user_can('read_post',$id)",
    "mad4b_scp_dynamic_content_post_type_discoverable",
    "mad4b_scp_dynamic_content_post_type_mutation_eligible",
    "mad4b_scp_dynamic_content_environment_mutation_eligible",
    "mad4b_scp_dynamic_content_taxonomy_discoverable",
    "mad4b_scp_dynamic_content_taxonomy_mutation_eligible",
    "mad4b_scp_dynamic_content_term_reference_eligible",
    "mad4b_scp_dynamic_content_model_post_type",
    "mad4b_scp_dynamic_content_model_taxonomy",
    "get_all_post_type_supports($name)",
    "wp_set_object_terms",
    "operation_key",
    "expected_state_sha256",
    "rollback_available",
    "pipeline_settings_sha256",
    "evidence_sha256",
    "acceptance_targets_sha256",
    "missing_terms",
    "dependency_create_term",
    "plan_sha256",
    "compensation_on_error",
    "failure_with_compensation",
    "metadata_exists",
    "'exists'=>false,'post_id'=>0",
    "'scope'=>$scope",
    "restore_snapshot_state",
    "mad4b_dynamic_compensation_verification_failed",
    "meta_entries",
    "mad4b_dynamic_meta_duplicate_contract",
    "mad4b_dynamic_meta_multi_payload_invalid",
    "normalize_meta_storage_value",
    "mad4b_scp_dynamic_content_title_required",
    "mad4b_scp_dynamic_content_meta_key_readable",
    "current_user_can('edit_post_meta',$id,(string)$k)",
    "current_user_can('read_post_meta',$id,$meta_key)",
    "current_user_can('read_post_meta',$id,$key)",
    "mad4b_scp_dynamic_content_model_meta_key_visible",
    "canonical_value",
    "pipeline_option_state",
    "production_mutation",
    "environment_policy_ineligible",
    "expected_pipeline_settings_sha256",
    "mad4b_dynamic_pipeline_drift",
    "MUTATION_LOCK_PREFIX",
    "MUTATION_LOCK_TTL",
    "acquire_mutation_lock",
    "release_mutation_lock",
    "mad4b_dynamic_mutation_busy",
    "mad4b_dynamic_term_name_ambiguous",
    "mad4b_scp_dynamic_content_require_acceptance",
    "mad4b_dynamic_acceptance_required",
    "mad4b_scp_dynamic_content_taxonomy_readable",
    "mad4b_dynamic_meta_restore_denied",
    "mad4b_dynamic_taxonomy_restore_denied",
    "mad4b_dynamic_restore_publish_denied",
    "pipeline_config",
    "mad4b.execution-state.v1",
    "not_started_error",


]:
    require(adapter, marker, "dynamic content adapter")

for forbidden in [
    "tours-and-activities",
    "product_cat",
    "category =>",
    "tour_type",
    "location =>",
]:
    forbid(adapter.lower(), forbidden.lower(), "site-specific content modeling")

for marker in [
    "class MAD4B_SCP_Dynamic_Content_Pipeline",
    "const OPTION='mad4b_scp_dynamic_content_pipeline_v1'",
    "'phase'=>'validate'",
    "'phase'=>'repair'",
    "'phase'=>'accept'",
    "mad4b_scp_dynamic_content_pipeline_registry",
    "mad4b_scp_dynamic_content_condition_registry",
    "condition_registry",
    "condition_registry_summary",
    "mad4b_scp_dynamic_content_policy_findings",
    "source_fidelity",
    "seo_validation",
    "frontend_validation",
    "mad4b_scp_dynamic_content_source_fidelity_findings",
    "mad4b_scp_dynamic_content_seo_findings",
    "mad4b_scp_dynamic_content_frontend_findings",
    "repair_budget_exceeded",
    "MAX_FINDINGS_PER_STAGE",
    "MAX_STAGE_ELAPSED_MS",
    "max_elapsed_ms",
    "max_findings",
    "mad4b_dynamic_pipeline_stage_time_budget_exceeded",
    "mad4b_dynamic_pipeline_findings_budget_exceeded",
    "expected_revision",
    "mad4b_dynamic_pipeline_stale",
    "mad4b_dynamic_pipeline_condition_unavailable",
    "stage_applies",
    "stop_on_no_progress",
    "no_progress_limit",
    "max_iterations",
    "has_meta",
    "has_taxonomy",
    "finding_code",
    "required_stage_unavailable",
    "pipeline_stage_error",
    "on_error",
    "depends_on",
    "dependency_status",
    "mad4b_dynamic_pipeline_dependency_unsatisfied",
    "validate_stage_graph",
    "mad4b_dynamic_pipeline_stage_duplicate",
    "mad4b_dynamic_pipeline_dependency_self",
    "mad4b_dynamic_pipeline_dependency_missing",
    "mad4b_dynamic_pipeline_dependency_forward",
    "mad4b_dynamic_pipeline_dependency_cycle",
    "skipped_dependency_unsatisfied",
    "LOCK_OPTION",
    "LOCK_TTL",
    "config_digest",
    "pinned_config_from_context",
    "mad4b_dynamic_pipeline_busy",
    "add_option(self::LOCK_OPTION",
    "release_settings_lock",

]:
    require(pipeline, marker, "dynamic pipeline")

# Persisted settings may select only registered IDs and data. They must never
# deserialize or evaluate executable callbacks supplied through the option.
for forbidden in ["eval(", "unserialize(", "create_function(", "call_user_func($stage['callback']"]:
    forbid(pipeline, forbidden, "pipeline settings code execution")

require(pipeline, "call_user_func($registry[$stage['id']]['callback']", "trusted registry callback execution")

for marker in [
    "Dynamic Content Pipeline",
    "pipeline_json",
    "Save and verify pipeline",
    "Registered conditions",
    "Stages and conditions are registry-driven",
]:
    require(admin, marker, "pipeline admin settings")

require(registry, "MAD4B_SCP_Dynamic_Content_Adapter", "adapter registry registration")
require(plugin, "class-mad4b-scp-dynamic-content-pipeline.php", "pipeline bootstrap")
require(plugin, "class-mad4b-scp-dynamic-content-pipeline-admin.php", "pipeline admin bootstrap")

# The request-local exact target binding is now an explicit public predicate.
for marker in [
    "public static function write_dispatch_target_matches",
    "public static function bounded_write_dispatch_active_for",
    "'mad4b-chatgpt' === self::current_server_id()",
]:
    require(transport, marker, "write dispatch binding")

scope = authority.split("public static function remote_scope_delegation_allowed", 1)[1].split("public static function force_remote_write_approval", 1)[0]
for marker in [
    "MAD4B_SCP_Transport_Context::bounded_write_dispatch_active_for( $ability_name )",
    "return self::effective() && self::is_write_ability( $ability_name );",
]:
    require(scope, marker, "nested transport scope delegation")

nested_pos = scope.find("bounded_write_dispatch_active_for")
ticket_pos = scope.rfind("approval_ticket_from_input")
if nested_pos < 0 or ticket_pos < 0 or nested_pos > ticket_pos:
    raise SystemExit("nested transport delegation must occur before ordinary approval-ticket fallback")

for marker in [
    "provider_declared_not_started",
    "provider_declared_not_started",
    "mad4b.execution-state.v1",
    "provider_declared_not_started",
]:
    require(reversible, marker, "reversible not-started execution contract")

for marker in [
    "'mad4b/content-apply-bundle'",
    "'mad4b/content-pipeline-settings-update'",
]:
    require(impact, marker, "impact policy dynamic content classification")


for marker in [
    "MAD4B_SCP_Core_Content_Modeling_Adapter::CREATE_POST_ABILITY",
    "MAD4B_SCP_Core_Content_Modeling_Adapter::CREATE_TERM_ABILITY",
    "MAD4B_SCP_Core_Content_Modeling_Adapter::SET_TERMS_ABILITY",
    "MAD4B_SCP_Dynamic_Content_Adapter::APPLY",
    "MAD4B_SCP_Dynamic_Content_Adapter::PIPELINE_UPDATE",
    "Never derive this allowlist",
]:
    require(reconciliation, marker, "exact reviewed generic content grant reconciliation")

for forbidden in [
    "foreach ( MAD4B_SCP_Adapter_Registry::instance()->all()",
    "mad4b/*",
]:
    forbid(reconciliation, forbidden, "grant reconciliation wildcard/dynamic extension")

for marker in ["0.4.0-rc.83"]:
    require(plugin, marker, "plugin release identity")
    require(readme, marker, "readme release identity")
    require(build, marker, "runtime build identity")

print("mad4b.dynamic-content-orchestration.contract.v1: PASS")
