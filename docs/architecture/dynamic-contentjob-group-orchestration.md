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


## Structural-only QA and five immutable document attestations (2026-11-03 Cairo)

Original Staging plugin runtime now includes `MAD4B_SCP_Content_Job_Structural_QA` and two native WordPress Abilities:
`mad4b/content-job-structural-qa-plan` (read-only) and
`mad4b/content-job-structural-qa-attest` (original-authorized, writes only one scoped QA option).

The independent inspection re-reads the exact native WPML ContentJob and currently linked Elementor pair, then uses the *original* `MAD4B_WPML_Structural_Sync_V2::plan` to compute candidate structural changes. It validates the original authoring tree, exact widget identity/order, no duplicated HTML anchors, no replacements of existing authored anchors, no changes outside an explicit small allowlist of responsive padding, CSS classes, animation, element anchor, and responsive gaps. Any text, media, link, unknown setting, or widget graph changes are rejected. Evidence is bound to site, native job/revision, source SHA, target SHA, planned SHA, changed paths, and WPML target identity; stale receipts fail independent fresh readback.

All five site-local structural documents passed exact source-controlled inspection:
ar `40547` (11 paths); de `34597` (11); es `34605` (10);
fr `34599` (11); it `39502` (10). The original source's missing `_element_id=state_governorate` anchor is predicted to be restored without a duplicate, along with style-only deltas.

Five **`STRUCTURAL_DOCUMENT_ATTESTED`** durable scoped original WordPress receipts were saved via `MAD4B_SCP_Content_Jobs::structural_qa_attest`, and all five passed fresh independent `receipt` readback. Each receipt explicitly says `editorial_review_completed=false`, `browser_render_verified=false`, and `cwa_write_authorized=false`. These are document-review attestations, not the broader independent Elementor owner quality/rights certificates that would authorize published-target writeback.

Persistent reproducible Staging acceptance:
`tools/dfsb-neutral/etg-staging-contentjob-structural-qa-acceptance.php`, **20/20 PASS**, including 5 exact document identity/plan tests, 5 fresh immutable attestation tests, 7 negative/positive field fences, and 3 ability/disabled-write guards. Group 8/8, original typed-owner certificate 17/17, typed writeback guards 12/12 rechecked PASS on the same Staging after deployment.

Group plan now exposes `structural_qa_state`, `structural_attestation_state`, `structural_qa_evidence_sha256`, `structural_delta_count`, and `structural_qa_render_verified` per Child. All five remain `HOLD:native_wpml_owner_readback_or_review_pending`; the group-wide write option remains `no`. No target Elementor JSON, translation text, publication status, CWA receipt, production file, or original editorial WPML receipt was changed by QA.

**Next distinct gate:** independent interactive/browser render QA and original Elementor write-owner certificate for the exact structural scope, then exact target CWA write/rollback and post-write frontend readback. Original editorial translation/outbox review remains independent; never use a document-only attestation to fabricate `quality_pass` or `rights_pass` on a general write contract.


## Verified structural-only CWA publication and Chromium postwrite readback — 2026-11-03

**Executed on ETG Staging `tours.dev.mad4b.com` only**, using registered original WordPress Ability `mad4b/content-job-structural-cwa-apply` (new independent structural-only owner) and original `MAD4B_SCP_Central_Write_Acceptance::execute` plus `MAD4B_WPML_Design_Reconciliation_V1::reconcile_exact`. Each call was admitted only with the single exact selected native ContentJob, under a short-lived activation and site, actor, WPML family, source/target SHA, provider plan, structural-document QA, and actual Chromium preview evidence. The global flag was returned to `no` after every operation and the exact job selector cleared. No group-wide write activation.

| Locale | Existing target | Native CWA result receipt SHA-256 |
| --- | ---: | --- |
| ar | 40547 | c069fc6acb77352e8d86a677d0a4949036c885b59a5ddbec8473946d5d7a74f7 |
| de | 34597 | f4d9c91b70b6fe1c3db599886934b1595eb10498e7f8fbf5ebdbf23d2ab22f24 |
| es | 34605 | 524c81cbfd6d94c67d9f6c5a61ea0195309e580bc0359d6daf8d4e0bd5bd4680 |
| fr | 34599 | 90a1f69e98d303ee6793e0b39d64eaae9cff3c017b931a95374a6860f270ac18 |
| it | 39502 | d87c565f5262276f15ed62b05226f820f0e8d60705a406169224b77d47987817 |

All five report `VERIFIED_WRITE_AND_READBACK` and `replay_allowed=false`. The original Elementor provider preserved a rollback option for every exact target. Provider postmeta readback normalized the Elementor JSON and matched each independently predicted structural SHA, with exactly one `state_governorate` anchor. Native Elementor PHP rendering was part of CWA readback. A subsequent **real Chromium browser visit to each localized homepage path** `/ar/`, `/de/`, `/es/`, `/fr/`, `/it/` independently passed HTTP 200, correctly loaded WPML template, exactly one visible anchor, hash navigation, and zero observed page JavaScript errors. The browser step performed no client-side fixup after writes. Each pair's immutable final receipt is stored as `mad4b_cj_structural_final_browser_<sha256(site|job)>` with original CWA SHA and normalized current document SHA.

**Read-only regression acceptance:** `tools/dfsb-neutral/etg-staging-exact-structural-cwa-postwrite-acceptance.php` PASS **21/21**. The Group Plan on site now reports `ALREADY_SYNCED`, `already_synced=5`, `postwrite_verified=5`, `hold=0`; the children expose `postwrite_browser_state=VERIFIED` and the exact CWA/browser receipt hashes. Prewrite QA attestation is intentionally superseded by verified postwrite evidence and should not be mistaken for current prewrite eligibility. No blind replay was attempted. The jobs remain editorially `QUEUED`, with **no WPML translation approval fabricated or applied**; completing structural synchronization does not assert editorial translation quality.

The direct `?lang=fr` smoke initially displayed the English homepage; this was an invalid WPML URL for this site. WPML negotiation uses **language directories**; all actual frontend certifications used `/fr/`, `/ar/`, etc. Desktop checks found a ~3px horizontal scroll extent for fr/it tied to Swiper/Jet Listing offscreen slides. Record that as a separate low-severity frontend issue; it is not an anchor/CWA failure.

**Source reconciliation:** Draft branch `class-mad4b-scp-content-jobs.php` was discovered with a malformed group Ability fragment and divergent 134 KB content. Replaced on the Draft branch with the PHP-linted, live-working 50 KB Staging source at commit `4d6623b107d17c16b1de87f2ce2fc31ae70d6a0d`; the Git blob now matches live source except one terminal newline. Exact structural CWA class was already installed live and committed to this branch, also matching after newline normalization. This is not a production release. PR #744 remains **Draft**, `mergeable=false`, without recorded CI statuses/workflow runs as of this execution; merging or promoting requires external source reconciliation and normal CI. Production was not modified.
