#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
dep=(root/'includes/class-mad4b-scp-dependency-manager.php').read_text(encoding='utf-8')
provider=(root/'includes/class-mad4b-scp-provider-contracts.php').read_text(encoding='utf-8')
conn=(root/'includes/class-mad4b-scp-connection-status.php').read_text(encoding='utf-8')
fleet=(root/'includes/class-mad4b-scp-provider-closure-matrix.php').read_text(encoding='utf-8')
assert "adapter_read_runtime_ready(" in dep
assert "adapter_read_runtime_observation()" in dep
assert "'read_ready' => $read_ready" in dep
assert "'write_certification_ready' => empty( $hard_blockers )" in dep
assert "'mcp_adapter_version_drift'" in dep
assert "installed_mcp_adapter_integrity" in dep
assert "runtime_from_official_plugin" in dep
assert "read_continuity_status(" in provider
assert "mutation_allowed(" in provider
assert "mutation_guard(" in provider
assert "mcp_adapter_not_certified" in conn and "certification_blockers" in conn
assert "adapter_read_runtime_observation()" in conn
assert "version_change_blocks_unrelated_plugins" in fleet
assert "exact_write_recertification_required_on_contract_change" in fleet
assert "unknown_plugin_write_default' => 'deny'" in fleet
print("PROVIDER_VERSION_NEUTRAL_SOURCE_CONTRACT_PASS")
