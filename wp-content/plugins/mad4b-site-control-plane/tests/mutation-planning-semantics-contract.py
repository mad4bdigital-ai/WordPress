#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
adapters = root / "includes" / "adapters"
base = adapters / "class-mad4b-scp-adapter-base.php"
semantics = adapters / "class-mad4b-scp-mutation-semantics-adapter.php"
translation = adapters / "class-mad4b-scp-translation-bridge-adapter.php"

for path in (base, semantics, translation):
    assert path.is_file(), f"missing required source: {path}"

base_src = base.read_text(encoding="utf-8")
semantics_src = semantics.read_text(encoding="utf-8")
translation_src = translation.read_text(encoding="utf-8")

# Mutation semantics must be loaded and booted from the canonical adapter bootstrap.
assert "class-mad4b-scp-mutation-semantics-adapter.php" in base_src
assert "MAD4B_SCP_Mutation_Semantics_Adapter::boot();" in base_src

# Hard deletes and provider-native development/import operations must declare
# irreversible semantics instead of being mistaken for missing rollback support.
for token in (
    "mad4b.mutation-semantics.v2",
    "mad4b/mutation-semantics",
    "mad4b/taxonomy-delete-term",
    "wordpress_hard_delete_cannot_restore_same_term_identity",
    "'reversible' => false",
    "'impact' => 'high'",
    "jetengine/create-cpt",
    "jetengine/create-taxonomy",
    "jetengine/import-configuration",
    "mad4b/provider-import-content",
):
    assert token in semantics_src, f"mutation semantics contract missing: {token}"

# Approval planning must validate nested target input before the canonical
# approval-plan callback can create a Pending Ticket.
for token in (
    "wp_register_ability_args",
    "mad4b/approval-plan",
    "validate_planned_target",
    "->validate_input( $operation_input )",
    "mad4b_approval_plan_recursive_target_denied",
    "mad4b_approval_target_input_invalid",
    "mad4b_approval_target_validation_unavailable",
):
    assert token in semantics_src, f"approval planning validation missing: {token}"

# Translation reads may keep provider=auto, but write schemas must be rewritten
# to require an exact provider and runtime validation must deny missing/auto.
assert "array( 'auto', 'wpml', 'polylang' )" in translation_src
for token in (
    "mad4b/translation-set-post-language",
    "mad4b/translation-link-posts",
    "bind_translation_provider_schema",
    "'enum' => array( 'wpml', 'polylang' )",
    "in_array( 'provider', $required, true )",
    "mad4b_translation_provider_binding_required",
    "wp_ability_validate_input",
):
    assert token in semantics_src, f"translation provider binding missing: {token}"

# This governance layer is metadata/validation only and must not gain side channels.
assert "$wpdb" not in semantics_src
assert "database-raw-query" not in semantics_src
assert "BREAKGLASS" not in semantics_src.upper()

print("MAD4B mutation planning semantics contract: PASS")
