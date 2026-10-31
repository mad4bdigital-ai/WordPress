#!/usr/bin/env python3
"""Static guard: dependency versions are informational and isolation starts ON.

This test does not assert a live OAuth handshake or authorize WordPress writes.
"""
import json
from pathlib import Path

plugin = Path(__file__).resolve().parents[1]
inc = plugin / "includes"
config = plugin / "config"
read = lambda name: (inc / name).read_text(encoding="utf-8")

provider = read("class-mad4b-scp-provider-contracts.php")
dependency = read("class-mad4b-scp-dependency-manager.php")
connection = read("class-mad4b-scp-connection-status.php")
profile = read("class-mad4b-scp-site-profile.php")
admin = read("class-mad4b-scp-site-profile-admin.php")
release = json.loads((config / "runtime-release-policy.json").read_text(encoding="utf-8"))

assert "mcp_adapter_dependency_status" in provider
assert "'version_gate_enabled' => false" in provider
assert "'mutation_certified' => false" in provider
assert "return 'mcp_adapter' !== sanitize_key" in provider
assert "mad4b_mcp_adapter_dependency_not_mutation_authority" in provider
assert "'mcp_adapter_version_drift'" not in dependency
assert "'mcp_adapter_integrity_mismatch'" not in dependency
assert "'dependency_version_gate_enabled' => false" in dependency
assert "'provider_code_update_requires_exact_package_integrity' => true" in dependency
assert "'mcp_adapter_dependency_incompatible'" in connection
assert "mcp_adapter_not_certified" not in connection
assert "'mcp_adapter_dependency_compatible'" in connection
assert "array_key_exists( 'provider_isolation_enabled', $input )" in profile
assert "provider_isolation_explicit_opt_out" in profile
assert "'provider_isolation' => ! $provider_isolation_explicit_opt_out" in profile
assert "'provider_isolation_legacy_default_pending_persist'" in profile
assert "'provider_isolation' === $feature" in profile
assert "MAD4B_SCP_Site_Profile::provider_isolation_enabled()" in admin
assert "array_key_exists( $input_key, $input )" in admin
assert release["dependency_admission"]["mode"] == "capability_first_any_installed_version"
assert release["dependency_admission"]["installed_version_allowlist_required"] is False
assert release["dependency_admission"]["installed_version_drift_blocks_connection"] is False
assert release["dependency_admission"]["never_grants_owner_or_write_authority"] is True
assert release["authority"]["production_auto_apply"] is False
assert release["pair_certification_required"] is True
print("MCP_DEPENDENCY_ISOLATION_POLICY: PASS")
