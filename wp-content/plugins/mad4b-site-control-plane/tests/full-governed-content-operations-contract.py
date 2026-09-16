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
assert "expected_native_ability" in provider_src and "expected_schema_sha256" in provider_src

for src, label in ((full_src, "full-content"), (translation_src, "translation"), (provider_src, "provider-bridge")):
    assert "$wpdb" not in src, f"{label} must not use direct SQL"
    assert "database-raw-query" not in src, f"{label} must not expose raw SQL"
    assert "BREAKGLASS" not in src.upper(), f"{label} must not expose breakglass"

assert "registered_adapter_write_candidates" in servers_src
assert "array( 'content', 'admin', 'write' )" in servers_src
assert "array_keys( self::registered_adapter_write_candidates() )" in servers_src
assert "'mad4b/database-raw-query'" in servers_src

print("MAD4B full governed content operations contract: PASS")
