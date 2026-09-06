#!/usr/bin/env python3
import argparse
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGINS = ROOT.parent
CATALOG_PATH = ROOT / "config" / "adapter-support-catalog.json"


def require(condition, message):
    if not condition:
        raise SystemExit(message)


def text(path):
    require(path.is_file(), f"missing required file: {path.relative_to(ROOT.parent.parent.parent)}")
    return path.read_text(encoding="utf-8")


def family_for_archive(name, catalog):
    synthetic = name[:-4] + "/" if name.endswith(".zip") else name + "/"
    for family in catalog.get("families", []):
        for prefix in family.get("match", []):
            if synthetic.startswith(prefix):
                return {
                    "family": family.get("id", "unknown"),
                    "adapter_id": family.get("adapter_id", ""),
                    "strategy": family.get("strategy", "adapter_required"),
                    "risk": family.get("risk", "unknown"),
                    "requested_contracts": family.get("requested_contracts", []),
                }
    default = catalog.get("default", {})
    return {
        "family": "unknown",
        "adapter_id": "",
        "strategy": default.get("strategy", "adapter_required"),
        "risk": default.get("risk", "unknown"),
        "requested_contracts": default.get("requested_contracts", ["read", "bounded_write", "reversible_restore"]),
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", default="")
    args = parser.parse_args()

    catalog = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
    require(catalog.get("contract") == "mad4b.adapter-support-catalog.v1", "adapter support catalog contract drift")
    require(catalog.get("default", {}).get("strategy") == "adapter_required", "unknown plugin default must request an adapter")

    priority = {item.get("id"): item for item in catalog.get("priority_external", [])}
    require("woocommerce" in priority and priority["woocommerce"].get("adapter_id") == "woocommerce", "WooCommerce must remain first-class external coverage")
    require("polylang" in priority and priority["polylang"].get("adapter_id") == "polylang", "Polylang must remain first-class external coverage")
    require("products_only_no_orders_payments_refunds" == priority["woocommerce"].get("mutation_scope"), "WooCommerce scope must remain products-only")

    families = {item.get("id"): item for item in catalog.get("families", []) if isinstance(item, dict)}
    require(families.get("jetengine", {}).get("known_parallel_mcp_namespace") == "jet-engine/v1", "JetEngine native MCP namespace must remain an explicit isolation requirement")
    require("native_mcp_isolation" in families.get("jetengine", {}).get("requested_contracts", []), "JetEngine support contract must require native MCP isolation")

    discovery = text(ROOT / "includes" / "class-mad4b-scp-plugin-discovery.php")
    registry = text(ROOT / "includes" / "class-mad4b-scp-adapter-registry.php")
    base = text(ROOT / "includes" / "adapters" / "class-mad4b-scp-adapter-base.php")
    reversible = text(ROOT / "includes" / "class-mad4b-scp-reversible-adapter-mutations.php")
    overrides = text(ROOT / "includes" / "class-mad4b-scp-governed-ability-overrides.php")
    admin_ui = text(ROOT / "includes" / "class-mad4b-scp-adapter-coverage-admin-ui.php")

    for marker in [
        "get_plugins()",
        "mad4b.plugin-adapter-discovery.v1",
        "mad4b.adapter-support-requests.v1",
        "unknown_plugin_write_default",
        "'auto_install' => false",
        "'auto_generate_adapter' => false",
        "'auto_create_authority' => false",
        "adapter_present_certification_required",
        "provider_certification_required",
        "adapter_present_side_channel_blocked",
        "parallel_mcp_write_plane_requires_isolation",
        "known_parallel_mcp_namespace",
        "mcp_foreign_transport_unreviewed",
        "excluded_high_risk",
    ]:
        require(marker in discovery, f"plugin discovery safety marker missing: {marker}")

    for marker in ["mad4b/plugin-adapter-coverage", "mad4b/adapter-support-requests", "reversible_adapter_count"]:
        require(marker in registry, f"registry discovery marker missing: {marker}")

    for marker in [
        "public function reversible_contracts()",
        "reversible_contract_for",
        "capture_reversible_state",
        "read_reversible_state",
        "restore_reversible_state",
        "MAD4B_SCP_Reversible_Adapter_Mutations::execute",
        "mad4b_reversible_contract",
    ]:
        require(marker in base, f"adapter base reversible marker missing: {marker}")

    for marker in [
        "mad4b.rollback.adapter.v1",
        "provider_restore_guard",
        "MAD4B_SCP_Provider_Contracts::mutation_guard",
        "mad4b_undo_state_drift",
        "rollback_payload_sha256",
        "provider_readback_recorded",
    ]:
        require(marker in reversible, f"reversible mutation safety marker missing: {marker}")
    require("callback" not in reversible.split("$rollback = array(", 1)[1].split("$rollback_json", 1)[0], "rollback envelope must not persist callbacks")
    require("MAD4B_SCP_Reversible_Adapter_Mutations::undo" in overrides, "central undo must dispatch certified adapter rollback")

    contracts = {
        "media": (ROOT / "includes" / "adapters" / "class-mad4b-scp-media-adapter.php", [
            "mad4b.rollback.media-metadata.v1",
            "mad4b.rollback.featured-image.v1",
        ]),
        "rank-math": (ROOT / "includes" / "adapters" / "class-mad4b-scp-seo-adapter.php", [
            "mad4b.rollback.rank-math-meta.v1",
            "metadata_exists",
            "delete_post_meta",
        ]),
        "woocommerce": (ROOT / "includes" / "adapters" / "class-mad4b-scp-woocommerce-adapter.php", [
            "mad4b.rollback.woocommerce-product.v1",
            "wc_get_product",
            "->save()",
            "products_only_no_orders_payments_refunds",
        ]),
        "polylang": (ROOT / "includes" / "adapters" / "class-mad4b-scp-polylang-adapter.php", [
            "mad4b.rollback.polylang-post-language.v1",
            "pll_set_post_language",
            "mad4b_polylang_unassigned_not_reversible",
        ]),
        "jetengine": (ROOT / "includes" / "adapters" / "class-mad4b-scp-jetengine-adapter.php", [
            "mad4b.rollback.jetengine-post-meta.v1",
            "metadata_exists",
            "mad4b_scp_jetengine_field_write_allowed",
            "mad4b_jetengine_sensitive_meta_write",
        ]),
    }
    for family, (path, markers) in contracts.items():
        source = text(path)
        for marker in markers:
            require(marker in source, f"{family} reversible contract marker missing: {marker}")

    for forbidden in ["$_POST", "admin_post_", "$wpdb->insert", "$wpdb->update", "$wpdb->delete"]:
        require(forbidden not in admin_ui, f"Adapter Coverage console must remain read-only: {forbidden}")
    require("manage_options" in admin_ui and "Adapter Coverage" in admin_ui, "Adapter Coverage console capability/UI contract missing")
    require("adapter_present_side_channel_blocked" in admin_ui and "Runtime blocker" in admin_ui, "Adapter Coverage console must surface side-channel blockers")

    archives = sorted(path.name for path in PLUGINS.glob("*.zip"))
    require(archives, "repository plugin archive inventory is empty")
    archive_coverage = []
    for archive in archives:
        row = {"archive": archive}
        row.update(family_for_archive(archive, catalog))
        require(row["strategy"], f"archive silently omitted from adapter strategy: {archive}")
        archive_coverage.append(row)

    archive_names = set(archives)
    for expected_prefix in ["elementor", "jet-engine", "jet-smart-filters", "bit-pi", "seo-by-rank-math"]:
        require(any(name.startswith(expected_prefix) for name in archive_names), f"expected repository provider family missing: {expected_prefix}")

    by_archive = {row["archive"]: row for row in archive_coverage}
    require(by_archive.get("code-snippets.zip", {}).get("strategy") == "excluded_high_risk", "Code Snippets must be excluded from normal adapter writers")
    require(by_archive.get("better-search-replace.zip", {}).get("strategy") == "excluded_high_risk", "Better Search Replace must be excluded from normal adapter writers")

    counts = {}
    for row in archive_coverage:
        counts[row["strategy"]] = counts.get(row["strategy"], 0) + 1
    evidence = {
        "contract": "mad4b.repository-plugin-adapter-coverage.v1",
        "archive_count": len(archive_coverage),
        "strategy_counts": counts,
        "archives": archive_coverage,
        "priority_external": [
            {
                "id": item.get("id"),
                "adapter_id": item.get("adapter_id"),
                "strategy": item.get("strategy"),
                "installed_archive_required": False,
            }
            for item in catalog.get("priority_external", [])
        ],
        "unknown_archive_default": "adapter_required",
        "auto_install": False,
        "auto_generate_adapter": False,
        "authority_created": False,
    }
    if args.output:
        Path(args.output).write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"mad4b.site-control-plane.adapter-discovery-reversibility.v2: PASS archives={len(archive_coverage)} adapter_required={counts.get('adapter_required', 0)}")


if __name__ == "__main__":
    main()
