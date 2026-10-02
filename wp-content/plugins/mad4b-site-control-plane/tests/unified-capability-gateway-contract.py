from pathlib import Path

root = Path('wp-content/plugins/mad4b-site-control-plane')
gateway = (root / 'includes/class-mad4b-scp-unified-capability-gateway.php').read_text(encoding='utf-8')
catalog = (root / 'includes/class-mad4b-scp-ability-catalog-transport.php').read_text(encoding='utf-8')
projection = (root / 'includes/class-mad4b-scp-chatgpt-tool-projection.php').read_text(encoding='utf-8')
inspector = (root / 'includes/class-mad4b-scp-ability-contract-inspector.php').read_text(encoding='utf-8')
descriptor = (root / 'includes/class-mad4b-scp-capability-descriptor-registry.php').read_text(encoding='utf-8')
abilities = (root / 'includes/class-mad4b-scp-abilities.php').read_text(encoding='utf-8')
client = (root / 'client/ability-catalog-client.mjs').read_text(encoding='utf-8')
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
search_section = gateway.split("public static function search", 1)[1].split("private static function execution_descriptor", 1)[0]
for forbidden in ["describe_ability(", "get_input_schema(", "get_output_schema(", "input_schema_sha256", "classification_sha256", "execution_eligible"]:
    assert forbidden not in search_section, f'task search must remain metadata-only before preparation: {forbidden}'
for marker in ["bounded_metadata_only_relevance", "preparation_required", "schema_loaded", "authority_decision_deferred"]:
    assert marker in search_section, f'metadata-only search invariant missing: {marker}'

schema_section = gateway.split("private static function schema_transport", 1)[1]
for marker in ["schema_format", "'wire' === $format", "$prepared['item']['wire']", "$prepared['item']['source']", "mad4b_capability_gateway_schema_format_unavailable"]:
    assert marker in schema_section, f'format-aware schema resolution invariant missing: {marker}'

assert "public static function prepare_ability" in catalog, 'single-Ability lazy schema preparation is missing'
for marker in ["subject_fingerprint", "issuer_fingerprint", "client_fingerprint", "token_scopes"]:
    assert marker in catalog, f'catalog scope is not identity-bound: {marker}'
assert "public static function describe_ability" in projection, 'projection classification is not reusable by gateway'
assert "MAD4B_SCP_Ability_Contract_Inspector::inspect" in descriptor, 'descriptor registry is not backed by the canonical inspector'
assert "MAD4B_SCP_ChatGPT_Tool_Projection::inspect_contract" not in descriptor, 'descriptor registry regressed to presentation-layer classification'
assert "mad4b.ability-classification.v2" in inspector and "sort( $keys, SORT_STRING )" in inspector, 'canonical classification digest contract is incomplete'
assert "expected_authority_scope_sha256', 'preparation_receipt" in abilities, 'fixed dispatch schema does not require signed preparation scope'
assert "expected_authority_scope_sha256: item.authority_scope_sha256" in client, 'client does not forward the fresh server authority scope'
assert "preparation_receipt: item.preparation_receipt" in client, 'client does not forward fresh signed preparation evidence'
assert main.index("class-mad4b-scp-ability-contract-inspector.php") < main.index("class-mad4b-scp-capability-descriptor-registry.php") < main.index("class-mad4b-scp-chatgpt-tool-projection.php"), 'canonical inspector/descriptor/projection bootstrap order drifted'
assert "'/mad4b/v1/capability-gateway'" in oauth, 'adaptive REST gateway is not protected by the ChatGPT OAuth resource'
assert "MAD4B_SCP_Unified_Capability_Gateway::public_manifest()" in compat, 'client compatibility manifest does not advertise adaptive gateway'
assert "class-mad4b-scp-unified-capability-gateway.php" in main, 'gateway class is not loaded by the Control Plane'
print('mad4b.unified-capability-gateway.contract.v1: PASS')
