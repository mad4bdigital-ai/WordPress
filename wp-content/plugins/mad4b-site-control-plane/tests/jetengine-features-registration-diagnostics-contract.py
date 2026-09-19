#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADAPTER = ROOT / "includes/adapters/class-mad4b-scp-jetengine-features-registration-diagnostics-adapter.php"
LOADER = ROOT / "includes/adapters/class-mad4b-scp-mutation-semantics-adapter.php"

src = ADAPTER.read_text(encoding="utf-8")
loader = LOADER.read_text(encoding="utf-8")

required = [
    "mad4b.jetengine-features-registration-diagnostics.v1",
    "jetengine/features-registration-diagnostics",
    "Jet_Engine\\\\MCP_Tools\\\\Registry",
    "register_features_api",
    "Jet_Engine\\\\MCP_Tools\\\\Rest_API\\\\Get_Controller",
    "Jet_Engine\\\\MCP_Tools\\\\Rest_API\\\\MCP_Controller",
    "Jet_Engine\\\\MCP_Tools\\\\Rest_API\\\\Run_Controller",
    "registry_instantiation_declared",
    "instance_creation_proven",
    "constructor_calls_register_routes",
    "pre_register_routes_guards",
    "pre_register_routes_guard_result",
    "register_rest_route_call_count",
    "rest_server_already_present",
    "callback_execution_attempted' => false",
    "controller_instantiation_attempted' => false",
    "route_registration_attempted' => false",
    "rest_api_init_replayed' => false",
    "rest_server_instantiated' => false",
    "settings_mutation' => false",
    "filesystem_mutation' => false",
    "database_mutation' => false",
    "no_secrets_exposed' => true",
]
for token in required:
    assert token in src, f"missing features registration diagnostics token: {token}"

for forbidden in [
    "rest_get_server(",
    "rest_do_request(",
    "do_action( 'rest_api_init'",
    'do_action( "rest_api_init"',
    "update_option(",
    "add_option(",
    "delete_option(",
    "$wpdb->",
    "wp_remote_post(",
    "wp_remote_request(",
    "newInstance(",
    "newInstanceArgs(",
]:
    assert forbidden not in src, f"features registration diagnostics must remain passive: {forbidden}"

for controller in [
    "new Jet_Engine\\\\MCP_Tools\\\\Rest_API\\\\Get_Controller",
    "new Jet_Engine\\\\MCP_Tools\\\\Rest_API\\\\MCP_Controller",
    "new Jet_Engine\\\\MCP_Tools\\\\Rest_API\\\\Run_Controller",
]:
    assert controller not in src, f"diagnostics must not instantiate provider controller: {controller}"

assert "class-mad4b-scp-jetengine-features-registration-diagnostics-adapter.php" in loader
assert "'read' => array( self::ABILITY )" in src
assert "'content' => array(), 'admin' => array(), 'write' => array()" in src

print("JetEngine Features registration diagnostics contract: PASS")
