import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
config = json.loads((root / "config/mcp-protocol-profiles.json").read_text(encoding="utf-8"))
release_policy = json.loads((root / "config/runtime-release-policy.json").read_text(encoding="utf-8"))
providers = json.loads((root / "config/certified-provider-profiles.json").read_text(encoding="utf-8"))
impl = (root / "includes/class-mad4b-scp-mcp-protocol-profile.php").read_text(encoding="utf-8")
projection = (root / "includes/class-mad4b-scp-chatgpt-tool-projection.php").read_text(encoding="utf-8")
compat = (root / "includes/class-mad4b-scp-mcp-client-compatibility.php").read_text(encoding="utf-8")

target = str(release_policy.get("target_adapter_version") or "")
provider = ((providers.get("providers") or {}).get("mcp_adapter") or {}).get(target) or {}
transport = provider.get("transport_compatibility") or {}
legacy = [str(v) for v in transport.get("legacy_session_revisions") or []]
modern = [str(v) for v in transport.get("modern_per_request_revisions") or []]
expected = modern + list(reversed(legacy))

if config.get("contract") != "mad4b.mcp-protocol-profile-catalog.v1":
    raise SystemExit("FAIL protocol catalog contract")
adapter = config.get("certified_adapter") or {}
if not target or adapter.get("version") != target:
    raise SystemExit("FAIL protocol catalog/runtime release Adapter binding")
if adapter.get("version_source") != "runtime_release_policy.target_adapter_version":
    raise SystemExit("FAIL protocol Adapter version source")
if adapter.get("compatibility_source") != "certified-provider-profiles.mcp_adapter.<version>.transport_compatibility":
    raise SystemExit("FAIL protocol compatibility source")
if config.get("supported_protocol_versions") != expected:
    raise SystemExit(f"FAIL exact protocol versions: {config.get('supported_protocol_versions')!r} expected={expected!r}")

for version in expected:
    p = (config.get("profiles") or {}).get(version) or {}
    expected_lifecycle = "per_request_revision" if version in modern else "initialize_session"
    if p.get("lifecycle") != expected_lifecycle:
        raise SystemExit("FAIL protocol lifecycle profile " + version)
    if p.get("tools_list_changed") is not False or p.get("refresh_semantics") != "pull_tools_list_then_reconnect_if_cached" or p.get("unknown_feature_policy") != "deny":
        raise SystemExit("FAIL protocol behavior profile " + version)

for marker in [
    "CERTIFIED_ADAPTER_VERSION_SOURCE = 'runtime_release_policy.target_adapter_version'",
    "certified-provider-profiles.json",
    "modern_per_request_revisions",
    "legacy_session_revisions",
    "mad4b_mcp_protocol_version_uncertified",
    "mad4b_mcp_protocol_feature_uncertified",
    "fallback_protocol_negotiation_used",
    "projection_refresh(",
    "successor_dual_protocol_regression_required",
]:
    if marker not in impl:
        raise SystemExit("FAIL protocol implementation marker " + marker)
if "CERTIFIED_ADAPTER_VERSION = '0.6.1'" in impl:
    raise SystemExit("FAIL stale hardcoded Adapter 0.6.1 protocol authority")
for marker in ["ISOLATION_SCOPE = 'site_global'", "CONTENTION_POLICY = 'optimistic_cas_single_winner'", "fixed_dispatch_correctness_independent", "protocol_profile"]:
    if marker not in projection:
        raise SystemExit("FAIL projection isolation marker " + marker)
if "certified_protocol_versions" not in compat or "protocol_profile" not in compat:
    raise SystemExit("FAIL compatibility manifest/status protocol evidence")
print("mad4b.mcp-protocol-profile.contract.v2: PASS")
