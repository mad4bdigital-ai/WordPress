from pathlib import Path
root=Path(__file__).resolve().parents[1]
schema=(root/"includes/class-mad4b-scp-schema.php").read_text()
plugin=(root/"mad4b-site-control-plane.php").read_text()
canonical=(root/"includes/class-mad4b-scp-canonicalization.php").read_text()
context=(root/"includes/class-mad4b-scp-operation-context.php").read_text()
journal=(root/"includes/class-mad4b-scp-operation-journal.php").read_text()
adapter=(root/"includes/adapters/class-mad4b-scp-dynamic-content-adapter.php").read_text()

def req(text, marker):
    if marker not in text: raise SystemExit("missing: "+marker)

for marker in ["const VERSION = 12","operation_events","operation_heads","operation_sequence","event_sha256","mad4b-schema-v12"]:
    req(schema, marker)
for marker in ["class-mad4b-scp-canonicalization.php","class-mad4b-scp-operation-context.php","class-mad4b-scp-operation-journal.php"]:
    req(plugin, marker)
for marker in ["mad4b.dynamic-canonicalization.v1","mad4b:' . $contract","mad4b_canonical_float_denied","SORT_STRING"]:
    req(canonical, marker)
for marker in ["operation_key","operation_id","operation_binding_sha256","hard_deadline_at","dynamic-operation-binding:v1"]:
    req(context, marker)
for marker in ["append-only","operation_started","previous_event_sha256","FOR UPDATE","operation_head_cas_failed","MAX_METADATA_BYTES","REDACTED"]:
    req(journal, marker)
# rc.83 characterization: preserve the public surface before decomposition.
for marker in ["mad4b/content-model-discover","mad4b/content-bundle-readback","mad4b/content-orchestration-plan","mad4b/content-apply-bundle","mad4b/content-pipeline-status","mad4b/content-pipeline-settings-update","mad4b.rollback.dynamic-content-bundle.v1"]:
    req(adapter, marker)
print("mad4b.dynamic-runtime-hardening.contract.v1: PASS")
