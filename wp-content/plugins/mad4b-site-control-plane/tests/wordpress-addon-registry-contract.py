#!/usr/bin/env python3
import json
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
cp = repo / "wp-content" / "plugins" / "mad4b-site-control-plane"
main = (cp / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
servers = (cp / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
registry = (cp / "includes" / "class-mad4b-scp-addon-registry.php").read_text(encoding="utf-8")
catalog = json.loads((cp / "config" / "wordpress-addon-catalog.json").read_text(encoding="utf-8"))

if catalog.get("contract") != "mad4b.wordpress-addon-catalog.v1":
    raise SystemExit("add-on catalog contract mismatch")
if not isinstance(catalog.get("addons"), list):
    raise SystemExit("add-on catalog must expose an addons list")
if catalog.get("defaults", {}).get("production_authorized") is not False:
    raise SystemExit("add-on catalog must not authorize Production")

for marker in [
    "const CONTRACT = 'mad4b.wordpress-addon-registry.v1'",
    "const CATALOG_CONTRACT = 'mad4b.wordpress-addon-catalog.v1'",
    "mad4b/addon-registry-status",
    "activated_plugin",
    "deactivated_plugin",
    "upgrader_process_complete",
    "exact_provider_version_required",
    "exact_addon_version_required",
    "mad4b.wordpress-addon-execution-binding.v1",
    "mutation_guard",
    "mad4b_addon_pair_fingerprint_drift",
    "mad4b_addon_certification_fingerprint_drift",
    "pair_fingerprint",
    "certification_fingerprint",
    "compatible_range_is_not_execution_authority",
    "revalidation_required_after_plugin_lifecycle_change",
    "plugin_lifecycle_invalidates_plan_fingerprint",
    "runtime_pair_revalidated_on_every_status_read",
    "production_authorized",
]:
    if marker not in registry:
        raise SystemExit(f"add-on registry missing guard: {marker}")

for forbidden in ["shell_exec(", "proc_open(", "passthru(", "eval("]:
    if forbidden in registry:
        raise SystemExit(f"add-on registry may not contain execution primitive: {forbidden}")

if "class-mad4b-scp-addon-registry.php" not in main or "MAD4B_SCP_Addon_Registry::boot()" not in main:
    raise SystemExit("add-on registry is not loaded/booted")
if "mad4b/addon-registry-status" not in servers:
    raise SystemExit("add-on registry status is not mounted on the read plane")

print("mad4b.wordpress-addon-registry.v1: PASS")
