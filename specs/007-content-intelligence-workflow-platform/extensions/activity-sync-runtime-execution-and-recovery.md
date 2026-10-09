# MSR02 — Governed Business Activity Sync Runtime, Provider CAS and Recovery

**10 October 2026 — Feature007 child PR #366.** This is a **facet of the existing Content Experience Profile**, not a travel plugin or duplicate profile store. It builds on MSR01 read-only conflict plans and authenticated source roles/field ownership.

## Runtime state machine

`unconfigured` → `bootstrap_preview` → (`bootstrap` if identical / `bootstrap_arbitrate` if divergent) → `checkpoint_initialized` → `sync_plan` → `queued` → `step_inflight` → `running` → `complete` → `archived`.

On uncertain/failed write: `step_inflight` → `needs_reconcile` → independent provider readback → `running` (without replay) or `complete` (only if all sources converge) or remain blocked for manual correction. `queued`/prewrite `needs_reconcile` can be cancelled and archived only if zero writes occurred. An in-flight or partially applied saga cannot be silently erased.

### Canonical configuration
`Content Experience Profiles → optional activity_contract`:
- `sync_targets` (up to 12) with `provider`, `purpose`, `direction`, `resource_kind`, `field_keys`, typed `field_bindings`, `conflict_policy`.
- `field_owners` per canonical attribute: WordPress, an inbound configured `record_data` source, or manual-review.
- `sync_identity_key` stable post ID or independently verified external stable ID. No fuzzy name matching.
- `resource_binding_mode=static`: one exact provider ID as `source_ref`.
- `resource_binding_mode=entity_post_meta`: resolve each profile record's provider resource ID from a parent-profile allowlisted WP Meta key. No provider ID from untrusted chat content.
- Editorial policies, brand guide, reference material and media folders are context evidence, **not automatically business profile data**.

### Abilities
- `mad4b/business-activity-sync-status` — redacted option journal/checkpoint hashes and per-source provider adapter readiness (not OAuth secret).
- `mad4b/business-activity-sync-plan` — fresh authenticated provider reads, exact snapshot/previous checkpoint comparison, before/after field-digest and destination steps, no write. On first-run divergence, `field_sources` explicitly selects a source **per conflicting field**, subject to configured owner; no newer-file-wins.
- `mad4b/business-activity-sync-begin` — admin confirmation + exact plan SHA-256 + unique operation key; reserves immutable per-entity operation. First-run identical source baseline is persisted only after fresh verification; divergent first-run creates conditional arbitration steps and persists checkpoint after full convergence.
- `mad4b/business-activity-sync-advance` — one exact pending source→destination field update at a time, after reading all sources, verifying approved owner and preapproved destination digests, setting `step_inflight` **before** write, checking provider conditional revision and postwrite provider readback. Full step count limit 400. Final all-source value convergence is checked before checkpoint advance/readback and final journal state.
- `mad4b/business-activity-sync-recover` — an ambiguous external write is recovered only by authenticated provider readback matching the approved value and an exact journal hash. **No automatic replay**.
- `mad4b/business-activity-sync-finalize-reconciled` — admin-confirmed recovery when external/manual actions restored full convergence; original owners and checkpoint must still match approved operation, then persist/read back checkpoint.
- `mad4b/business-activity-sync-cancel` — only zero-write preflight operations, with immutable cancellation archive.
- `mad4b/business-activity-sync-archive` — only completed operations, preserve immutable hash-bound journal before new operation can start.

### Durable state, crash handling, consistency
- WordPress Options store is keyed by hash of `site_uuid|profile_slug|entity_id`: immutable accepted source checkpoints containing resource IDs, opaque revisions and type-aware field SHA-256; no plaintext business values or OAuth tokens. Active journal holds exact step identities/hashes, approved plan hash, authority revision, next step, state and timestamps; archive is immutable and hash-addressed.
- `add_option` provides single-winner per-entity operation reservation and a fail-closed execution lease. Crashed leases **are not silently stolen**. Admin exact-readback recovery or explicit prewrite cancel is required.
- Before **any** external provider write, the `step_inflight` journal transition is persisted and independently reread from WordPress Options; failed readback blocks the provider call. After readback-confirmed writes, the next-step journal is verified before unlocking. First-run checkpoints also require readback before claiming success.
- A definite prewrite refusal records `needs_reconcile` before releasing the execution lease; cancellation obtains the **same** atomic per-entity lease and rechecks its exact journal checksum before archiving, blocking a cancel/advance race.
- Recovery and full-convergence finalization share a per-entity recovery reservation. Unverified journal transitions prevent a success result. An ambiguous `step_inflight` write cannot be recovered while the original execution lease may represent an active provider request; genuine process quiescence requires an independent operational check, not an age-only takeover or user-provided boolean.
- Exact journal readback is a necessary guard, **not** proof of transactional compare-and-swap in arbitrary WordPress storage engines. Concurrency and cache-coherence must be certified on native MySQL/MariaDB with multiple PHP processes.
- Source reader return must include exact resource ID, revision, observed timestamp, complete mapped fields and `present` state. Stale, truncated, absent or reordered bindings cannot become accepted checkpoints.
- Admin `manage_options`, WP parent post edit authorization, exact Site Profile enrollment, profile revision and SHA, source field allowlists are required. The source ownership map can be versioned through the existing governed Profile plan/apply.
- No two-phase transaction exists across Google and MySQL. This is a bounded outbox/saga with partial-progress recovery, not fake atomic commit.

