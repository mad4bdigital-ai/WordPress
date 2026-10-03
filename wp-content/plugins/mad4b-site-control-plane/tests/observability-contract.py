import json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
impl=(root/"includes/class-mad4b-scp-observability.php").read_text(encoding="utf-8")
cfg=json.loads((root/"config/observability-slo-profiles.json").read_text(encoding="utf-8"))
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8")
if cfg.get("contract")!="mad4b.observability-slo-profile.v1": raise SystemExit("FAIL observability profile contract")
required_stages=["discovery","preparation","authorization","approval","commit_guard","provider_execution","readback","reconciliation","catalog_rebuild","network_fanout"]
if sorted(cfg.get("stages",{}))!=sorted(required_stages): raise SystemExit("FAIL observability stage registry")
for marker in [
 "TRACE_CONTRACT","parse_traceparent(","fork_for_tenant(","causal_link_sha256",
 "cross_tenant_trace_id_reused","bounded_attributes(","sensitive_key(","raw_provider_payloads",
 "record_stage(","quantiles(","slo_status(","burn_rate",
 "optional_telemetry_failure_blocks_safe_reads","mandatory_audit_or_execution_evidence_failure_blocks_governed_write"
]:
 if marker not in impl and marker not in json.dumps(cfg): raise SystemExit("FAIL observability invariant missing "+marker)
for forbidden in ["$_SERVER['HTTP_AUTHORIZATION']","getallheaders()","file_get_contents('php://input')"]:
 if forbidden in impl: raise SystemExit("FAIL observability captures raw request secrets/body: "+forbidden)
if "class-mad4b-scp-observability.php" not in main: raise SystemExit("FAIL observability runtime not bootstrapped")
print("mad4b.observability.contract.v1: PASS")

gateway=(root/"includes/class-mad4b-scp-unified-capability-gateway.php").read_text(encoding="utf-8")
commit_guard=(root/"includes/class-mad4b-scp-execution-commit-guard.php").read_text(encoding="utf-8")
for marker in ["run_stage(", "'' === trim( (string) $tenant_scope )", "Fan-out children therefore cannot", "result_class"]:
    if marker not in impl: raise SystemExit("FAIL observability runtime integration primitive missing "+marker)
for marker in ["run_stage( 'discovery'", "run_stage( 'preparation'"]:
    if marker not in gateway: raise SystemExit("FAIL gateway observability stage missing "+marker)
for marker in ["run_stage( 'commit_guard'", "capture_impl(", "revalidate_impl("]:
    if marker not in commit_guard: raise SystemExit("FAIL commit-guard observability stage missing "+marker)
