#!/usr/bin/env python3
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
catalog_path = root / "config" / "semantic-content-field-contracts.json"
semantic_path = root / "includes" / "class-mad4b-scp-semantic-content-field-contracts.php"

catalog = json.loads(catalog_path.read_text(encoding="utf-8"))
semantic_src = semantic_path.read_text(encoding="utf-8")
abilities = catalog.get("abilities", {})

assert catalog.get("contract") == "mad4b.semantic-content-field-contracts.v1"
assert "fallback_evidence_authorizing" in semantic_src
assert "governance_path" in semantic_src

exact_operational = {
    "context/source-scan-apply": {
        "source_id",
        "expected_plan_sha256",
        "expected_registry_revision",
        "expected_provider_inventory_digest",
    },
    "mad4b/context-ai-review": {
        "asset_id",
        "decision",
        "review_note",
        "expected_content_hash",
        "expected_registry_revision",
        "expected_authority_manifest_fingerprint",
    },
}
for ability, required_paths in exact_operational.items():
    row = abilities.get(ability)
    assert isinstance(row, dict), f"missing semantic contract: {ability}"
    assert row.get("mode") == "exact_paths", f"{ability} must use exact-path classification"
    assert row.get("brand_paths") == [], f"{ability} must not classify governance metadata as brand content"
    assert required_paths.issubset(set(row.get("operational_paths", []))), f"{ability} operational paths incomplete"

for ability, provider in (
    ("mad4b/content-experience-profile-apply", "content_experience"),
    ("mad4b/search-profile-apply", "search_intelligence"),
):
    row = abilities.get(ability)
    assert isinstance(row, dict), f"missing semantic contract: {ability}"
    assert row.get("provider") == provider
    assert row.get("mode") == "object_fields"
    assert row.get("container_path") == "profile"
    assert row.get("brand_fields") == []
    assert row.get("operational_key_regex") == ".*"
    roots = set(row.get("root_operational_paths", []))
    assert {"expected_revision", "plan_sha256"}.issubset(roots)

# Brand-bearing materialization must remain outside the operational-only bypass.
for protected in (
    "context/materialize-brand-draft",
    "context/create-drive-asset",
    "mad4b/content-create-post",
):
    if protected in abilities and protected != "mad4b/content-create-post":
        row = abilities[protected]
        assert row.get("operational_key_regex") != ".*", f"{protected} must not become an operational-only subtree"

# Existing public content contract must stay brand-aware.
core_create = abilities["mad4b/content-create-post"]
assert "post_title" in core_create.get("brand_paths", [])
assert "post_content" in core_create.get("brand_paths", [])
assert "post_excerpt" in core_create.get("brand_paths", [])

print("MAD4B governed configuration semantic contract: PASS")
