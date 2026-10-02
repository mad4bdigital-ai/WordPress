from pathlib import Path

root = Path('wp-content/plugins/mad4b-site-control-plane')
gateway = (root / 'includes/class-mad4b-scp-unified-capability-gateway.php').read_text(encoding='utf-8')
catalog = (root / 'includes/class-mad4b-scp-ability-catalog-transport.php').read_text(encoding='utf-8')
projection = (root / 'includes/class-mad4b-scp-chatgpt-tool-projection.php').read_text(encoding='utf-8')
oauth = (root / 'includes/class-mad4b-scp-oauth-resource-bridge.php').read_text(encoding='utf-8')
compat = (root / 'includes/class-mad4b-scp-mcp-client-compatibility.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')

for marker in [
    "mad4b.unified-capability-gateway.v1",
    "client_claims_authoritative",
    "dynamic_tool_refresh",
    "fixed_dispatch",
    "dynamic_projection",
    "recommended_chunk_bytes",
    "recommended_parallel_schema_fetches",
    "mad4b/read-execute",
    "mad4b/write-execute",
    "mad4b/developer-execute",
    "mad4b/enrollment-execute",
    "projection_is_never_implicit",
    "dynamic_projection_scope",
    "site_enrollment_explicit",
    "per_client_projection_isolation",
    "expected_input_schema_sha256",
    "requires_operation_resolution",
    "enrollment_operation_resolution_required",
    "source_of_truth' => 'wordpress_abilities_api",
]:
    assert marker in gateway, f'missing unified gateway invariant: {marker}'

assert '->execute(' not in gateway, 'unified gateway must not directly execute target Abilities'
assert "public static function prepare_ability" in catalog, 'single-Ability lazy schema preparation is missing'
for marker in ["subject_fingerprint", "issuer_fingerprint", "client_fingerprint", "token_scopes"]:
    assert marker in catalog, f'catalog scope is not identity-bound: {marker}'
assert "public static function describe_ability" in projection, 'projection classification is not reusable by gateway'
assert "'/mad4b/v1/capability-gateway'" in oauth, 'adaptive REST gateway is not protected by the ChatGPT OAuth resource'
assert "MAD4B_SCP_Unified_Capability_Gateway::public_manifest()" in compat, 'client compatibility manifest does not advertise adaptive gateway'
assert "class-mad4b-scp-unified-capability-gateway.php" in main, 'gateway class is not loaded by the Control Plane'
print('mad4b.unified-capability-gateway.contract.v1: PASS')
