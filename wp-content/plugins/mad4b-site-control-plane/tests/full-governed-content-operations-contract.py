#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
adapters = root / "includes" / "adapters"
base = adapters / "class-mad4b-scp-adapter-base.php"
full = adapters / "class-mad4b-scp-full-content-operations-adapter.php"
translation = adapters / "class-mad4b-scp-translation-bridge-adapter.php"
provider = adapters / "class-mad4b-scp-native-provider-bridge-adapter.php"
jetengine_client = adapters / "class-mad4b-scp-jetengine-mcp-client.php"
servers = root / "includes" / "class-mad4b-scp-servers.php"
semantic = root / "includes" / "class-mad4b-scp-semantic-content-field-contracts.php"
experience = root / "includes" / "class-mad4b-scp-content-experience-profiles.php"
experience_runtime = root / "includes" / "class-mad4b-scp-content-experience-runtime.php"
reversible = root / "includes" / "class-mad4b-scp-reversible-adapter-mutations.php"
plugin = root / "mad4b-site-control-plane.php"

for path in (base, full, translation, provider, jetengine_client, servers, semantic, experience, experience_runtime, reversible, plugin):
    assert path.is_file(), f"missing required source: {path}"

base_src = base.read_text(encoding="utf-8")
full_src = full.read_text(encoding="utf-8")
translation_src = translation.read_text(encoding="utf-8")
provider_src = provider.read_text(encoding="utf-8")
jetengine_client_src = jetengine_client.read_text(encoding="utf-8")
servers_src = servers.read_text(encoding="utf-8")
semantic_src = semantic.read_text(encoding="utf-8")
experience_src = experience.read_text(encoding="utf-8")
experience_runtime_src = experience_runtime.read_text(encoding="utf-8")
reversible_src = reversible.read_text(encoding="utf-8")
plugin_src = plugin.read_text(encoding="utf-8")

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
    "mad4b.rollback.created-content-bundle.v2",
):
    assert contract in full_src, f"content rollback contract missing: {contract}"

assert "MAX_IMPORT_POSTS = 20" in full_src
assert "MAX_EXPORT_POSTS = 25" in full_src
assert "expected_modified_gmt" in full_src
assert "expected_sha256" in full_src
assert "expected_object_ids" in full_src
assert "mad4b_content_sensitive_meta_denied" in full_src
assert "mad4b_scp_allow_protected_content_meta_write" in full_src

# Content experiences are configuration-driven. A profile created after install
# materializes a bounded create/update/publish/verify Ability family without
# adding business-specific PHP branches.
for token in (
    "MAD4B_SCP_Content_Experience_Profiles::ability_names( 'read' )",
    "MAD4B_SCP_Content_Experience_Profiles::ability_names( 'content' )",
    "MAD4B_SCP_Content_Experience_Profiles::ability_definitions()",
    "MAD4B_SCP_Content_Experience_Profiles::capture_reversible_state",
    "MAD4B_SCP_Content_Experience_Profiles::restore_reversible_state",
):
    assert token in full_src, f"dynamic experience adapter integration missing: {token}"

for token in (
    "mad4b.content-experience-profiles.v1",
    "content-experience-profile-plan",
    "content-experience-profile-apply",
    "create-plan",
    "create-apply",
    "update-plan",
    "update-apply",
    "publish-plan",
    "publish-apply",
    "-verify",
    "-helpers",
    "profile_routes",
    "helper_catalog_sha256",
    "mad4b_scp_content_experience_helper_catalog",
    "dynamic_routes_are_configuration_driven",
    "hardcoded_business_content_types",
):
    assert token in experience_src, f"dynamic content experience registry contract missing: {token}"

for token in (
    "mad4b.content-experience-operation-plan.v1",
    "mad4b.content-experience-verify.v1",
    "mad4b_scp_content_experience_plan_helper",
    "mad4b_scp_content_experience_apply_helper",
    "mad4b_scp_content_experience_capture_helper_state",
    "mad4b_scp_content_experience_restore_helper_state",
    "mad4b_scp_content_experience_verify_helpers",
    "wp_insert_post(",
    "wp_update_post(",
    "wp_set_object_terms(",
    "set_post_thumbnail(",
    "plan_sha256",
    "creation_binding",
    "idempotent_replay",
    "live_update_requires_draft",
    "sensitive_meta_denied",
    "protected_meta_denied",
):
    assert token in experience_runtime_src, f"dynamic content experience execution contract missing: {token}"

