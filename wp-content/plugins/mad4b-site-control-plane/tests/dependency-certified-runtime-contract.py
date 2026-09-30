from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
dep=(ROOT/'includes/class-mad4b-scp-dependency-manager.php').read_text(encoding='utf-8')
for marker in ("MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter', $runtime_loaded )", "$runtime_certified", "'mcp_adapter_runtime_not_certified'", "'certified_loaded_runtime'", "'runtime_contract' => $runtime_contract"):
    assert marker in dep, marker
assert "elseif ( ! $active ) $hard_blockers[] = 'mcp_adapter_inactive';" not in dep
print('mad4b.dependency-certified-runtime.v1: PASS')
