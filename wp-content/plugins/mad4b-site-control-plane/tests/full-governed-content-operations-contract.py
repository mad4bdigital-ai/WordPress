#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
adapters = root / "includes" / "adapters"
base = adapters / "class-mad4b-scp-adapter-base.php"
full = adapters / "class-mad4b-scp-full-content-operations-adapter.php"
translation = adapters / "class-mad4b-scp-translation-bridge-adapter.php"
provider = adapters / "class-mad4b-scp-native-provider-bridge-adapter.php"
jetengine = adapters / "class-mad4b-scp-jetengine-adapter.php"
jetengine_client = adapters / "class-mad4b-scp-jetengine-mcp-client.php"
media = adapters / "class-mad4b-scp-media-adapter.php"
remote_media = adapters / "class-mad4b-scp-remote-media-adapter.php"
servers = root / "includes" / "class-mad4b-scp-servers.php"
semantic = root / "includes" / "class-mad4b-scp-semantic-content-field-contracts.php"
experience = root / "includes" / "class-mad4b-scp-content-experience-profiles.php"
experience_bootstrap = root / "includes" / "class-mad4b-scp-content-experience-bootstrap.php"
experience_governance = root / "includes" / "class-mad4b-scp-content-experience-governance.php"
experience_media = root / "includes" / "class-mad4b-scp-content-experience-media.php"
experience_media_storage = root / "includes" / "class-mad4b-scp-content-experience-media-storage.php"
experience_media_binding = root / "includes" / "class-mad4b-scp-content-experience-media-binding.php"
experience_media_manifest = root / "includes" / "class-mad4b-scp-content-experience-media-manifest.php"
experience_media_planning = root / "includes" / "class-mad4b-scp-content-experience-media-planning.php"
remote_media_recovery = root / "includes" / "class-mad4b-scp-remote-media-recovery.php"
experience_media_rights = root / "includes" / "class-mad4b-scp-content-experience-media-rights.php"
remote_media_rights = root / "includes" / "class-mad4b-scp-remote-media-rights.php"
experience_runtime = root / "includes" / "class-mad4b-scp-content-experience-runtime.php"
descriptor = root / "includes" / "class-mad4b-scp-capability-descriptor-registry.php"
experience_runtime_smoke = root / "tests" / "runtime-content-experience-smoke.php"
reversible = root / "includes" / "class-mad4b-scp-reversible-adapter-mutations.php"
plugin = root / "mad4b-site-control-plane.php"

for path in (base, full, translation, provider, jetengine, jetengine_client, media, remote_media, servers, semantic, experience, experience_bootstrap, experience_governance, experience_media, experience_media_storage, experience_media_binding, experience_media_manifest, experience_media_planning, remote_media_recovery, experience_media_rights, remote_media_rights, experience_runtime, descriptor, experience_runtime_smoke, reversible, plugin):
    assert path.is_file(), f"missing required source: {path}"

base_src = base.read_text(encoding="utf-8")
full_src = full.read_text(encoding="utf-8")
translation_src = translation.read_text(encoding="utf-8")
provider_src = provider.read_text(encoding="utf-8")
jetengine_src = jetengine.read_text(encoding="utf-8")
jetengine_client_src = jetengine_client.read_text(encoding="utf-8")
media_src = media.read_text(encoding="utf-8")
remote_media_src = remote_media.read_text(encoding="utf-8")
servers_src = servers.read_text(encoding="utf-8")
semantic_src = semantic.read_text(encoding="utf-8")
experience_src = experience.read_text(encoding="utf-8")
experience_bootstrap_src = experience_bootstrap.read_text(encoding="utf-8")
experience_governance_src = experience_governance.read_text(encoding="utf-8")
experience_media_src = experience_media.read_text(encoding="utf-8")
experience_media_storage_src = experience_media_storage.read_text(encoding="utf-8")
experience_media_binding_src = experience_media_binding.read_text(encoding="utf-8")
experience_media_manifest_src = experience_media_manifest.read_text(encoding="utf-8")
experience_media_planning_src = experience_media_planning.read_text(encoding="utf-8")
remote_media_recovery_src = remote_media_recovery.read_text(encoding="utf-8")
experience_media_rights_src = experience_media_rights.read_text(encoding="utf-8")
remote_media_rights_src = remote_media_rights.read_text(encoding="utf-8")
experience_runtime_src = experience_runtime.read_text(encoding="utf-8")
descriptor_src = descriptor.read_text(encoding="utf-8")
experience_runtime_smoke_src = experience_runtime_smoke.read_text(encoding="utf-8")
reversible_src = reversible.read_text(encoding="utf-8")
plugin_src = plugin.read_text(encoding="utf-8")

