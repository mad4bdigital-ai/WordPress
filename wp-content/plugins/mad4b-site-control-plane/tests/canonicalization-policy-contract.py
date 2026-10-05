from pathlib import Path
root=Path(__file__).resolve().parents[1]
canon=(root/"includes/class-mad4b-scp-canonicalization.php").read_text(encoding="utf-8")
desc=(root/"includes/class-mad4b-scp-capability-descriptor-registry.php").read_text(encoding="utf-8")
op=(root/"includes/class-mad4b-scp-operation-registry.php").read_text(encoding="utf-8")
resource=(root/"includes/class-mad4b-scp-resource-constraint-set.php").read_text(encoding="utf-8")
for marker in ["POLICY_CONTRACT","ability_name(","semantic_operation_id(","resource_identifier(","relative_path(","header_name(","url(","mad4b_canonical_hidden_unicode_denied"]:
    if marker not in canon: raise SystemExit("FAIL canonicalization policy missing "+marker)
if "MAD4B_SCP_Canonicalization::ability_name( $name )" not in desc:
    raise SystemExit("FAIL capability descriptor does not enforce canonical ability identity")
for marker in ["semantic_operation_id( $id )","ability_name( $planner )","ability_name( $executor )","semantic_operation_id( $operation_id )"]:
    if marker not in op: raise SystemExit("FAIL operation registry canonical binding missing "+marker)
for marker in ["MAD4B_SCP_Canonicalization::ability_name","MAD4B_SCP_Canonicalization::resource_identifier","MAD4B_SCP_Canonicalization::relative_path"]:
    if marker not in resource: raise SystemExit("FAIL resource compiler canonical binding missing "+marker)
print("mad4b.canonicalization-policy.contract.v1: PASS")