## Conditional writer providers

### WordPress metadata — built in
- Reads only typed/scalar, bounded, **allowlisted** profile Meta fields.
- Uses DB row-specific, byte-sensitive compare-and-set rather than the WordPress `update_post_meta` shortcut that can ignore blank `$prev_value`. Refuses missing or duplicate meta rows. Checks `sanitize_meta` would not silently transform approved value, invalidates the meta cache and sends post-update hooks. Direct SQL path and JetEngine plugin semantics need Staging integration acceptance; do not assume all JetEngine meta types are representable as simple scalars.
- New Meta fields must first be created by the existing governed profile/write lane, not by unsafe sync initialization.

### Google Docs — opt-in server-side OAuth
- `MAD4B_SCP_Activity_Google_Docs_Adapter` registers `google_drive` only when the existing WordPress `MAD4B_SCP_Google_Drive_Context::connection_status()` reports managed Google OAuth read availability, and exposes conditional writes only when the existing connection reports write availability. It does **not** replace a separately configured provider. The ChatGPT user-side Google Drive connection is not, by itself, an OAuth connection on the WordPress host.
- Supports `resource_kind=drive_document` and `purpose=record_data` with simple **single-text-run paragraphs** formatted `field_name: value`. A source `field_bindings` entry provides the allowed exact paragraph label. Existing complex documents, multiple tabs, styled/multirun fields, formulas and Sheets are deliberately rejected.
- Calls fixed official Google Docs APIs through the **preexisting encrypted, refreshable managed OAuth provider** `MAD4B_SCP_Google_Drive_Context::activity_docs_request`, never reading bearer tokens into a second registry or returning them to an MCP caller. This bound server-internal entry verifies enrolled Site Profile, administrator, exact configured resource binding, `record_data` purpose, managed Google scope, bounded text operations, and provider identity. Reads source `documentId` and `revisionId`; writes with `documents.batchUpdate.writeControl.requiredRevisionId`. Text offsets use UTF-16 indexes; postwrite `documents.get` confirms the exact updated field.
- Does **not** edit All Royal's Editorial Guidelines (its context source is not a business-record field). Does not upload files, publish content, change permissions or create new Docs.
- Google Sheets values changes and arbitrary Docs rewrite are **not enabled** as unconditional writers because Google Sheets does not expose the same required Docs revision control for general cell updates. A site-specific adapter can register a reader but only advertise `conditional_write=true` when it provides a demonstrable provider-side concurrency guarantee and exact readback.

### Provider extension contract
Trusted WP-local code registers adapters via `mad4b_activity_sync_adapters`, each with `read(source,fields,binding)` and `write(source,field,value,expected,binding)`, `conditional_write=true` and `readback=true`. These callbacks are server-installed, not dynamic executable strings from a ChatGPT message or Google Drive file. Provider status exposes readiness without secrets. Other providers/versions can be added without changing the Business Activity schema.

## Adversarial cases
1. Two fresh identical sources → exact, confirmed accepted checkpoint.
2. Initial values differ → explicit owner-respecting field choice; CAS steps and checkpoint only after convergence.
3. Source changes after plan → abort at exact approval mismatch; no mutation.
4. Destination changes after approval → abort and enter `needs_reconcile`.
5. Owner changes between step and write → abort and enter `needs_reconcile`.
6. Two workers race: only one operation/lease wins.
7. Network timeout after provider applied write → durable `step_inflight`/uncertain state, authenticated readback recovery, **no blind replay**.
8. Partial multi-destination commit → never claim all-or-nothing; finalize only after full source convergence, explicit approval and unchanged owner/checkpoint.
9. WordPress blank meta or case-only change → byte-sensitive meta row CAS or fail closed on missing/ambiguous metadata.
10. Provider identifier changed, wrong CPT, unauthorized field, source purpose changed → fail closed.
11. OAuth missing or adapter not advertising conditional write → no external write.
12. Old owner rules after profile revision changes → replan; no stale journal continuation.
13. Completed operation → immutable archive, then separately approve new operation.
14. No hidden credential values in status/journal or public source sample.
15. No sector-specific DMC/driver/guide code in generic runtime.
16. Native PHP fixture `business-activity-sync-runtime.php` covers fake provider CAS/readback, initial arbitration, stale approval, concurrent destination edit, partial closeout, ambiguous-write recovery, immutable archive.
17. Simulated Options-write failure before `step_inflight` receipt → no external write, explicit failure and safe lease release.
18. Simulated Options-write failure after provider write → no false progress/lease release, retained `step_inflight` and blocked recovery while worker may be active.
19. Two recovery workers → exactly one recovery reservation; cancel against a leased execution → hard refusal; settled prewrite conflicts release unused leases.

## Current acceptance and blockers
**Source delivered on child PR #366**, not deployed to WordPress Staging or Production.

Static invariants / exact HEAD checks are source-level evidence only. Native php lint, PHP fixtures, WordPress + JetEngine authoring, Google Docs OAuth live consent/token lifecycle, rate limit/crash simulations, browser acceptance, and authenticated real Google Docs read-write-readback are **independent NOT_RUN gates**. The existing WordPress Google Drive Context managed connection must be explicitly connected and granted the Docs/Drive scopes appropriate to actual business-record resources, and Site Profile + per-entity resource bindings must be verified without leaking credentials to chat. No Google Drive or WordPress content was changed on the live user site in this source-only delivery.
