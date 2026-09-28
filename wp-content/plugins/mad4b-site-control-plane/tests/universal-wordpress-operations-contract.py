#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"

main = (PLUGIN / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
operation = json.loads((PLUGIN / "config/operation-registry.json").read_text(encoding="utf-8"))
transport = json.loads((PLUGIN / "config/provider-transport-registry.json").read_text(encoding="utf-8"))
registry = (PLUGIN / "includes/class-mad4b-scp-operation-registry.php").read_text(encoding="utf-8")
pipeline = (PLUGIN / "includes/class-mad4b-scp-operation-pipeline.php").read_text(encoding="utf-8")
isolation = (PLUGIN / "includes/class-mad4b-scp-mcp-provider-isolation.php").read_text(encoding="utf-8")
lifecycle = (PLUGIN / "includes/class-mad4b-scp-plugin-lifecycle.php").read_text(encoding="utf-8")
abilities = (PLUGIN / "includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
transaction = (PLUGIN / "includes/class-mad4b-scp-plugin-transaction.php").read_text(encoding="utf-8")
resume = (PLUGIN / "includes/class-mad4b-scp-operation-resume.php").read_text(encoding="utf-8")
activation_state = (PLUGIN / "includes/class-mad4b-scp-plugin-activation-state.php").read_text(encoding="utf-8")
plugin_package = (PLUGIN / "includes/class-mad4b-scp-plugin-package.php").read_text(encoding="utf-8")
remote_update = (PLUGIN / "includes/class-mad4b-scp-remote-plugin-update.php").read_text(encoding="utf-8")
dependency_impact = (PLUGIN / "includes/class-mad4b-scp-dependency-impact-graph.php").read_text(encoding="utf-8")
plugin_discovery = (PLUGIN / "includes/class-mad4b-scp-plugin-discovery.php").read_text(encoding="utf-8")
provider_autopilot = (PLUGIN / "includes/class-mad4b-scp-provider-autopilot.php").read_text(encoding="utf-8")
adapter_registry = (PLUGIN / "includes/class-mad4b-scp-adapter-registry.php").read_text(encoding="utf-8")
transport_registry = (PLUGIN / "includes/class-mad4b-scp-provider-transport-registry.php").read_text(encoding="utf-8")
servers = (PLUGIN / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
all_php = "\n".join(p.read_text(encoding="utf-8", errors="ignore") for p in (PLUGIN / "includes").rglob("*.php"))

assert "Version: 0.4.0-rc.83" in main
for boot in (
    "MAD4B_SCP_Operation_Registry::boot();",
    "MAD4B_SCP_Operation_Pipeline::boot();",
    "MAD4B_SCP_Provider_Autopilot::boot();",
    "MAD4B_SCP_Plugin_Transaction::boot();",
    "MAD4B_SCP_Operation_Resume::boot();",
    "MAD4B_SCP_Provider_Transport_Registry::boot();",
    "MAD4B_SCP_Dependency_Impact_Graph::boot();",
):
    assert boot in main

assert operation["contract"] == "mad4b.operation-registry.v1"
assert operation["default_mutation_policy"] == "deny"
assert operation["generic_shell"] is False
assert operation["arbitrary_operation_ids"] is False
autopilot_policy = operation["dynamic_provider_autopilot"]
assert autopilot_policy["enabled_by_default"] is True
assert autopilot_policy["mode"] == "shadow_auto"
assert autopilot_policy["auto_generate_adapter_candidate"] is True
assert autopilot_policy["auto_shadow_certify_provider"] is True
assert autopilot_policy["auto_materialize_candidate_code"] is False
assert autopilot_policy["auto_register_generated_adapter"] is False
assert autopilot_policy["auto_write_certification"] is False
assert autopilot_policy["auto_create_authority"] is False
assert autopilot_policy["auto_enable_mutation"] is False
assert autopilot_policy["environments"]["production"] == "observe_propose_only"
assert autopilot_policy["max_automatic_support_level"] == "L1_lifecycle"
ids = [row["id"] for row in operation["operations"]]
assert len(ids) == len(set(ids))
assert "wordpress.plugin.transaction" in ids
assert "wordpress.provider.recertify" in ids
optional_ids = {row["id"] for row in operation["operations"] if row.get("required_runtime") is False}
assert optional_ids == {"wordpress.content.import", "wordpress.content.export"}

for row in operation["operations"]:
    assert row["planner"]
    assert row["executor"]
    assert row["planner"] in all_php, f"planner not represented in runtime source: {row['planner']}"
    if row["executor"] == "exact_executor_from_plan":
        assert row["id"] == "wordpress.plugin.transaction"
        assert row.get("generic_mutation_dispatch") is False
    else:
        assert row["executor"] in all_php, f"executor not represented in runtime source: {row['executor']}"

assert "wp_register_ability( 'mad4b/wordpress-operation-discover', array(" in registry
assert "wp_register_ability( 'mad4b/universal-operation-discover', array(" not in registry
assert "wp_register_ability( 'mad4b/operation-discover', array(" not in registry
assert "$aliases = self::aliases();" in registry
assert "public static function read_projection" in registry
assert "mad4b_operation_registry_alias_invalid" in registry
assert "mad4b_operation_registry_projection_invalid" in registry
for marker in (
    "mad4b_operation_registry_autopilot_invalid",
    "mad4b_operation_registry_autopilot_environment_invalid",
    "mad4b_operation_registry_autopilot_boundary_invalid",
    "mad4b_operation_registry_autopilot_production_invalid",
    "mad4b_operation_registry_autopilot_level_invalid",
):
    assert marker in registry, marker
assert "public static function autopilot_config" in registry
for marker in (
    "mad4b_operation_registry_stage_bindings_missing",
    "mad4b_operation_registry_stage_binding_invalid",
    "mad4b_operation_registry_stage_binding_type_invalid",
    "mad4b_operation_registry_stage_binding_ability_invalid",
):
    assert marker in registry, marker
for marker in (
    "mad4b_operation_registry_pipeline_profiles_missing",
    "mad4b_operation_registry_pipeline_profile_invalid",
    "mad4b_operation_registry_pipeline_stage_invalid",
    "mad4b_operation_registry_pipeline_condition_invalid",
    "mad4b_operation_registry_pipeline_required_stage_missing",
    "mad4b_operation_registry_pipeline_order_invalid",
    "mad4b_operation_registry_pipeline_binding_invalid",
):
    assert marker in registry, marker
assert "public static function pipeline_profile" in registry
assert "public static function stage_binding" in registry
assert "operation_planner" in registry
assert "operation_executor" in registry
assert "registered_ability_gap" in registry
assert "mad4b_operation_planner_unregistered" in registry
assert "mad4b_operation_executor_unregistered" in registry
assert "optional_unavailable_operations" in registry
assert "projection_missing_registered_abilities" in registry
assert "read_projection_gap" in registry
assert "catalog_sha256" in registry
for marker in (
    "mad4b_operation_registry_policy_invalid",
    "mad4b_operation_registry_id_invalid",
    "mad4b_operation_registry_planner_invalid",
    "mad4b_operation_registry_executor_invalid",
    "mad4b_operation_registry_required_runtime_invalid",
):
    assert marker in registry, marker
assert "MAD4B_SCP_Operation_Registry::read_projection( 'catalog' )" in servers
assert "MAD4B_SCP_Operation_Registry::read_projection( 'direct' )" in servers
assert "mad4b/wordpress-operation-discover" not in servers
assert "mad4b/plugin-transaction-plan" not in servers
assert "mad4b/operation-resume-status" not in servers

assert operation["aliases"]["update"] == "wordpress.plugin.transaction"
assert operation["aliases"]["activate"] == "wordpress.plugin.transaction"
assert operation["aliases"]["reconcile-provider"] == "wordpress.provider.recertify"
direct_projection = operation["read_projection"]["direct"]
catalog_projection = operation["read_projection"]["catalog"]
assert set(direct_projection).issubset(set(catalog_projection))
assert direct_projection == [
    "mad4b/wordpress-operation-discover",
    "mad4b/plugin-transaction-plan",
    "mad4b/operation-resume-status",
]
assert "mad4b/dependency-impact" in catalog_projection
assert "mad4b/provider-candidate-matrix" in catalog_projection
assert "mad4b/provider-autopilot-status" in catalog_projection
assert "mad4b/provider-autopilot-plan" in catalog_projection
assert "mad4b/provider-autopilot-promotion-plan" in catalog_projection
assert "mad4b/operation-pipeline-compile" in catalog_projection
assert "mad4b/provider-transport-registry-status" in catalog_projection
stage_bindings = operation["stage_bindings"]
assert stage_bindings["native_plan"]["binding_type"] == "operation_planner"
assert stage_bindings["exact_execute"]["binding_type"] == "operation_executor"
assert stage_bindings["authorization_boundary"]["binding_type"] == "policy_boundary"
assert stage_bindings["readback"]["binding_type"] == "executor_owned_verification"
assert stage_bindings["provider_candidate"]["ability"] == "mad4b/provider-candidate-matrix"
assert stage_bindings["durable_resume"]["ability"] == "mad4b/operation-resume-status"

assert transport["contract"] == "mad4b.provider-transport-registry.v1"
assert transport["default_unknown_transport"] == "visible_to_peer_governance"
providers = {row["provider_id"] for row in transport["descriptors"]}
for expected in {"jetengine", "fluent_forms", "elementskit", "hostinger_ai_assistant", "uae_hfe", "wp-media"}:
    assert expected in providers
for descriptor in transport["descriptors"]:
    for callback in descriptor.get("server_callbacks", []):
        assert "\\\\" not in callback["callback_class"], callback["callback_class"]

assert "MAD4B_SCP_Provider_Transport_Registry::route_descriptors()" in isolation
assert "unknown_routes_fail_closed" in isolation
assert "internal_retention" in isolation
assert "Only the isolated JetEngine" not in isolation
for marker in (
    "catalog_version_invalid",
    "unknown_transport_default_invalid",
    "provider_id_duplicate",
    "external_visibility_invalid",
    "route_pattern_invalid",
    "route_methods_invalid",
    "callback_class_invalid",
    "suppress_when_isolation_effective",
):
    assert marker in transport_registry, marker

assert "activation_scope" in lifecycle
assert "get_option( 'active_plugins', array() )" in lifecycle
assert "network_activation_controls_site_state" in lifecycle
assert "verify_state( $plugin, $desired_active, $activation_scope = 'site' )" in lifecycle
assert "plugin_site_active" in abilities
assert "mad4b_plugin_network_activation_controls_site" in abilities
assert "manage_network_plugins" in abilities
assert "activate_plugin( $plugin, '', 'network' === $scope )" in abilities
assert "deactivate_plugins( $plugin, false, $network )" in abilities
assert "mad4b.plugin-activation-state.v1" in activation_state
assert "get_option( 'active_plugins', array() )" in activation_state
assert "expected_site_active" in activation_state
assert "expected_network_active" in activation_state
assert "mad4b_plugin_activation_state_restore_mismatch" in activation_state
for source in (plugin_package, remote_update):
    assert "current_site_active" in source
    assert "MAD4B_SCP_Plugin_Activation_State::snapshot" in source
    assert "MAD4B_SCP_Plugin_Activation_State::restore" in source

assert "mad4b.provider-candidate-matrix.v1" in plugin_discovery
assert "public static function provider_candidate_for" in plugin_discovery
for level in ("L0_inventory", "L1_lifecycle", "L2_read", "L3_governed_write", "L4_certified_governed"):
    assert level in plugin_discovery
for invariant in (
    "'mutation_auto_enabled' => false",
    "'authority_created' => false",
    "'unknown_plugin_write_default' => 'deny'",
    "'auto_generate_adapter' => true",
    "'auto_generate_adapter_scope' => 'candidate_only'",
    "'auto_certify_provider' => true",
    "'auto_certify_provider_scope' => 'shadow_identity_only'",
    "'auto_register_generated_adapter' => false",
    "'auto_write_certification' => false",
    "'auto_create_authority' => false",
    "'arbitrary_provider_execution'",
):
    assert invariant in plugin_discovery, invariant
assert "MAD4B_SCP_Provider_Autopilot::proposal_for_candidate" in plugin_discovery
assert "mad4b/provider-candidate-matrix" in adapter_registry
assert "provider_candidate_matrix" in adapter_registry

assert "mad4b.provider-autopilot.v1" in provider_autopilot
assert "mad4b.generated-adapter-candidate.v1" in provider_autopilot
assert "mad4b.provider-shadow-certification.v1" in provider_autopilot
for marker in (
    "enabled_by_default",
    "shadow_auto",
    "observe_propose_only",
    "generated_php_sha256",
    "candidate_sha256",
    "plugin_state_sha256",
    "provider_candidate_fingerprint",
    "SHADOW_IDENTITY_CERTIFIED",
    "'read_execution_eligible' => false",
    "'write_eligible' => false",
    "'auto_registered' => false",
    "'auto_write_certified' => false",
    "'auto_authority_created' => false",
    "'auto_mutation_enabled' => false",
):
    assert marker in provider_autopilot, marker
assert "wp_register_ability( 'mad4b/provider-autopilot-status'" in provider_autopilot
assert "wp_register_ability( 'mad4b/provider-autopilot-plan'" in provider_autopilot
assert "wp_register_ability( 'mad4b/provider-autopilot-promotion-plan'" in provider_autopilot
assert "mad4b.provider-autopilot-promotion-plan.v1" in provider_autopilot
for marker in (
    "runtime_adapter_available",
    "bounded_read_abilities_declared",
    "side_channel_clear",
    "provider_certification_ok",
    "reversible_write_contracts",
    "functional_ready",
    "enter_governed_promotion_lane",
    "promotion_plan_sha256",
):
    assert marker in provider_autopilot, marker

assert "infer_provider_id" in dependency_impact
assert "certified-providers.json" in dependency_impact
assert "'provider_id_inferred' => $provider_inferred" in dependency_impact

assert "mad4b.operation-pipeline-compile.v1" in pipeline
for marker in (
    "registry_catalog_sha256",
    "target_state_sha256",
    "provider_candidate_fingerprint",
    "dependency_impact_sha256",
    "execution_binding_sha256",
    "MAD4B_SCP_Plugin_Lifecycle::snapshot",
    "MAD4B_SCP_Plugin_Discovery::provider_candidate_for",
):
    assert marker in pipeline, marker
assert "MAD4B_SCP_Operation_Registry::stage_binding" in pipeline
assert "condition_value" in pipeline
assert "operation_dependency_impact" not in pipeline
assert "operation_durable_resume" not in pipeline
for marker in (
    "planner",
    "authorization",
    "executor",
    "readback",
    "generic_mutation_dispatch",
    "arbitrary_stage_execution",
    "pipeline_sha256",
):
    assert marker in pipeline, marker
assert "wp_register_ability( 'mad4b/operation-pipeline-compile'" in pipeline
assert operation["stage_bindings"]["exact_execute"]["binding_type"] == "operation_executor"
assert any(row["executor"] == "exact_executor_from_plan" for row in operation["operations"])

assert "mad4b.plugin-transaction-plan.v1" in transaction
assert "generic_mutation_dispatch" in transaction
assert "'plugin_file' => $plugin" in transaction
assert "requested_install_requires_absent_plugin" in transaction
assert "requested_replace_requires_installed_plugin" in transaction
assert "impact_plugin" in transaction
assert "exact_executor_only" in transaction

assert "mad4b.operation-resume-status.v1" in resume
assert "reconcile_provider_state_before_any_retry" in resume
assert "automatic_mutation_retry_allowed" in resume
assert "result_payload_exposed" in resume

print("universal-wordpress-operations-contract: PASS")