for src, label in (
    (experience_src, "experience-registry"),
    (experience_runtime_src, "experience-runtime"),
):
    assert "tours-and-activities" not in src.lower(), f"{label} must not hardcode the ETG tour CPT"
    assert "mad4b/tour-" not in src.lower(), f"{label} must not hardcode tour routes"
    assert len(src.splitlines()) <= 900, f"{label} exceeds the new domain-service 900-line budget"

assert "MAX_PROFILES = 64" in experience_src
assert "MAX_HELPERS = 32" in experience_src

# Dynamic routes participate in Brand Context semantics without static route names.
for token in (
    "brand_bearing_mutation_abilities",
    "semantic_contract_for_ability",
    "dynamic_profile_contract",
):
    assert token in experience_src, f"dynamic semantic binding missing: {token}"
for token in (
    "MAD4B_SCP_Content_Experience_Profiles::brand_bearing_mutation_abilities()",
    "MAD4B_SCP_Content_Experience_Profiles::semantic_contract_for_ability",
):
    assert token in semantic_src, f"semantic registry dynamic profile support missing: {token}"

# Any provider adapter may add helper options, but external helpers must bind to
# one exact certified provider Ability and implement the reversible helper lifecycle.
for token in (
    "content_experience_helpers",
    "plan_content_experience_helper",
    "apply_content_experience_helper",
    "capture_content_experience_helper_state",
    "restore_content_experience_helper_state",
    "verify_content_experience_helper",
):
    assert token in base_src, f"adapter content-experience helper contract missing: {token}"
for token in (
    "MAD4B_SCP_Adapter_Registry::instance()",
    "certification_ability",
    "helper_adapter_required",
    "helper_certification_required",
):
    assert token in experience_src, f"experience helper registry hardening missing: {token}"
for token in (
    "helper_mutation_guard",
    "MAD4B_SCP_Provider_Compatibility_Certification::mutation_guard",
    "MAD4B_SCP_Provider_Contracts::mutation_guard",
):
    assert token in experience_runtime_src, f"experience helper provider certification missing: {token}"

assert "class-mad4b-scp-content-experience-profiles.php" in plugin_src
assert "class-mad4b-scp-content-experience-runtime.php" in plugin_src

# Dynamic callbacks remain inside the same durable reversible envelope. The
# callback itself is never persisted; only the ability name and rollback state are.
assert "is_callable( $method ) ? $method : array( $this, $method )" in base_src
assert "$callable = is_callable( $method ) ? $method : array( $adapter, $method )" in reversible_src
assert "call_user_func( $callable, $input )" in reversible_src

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

# JetEngine has its own MCP transport. The bridge must discover that endpoint
# directly instead of pretending the provider tools live in wp_get_abilities().
for token in (
    "MAD4B_SCP_JetEngine_MCP_Client",
    "class-mad4b-scp-jetengine-mcp-client.php",
    "jetengine-mcp",
    "call_tool(",
    "mad4b_provider_import_reversibility_unverified",
):
    assert token in provider_src, f"JetEngine MCP bridge hardening missing: {token}"

for token in (
    "/jet-engine/v1/mcp",
    "notifications/initialized",
    "tools/list",
    "tools/call",
    "mcp-session-id",
    "mad4b_jetengine_mcp_schema_drift",
    "expected_schema_sha256",
):
    assert token in jetengine_client_src, f"JetEngine MCP client contract missing: {token}"

# Provider bridge cannot self-wrap MAD4B adapters, cross provider ownership, or
# execute native calls whose read/write mode is inconsistent with the fixed wrapper.
for token in (
    "mad4b_owned_row",
    "0 === strpos( $category, 'mad4b-' )",
    "if ( ! $this->jetengine_row( $row ) ) return false;",
    "enforce_native_mode",
    "mad4b_native_provider_write_mode_unverified",
    "mad4b_native_provider_read_mode_unverified",
    "mutation_ability_runtime_eligibility",
    "mad4b_provider_import_reversibility_unverified",
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

for src, label in (
    (full_src, "full-content"),
    (translation_src, "translation"),
    (provider_src, "provider-bridge"),
    (jetengine_client_src, "jetengine-mcp-client"),
    (experience_src, "content-experience-registry"),
    (experience_runtime_src, "content-experience-runtime"),
):
    assert "$wpdb" not in src, f"{label} must not use direct SQL"
    assert "database-raw-query" not in src, f"{label} must not expose raw SQL"
    assert "BREAKGLASS" not in src.upper(), f"{label} must not expose breakglass"

assert "array( 'content', 'admin', 'write' )" in servers_src
assert "'mad4b/database-raw-query'" in servers_src

print("MAD4B full governed content operations contract: PASS")