for filename, class_name in (
    ("class-mad4b-scp-full-content-operations-adapter.php", "MAD4B_SCP_Full_Content_Operations_Adapter"),
    ("class-mad4b-scp-translation-bridge-adapter.php", "MAD4B_SCP_Translation_Bridge_Adapter"),
    ("class-mad4b-scp-native-provider-bridge-adapter.php", "MAD4B_SCP_Native_Provider_Bridge_Adapter"),
):
    assert filename in base_src, f"adapter base does not load {filename}"
    assert f"{class_name}::boot();" in base_src, f"adapter base does not boot {class_name}"

for token in (
    "media/update-metadata",
    "media/set-featured",
    "media/set-parent",
    "mad4b.rollback.media-metadata.v1",
    "mad4b.rollback.featured-image.v1",
    "mad4b.rollback.media-parent.v1",
    "metadata_sha256",
    "parent_id",
    "parent_post_id",
    "unattached_only",
    "image_only",
    "detail_level",
    "mutable_state_from_post",
    "wp_attachment_is_image(",
    "mad4b_media_metadata_readback_mismatch",
    "mad4b_media_parent_readback_mismatch",
    "mad4b_media_featured_readback_mismatch",
    "mad4b_media_restore_readback_mismatch",
):
    assert token in media_src, f"media governance contract missing: {token}"

assert "$wpdb" not in media_src
assert "database-raw-query" not in media_src
assert "BREAKGLASS" not in media_src.upper()

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
    "MAD4B_SCP_Content_Experience_Profiles::high_impact_abilities()",
    "MAD4B_SCP_Content_Experience_Profiles::owns_ability",
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
    "'verify'",
    "'helpers'",
    "profile_routes",
    "helper_catalog_sha256",
    "mad4b_scp_content_experience_helper_catalog",
    "dynamic_routes_are_configuration_driven",
    "hardcoded_business_content_types",
    "taxonomy_mode",
    "authority_sha256",
    "post_type_immutable",
    "content-experience-profile-clone-plan",
    "content-experience-profile-delete-plan",
    "PROFILE_CLONE_APPLY_ABILITY",
    "PROFILE_DELETE_APPLY_ABILITY",
    "high_impact_abilities",
    "publish_apply",
    "executor_generation",
    "migration_required",
    "helper_bindings",
    "binding_sha256",
    "media_meta_fields",
    "MAX_MEDIA_META_FIELDS",
    "MAX_MEDIA_GALLERY_ITEMS",
):
    assert token in experience_src, f"dynamic content experience registry contract missing: {token}"

# Typed media kinds/storage are intentionally owned by the dedicated media
# component. Profiles retain only bounded limits and normalized media field
# configuration so new media semantics do not grow the profile registry into a
# business/runtime monolith.
for token in (
    "image_gallery",
    "attachment_gallery",
    "image_gallery_usage",
    "attachment_gallery_usage",
    "alt_override",
    "caption_override",
    "title_override",
    "description_override",
    "credit",
    "copyright",
    "license",
    "license_expires_on",
    "source_url",
    "focal_point",
    "aria_label",
    "decorative",
    "link_url",
    "link_target",
    "licenses",
    "publish_rights_policy",
    "expiry_required_licenses",
    "effective_meta_state",
    "effective_state_sha256",
):
    assert token in experience_media_src, f"content experience media contract missing: {token}"

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
    "profile_snapshot",
    "profile_authority_sha256",
    "AUTHORITY_META",
    "compensated_error",
    "mad4b_content_experience_compensation_failed",
    "mad4b_content_experience_locked_plan_drift",
    "parent_read_denied",
    "authority_match",
    "mad4b_content_experience_helper_restore_contract_drift",
):
    assert token in experience_runtime_src, f"dynamic content experience execution contract missing: {token}"

