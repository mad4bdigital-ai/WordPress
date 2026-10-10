# Feature 007 — Brand Core recovery from A to Z through the existing MAD4B MCP

Status: **source implemented for detection/routing and narrow Staging-only ownership transition**.
The WordPress/Google Drive readback, owner-approved legacy transfer, exact
context materialization, AI review and tour-draft acceptance are **not yet
certified live on this PR HEAD**. No Production promotion or automatic approval.

## What the live All Royal Egypt readback established

- Staging Context Brand ID `adc15fff556d3bb806e021dcdcb4ce5e`, Brand Profile revision 4.
- Context registry revision 6. One stored Drive source and 12 stored Context
  assets are **quarantined**, not demonstrably absent. Governed sources/assets
  visible under the current Brand ID = zero; three required approved Brand Core
  sets = zero.
- Google Drive is connected with read/write scope, but connection reported
  `refresh_required`. A connected account is not proof of an approved source
  or that automatic Provider creation/rollback is certified.
- Existing Google Doc `All Royal Egypt – Editorial Guidelines` is visible
  in the linked Drive account. Its exact linkage to the quarantined 12 assets
  has NOT been demonstrated; do not create a duplicate or confer review
  authority based on the title alone.
- Brand Core AI review is configured with exact Staging delegation. That
  permits a separately governed eligible review operation, not delegation of
  original business strategy authorship, external rights, or original source
  ownership.

## Control loop — keep one source of truth

Use the existing WordPress Context Authority + the selected, managed Google
Drive folder. No parallel Brand DB, cloned Drive folder, copied approvals or
independent authority index.

1. Call `context/brand-core-control-loop` with `{}`. It reads exact Brand
   scope, current registry/authority hash, Brand Core coverage, safe
   reconciliation census and current Drive connection.
2. When quarantine exists, call `context/legacy-reconciliation-census` and
   `context/legacy-owner-transfer-discover` using the current administrator.
   Both read-only. A foreign or mixed-owned record MUST NOT be adopted.
3. For **one truly unbound** legacy governed Drive source, call
   `context/legacy-owner-transfer-plan` using its exact stored source SHA.
   Review owner, original folder, site/brand identity, tenant, every asset,
   external file metadata, ownership evidence, source/asset preimages and
   recovery strategy. Neither a reused display name nor a valid Drive link
   establishes ownership.
4. Only after exact owner consent and one-time governed write approval may
   `context/legacy-owner-transfer-apply` accept the SHA-bound plan and its
   precise existing Drive folder. It independently fetches that folder and
   checks original Staging scope again under registry lock. It never
   transfers foreign/mixed assets; the single source becomes `read_only`,
   all transferred assets `stale/unreviewed`, and inherited review
   identities and stale-evidence overrides are invalidated. No new folder,
   data copy, supplier license or approval is created.
5. On failure, do **not** blindly retry or delete. Re-read registry and
   append-only audit. The same-request registry snapshot can compensate
   failed writes, with an exact readback of source ownership, asset count
   and discarded old reviews before reporting success. A separate
   `context/legacy-owner-transfer-readback` ability independently confirms
   the current source and its unreviewed assets on a later request.
   A separately certified durable cross-request rollback and crash/race
   test are still mandatory before production-like live migration.
6. Once exactly bound, use `context/source-scan-plan`, then independent
   approval for `context/source-scan-apply` and source readback. Preserve
   provider IDs/revisions; incomplete or truncated scans cannot prove files
   absent and cannot authorize recreation.
7. Re-evaluate `context/brand-core-coverage`. If Brand Strategy exists,
   verify exact content hash and owner approval. If not, restore original
   first or supply a reviewable owner-created draft; the engine cannot
   fabricate authoritative business strategy from competitor material.
8. For voice/editorial evidence, use `context/brand-gap-plan`,
   `context/brand-draft-preflight`, the existing governed
   `context/brand-draft-create`, `context/materialize-brand-draft`,
   `context/reconcile-brand-materialization` and provider verification.
   Never create replacements for existing unverified assets. A prior
   in-progress materialization must be reconciled, not repeated.
