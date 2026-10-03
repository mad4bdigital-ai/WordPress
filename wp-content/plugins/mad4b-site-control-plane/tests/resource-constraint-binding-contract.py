from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
files={
 "authorization":(ROOT/"includes/class-mad4b-scp-authorization.php").read_text(encoding="utf-8"),
 "approval":(ROOT/"includes/class-mad4b-scp-approval-tickets.php").read_text(encoding="utf-8"),
 "planning":(ROOT/"includes/class-mad4b-scp-staging-write-planning-guard.php").read_text(encoding="utf-8"),
 "commit_guard":(ROOT/"includes/class-mad4b-scp-execution-commit-guard.php").read_text(encoding="utf-8"),
 "evidence":(ROOT/"includes/class-mad4b-scp-execution-evidence-policy.php").read_text(encoding="utf-8"),
}
def need(name,needle):
    if needle not in files[name]:
        raise SystemExit(f"FAIL resource-constraint-binding: {name} missing {needle}")
need("authorization","resource_set_sha256")
need("authorization","MAD4B_SCP_Resource_Constraint_Set::compile")
need("approval","resource_set_sha256")
need("planning","resource_preparation_evidence")
need("planning","MAD4B_SCP_Resource_Constraint_Set::preparation_evidence")
need("commit_guard","MAD4B_SCP_Resource_Constraint_Set::assert_same")
need("commit_guard","'resource_set'")
need("evidence","resource_set_sha256")
if "resource_set_authorizing' => false" not in files["authorization"]:
    raise SystemExit("FAIL resource-constraint-binding: resource set became authorization")
print("mad4b.resource-constraint-binding.contract.v1: PASS")
