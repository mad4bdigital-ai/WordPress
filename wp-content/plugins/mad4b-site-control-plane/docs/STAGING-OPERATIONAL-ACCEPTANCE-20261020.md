# Staging Operational Acceptance — independent site-specific closure

**20 October 2026 | Candidate PR #449 | Staging only | Non-authorizing**

The source and test changes in this PR are **not** an external browser
executor, an OAuth reconnection, a Rank Math write certificate, a cache
benchmark, or an approved content mutation. CI alone cannot grant those.

## Updated field observations (supplied by the Staging operator)

| Check | ETG Staging | ARE Staging | Disposition |
| --- | --- | --- | --- |
| WordPress/MCP and candidate binding | matched | matched | no retry of already passed identity needed |
| Write Authority | ready | ready | do not silently reconcile |
| Skills Runtime Certification | READY, seed 13, file hashes match | READY, seed 13, file hashes match | **closed** for live runtime; no Reconcile or seed rebuild |
| Homepage / REST | HTTP 200 / 200 | HTTP 200 / 200 | connectivity evidence only |
| ETG semantic server-side Direct/AJAX | 8/8 PASS | not applicable | does not prove browser JavaScript behavior |
| Rank Math read | accessible | accessible | does not certify SEO write |
| Rank Math write | not certified | not certified | separate provider scope and rollback acceptance |
| WPML | 4.9.5 active | 4.9.7 active | active version does not prove canonical/hreflang/browser parity |
| Browser Acceptance | task `832975ac-d032-4054-b054-e90f0ef72cd4` expired unclaimed | no equivalent task recorded | external executor identity + claim/receipt required |
| Isolated Acceptance Target | absent | absent | guarded Staging-only provision under **new exact approval** |
| Apply → Verify → Undo | not attempted | not attempted | separate mutation and Undo ticket, before/after hashes |
| Object Cache / rate limit | LiteSpeed + drop-in + persistent reported; atomicity/TTL/cross-worker unproved | insufficient deep proof | test real backend, not plugin-extension presence |

These are operator observations; they are not the signed independent
browser/cache/mutation receipts required for **Full Operational Acceptance**.

## Implemented as part of PR #449

The administrator-only `mad4b/operational-acceptance-evidence` read ability
now supports **`include_staging_gates=true`**. Its added
`staging_gate_diagnostics` is read-only, separately digested, and
**non-authorizing**. It inspects the *current* Skills Runtime status and
bounded Browser capabilities/Object Cache status. It does not run Browser,
authorize or provision the scratch target, edit content, undo a mutation,
write Rank Math metadata, refresh OAuth, or certify an external receipt.

The previous eight-stage source/Host/Brand acceptance contract and snapshot
preconditions remain in place. Do not use the new diagnostic digest as an
owner approval, package signature or acceptance certificate.

The `Object Cache Diagnostics::cli_probe('expiry')` branch was hardened:
an absent cache key (which may have been evicted or explicitly deleted)
is merely `expired_observed=true`, **not** proof that its TTL elapsed.
The regression deletes a key and confirms `ttl_expiration_proven=false`.
Future benchmark tooling must measure actual elapsed TTL independently.

## Independent executable acceptance sequence

1. **Browser executor — before creating another task.** Verify a registered
   external Browser Agent, its site-specific authorized identity, dispatch
   endpoint, claim/lease, `executor_id`, `provider_checkpoint`, trusted
   execution receipt and TTL. For ETG, the expired and unclaimed
   `832975ac-d032-4054-b054-e90f0ef72cd4` is **not** a JavaScript
   failure and must not be replayed blindly. Create a *new* task only after
   the external executor can actually claim it. ARE needs its own task.
2. **Separate isolated target per site.** Call
   `mad4b/acceptance-target-status`. If absent, collect the current Staging
   profile revision/digest and exact installed source/build fingerprint,
   then prepare and approve a new `mad4b/acceptance-target-provision`
   operation through the existing governed write lane. Read back the target's
   exact ID, binding, unpublished state, and ownership. Never repurpose a
   live editorial/SEO post.
3. **Apply → Verify → Undo.** Prepare `mad4b/content-update-post` for the
   isolated target only. Obtain its exact mutation approval; execute once;
   verify persisted After-state and journal/Mutation ID. Independently plan
   `mad4b/mutation-undo`, obtain its separate approval, require matching
   After-state and execute once. Verify final restored hash and audit
   continuity. Any ambiguous response requires readback and reconciliation
   **before** another write. The test may leave no content change.
4. **Rate Limiting.** Confirm the actual WordPress object-cache backend
   (LSMCD/Memcached/Redis or alternative) with provider evidence, not the PHP
   extension or LiteSpeed Cache alone. In isolated, permissioned Staging,
   seed a unique key then prove a separate request/worker reads the same
   key; run deterministic parallel atomic increments with expected counts,
   verify TTL only after measured elapsed time, and repeat across nodes if
   multi-node sharing is claimed. Compare fresh real AJAX throttle behavior
   against limit policy. Preserve sanitized timings and receipts, not cache
   keys, visitor IPs or secrets. No Production load tests.
5. **Rank Math Write.** Discover per-site active version and exact candidate
   abilities. Require provider write certification, impact/rollback contract,
   tenant/content scope and separate owner approval. A successful
   `rankmath/status` read never supplies any of those facts.
6. **Browser + WPML/SEO parity.** With real external Browser Agent, inspect
   canonical and hreflang (for both WPML versions), AJAX result sets,
   navigation, JavaScript console/network and performance budgets.
   HTTP 200 and ETG server-side 8/8 alone cannot close this gate.

The release verdict must remain **NO-GO** until independently linked
receipts for the specific site, exact installed build and every required
gate have been verified. Skills READY remains a passed *live* check unless a
new current-build Skills read actually fails; do not revive an obsolete
stored checkpoint as an operational blocker.

## Security/replay constraints

- No Developer, Breakglass or Production grants.
- No Browser task replay without confirming executor claim/lease outcome.
- No user-supplied arbitrary JavaScript or URLs in provider registration.
- No live post mutation without a verified isolated target and exact approval.
- No automatic Undo; separate ticket and matching After-state required.
- No cache proof inferred from missing/evicted key, reported persistent cache,
  object-cache drop-in, or Memcached PHP extension.
- No external provider write certification or owner intent inferred from reads.

**Acceptance is site-scoped. ETG and ARE must not share a source/target/ticket,
Browser execution receipt, WPML verification or object-cache benchmark.**
