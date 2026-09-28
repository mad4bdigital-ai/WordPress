#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"

main = (PLUGIN / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
operation = json.loads((PLUGIN / "config/operation-registry.json").read_text(encoding="utf-8"))
transport = json.loads((PLUGIN / "config/provider-transport-registry.json").read_text(encoding="utf-8"))
registry = (PLUGIN / "includes/class-mad4b-scp-operation-registry.php").read_text(encoding="utf-8")
isolation = (PLUGIN / "includes/class-mad4b-scp-mcp-provider-isolation.php").read_text(encoding="utf-8")
lifecycle = (PLUGIN / "includes/class-mad4b-scp-plugin-lifecycle.php").read_text(encoding="utf-8")
abilities = (PLUGIN / "includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
transaction = (PLUGIN / "includes/class-mad4b-scp-plugin-transaction.php").read_text(encoding="utf-8")
resume = (PLUGIN / "includes/class-mad4b-scp-operation-resume.php").read_text(encoding="utf-8")
activation_state = (PLUGIN / "includes/class-mad4b-scp-plugin-activation-state.php").read_text(encoding="utf-8")
plugin_package = (PLUGIN / "includes/class-mad4b-scp-plugin-package.php").read_text(encoding="utf-8")
remote_update = (PLUGIN / "includes/class-mad4b-scp-remote-plugin-update.php").read_text(encoding="utf-8")
dependency_impact = (PLUGIN / "includes/class-mad4b-scp-dependency-impact-graph.php").read_text(encoding="utf-8")
transport_registry = (PLUGIN / "includes/class-mad4b-scp-provider-transport-registry.php").read_text(encoding="utf-8")
servers = (PLUGIN / "includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
all_php = "\n".join(p.read_text(encoding="utf-8", errors="ignore") for p in (PLUGIN / "includes").rglob("*.php"))

assert "Version: 0.4.0-rc.83" in main
for boot in (
    "MAD4B_SCP_Operation_Registry::boot();",
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
assert "registered_ability_gap" in registry
assert "mad4b_operation_planner_unregistered" in registry
assert "mad4b_operation_executor_unregistered" in registry
assert "optional_unavailable_operations" in registry
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
assert "mad4b/provider-transport-registry-status" in catalog_projection

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

assert "infer_provider_id" in dependency_impact
assert "certified-providers.json" in dependency_impact
assert "'provider_id_inferred' => $provider_inferred" in dependency_impact

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
