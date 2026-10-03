from pathlib import Path
root=Path(__file__).resolve().parents[1]
schema=(root/"includes/class-mad4b-scp-schema.php").read_text(encoding="utf-8")
backend=(root/"includes/class-mad4b-scp-catalog-table-backend.php").read_text(encoding="utf-8")
controller=(root/"includes/class-mad4b-scp-catalog-backend-controller.php").read_text(encoding="utf-8")
store=(root/"includes/class-mad4b-scp-catalog-object-store.php").read_text(encoding="utf-8")
transport=(root/"includes/class-mad4b-scp-ability-catalog-transport.php").read_text(encoding="utf-8")

for marker in ["catalog_objects","catalog_generations","catalog_heads","fencing_token","generation_object_key","scope_generation"]:
    if marker not in schema: raise SystemExit("FAIL catalog-table-backend schema missing "+marker)
for marker in ["catalog_table_head_cas_conflict","FOR UPDATE","fencing_token=%d","generation_object_key","object_sha256","retain_until","collect_expired"]:
    if marker not in backend: raise SystemExit("FAIL catalog-table-backend invariant missing "+marker)
for marker in ["authority_backend","shadow_options_directory","cutover(","rollback(","mad4b_catalog_cutover_parity_required","mad4b_catalog_rollback_generation_advanced","fallback_on_table_failure"]:
    if marker not in controller: raise SystemExit("FAIL catalog-backend-controller invariant missing "+marker)
if "MAD4B_SCP_Catalog_Backend_Controller::authority_backend" not in store:
    raise SystemExit("FAIL object store does not route through single-authority controller")
if "shadow_options_directory" not in store:
    raise SystemExit("FAIL options authority does not produce table shadow evidence")
if "new MAD4B_SCP_Catalog_Object_Store( $scope )" not in transport:
    raise SystemExit("FAIL catalog transport does not bind backend to exact authority scope")
if "fallback_on_table_failure'=>false" not in controller.replace(" ", ""):
    raise SystemExit("FAIL table authority fallback policy is not fail-closed")
print("mad4b.catalog-table-backend.contract.v1: PASS")
