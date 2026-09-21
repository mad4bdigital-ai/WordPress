#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
lifecycle = (root / "includes" / "class-mad4b-scp-plugin-lifecycle.php").read_text(encoding="utf-8")
abilities = (root / "includes" / "class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
policy = (root / "includes" / "class-mad4b-scp-policy.php").read_text(encoding="utf-8")
servers = (root / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

for marker in (
    "mad4b.plugin-lifecycle-plan.v1",
    "mad4b/plugin-lifecycle-plan",
    "expected_state_sha256",
    "expected_plan_sha256",
    "write_binding",
    "mad4b_plugin_lifecycle_plan_changed",
    "RequiresPlugins",
    "active_dependents",
    "required_dependencies",
    "plugin_main_file_sha256",
    "state_sha256",
    "plan_sha256",
    "mutation_performed",
    "authority_created",
):
    if marker not in lifecycle:
        raise SystemExit(f"plugin lifecycle planner missing guard: {marker}")

for marker in (
    "MAD4B_SCP_Plugin_Lifecycle::mutation_preflight",
    "MAD4B_SCP_Plugin_Lifecycle::verify_state",
    "before_state_sha256",
    "after_state_sha256",
    "readback_verified",
    "expected_state_sha256",
    "expected_plan_sha256",
):
    if marker not in abilities:
        raise SystemExit(f"plugin lifecycle mutation path missing evidence guard: {marker}")

for marker in (
    "MAD4B_MCP_PLUGIN_LIFECYCLE_ENABLED",
    "mad4b_scp_plugin_lifecycle_allowlist",
    "plugin_lifecycle_protected",
):
    if marker not in policy:
        raise SystemExit(f"plugin lifecycle policy guard missing: {marker}")

if "mad4b_control_plane_dependency_protected" not in abilities:
    raise SystemExit("Control Plane / MCP Adapter deactivation protection must remain defense-in-depth")
if "mad4b/plugin-lifecycle-plan" not in servers:
    raise SystemExit("plugin lifecycle plan must be exposed on a governed read surface")
if "class-mad4b-scp-plugin-lifecycle.php" not in main or "MAD4B_SCP_Plugin_Lifecycle::boot();" not in main:
    raise SystemExit("main plugin does not load and boot plugin lifecycle governance")

print("mad4b.plugin-lifecycle-governance.v1: PASS")
