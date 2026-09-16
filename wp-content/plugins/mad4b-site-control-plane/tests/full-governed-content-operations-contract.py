#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
adapters = root / "includes" / "adapters"
base = adapters / "class-mad4b-scp-adapter-base.php"
full = adapters / "class-mad4b-scp-full-content-operations-adapter.php"
translation = adapters / "class-mad4b-scp-translation-bridge-adapter.php"
provider = adapters / "class-mad4b-scp-native-provider-bridge-adapter.php"
servers = root / "includes" / "class-mad4b-scp-servers.php"

for path in (base, full, translation, provider, servers):
    assert path.is_file(), f"missing required source: {path}"

base_src = base.read_text(encoding="utf-8")
full_src = full.read_text(encoding="utf-8")
translation_src = translation.read_text(encoding="utf-8")
provider_src = provider.read_text(encoding="utf-8")
servers_src = servers.read_text(encoding="utf-8")

for filename, class_name in (
    ("class-mad4b-scp-full-content-operations-adapter.php", "MAD4B_SCP_Full_Content_Operations_Adapter"),
    ("class-mad4b-scp-translation-bridge-adapter.php", "MAD4B_SCP_Translation_Bridge_Adapter"),
    ("class-mad4b-scp-native-provider-bridge-adapter.php", "MAD4B_SCP_Native_Provider_Bridge_Adapter"),
):
    assert filename in base_src, f"adapter base does not load {filename}"
    assert f"{class_name}::boot();" in base_src, f"adapter base does not boot {class_name}"

for ability in (
    "mad4b/content-modeling-context",
    "mad4b/content-get-meta",
    "mad4b/content-list-meta",
    "mad4b/taxonomy-get-term",
    "mad4b/taxonomy-get-object-terms",
    "mad4b/content-export-bundle",
    "mad4b/content-trash-post",
    "mad4b/content-set-meta",
    "mad4b/content-delete-meta",
    "mad4b/taxonomy-update-term",
    "mad4b/taxonomy-delete-term",
    "mad4b/content-import-bundle",
):
    assert ability in full_src, f"full content ability missing: {ability}"

for api in (
    "wp_trash_post(",
    "wp_untrash_post(",
    "update_post_meta(",
    "delete_post_meta(",
    "wp_update_term(",
    "wp_delete_term(",
    "wp_insert_post(",
    "wp_set_object_terms(",
):
    assert api in full_src, f"expected WordPress content API missing: {api}"

for contract in (
    "mad4b.rollback.trashed-post.v1",
    "mad4b.rollback.post-meta.v1",
    "mad4b.rollback.updated-term.v1",
    "mad4b.rollback.created-content-bundle.v1",
):
    assert contract in full_src, f"content rollback contract missing: {contract}"

assert "MAX_IMPORT_POSTS = 20" in full_src
assert "MAX_EXPORT_POSTS = 25" in full_src
assert "expected_modified_gmt" in full_src
assert "expected_sha256" in full_src
assert "expected_object_ids" in full_src
assert "mad4b_content_sensitive_meta_denied" in full_src
assert "mad4b_scp_allow_protected_content_meta_write" in full_src

for ability in (
    "mad4b/translation-status",
    "mad4b/translation-list-languages",
    "mad4b/translation-get-post",
    "mad4b/translation-set-post-language",
    "mad4b/translation-link-posts",
):
    assert ability in translation_src, f"translation ability missing: {ability}"

for api in (
    "pll_set_post_language(",
    "pll_save_post_translations(",
    "wpml_active_languages",
    "wpml_element_language_details",
    "wpml_get_element_translations",
    "wpml_set_element_language_details",
):
    assert api in translation_src, f"translation provider integration missing: {api}"

for contract in (
    "mad4b.rollback.translation-post-language.v1",
    "mad4b.rollback.translation-post-link.v1",
):
    assert contract in translation_src, f"translation rollback contract missing: {contract}"

# Translation mutation plans must be exact-bound to the current languages/groups.
for token in (
    "expected_group",
    "expected_source_language",
    "expected_target_language",
    "expected_source_group",
    "expected_target_group",
    "allow_relink_existing_group",
    "mad4b_translation_language_collision",
    "mad4b_translation_existing_group_relink_denied",
    "polylang_group_identity",
    "mad4b_translation_unassigned_not_reversible",
):
    assert token in translation_src, f"translation hardening missing: {token}"

for ability in (
    "mad4b/provider-native-tools-inventory",
    "jetengine/native-tools-inventory",
    "jetengine/get-native-configuration",
    "jetengine/get-native-website-config",
    "jetengine/get-native-macros",
    "jetengine/create-cpt",
    "jetengine/create-taxonomy",
    "jetengine/create-meta-box",
    "jetengine/create-cct",
    "jetengine/create-query",
    "jetengine/create-glossary",
    "jetengine/create-listing",
    "jetengine/manage-modules",
    "jetengine/import-configuration",
    "jetengine/export-configuration",
    "mad4b/provider-import-content",
    "mad4b/provider-export-content",
):
    assert ability in provider_src, f"provider bridge ability missing: {ability}"

assert "expected_native_ability" in provider_src
assert "expected_schema_sha256" in provider_src
assert "schema_sha256" in provider_src
assert "->execute( $provider_input )" in provider_src
assert "provider_import_content" in provider_src and "provider_export_content" in provider_src
assert "mcp-adapter/execute-ability" not in provider_src

# Provider bridge cannot self-wrap MAD4B adapters, cross provider ownership, or
# execute native calls whose read/write annotation is missing or wrong.
for token in (
    "mad4b_owned_row",
    "0 === strpos( $category, 'mad4b-' )",
    "if ( ! $this->jetengine_row( $row ) ) return false;",
    "enforce_native_mode",
    "mad4b_native_provider_write_mode_unverified",
    "mad4b_native_provider_read_mode_unverified",
    "mutation_ability_runtime_eligibility",
    "mad4b_provider_import_runtime_unavailable",
):
    assert token in provider_src, f"native-provider hardening missing: {token}"

# Exports are reads; imports remain writes.
read_section = provider_src.split("'read' => array(", 1)[1].split("'content' => array()", 1)[0]
write_section = provider_src.split("'write' => array(", 1)[1].split("),", 1)[0]
assert "jetengine/export-configuration" in read_section
assert "mad4b/provider-export-content" in read_section
assert "jetengine/export-configuration" not in write_section
assert "mad4b/provider-export-content" not in write_section
assert "jetengine/import-configuration" in write_section
assert "mad4b/provider-import-content" in write_section

# Runtime adapter eligibility is a hard mount gate in mad4b-write while the
# stable external catalog remains driven by registered candidates.
for token in (
    "mutation_ability_runtime_eligibility",
    "adapter_runtime_capability_not_eligible",
    "runtime_eligibility_code",
    "registered_adapter_write_candidates",
    "array_keys( self::registered_adapter_write_candidates() )",
):
    assert token in servers_src, f"runtime projection separation missing: {token}"

for src, label in ((full_src, "full-content"), (translation_src, "translation"), (provider_src, "provider-bridge")):
    assert "$wpdb" not in src, f"{label} must not use direct SQL"
    assert "database-raw-query" not in src, f"{label} must not expose raw SQL"
    assert "BREAKGLASS" not in src.upper(), f"{label} must not expose breakglass"

assert "array( 'content', 'admin', 'write' )" in servers_src
assert "'mad4b/database-raw-query'" in servers_src

print("MAD4B full governed content operations contract: PASS")
