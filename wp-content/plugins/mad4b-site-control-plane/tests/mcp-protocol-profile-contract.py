import json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
config=json.loads((root/"config/mcp-protocol-profiles.json").read_text(encoding="utf-8"))
impl=(root/"includes/class-mad4b-scp-mcp-protocol-profile.php").read_text(encoding="utf-8")
projection=(root/"includes/class-mad4b-scp-chatgpt-tool-projection.php").read_text(encoding="utf-8")
compat=(root/"includes/class-mad4b-scp-mcp-client-compatibility.php").read_text(encoding="utf-8")
expected=["2025-11-25","2025-06-18","2024-11-05"]
if config.get("contract")!="mad4b.mcp-protocol-profile-catalog.v1": raise SystemExit("FAIL protocol catalog contract")
if config.get("certified_adapter",{}).get("version")!="0.6.1": raise SystemExit("FAIL protocol catalog adapter version")
if config.get("supported_protocol_versions")!=expected: raise SystemExit("FAIL exact v0.6.1 protocol versions")
for version in expected:
    p=config.get("profiles",{}).get(version,{})
    if p.get("tools_list_changed") is not False or p.get("refresh_semantics")!="pull_tools_list_then_reconnect_if_cached" or p.get("unknown_feature_policy")!="deny":
        raise SystemExit("FAIL protocol behavior profile "+version)
for marker in ["mad4b_mcp_protocol_version_uncertified","mad4b_mcp_protocol_feature_uncertified","fallback_protocol_negotiation_used","projection_refresh(","successor_dual_protocol_regression_required"]:
    if marker not in impl: raise SystemExit("FAIL protocol implementation marker "+marker)
for marker in ["ISOLATION_SCOPE = 'site_global'","CONTENTION_POLICY = 'optimistic_cas_single_winner'","fixed_dispatch_correctness_independent","protocol_profile"]:
    if marker not in projection: raise SystemExit("FAIL projection isolation marker "+marker)
if "certified_protocol_versions" not in compat or "protocol_profile" not in compat:
    raise SystemExit("FAIL compatibility manifest/status protocol evidence")
print("mad4b.mcp-protocol-profile.contract.v1: PASS")
