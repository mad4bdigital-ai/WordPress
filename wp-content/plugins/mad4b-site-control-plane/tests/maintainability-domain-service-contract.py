from pathlib import Path
import json

root=Path(__file__).resolve().parents[1]
files={
 "authorization":(root/"includes/class-mad4b-scp-authorization.php").read_text(encoding="utf-8"),
 "durable":(root/"includes/class-mad4b-scp-durable-execution.php").read_text(encoding="utf-8"),
 "remote":(root/"includes/class-mad4b-scp-remote-operation-parity.php").read_text(encoding="utf-8"),
}
required={
 "authorization":["MAD4B_SCP_Authorization_Target_Fingerprint::fingerprint","class-mad4b-scp-authorization-target-fingerprint.php"],
 "durable":["MAD4B_SCP_Durable_DB_Boundary::restore_epoch_preflight","MAD4B_SCP_Durable_DB_Boundary::commit_owned_transaction","class-mad4b-scp-durable-db-boundary.php"],
 "remote":["MAD4B_SCP_Managed_Skills_Lease::refresh","MAD4B_SCP_Managed_Skills_Lease::acquire","MAD4B_SCP_Managed_Skills_Lease::release","class-mad4b-scp-managed-skills-lease.php"],
}
for name,markers in required.items():
 for marker in markers:
  if marker not in files[name]:
   raise SystemExit("MAINTAINABILITY_DELEGATION_MISSING:"+name+":"+marker)
if "canonicalize_target_value" in files["authorization"]:
 raise SystemExit("AUTHORIZATION_TARGET_CANONICALIZATION_REABSORBED")
if "private static function compare_and_swap_option" in files["remote"]:
 raise SystemExit("REMOTE_SKILLS_LEASE_CAS_REABSORBED")
policy=json.loads((root/"config/architecture-maintainability-policy.json").read_text(encoding="utf-8"))
facades=policy["size_budgets"]["legacy_facades"]
limits={
 "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-remote-operation-parity.php":2200,
 "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-durable-execution.php":1100,
 "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-authorization.php":950,
}
for path,limit in limits.items():
 if int(facades.get(path,10**9))>limit:
  raise SystemExit("MAINTAINABILITY_RATCHET_NOT_TIGHTENED:"+path)
print("mad4b.maintainability-domain-service-decomposition.v1: PASS")
