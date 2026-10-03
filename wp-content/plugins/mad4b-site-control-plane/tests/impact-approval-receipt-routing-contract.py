from pathlib import Path
root=Path(__file__).resolve().parents[1]
approval=(root/"includes/class-mad4b-scp-approval-tickets.php").read_text(encoding="utf-8")
auth=(root/"includes/class-mad4b-scp-authorization.php").read_text(encoding="utf-8")
graph=(root/"includes/class-mad4b-scp-authorization-decision-graph.php").read_text(encoding="utf-8")
receipt=(root/"includes/class-mad4b-scp-execution-receipt.php").read_text(encoding="utf-8")
router=(root/"includes/class-mad4b-scp-semantic-intent-router.php").read_text(encoding="utf-8")

for marker in ["'exact_input_sha256'","'resource_set_sha256'","'dependency_generation_sha256'","'impact_sha256'","'approval_impact_binding_sha256'"]:
    if marker not in approval:
        raise SystemExit("FAIL impact approval payload missing "+marker)
if approval.count("canonical_payload_hash(") < 3:
    raise SystemExit("FAIL approval validation does not recompute canonical impact-bound payload")
for marker in ["probe_mutation(", "'approval_impact_binding_sha256'", "'approval_dependency_generation_sha256'", "'approval_impact_sha256'", "'policy_decision_sha256'"]:
    if marker not in auth:
        raise SystemExit("FAIL authorization impact/decision binding missing "+marker)
for marker in ["PASS","FAIL","NOT_EVALUATED","evidence_refs","redacted","decision_sha256"]:
    if marker not in graph:
        raise SystemExit("FAIL authorization decision graph missing "+marker)
for marker in ["approval_required","execution-receipt:v1:","mad4b_execution_receipt_identity_invalid","cryptographic_signature_verified"]:
    if marker not in receipt:
        raise SystemExit("FAIL execution receipt invariant missing "+marker)
if "SIGNATURE_STATE = 'crypto_profile_required'" not in receipt or "'signature' => ''" not in receipt:
    raise SystemExit("FAIL execution receipt must explicitly remain signature-profile pending until T3755 closes")
for marker in ["execution_input","probe_mutation","authority_eligible","semantic_exact_execution_input_required","semantic_executor_not_mounted_on_governed_surface","authority_created'=>false","authorizing'=>false"]:
    if marker not in router.replace(" ", "") and marker not in router:
        raise SystemExit("FAIL semantic routing authority constraint missing "+marker)
print("mad4b.impact-approval-receipt-routing.contract.v1: PASS")