for token in (
    "mad4b_content_experience_media_gallery_shape_invalid",
    "mad4b_content_experience_media_gallery_duplicate",
    "mad4b_content_experience_media_image_required",
    "mad4b_content_experience_media_attachment_missing",
    "mad4b_content_experience_media_usage_license_denied",
    "mad4b_content_experience_media_usage_decorative_alt_conflict",
    "mad4b_content_experience_media_usage_decorative_aria_conflict",
    "mad4b_content_experience_media_usage_link_target_without_url",
    "mad4b_content_experience_media_usage_date_invalid",
    "MAX_META_VALUE_BYTES",
    "normalize_meta_value",
    "validate_usage_bindings",
    "verify_post_meta",
    "value_within_budget",
):
    assert token in experience_media_src, f"content-experience media contract missing: {token}"
assert "$wpdb" not in experience_media_src
assert "database-raw-query" not in experience_media_src
assert "BREAKGLASS" not in experience_media_src.upper()
assert len(experience_media_src.splitlines()) <= 500, "content-experience-media exceeds the focused 500-line domain-service budget"

for token in (
    "mad4b.content-experience-media-publish-rights.v1",
    "publish_guard",
    "mad4b_content_experience_media_rights_usage_required",
    "mad4b_content_experience_media_rights_license_required",
    "mad4b_content_experience_media_rights_expiry_required",
    "mad4b_content_experience_media_rights_expired",
    "checked_item_count",
    "nearest_expiry",
):
    assert token in experience_media_rights_src, f"content-experience media rights contract missing: {token}"
assert "$wpdb" not in experience_media_rights_src
assert "database-raw-query" not in experience_media_rights_src
assert "BREAKGLASS" not in experience_media_rights_src.upper()
assert len(experience_media_rights_src.splitlines()) <= 180, "content-experience-media-rights exceeds the focused 180-line domain-service budget"
assert "class-mad4b-scp-content-experience-media-rights.php" in plugin_src

for token in (
    "mad4b.remote-media-publish-provenance.v1",
    "publish_guard",
    "mad4b_remote_media_publish_content_identity_missing",
    "mad4b_remote_media_publish_provenance_missing",
    "mad4b_remote_media_publish_rights_unproven",
    "mad4b_remote_media_publish_rights_expired",
    "checkdate",
):
    assert token in remote_media_rights_src, f"remote media rights contract missing: {token}"
assert "$wpdb" not in remote_media_rights_src
assert "database-raw-query" not in remote_media_rights_src
assert "BREAKGLASS" not in remote_media_rights_src.upper()
assert len(remote_media_rights_src.splitlines()) <= 180, "remote-media-rights exceeds the focused 180-line domain-service budget"
assert "class-mad4b-scp-remote-media-rights.php" in plugin_src
assert "MAD4B_SCP_Remote_Media_Rights::publish_guard(" in experience_runtime_src
assert len(media_src.splitlines()) <= 550, "media adapter exceeds the focused 550-line adapter budget"
for token in (
    "media/remote-source-discover",
    "media/remote-image-inspect",
    "media/remote-import-plan",
    "media/remote-import-apply",
    "media/remote-provenance-get",
    "content_inspection_required",
    "remote_candidate_score",
):
    assert token in remote_media_src, f"remote media adapter contract missing: {token}"
assert "class-mad4b-scp-remote-media-adapter.php" in plugin_src
assert len(remote_media_src.splitlines()) <= 900, "remote media adapter exceeds the focused 900-line adapter budget"

for src, label in (
    (experience_src, "experience-registry"),
    (experience_governance_src, "experience-governance"),
    (experience_runtime_src, "experience-runtime"),
):
    assert "tours-and-activities" not in src.lower(), f"{label} must not hardcode the ETG tour CPT"
    assert "mad4b/tour-" not in src.lower(), f"{label} must not hardcode tour routes"
    assert len(src.splitlines()) <= 900, f"{label} exceeds the new domain-service 900-line budget"

assert "MAX_PROFILES = 64" in experience_src
assert "MAD4B_SCP_Content_Experience_Bootstrap::plan(" in experience_src
assert "class-mad4b-scp-content-experience-bootstrap.php" in plugin_src
for token in (
    "remote_media_library_first",
    "media_field_candidates",
    "infer_sampled_media_meta",
    "mad4b.content-experience-ingestion-workflow.v1",
    "hardcoded_business_content_types",
):
    assert token in experience_bootstrap_src, f"content-experience bootstrap contract missing: {token}"
