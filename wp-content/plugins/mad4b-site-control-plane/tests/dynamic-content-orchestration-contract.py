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
    "bundle_sha256",
    "execution_bindings",
    "expected_pipeline_settings_sha256",
    "expected_bundle_sha256",
    "mad4b_dynamic_bundle_drift",
    "bundle_sha256_for_input",
    "MAX_BUNDLE_INPUT_BYTES",
    "MAX_META_TOTAL_BYTES",
    "MAX_META_VALUE_BYTES",
    "MAX_EVIDENCE_BYTES",
    "MAX_ACCEPTANCE_TARGET_BYTES",
    "MAX_VALIDATION_BYTES",
    "json_size_bytes",
    "mad4b_dynamic_input_too_large",
    "mad4b_dynamic_meta_value_too_large",
    "mad4b_dynamic_meta_total_too_large",
    "mad4b_dynamic_aux_payload_too_large",


    "expected_state_sha256",

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
    "mad4b_dynamic_direct_publication_denied",
    "mad4b_dynamic_live_target_denied",
    "separate_publication_required",
    "live_target_requires_draft_workflow",
    "required_next_ability",

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

planner_start = adapter.find("public function orchestration_plan")
planner_end = adapter.find("public function pipeline_status", planner_start)
planner = adapter[planner_start:planner_end]
pipeline_calc = planner.find("$pipeline_settings_sha256=''")
state_calc = planner.find("$expected_state_sha256=''")
bundle_calc = planner.find("$bundle_sha256=")
bindings_emit = planner.find("'execution_bindings'=>array(")
if min(pipeline_calc, state_calc, bundle_calc, bindings_emit) < 0:
    raise SystemExit("execution-ready planner binding markers missing")
if "'expected_bundle_sha256'=>$bundle_sha256" not in planner:
    raise SystemExit("planner content-bundle step must emit expected_bundle_sha256")
if not (pipeline_calc < bindings_emit and state_calc < bindings_emit and bundle_calc < bindings_emit):
    raise SystemExit("execution bindings must be emitted only after pipeline/state/bundle hashes are computed")



