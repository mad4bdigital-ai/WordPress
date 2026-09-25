#!/usr/bin/env python3
import json
from pathlib import Path

repo = Path(__file__).resolve().parents[4]
cp = repo / "wp-content" / "plugins" / "mad4b-site-control-plane"
policy_path = cp / "config" / "wordpress-extension-strategy.json"
portable_skill = repo / "plugins" / "mad4b-wordpress" / "skills" / "wordpress-extension-strategy" / "SKILL.md"
seed_skill = cp / "skill-seeds" / "wordpress-extension-strategy" / "SKILL.md"

policy = json.loads(policy_path.read_text(encoding="utf-8"))
if policy.get("contract") != "mad4b.wordpress-extension-strategy.v1":
    raise SystemExit("extension strategy contract mismatch")
if policy.get("decision_order") != ["REUSE", "ADDON", "FORK", "NATIVE"]:
    raise SystemExit("decision order must remain REUSE -> ADDON -> FORK -> NATIVE")
if policy.get("default_decision") != "REUSE":
    raise SystemExit("reuse must remain the default decision")

if policy.get("decision_order_semantics") != "evaluation_order_not_mandatory_preference":
    raise SystemExit("decision order must be evaluation order, not an absolute ranking")
reuse = policy.get("decisions", {}).get("REUSE", {})
if reuse.get("automatic_acceptance") is not False:
    raise SystemExit("REUSE may not imply automatic acceptance")
reuse_required = {"exact_provider_identity","data_ownership","authority_exposure","backup_impact","update_policy","certification_level","portability_exit_path"}
if not reuse_required.issubset(set(reuse.get("requires", []))):
    raise SystemExit("REUSE governance requirements are incomplete")

if policy.get("production_authorized") is not False:
    raise SystemExit("extension strategy must never authorize Production")

addon = policy.get("decisions", {}).get("ADDON", {})
required = {
    "base_provider_identity",
    "compatible_version_range",
    "extension_points",
    "data_ownership",
    "authority_impact",
    "rollback_or_disable_path",
    "runtime_certification",
    "compatibility_test",
    "portability_exit_path",
    "supply_chain","network_access","multisite","performance_budget","observability","failure_policy","release_ring","certified_pairs",
}
if not required.issubset(set(addon.get("requires", []))):
    raise SystemExit("ADDON decision is missing mandatory lifecycle/certification fields")
for key in [
    "vendor_patch_allowed",
    "production_authority_inherited",
]:
    if addon.get(key) is not False:
        raise SystemExit(f"ADDON must fail closed for {key}")
for key in [
    "exact_provider_version_runtime_certification_required",
    "fail_closed_on_unknown_provider_version",
    "fail_closed_on_missing_extension_point",
    "compatible_range_is_candidate_only",
    "exact_provider_addon_pair_required_for_mutation",
    "plugin_update_invalidates_certification",
    "plugin_deactivation_invalidates_certification",
    "execution_commit_revalidation_required",
    "data_governance_required_for_external_data_transfer",
    "direct_mutation_escape_paths_forbidden",
    "release_ring_required",
]:
    if addon.get(key) is not True:
        raise SystemExit(f"ADDON must require {key}")

generic = policy.get("generic_quality_tooling", {})
if generic.get("strategy") != "reuse-maintained-tools" or generic.get("runtime_dependency") is not False:
    raise SystemExit("generic quality tooling must be reused in CI/disposable environments, not embedded as runtime dependency")

expected_tools = {"php-lint","WordPressCS","PHPCompatibilityWP","PHPStan","WordPress Plugin Check"}
if not expected_tools.issubset(set(generic.get("recommended", []))):
    raise SystemExit("generic quality tooling baseline is incomplete")
coverage = generic.get("coverage", {})
if coverage.get("WordPress Plugin Check", {}).get("mode") != "blocking_observational":
    raise SystemExit("Plugin Check must remain blocking evidence without becoming release authority")
if coverage.get("PHPStan", {}).get("mode") != "advisory_pending_lock":
    raise SystemExit("PHPStan must not become authoritative before reproducible WordPress stubs/analyzer locking")
if generic.get("unknown_external_tool_state") != "UNKNOWN_NOT_PASS":
    raise SystemExit("unknown external tool state may not be promoted to PASS")
if generic.get("production_authorized") is not False:
    raise SystemExit("generic quality tooling may not authorize Production")

expected_guards = {
    "ability_surface_consistency",
    "authority_negative_space",
    "immutable_lineage",
    "provider_profile_and_certification_binding",
    "package_source_manifest_parity",
    "release_root_trust",
    "recovery_and_rollback_semantics",
}
if not expected_guards.issubset(set(policy.get("mad4b_custom_guards", []))):
    raise SystemExit("MAD4B-specific guard set is incomplete")

if policy.get("addon_manifest_contract") != "mad4b.wordpress-addon-manifest.v1":
    raise SystemExit("addon manifest contract must be stable")
manifest_fields = {
    "addon_id","plugin_file","base_provider","compatible_versions","extension_points","capabilities",
    "data_ownership","authority_impact","rollback","certification","tests","portability",
    "supply_chain","network_access","multisite","performance_budget","observability","failure_policy","release_ring","certified_pairs",
}
if not manifest_fields.issubset(set(policy.get("addon_manifest_required_fields", []))):
    raise SystemExit("addon manifest fields are incomplete")

if not portable_skill.is_file() or not seed_skill.is_file():
    raise SystemExit("extension strategy Skill is not packaged in both portable and canonical seed locations")
if portable_skill.read_bytes() != seed_skill.read_bytes():
    raise SystemExit("portable extension strategy Skill drifted from canonical seed")
skill = portable_skill.read_text(encoding="utf-8")
for marker in ["REUSE, ADDON, FORK, or NATIVE", "Add-on-first customization pattern", "never patch vendor files at runtime"]:
    if marker not in skill:
        raise SystemExit(f"extension strategy Skill missing marker: {marker}")

security = policy.get("addon_security", {})
if security.get("php_is_not_a_sandbox") is not True or security.get("manifest_is_not_a_security_boundary") is not True:
    raise SystemExit("add-on security model must not treat PHP or the manifest as a sandbox")
if policy.get("multisite_default") != "unsupported_unless_manifest_explicit":
    raise SystemExit("multisite default must fail closed unless explicitly supported")
if policy.get("supply_chain", {}).get("unknown_security_scan") != "UNKNOWN_NOT_PASS":
    raise SystemExit("unknown security scan state must not be promoted to PASS")
print("mad4b.wordpress-extension-strategy.v1: PASS")
