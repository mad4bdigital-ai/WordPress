#!/usr/bin/env python3
"""Prevent a future Adapter update from reinstating class-origin admission."""
from pathlib import Path
root = Path(__file__).resolve().parents[1] / 'includes'
catalog = (root / 'class-mad4b-scp-mcp-catalog-diagnostics.php').read_text()
guard = (root / 'class-mad4b-scp-mcp-runtime-conflict-guard.php').read_text()
provenance = (root / 'class-mad4b-scp-mcp-class-provenance.php').read_text()
release = (root / 'class-mad4b-scp-runtime-release-set.php').read_text()
assert "source_provenance_observational" in catalog
assert "MCP_Class_Provenance::BLOCKER" not in catalog
assert "mcp_catalog_builder_unavailable" in catalog
assert "MAD4B_SCP_MCP_Adapter_Compatibility::build_ability_wire" in catalog
assert "mcp_required_tool_preflight_failed" in catalog
assert "Source/hash drift is never a runtime repair trigger" in guard
assert "if ( ! $preventive )" in guard
assert "source_provenance_observational" in guard
assert "source_provenance_observational" in provenance
assert "$out['blocker'] = $out['failure_count'] > 0 ? self::BLOCKER" not in provenance
assert "$out['blocker'] = ''" in provenance
assert "source_provenance_observational" in release
assert "$out['ready'] = $out['provider_contract_ok'];" in release
assert "MCP_Class_Provenance::status" in release
print("MCP_ADAPTER_SOURCE_OBSERVATIONAL: PASS; source not gate, actual builder checks retained")