assert len(experience_bootstrap_src.splitlines()) <= 700, "content-experience bootstrap exceeds the focused 700-line service budget"
assert "MAX_HELPERS = 32" in experience_src

# Profile semantics are immutable authority: descriptor generation + revision-bound
# executor names prevent an existing grant from silently widening after reconfiguration.
for token in (
    "authority_payload",
    "authority_sha256",
    "validate_snapshot",
    "current_guard",
    "descriptor_roots",
    "acquire_lock",
    "mad4b_content_experience_target_busy",
    "helper_binding_guard",
    "mad4b_content_experience_helper_binding_drift",
):
    assert token in experience_governance_src, f"content-experience governance hardening missing: {token}"
assert "mad4b_scp_capability_descriptor_generation_roots" in descriptor_src
assert "extension_roots" in descriptor_src
assert "'r' . $generation . '-'" in experience_src
assert "profile_routes( $slug, $revision = 0 )" in experience_src
assert "'safe_defaults' => array( 'meta_mode' => 'allowlist', 'taxonomy_mode' => 'allowlist'" in experience_src

# Apply annotations are exact: only create replay is idempotent. Update/publish and
# profile lifecycle mutations require new state/revision after a successful apply.
assert "$idempotent = $readonly || ( 'apply' === $phase && 'create' === $operation )" in experience_src
assert experience_src.count("'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false") >= 3

# Real disposable WordPress/MySQL proof is chained into Runtime Integration.
for token in (
    "register_post_type( 'mad4b_ci_trip'",
    "mad4b_content_experience_target_busy",
    "mad4b_compensation",
    "post_type_immutable",
    "-r2-update-apply",
    "profile_clone_plan",
    "profile_delete_plan",
    "authority_match",
    "MAD4B_SCP_Capability_Descriptor_Registry::binding",
    "content_experience_profile",
    "annotations']['idempotent",
):
    assert token in experience_runtime_smoke_src, f"content-experience runtime E2E proof missing: {token}"
assert "runtime-content-experience-smoke.php" in (root / "tests" / "runtime-reversible-mutation-smoke.php").read_text(encoding="utf-8")

# Publish status transition must follow helper execution, not precede it.
helper_pos = experience_runtime_src.index("apply_helpers( $profile, $operation")
publish_transition_pos = experience_runtime_src.index("wp_update_post( array( 'ID' => $post_id, 'post_status' => $normalized['post_status'] )", helper_pos)
assert helper_pos < publish_transition_pos, "publish transition must occur after helper success"

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

assert "MAD4B_SCP_Content_Experience_Media::normalize_meta_value" in experience_runtime_src
assert "MAD4B_SCP_Content_Experience_Media::value_within_budget" in experience_runtime_src
assert "class-mad4b-scp-content-experience-media.php" in plugin_src

assert "class-mad4b-scp-content-experience-profiles.php" in plugin_src
assert "class-mad4b-scp-content-experience-runtime.php" in plugin_src

# Dynamic callbacks remain inside the same durable reversible envelope. The
# callback itself is never persisted; only the ability name and rollback state are.
assert "is_string( $method ) ? array( $this, $method ) : $method" in base_src
assert "is_string( $method ) ? array( $adapter, $method ) : $method" in reversible_src
assert "is_callable( $execute_callback )" in base_src
assert "is_callable( $callable )" in reversible_src
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
    (experience_governance_src, "content-experience-governance"),
    (experience_runtime_src, "content-experience-runtime"),
    (descriptor_src, "capability-descriptor"),
):
    assert "$wpdb" not in src, f"{label} must not use direct SQL"
    assert "database-raw-query" not in src, f"{label} must not expose raw SQL"
    assert "BREAKGLASS" not in src.upper(), f"{label} must not expose breakglass"

assert "array( 'content', 'admin', 'write' )" in servers_src
assert "'mad4b/database-raw-query'" in servers_src

# Provider-compatible media storage remains attachment-identity based even when
# a field stores URL or ID+URL projections.
for token in (
    "mad4b.content-experience-media-storage.v1",
    "csv_ids",
    "csv_urls",
    "id_url_items",
    "json_id_url_items",
    "attachment_id_from_url",
    "infer_spec",
):
    assert token in experience_media_storage_src, f"media storage projection contract missing: {token}"
