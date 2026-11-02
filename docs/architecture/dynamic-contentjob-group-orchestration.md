# Dynamic ContentJob Group Orchestration — Staging implementation

## Scope and ownership

- Contract: `mad4b.contentjob-group.v1`.
- Reuses original ContentJobs, WPML family mapping, Elementor reconciliation, and CWA receipts. Group IDs are deterministic for exact site, source and provider; snapshot hashes bind all current targets and child states.
- **A group is not a universal write authorization.** Each child retains its original ContentJob, source hash, original provider policy, independent certificates, idempotency, and readback.
- `mad4b/content-job-group-plan`: non-mutating original WordPress Ability.
- `mad4b/content-job-group-apply`: guarded original WordPress write Ability, **disabled by default** with `mad4b_contentjob_group_apply_enabled=no`; max 8 selected eligible targets per call.
- The original Elementor owner now invokes `MAD4B_WPML_Design_Reconciliation_V1::reconcile_exact(source,target,apply,source_sha256,target_sha256)` rather than wide `reconcile(source,true)`.
- `reconcile_exact` changes only the target in the *existing* WPML family, subject to both hashes and original edit permissions. The original provider retains its backup and post-write readback.
- No publisher, cross-company, supplier, pricing, or production permissions are added.

## ETG Staging actual ContentJob followups (2026-11-02 UTC)

Original source: Elementor post `25909`. Group ID:
`d5063d4ffd16d30021bada593ff74b94605322a2a477ae54a64de51582319665`.

Five native ContentJobs were created using `MAD4B_SCP_Content_Jobs::create_job`, linked through the existing `MAD4B_WPML_ContentJob_Adapter::MAP`, and moved with original `transition_job` into `QUEUED/INTAKE`, revision 2, each bound to the already-existing exact WPML target. All native post-state readbacks verified.

| Locale | Target | New job | Previous |
| --- | ---: | --- | --- |
| ar | 40547 | 45076890-9fc9-410d-9dba-7a4231729647 | d1eff9e3-1c13-43cf-a364-e14bfc692777 (CANCELLED) |
| de | 34597 | 57679eb4-1843-46b4-99a7-294e30e4a67e | none |
| es | 34605 | 720cb9e8-ec58-4e0c-8c8c-2540927a1580 | none |
| fr | 34599 | 4a302e58-4e06-42b3-9563-4b052d21a511 | 0f5b651c-3994-46ed-817f-24c4ba5127cd (CANCELLED) |
| it | 39502 | 1c946b72-1cbb-4f5e-b671-484db19cf5e7 | none |

Operational source is preserved at `tools/dfsb-neutral/etg-staging-group-followups-one-shot-20261102.php`, with strict site/user/snapshot binding, per-child immutable no-blind-retry intent markers, original ContentJob transactions, and MySQL lock shared with WPML intake. This is an audited one-time Staging operation, **not** a general recurring provisioning agent. Original cancelled jobs remain immutable. Predecessors are recorded in `mad4b_contentjob_group_followup_history_v1`. All five final markers read `QUEUED_ARTIFACT_VERIFIED`.

The native WPML intake duplicate read was corrected to select the authoritative current mapped job **only when all other matching original jobs are terminal**; two genuinely active conflicting jobs still return `bridge_duplicate_native_content_jobs`. On-site live readback: five unambiguous active jobs, 2 preserved history links.

## Current readiness

All 5 child jobs are currently HOLD for original write, after successful creation and artifact binding:

- ar/fr: unverified localization outbox receipt or review; original `binding.readback_verified=false`.
- de/es/it: no original localization provider completion/QA evidence, therefore original binding is not certified even though no outbox gaps are listed.
- An independently obtained original Elementor owner quality/rights certificate has not been registered.
- No automatic Elementor write has occurred. The Group apply flag remains `no`; a forged certificate, generic admin/SSH access, or terminal-job reopening must never be used to bypass these gates.

## Acceptance and provenance

- Exact-target provider preview: 1 target versus 5 for broad preview; invalid source CAS rejected; no write.
- Group plan acceptance: 8/8 PASS before child provisioning.
- Owner certificate fixture: 17/17 PASS.
- Typed relationship guard: 12/12 PASS.
- Post-provision: 5/5 durable markers and original job/readback, 2/2 predecessor links, and native WPML `native_lookup` 5/5.
- A later combined test execution returned `STAGING_WPCLI_BUSY` while other active WP-CLI sessions were present. No tests were claimed from that blocked run; existing earlier passes remain evidence.
- Site-local original provider 349/350 exact copies are archived under `tools/dfsb-neutral/staging-mu-provenance/`; they are staging-source provenance, **not a production release**.
- PR #744 remains Draft and unmerged; reconcile the branch with master and obtain CI before promotion. Production unchanged.

## Follow-up for generalization

Extract the one-shot WPML original-child admission into a reusable original-provider method with strict lineage, active-job uniqueness, durable claim per child, bounded batch, source/site snapshots, and post-transition readback. Add an alert driver for per-child `review_blockers` and quality/rights evidence. Then certify a single positive exact Elementor owner write via CWA before enabling group apply. Do not broaden WordPress authorizations or the original provider's target scope.
