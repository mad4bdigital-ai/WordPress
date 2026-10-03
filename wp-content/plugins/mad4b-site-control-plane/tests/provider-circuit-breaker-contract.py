from pathlib import Path
root=Path(__file__).resolve().parents[1]
impl=(root/"includes/class-mad4b-scp-provider-circuit-breaker.php").read_text(encoding="utf-8")
cert=(root/"includes/class-mad4b-scp-provider-compatibility-certification.php").read_text(encoding="utf-8")
schema=(root/"includes/class-mad4b-scp-schema.php").read_text(encoding="utf-8")
for marker in ["STATE_CLOSED = 'closed'","STATE_OPEN = 'open'","STATE_HALF_OPEN = 'half_open'","FAILURE_THRESHOLD = 3","MAX_OPEN_SECONDS = 900","HALF_OPEN_LEASE_SECONDS = 45","provider_breaker_event","probe_restores_transport_only","production_eligibility_granted","event_chain_valid"]:
    if marker not in impl: raise SystemExit("FAIL provider breaker invariant "+marker)
for marker in ["provider_breakers","provider_breaker_events","provider_site_generation","breaker_sequence"]:
    if marker not in schema: raise SystemExit("FAIL provider breaker schema "+marker)
if "certification_generation_sha256" not in cert or "mad4b.provider-certification-generation.v1" not in cert:
    raise SystemExit("FAIL certification generation authority missing")
for forbidden in ["Staging_Write_Authority::apply","Governed_Runtime_Gates::apply","Approval_Tickets::finalize","certification_level ="]:
    if forbidden in impl: raise SystemExit("FAIL breaker mutates authority/certification "+forbidden)
print("mad4b.provider-circuit-breaker.contract.v1: PASS")

connector=(root/"includes/class-mad4b-scp-connector-resilience.php").read_text(encoding="utf-8")
for marker in ["begin_for_target( 'read', $target )","max_attempts' => ! empty( $breaker['half_open_probe'] ) ? 1","begin_for_target( $surface, $target )","record_result( $breaker"]:
    if marker not in connector: raise SystemExit("FAIL connector breaker integration "+marker)
for marker in ["mad4b_provider_circuit_breaker_read_probe_required","half_open_probe_inconclusive","$transport_ok && (int)$row['failure_count'] > 0"]:
    if marker not in impl: raise SystemExit("FAIL breaker probe safety "+marker)
