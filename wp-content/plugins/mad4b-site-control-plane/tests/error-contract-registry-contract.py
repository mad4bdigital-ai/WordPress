from pathlib import Path
import json

root=Path(__file__).resolve().parents[1]
registry=json.loads((root/"config/error-reason-codes.json").read_text(encoding="utf-8"))
runtime=(root/"includes/class-mad4b-scp-error-contract-registry.php").read_text(encoding="utf-8")
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8")

if registry.get("contract")!="mad4b.error-reason-code-registry.v1":
    raise SystemExit("ERROR_REGISTRY_CONTRACT_INVALID")
if registry.get("schema_version")!=1 or registry.get("response_contract")!="mad4b.error-response.v1":
    raise SystemExit("ERROR_RESPONSE_SCHEMA_INVALID")
evolution=registry.get("evolution_policy") or {}
for key in ("additive_fields_backward_compatible","existing_reason_code_semantics_immutable","removals_require_major_schema_version","retry_semantics_are_machine_readable"):
    if evolution.get(key) is not True:
        raise SystemExit("ERROR_EVOLUTION_POLICY_MISSING:"+key)
families=registry.get("families")
if not isinstance(families,list) or not families:
    raise SystemExit("ERROR_FAMILY_REGISTRY_MISSING")
prefixes=[row.get("prefix") for row in families if isinstance(row,dict)]
if len(prefixes)!=len(set(prefixes)) or "mad4b_" not in prefixes:
    raise SystemExit("ERROR_FAMILY_PREFIXES_INVALID")
for marker in (
    "class MAD4B_SCP_Error_Contract_Registry",
    "add_filter( 'wp_register_ability_args'",
    "mad4b_error",
    "message_is_contractual",
    "registered_exact",
    "propagate_trusted_execution_boundary",
):
    if marker not in runtime:
        raise SystemExit("ERROR_RUNTIME_CONTRACT_MISSING:"+marker)
for marker in (
    "class-mad4b-scp-error-contract-registry.php",
    "MAD4B_SCP_Error_Contract_Registry::boot()",
):
    if marker not in main:
        raise SystemExit("ERROR_RUNTIME_NOT_BOOTSTRAPPED:"+marker)
print("mad4b.error-reason-code-registry.v1: PASS")