# Pre-merge hardening invariants: exact acceptance-to-publication binding.
abilities = (root / "includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
mutations = (root / "includes/class-mad4b-scp-mutation-manager.php").read_text(encoding="utf-8")

for marker in [
    "dynamic_acceptance_sha256",
    "mad4b_dynamic_live_update_requires_draft_workflow",
    "mad4b_dynamic_publication_must_be_status_only",
    "verify_publication_acceptance",
]:
    if marker not in (abilities + mutations + adapter):
        raise SystemExit(f"publication acceptance hardening marker missing: {marker}")

for marker in [
    "ACCEPTANCE_META",
    "ACCEPTANCE_CONTRACT",
    "persist_acceptance_receipt",
    "invalidate_acceptance_receipt",
    "mad4b_dynamic_acceptance_state_drift",
    "mad4b_dynamic_acceptance_pipeline_drift",
    "mad4b_dynamic_acceptance_expired",
    "publication_acceptance",
]:
    if marker not in adapter:
        raise SystemExit(f"dynamic acceptance receipt marker missing: {marker}")

for marker in [
    "mad4b_dynamic_registered_meta_single_violation",
    "mad4b_dynamic_compensation_state_drift",
    "refresh_mutation_lock",
    "mad4b_dynamic_mutation_lock_lost",
    "description'=>array('type'=>'string','maxLength'=>65535)",
    "parent'=>array('type'=>'integer','minimum'=>0)",
]:
    if marker not in adapter:
        raise SystemExit(f"pre-merge dynamic hardening marker missing: {marker}")

if "get_registered_meta_keys('post',$pt)" not in adapter:
    raise SystemExit("registered meta multiplicity must be checked against the live post subtype")
if "current_live = in_array" not in mutations or "request_live = in_array" not in mutations:
    raise SystemExit("dynamic publication guard must distinguish live current state from requested live transition")
if "status-only transition" not in mutations:
    raise SystemExit("dynamic publication must remain a status-only transition")



for marker in [
    "create_slug",
    "mad4b_scp_dynamic_content_acceptance_scope",
    "mad4b_dynamic_acceptance_scope_invalid",
]:
    if marker not in adapter:
        raise SystemExit(f"dynamic term/acceptance extensibility marker missing: {marker}")

if "in_array( $update['post_status'], array( 'publish', 'private' ), true )" not in mutations:
    raise SystemExit("content-update-post must require publish capability for both publish and private transitions")



for marker in [
    "mad4b_dynamic_acceptance_scope_meta_invalid",
    "mad4b_dynamic_acceptance_scope_taxonomy_invalid",
    "mad4b_dynamic_acceptance_scope_recursive",
    "invalidate_acceptance_after_restore",
    "mad4b_dynamic_acceptance_restore_invalidation_failed",
    "mad4b_dynamic_acceptance_receipt_write_failed",
    "write_returned",
]:
    if marker not in adapter:
        raise SystemExit(f"acceptance scope/receipt invariant missing: {marker}")

pipeline_update_start = adapter.find("public function pipeline_update")
pipeline_update_end = adapter.find("public function discover_model", pipeline_update_start)
pipeline_update = adapter[pipeline_update_start:pipeline_update_end]
for marker in [
    "mad4b_dynamic_pipeline_busy",
    "mad4b_dynamic_pipeline_stale",
    "mad4b_dynamic_pipeline_dependency_cycle",
    "not_started_error",
]:
    if marker not in pipeline_update:
        raise SystemExit(f"pipeline prewrite failure marker missing: {marker}")



if "'heartbeat'=>function() use ($lock)" not in adapter:
    raise SystemExit("dynamic content loop must carry a request-local mutation lock heartbeat")
if "isset($current['heartbeat'])&&is_callable($current['heartbeat'])" not in pipeline:
    raise SystemExit("dynamic pipeline must refresh request-local heartbeat before configured stages")



for marker in [
    "consume_publication_acceptance",
    "mad4b_dynamic_acceptance_receipt_raced",
    "mad4b_dynamic_acceptance_receipt_consume_failed",
]:
    if marker not in adapter:
        raise SystemExit(f"single-use acceptance marker missing: {marker}")

for marker in [
    "consume_publication_acceptance",
    "$dynamic_publication",
    "mad4b_dynamic_acceptance_runtime_unavailable",
]:
    if marker not in mutations:
        raise SystemExit(f"publication receipt consumption marker missing: {marker}")



for marker in [
    "mad4b_dynamic_reserved_meta_denied",
    "get_post_stati",
    "mad4b_dynamic_binding_write_failed",
    "publication_acceptance_status",
    "'publication_acceptance'=>$this->publication_acceptance_status($id)",
    "acceptance_receipt_missing",
]:
    if marker not in adapter:
        raise SystemExit(f"binding/session-recovery hardening marker missing: {marker}")


# Publication acceptance must be consumed only after the status transition and
# normalized exact full-state verification have both succeeded.
guard_pos = mutations.find("dynamic_publication_guard( $post, $input )")
write_pos = mutations.find("$result = wp_update_post( wp_slash( $update ), true )")
transition_pos = mutations.find("verify_publication_transition( $id, $dynamic_acceptance_sha256")
consume_pos = mutations.find("consume_publication_acceptance( $id, $dynamic_acceptance_sha256")
if min(guard_pos, write_pos, transition_pos, consume_pos) < 0:
    raise SystemExit("dynamic publication lifecycle markers missing")
if not (guard_pos < write_pos < transition_pos < consume_pos):
    raise SystemExit("dynamic publication must follow verify -> write -> full-state verify -> consume ordering")
if "rollback_dynamic_publication_transition" not in mutations:
    raise SystemExit("dynamic publication failures must restore the pre-publication post state")
if "mad4b_dynamic_publication_state_drift" not in adapter:
    raise SystemExit("post-publication full-state drift must fail closed")
if "invalidate_acceptance_after_restore" not in adapter:
    raise SystemExit("dynamic bundle rollback must invalidate publication acceptance")


# Existing posts adopted by the dynamic orchestrator must remain governed after
# successful update/publication, while compensation/undo restores prior adoption.
for marker in [
    "MANAGED_META",
    "_mad4b_dynamic_content_managed_v1",
    "'dynamic_managed'=>metadata_exists('post',$id,self::MANAGED_META)",
    "self::MANAGED_META=>'1'",
    "mad4b_dynamic_managed_marker_write_failed",
    "mad4b_dynamic_managed_marker_restore_failed",
]:
    if marker not in adapter:
        raise SystemExit(f"dynamic-managed adoption marker missing: {marker}")

if "_mad4b_dynamic_content_managed_v1" not in mutations or "_mad4b_dynamic_content_binding" not in mutations:
    raise SystemExit("publication guard must recognize durable managed marker and legacy binding")
if "dynamic_managed" not in adapter[adapter.find("private function snapshot"):adapter.find("private function restore_snapshot_state")]:
    raise SystemExit("managed lifecycle must be part of reversible dynamic state")

print("mad4b.dynamic-content-orchestration.contract.v1: PASS")
