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
for marker in ["operation_started","previous_event_sha256","FOR UPDATE","operation_head_cas_failed","MAX_METADATA_BYTES","MAX_EVENTS_PER_OPERATION","REDACTED"]:
    req(journal, marker)
# rc.83 characterization: preserve the public surface before decomposition.
for marker in ["mad4b/content-model-discover","mad4b/content-bundle-readback","mad4b/content-orchestration-plan","mad4b/content-apply-bundle","mad4b/content-pipeline-status","mad4b/content-pipeline-settings-update","mad4b.rollback.dynamic-content-bundle.v1"]:
    req(adapter, marker)
print("mad4b.dynamic-runtime-hardening.contract.v1: PASS")

for marker in ["expected_operation_binding_sha256","operation_binding_sha256","MAD4B_SCP_Operation_Context::create","MAD4B_SCP_Operation_Journal::begin","mutation_lock_acquired","operation_completed","operation_failed","mad4b_operation_binding_drift"]:
    req(adapter, marker)

for marker in ["mad4b.dynamic-operation-trace.v1","chain_valid","orphan_candidate","hard_deadline_exceeded"]:
    req(journal, marker)

recovery=(root/"includes/class-mad4b-scp-dynamic-recovery.php").read_text()
recovery_adapter=(root/"includes/adapters/class-mad4b-scp-dynamic-recovery-adapter.php").read_text()
registry=(root/"includes/class-mad4b-scp-adapter-registry.php").read_text()
for marker in ["journal_head_sha256","current_state_sha256","provider_state_digest","pipeline_settings_sha256","policy_sha256","expires_at","ai_approval_allowed'=>false"]:
    req(recovery, marker)
for marker in ["mad4b/content-operation-metrics","mad4b/content-operation-status","mad4b/content-operation-trace","mad4b/content-recovery-inspect","mad4b/content-recovery-plan","Dynamic Content Recovery Inspect","Dynamic Content Recovery Plan","status_read","trace_read","metrics_read"]:
    req(recovery_adapter, marker)
for marker in ["class-mad4b-scp-dynamic-recovery-adapter.php","MAD4B_SCP_Dynamic_Recovery_Adapter"]:
    req(registry, marker)

# Guard against accidental adapter text expansion during patch generation.
if len(adapter.encode("utf-8")) > 200000 or len(adapter.splitlines()) > 3000:
    raise SystemExit("dynamic content adapter size guard failed")

for marker in ["mutation_ttl_policy","acceptance_ttl_policy","dynamic.apply.attempt","dynamic.apply.success","dynamic.apply.failure","dynamic.apply.elapsed_ms","ttl_seconds"]:
    req(adapter, marker)

for marker in ["owned_state_sha256","post_write_verified","desired_state_write_verified","repair_write_verified","journal_heartbeat"]:
    req(adapter, marker)