for token in (
    "mad4b.content-experience-media-binding-plan.v1",
    "content_input_fragment",
    "storage_projection",
    "binding_plan_sha256",
):
    assert token in experience_media_binding_src, f"media binding plan contract missing: {token}"
assert "MEDIA_BINDING_PLAN_ABILITY" in experience_src
assert "class-mad4b-scp-content-experience-media-storage.php" in plugin_src
assert "class-mad4b-scp-content-experience-media-binding.php" in plugin_src
assert len(experience_media_storage_src.splitlines()) <= 360, "content-experience-media-storage exceeds focused 360-line service budget"
assert len(experience_media_binding_src.splitlines()) <= 260, "content-experience-media-binding exceeds focused 260-line service budget"

# JetEngine can contribute exact media-field declarations through its public
# context field catalog; unknown value formats remain non-authorizing.
for token in (
    "content_experience_media_field_candidates",
    "get_fields_for_context",
    "provider_value_format",
    "id_url_items",
):
    assert token in jetengine_src, f"JetEngine adaptive media-field contract missing: {token}"
for token in ("spec_conflict", "alternative_specs", "requires_review", "provider_declared"):
    assert token in experience_bootstrap_src, f"adaptive media candidate reconciliation missing: {token}"


# Media manifest execution is checkpointed: successful imports survive later
# content failure, remain discoverable, and are never silently auto-deleted.
for token in (
    "media/remote-recovery-status",
    "manifest_hash_scope",
    "manifest_sha256",
    "manifest_index",
    "manifest_item_sha256",
    "manifest_binding_role",
    "stage_import_result",
):
    assert token in remote_media_src or token in remote_media_recovery_src, f"remote media recovery contract missing: {token}"
for token in (
    "mad4b.remote-media-recovery.v1",
    "staged_unbound",
    "created_for_manifest_attachment_ids",
    "cleanup_policy",
    "manual_only_after_reference_review",
    "manifest_receipt",
    "bind_post",
    "verify_post_binding",
):
    assert token in remote_media_recovery_src, f"remote media recovery service missing: {token}"
for token in (
    "mad4b.content-experience-media-manifest.v1",
    "media_recovery_receipt",
    "expected_media_binding_state_sha256",
    "attachment_ids",
):
    assert token in experience_media_manifest_src or token in experience_runtime_src, f"content media manifest contract missing: {token}"
for token in (
    "mad4b.content-experience-media-planning.v1",
    "mad4b_content_experience_featured_media_read_denied",
    "effective_media_state_sha256",
    "MAD4B_SCP_Content_Experience_Media_Rights::publish_guard",
    "remote_media_state",
    "media_publish_rights",
    "remote_media_provenance_rights",
):
    assert token in experience_media_planning_src, f"content media planning extraction missing: {token}"
for token in (
    "expected_media_manifest_sha256",
    "expected_media_manifest_item_count",
    "expected_media_recovery_receipt_sha256",
    "expected_media_binding_state_sha256",
):
    assert token in experience_src, f"content experience manifest schema missing: {token}"
assert "class-mad4b-scp-remote-media-recovery.php" in plugin_src
assert "class-mad4b-scp-content-experience-media-manifest.php" in plugin_src
assert "class-mad4b-scp-content-experience-media-planning.php" in plugin_src
assert len(remote_media_recovery_src.splitlines()) <= 320, "remote-media-recovery exceeds focused 320-line service budget"
assert len(experience_media_manifest_src.splitlines()) <= 140, "content-experience-media-manifest exceeds focused 140-line service budget"
assert len(experience_media_planning_src.splitlines()) <= 120, "content-experience-media-planning exceeds focused 120-line service budget"
assert "Recoverable orphan semantics are not explicit/non-destructive." in experience_runtime_smoke_src
assert "Manifest-bound content create did not finish with verified recovery binding." in experience_runtime_smoke_src

print("MAD4B full governed content operations contract: PASS")

assert "mad4b_content_experience_media_binding_manifest_role_drift" in experience_media_binding_src
assert "mad4b_content_experience_media_binding_manifest_item_drift" in experience_media_binding_src

assert "multi_post_meta_keys" in experience_media_manifest_src
assert "'multi' => $is_multi" in experience_runtime_src
assert "created_for_manifest = true" in remote_media_recovery_src