9. Use human or independently delegated `mad4b/context-ai-review` on the
   exact content revision, followed by current source scan and
   `context/brand-core-coverage` readback. AI cannot approve its own
   draft without the configured separation of duties.
10. `mad4b/brand-content-gate-triage` discovers enabled exact Skill
    target/name from the original Skill Registry and missing Context.
    `mad4b/skill-context-preflight` remains redacted and non-authorizing.
    When Brand Core is ready, `mad4b/skill-get` may return the signed
    Context receipt under the original policy. No manual target guessing.
11. Resume the original requested `tours-and-activities` create **draft**
    via the existing `mad4b/content-orchestration-plan`, supplier/source
    rights preflight, one-time mutation approval,
    `mad4b/content-apply-bundle`, and independent
    `mad4b/content-bundle-readback`. Publish only through a second,
    separately approved publication receipt; no competitor-owned imagery,
    pricing, supplier affiliation, or inventory is inferred.

## Exact policies and cases

| Case | MCP action | Denial / acceptance |
| --- | --- | --- |
| Brand context complete and approved | Read exact coverage/readback | Already ready; no recreation |
| 1 quarantined source / 12 assets | Census + discover + exact plan | BLOCKED, no duplicate writes |
| Source has foreign Brand ID or mixed assets | Owner-review plan | Denied; no implicit reassignment |
| One unbound and owner-reviewed folder | Explicit one-time transfer | Site/brand/revision/folder/policy fenced; old approvals revoked |
| Original Drive file exists | Read/scan/update | Never replace with duplicate |
| File truly unavailable | Governed recreate + rollback | Needs independently proven absence and exact previous provider identity |
| Lost Provider OAuth / refresh | Reconnect/refresh original connector | No new credential copies or invented success |
| Source registry changes mid-flight | CAS + digest comparison | Stop, fresh plan |
| Brand Strategy absent | Owner source/approval | Never synthesized into authority from competitor facts |
| Voice / editorial missing with approved strategy | Draft, materialize, review | Independently approved; no auto publish |
| AI review configured | Exact delegated review | Does not bypass original owner/business rights |
| Skill preflight fails | Read `mad4b/brand-content-gate-triage` | Exact skill selection + Brand Core remedy, no raw instructions |
| External competitor source | Rights preflight | Public reference does NOT confer reuse, resale or media license |
| Release and Staging | Exact-head native/DB/browser readback | Required before claim of A-Z operational acceptance |

### Hard authorization gate for legacy transfer

The new `context/legacy-owner-transfer-apply` does **not mount by default**:
both the governed Context adapter and underlying Context Authority reject
it unless a Host-controlled Staging configuration explicitly sets
`MAD4B_SCP_CONTEXT_LEGACY_TRANSFER_ROLLBACK_CERTIFIED=true` after
independent durable rollback, crash-safety and exact owner-rights acceptance.
The PHP fixture exercises the refusal and the opt-in test path; that
fixture is **not** Host certification. Do not set the flag automatically
from a WordPress Site Profile, MCP caller input, database option or GitHub PR.

## Explicit remaining blockers

- Tested PHP 7.4/8.3 native fixtures, MySQL/MariaDB race, multisite,
  Provider 403/429/timeout, and cross-request durable rollback proof.
- Live source/asset transfer not performed: owner evidence and exact
  authorizing approval not yet obtained for the quarantined data.
- Google Drive refresh and verified exact scan/review; original provider
  source and owned Editorial Guidelines still need ownership proof.
- No actual new published tour or brand context mutation should be claimed.
- PR #258 remains Draft. This is not a Production promotion authorization.

The current GitHub source initially contained an accidental repeated-method
suffix in Context Adapter. That suffix was removed on PR #258, and a new CI
static refusal checks class uniqueness, unique method names, bounded source
size, and exact registered recovery routes. Do not deploy any commit before
that repair or without independent native PHP lint.
