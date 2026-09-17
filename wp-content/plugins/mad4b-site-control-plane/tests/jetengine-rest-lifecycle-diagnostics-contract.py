#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADAPTER = ROOT / "includes/adapters/class-mad4b-scp-jetengine-rest-lifecycle-diagnostics-adapter.php"
LOADER = ROOT / "includes/adapters/class-mad4b-scp-mutation-semantics-adapter.php"

src = ADAPTER.read_text(encoding="utf-8")
loader = LOADER.read_text(encoding="utf-8")

required = [
    "mad4b.jetengine-rest-lifecycle-diagnostics.v1",
    "jetengine/rest-lifecycle-diagnostics",
    "rest_api_init",
    "ReflectionClass",
    "ReflectionMethod",
    "source_lifecycle_evidence",
    "jetengine_rest_callbacks_not_attached_to_rest_api_init",
    "jetengine_rest_callbacks_attached_but_routes_absent",
    "jetengine_controller_bootstrap_missing_or_completed_after_rest_api_init",
    "callback_execution_attempted' => false",
    "route_registration_attempted' => false",
    "rest_server_instantiated' => false",
    "settings_mutation' => false",
    "no_secrets_exposed' => true",
]
for token in required:
    assert token in src, f"missing lifecycle diagnostics contract token: {token}"

for forbidden in [
    "rest_get_server(",
    "rest_do_request(",
    "call_user_func(",
    "do_action( 'rest_api_init'",
    'do_action( "rest_api_init"',
    "update_option(",
    "add_option(",
    "delete_option(",
    "$wpdb->query(",
    "$wpdb->insert(",
    "$wpdb->update(",
    "$wpdb->delete(",
    "wp_remote_post(",
    "wp_remote_request(",
]:
    assert forbidden not in src, f"lifecycle diagnostics must remain observational: {forbidden}"

assert "register_rest_route\\s*\\(" in src, "source scanner must detect provider route registration calls"
assert "register_rest_route(" not in src, "diagnostic must not register REST routes itself"
assert "class-mad4b-scp-jetengine-rest-lifecycle-diagnostics-adapter.php" in loader, "lifecycle diagnostics must be loaded by the governed adapter bootstrap"

print("JetEngine REST lifecycle diagnostics contract: PASS")
