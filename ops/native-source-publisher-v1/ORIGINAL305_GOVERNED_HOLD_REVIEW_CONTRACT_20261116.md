# Original305 — first-party signed incident review contract

## Why the old HOLD remains

The legacy `original305-readiness-ambiguous-write-HOLD.json` protects an execution result that could have committed a signed WordPress Option before the original service lost the acknowledgement. The original V1 supervisor logged only `ORIGINAL_BROKER_ERROR_REDACTED`; a newer signed Option was later observed but is now expired. It cannot be used as current readiness or proof that the particular failed write succeeded. Current Broker V8 and Supervisor V6 eliminate the known stale-prewrite/postwrite misclassification, but cannot retroactively attribute this failed historical write.

## Safe independent review proposal (source only)

`python3 ops/native-source-publisher-v1/original305_incident_signed_review_v1.py --plan` produces an exact review request pinned to the existing root-owned HOLD bytes, enrolled Registry V8, installed Supervisor V6, and an incident-specific approval ability `mad4b/original305-incident-review`. The request is read-only and carries `host_release_authorized=false`.

`--verify-approval /path/to/root-owned-private-signed-approval.json` checks an Ed25519 signature against the **already enrolled** Master Admin public-key pin from original Native Source Publisher Policy V2; checks exact request/incident/registry/supervisor bindings, distinct signer identity, and max 15-minute expiry. It never reads or creates a private signing key. Failed or unverifiable approvals cannot be treated as accepted.

The **ability and original WordPress ticket admission are NOT registered by this candidate**; `original_wordpress_ticket_admission_verified=false` is intentionally returned even for a cryptographically valid envelope. A signature-only check is *not* approval to close a historical incident. No current native first-party route was discovered that issues an approved/revocable ticket specifically for Original305 incident reconciliation.

## Real remaining first-party work

Register a dedicated original Master review action in the governed approval console and ticket authority, with exact incident SHA-256, binding hash, nonce, review timestamp, current ticket status and registered ability. Require independently authenticated/revocable ticket status and a valid signed approval. Only then let an owner-authorized incident-reconciliation finalizer archive the old marker under CAS and retain a signed audit receipt, without changing Broker keys or Host 312/313 release permissions. Never use the existing `mad4b/host-source-release` ability for this incident because that is a different, more powerful authorization domain.

When completed and reviewed through the provider-approved execution channel, enable **the existing** Original305 Systemd timer, verify at least two completed genuine signed renewal cycles and independent nonce-bound WordPress acceptance, and confirm `host_release_authorized=false`. Until then, keep the existing timer disabled and the historical HOLD unchanged. Previous upstream safety checks blocked an ad hoc attempt to directly alter this marker; this source-only reviewer does not evade those checks or make such an alteration possible without new signed governance.
