from pathlib import Path
root=Path(__file__).resolve().parents[1]
ids=(root/"includes/class-mad4b-scp-identifiers.php").read_text(encoding="utf-8")
journal=(root/"includes/class-mad4b-scp-operation-journal.php").read_text(encoding="utf-8")
jobs=(root/"includes/class-mad4b-scp-content-jobs.php").read_text(encoding="utf-8")
evidence=(root/"includes/class-mad4b-scp-execution-evidence-policy.php").read_text(encoding="utf-8")
config=(root/"config/identifier-policies.json").read_text(encoding="utf-8")
for marker in ["approval_ticket_id","operation_id","job_id","receipt_id","provider_id","legacy_opaque_v1","historical_identity_preserved","rewrite_allowed"]:
 if marker not in ids and marker not in config: raise SystemExit("FAIL identifier-policy missing "+marker)
for marker in ["operation_lookup","operation_id_for_write","BINARY operation_id=BINARY","historical_identity_preserved","rewrite_allowed"]:
 if marker not in journal: raise SystemExit("FAIL operation-journal identifier binding missing "+marker)
if "MAD4B_SCP_Identifiers::job_id" not in jobs: raise SystemExit("FAIL ContentJob identifier policy binding missing")
if "receipt_id_from_sha256" not in evidence: raise SystemExit("FAIL execution receipt identifier policy binding missing")
print("mad4b.identifier-policy.contract.v1: PASS")
