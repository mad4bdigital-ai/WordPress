#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
adapter = root / "includes" / "adapters" / "class-mad4b-scp-core-content-modeling-adapter.php"
registry = root / "includes" / "class-mad4b-scp-adapter-registry.php"
servers = root / "includes" / "class-mad4b-scp-servers.php"

for path in (adapter, registry, servers):
    assert path.is_file(), f"missing required source: {path}"

src = adapter.read_text(encoding="utf-8")
reg = registry.read_text(encoding="utf-8")
srv = servers.read_text(encoding="utf-8")

required_abilities = (
    "mad4b/list-taxonomies",
    "mad4b/list-terms",
    "mad4b/content-create-post",
    "mad4b/taxonomy-create-term",
    "mad4b/taxonomy-set-object-terms",
)
for ability in required_abilities:
    assert ability in src, f"ability missing: {ability}"

for contract in (
    "mad4b.rollback.created-post.v1",
    "mad4b.rollback.created-term.v1",
    "mad4b.rollback.object-terms.v1",
):
    assert contract in src, f"rollback contract missing: {contract}"

for api in (
    "wp_insert_post(",
    "wp_delete_post(",
    "wp_insert_term(",
    "wp_delete_term(",
    "wp_set_object_terms(",
    "wp_get_object_terms(",
    "is_object_in_taxonomy(",
):
    assert api in src, f"WordPress API missing: {api}"

for guard in (
    "expected_term_ids",
    "mad4b_term_assignment_state_drift",
    "current_user_can( $create_cap )",
    "current_user_can( $manage_cap )",
    "current_user_can( $assign_cap )",
    "mad4b_post_type_internal_denied",
    "mad4b_taxonomy_object_mismatch",
    "mad4b_term_create_undo_in_use",
    "POST_BINDING_META",
    "TERM_BINDING_META",
):
    assert guard in src, f"safety guard missing: {guard}"

assert "protected function certified_provider_key() { return 'core'; }" in src
assert "protected function mutation_requires_certification() { return false; }" in src
assert "MAD4B_SCP_Reversible_Adapter_Mutations" not in src, "adapter must use Adapter_Base reversible wrapper, not bypass it"
assert "$wpdb" not in src, "core content modeling adapter must not use direct SQL"
assert "database-raw-query" not in src
assert "BREAKGLASS" not in src.upper()
assert "production" not in src.lower(), "generic content adapter must remain tenant/environment-neutral"

assert "class-mad4b-scp-core-content-modeling-adapter.php" in reg
assert "MAD4B_SCP_Core_Content_Modeling_Adapter" in reg

assert "registered_adapter_write_candidates" in srv
assert "array( 'content', 'admin', 'write' )" in srv
assert "array_keys( self::registered_adapter_write_candidates() )" in srv
assert "'mad4b/database-raw-query'" in srv

print("MAD4B core content modeling contract: PASS")
