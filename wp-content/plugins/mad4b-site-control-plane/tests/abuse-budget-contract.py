from pathlib import Path
root=Path(__file__).resolve().parents[1]
abuse=(root/"includes/class-mad4b-scp-abuse-budget.php").read_text(encoding="utf-8")
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8")
abilities=(root/"includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
prep=(root/"includes/class-mad4b-scp-preparation-receipt.php").read_text(encoding="utf-8")
config=(root/"config/abuse-budget.json").read_text(encoding="utf-8")
for marker in ["mad4b.abuse-budget.v1","metric_buckets","ON DUPLICATE KEY UPDATE","site_fingerprint","principal_fingerprint","client_fingerprint","mad4b_rate_limit_exceeded","mad4b_abuse_depth_exceeded","mad4b_abuse_schema_properties_exceeded","mad4b_abuse_search_complexity_denied","mad4b_abuse_metadata_bytes_exceeded","rate_storage_error","blind_retry_allowed","recovery_read_ability","bounded_repair_ability","mad4b/session-safe-diagnostics","mad4b/query-monitor-db-attribution-bootstrap","retry_original_operation_after_topology_repair","cause_code","topology_blockers"]:
    if marker not in abuse: raise SystemExit("FAIL abuse-budget invariant missing "+marker)
if "class-mad4b-scp-abuse-budget.php" not in main: raise SystemExit("FAIL abuse-budget bootstrap missing")
if "new WP_Error( 'mad4b_abuse_rate_storage_unavailable', 'Rate-limit writer topology is unavailable.'" in abuse:
    raise SystemExit("FAIL topology storage error regressed to opaque dead-end response")
for fn in ["tool_discover","developer_discover","write_discover","enrollment_discover","read_execute","developer_execute","write_execute","enrollment_execute"]:
    start=abilities.find("function "+fn)
    if start<0: raise SystemExit("FAIL ability function missing "+fn)
    body=abilities[start:start+1400]
    surface="discovery" if "discover" in fn else "execute"
    if "MAD4B_SCP_Abuse_Budget::admit( '"+surface+"'" not in body:
        raise SystemExit("FAIL "+fn+" missing "+surface+" abuse admission")
if "MAD4B_SCP_Abuse_Budget::admit( 'prepare'" not in prep:
    raise SystemExit("FAIL signed preparation does not consume prepare abuse budget")
for marker in ['"discovery"','"prepare"','"execute"','"requests_per_window"','"max_schema_properties"','"max_search_terms"']:
    if marker not in config: raise SystemExit("FAIL abuse-budget config missing "+marker)
print("mad4b.abuse-budget.contract.v1: PASS")
