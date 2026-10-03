from pathlib import Path
root=Path(__file__).resolve().parents[1]
schema=(root/"includes/class-mad4b-scp-schema.php").read_text(encoding="utf-8")
backend=(root/"includes/class-mad4b-scp-catalog-table-backend.php").read_text(encoding="utf-8")
controller=(root/"includes/class-mad4b-scp-catalog-backend-controller.php").read_text(encoding="utf-8")
store=(root/"includes/class-mad4b-scp-catalog-object-store.php").read_text(encoding="utf-8")
transport=(root/"includes/class-mad4b-scp-ability-catalog-transport.php").read_text(encoding="utf-8")

for marker in ["catalog_objects","catalog_generations","catalog_heads","fencing_token","generation_object_key","scope_generation"]:
    if marker not in schema: raise SystemExit("FAIL catalog-table-backend schema missing "+marker)
for marker in ["catalog_table_head_cas_conflict","FOR UPDATE","fencing_token=%d","generation_object_key","object_sha256","retain_until","collect_expired","logical_digest_from_head"]:
    if marker not in backend: raise SystemExit("FAIL catalog-table-backend invariant missing "+marker)
for marker in ["storage_scope","authority_backend","shadow_options_directory","cutover(","rollback(","mad4b_catalog_cutover_parity_required","mad4b_catalog_rollback_generation_advanced","persist_state_cas","fallback_on_table_failure"]:
    if marker not in controller: raise SystemExit("FAIL catalog-backend-controller invariant missing "+marker)
if "MAD4B_SCP_Catalog_Backend_Controller::authority_backend" not in store:
    raise SystemExit("FAIL object store does not route through single-authority controller")
if "shadow_options_directory" not in store:
    raise SystemExit("FAIL options authority does not produce table shadow evidence")
if "MAD4B_SCP_Catalog_Backend_Controller::storage_scope" not in store:
    raise SystemExit("FAIL object store does not bind table backend to stable site storage scope")
if "fallback_on_table_failure'=>false" not in controller.replace(" ", ""):
    raise SystemExit("FAIL table authority fallback policy is not fail-closed")
print("mad4b.catalog-table-backend.contract.v1: PASS")

scope_body=controller.split("public static function storage_scope()",1)[1].split("const CONTRACT",1)[0]
if "site_uuid" in scope_body:
    raise SystemExit("FAIL storage scope changes with Site Profile enrollment")
for forbidden in ["get_option(", "wp_cache_get(", "get_transient("]:
    if forbidden in backend:
        raise SystemExit("FAIL table backend depends on WordPress object/option cache: "+forbidden)

for marker in ["catalog_table_capacity_exhausted","capacity_remaining_bytes","current_options_logical_digest","mad4b_catalog_cutover_options_drift","mad4b_catalog_rollback_options_drift","expired_generation_rows","expired_object_rows"]:
    if marker not in backend and marker not in controller:
        raise SystemExit("FAIL catalog backend hardening marker missing: "+marker)

helper=controller.split("private static function current_options_logical_digest()",1)[1].split("private static function record_shadow",1)[0]
for marker in ["wp_cache_delete( MAD4B_SCP_Catalog_Object_Store::DIRECTORY", "wp_cache_delete( 'alloptions'", "wp_cache_delete( (string)$entry['option']"]:
    if marker not in helper:
        raise SystemExit("FAIL catalog transition revalidation can trust stale object cache: "+marker)

for marker in ["retire_scope(", "catalog_table_retirement_head_cas_conflict", "purge_unreferenced_objects", "'orphan_bytes'", "'referenced_bytes'"]:
    if marker not in backend:
        raise SystemExit("FAIL catalog table retirement/accounting invariant missing: "+marker)
for marker in ["retire_after_rollback(", "mad4b_catalog_retirement_reader_drain_required", "'retirement'"]:
    if marker not in controller:
        raise SystemExit("FAIL catalog controller retirement invariant missing: "+marker)

state_body=controller.split("private static function state()",1)[1].split("private static function current_options_logical_digest",1)[0]
for marker in ["wp_cache_delete( self::STATE_OPTION, 'options' )","wp_cache_delete( 'alloptions', 'options' )","wp_cache_delete( 'notoptions', 'options' )"]:
    if marker not in state_body:
        raise SystemExit("FAIL backend authority state trusts persistent object cache: "+marker)
