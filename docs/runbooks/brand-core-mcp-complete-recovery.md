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

## Remote-first operator workflow (source implementation)

Feature 007 now exposes a **read-only, stage-bound orchestration layer**
`mad4b/brand-core-remote-recovery-status` and
`mad4b/brand-core-remote-recovery-prepare` through the canonical
Operational Remediation class. Both are administrator-only, exact-Staging
read operations; they **do not** run host commands, create approval tickets,
fetch arbitrary URLs, certify DB crash results, or execute WordPress writes.

The status endpoint supports an optional exact original `source_id`
(64-character SHA), `include_provider_scan=true`,
`include_host_challenge=true`, and exact `candidate_sha` (40-character Git
SHA) for selected-head planning. Source IDs, scan results, host identity and
Candidate Release Plan are read independently from the original provider and
the enrolled site; the caller cannot submit arbitrary `ready=true` facts.
A full Drive inventory may be costly; it is **opt-in** per request. Only
`original_file_identity` can consume the complete provider proof. The
host flag invokes an independent current nonce-signature observation; no
cached/forged Host flag is accepted.

Eight isolated stages are returned, each with its exact existing owning
read/plan, governed native effectful ability, independent readback,
prerequisite stages, operator role, approval requirement, currently mounted
ability markers, and blockers:

1. `source_inventory` — `context/legacy-reconciliation-census` and
   `context/legacy-owner-transfer-discover`
2. `original_file_identity` — `context/legacy-owner-transfer-evidence`;
   100% of original stored file IDs and original Drive root must match
3. `owner_attestation` — exact owner decision, one-time approval ticket,
   `context/legacy-owner-transfer-apply` then independent
   `context/legacy-owner-transfer-readback`; no inherited reviews
4. `host_identity` — enrolled signed Host Runner challenge plus
   `mad4b/host-doctor`, `mad4b/host-operation-plan`, separately approved
   `mad4b/host-operation-apply` and independent Host receipt
5. `durable_db_recovery` — **separate signed, isolated MariaDB
   crash/restart/race/rollback certification executor still required**.
   No WordPress option, simulated PHP test or generic host command may
   satisfy this gate
6. `certified_package` — remotely plan the explicit immutable SHA with
   `mad4b/control-plane-selected-head-plan`, then a separately approved
   certified `mad4b/control-plane-selected-head-apply` and exact installed
   source/manifest readback; a remote plan is not an install receipt
7. `brand_approval` — complete source scan, governed review and
   `context/brand-core-coverage`; quarantine must be zero before approval
8. `draft_acceptance` — independently authorized native Content
   Orchestration create-draft, then CPT/taxonomy independent readback; not
   publication or competitor-rights evidence

The Host write Ability is discoverable in the governed `mad4b-write`
catalog only through the existing Host Bridge and remains subject to
`MAD4B_SCP_Authorization::claim_mutation`, the exact Runner profile,
semantically allowlisted Host job, one-time ticket, source binding and
independent signed execution/readback. Merely mounting the tool is **not**
Host enrollment and does not grant write authority.

A stage handoff requires `stage_id` and
`expected_plan_sha256`, and must re-evaluate the same exact live evidence.
Use matching `source_id`, `candidate_sha`,
`include_provider_scan`, and `include_host_challenge` flags
when repeating the observation. If provider contents, site binding, coverage,
Host signature or candidate plan changed, reject the stale handoff. Handoff
never exposes a dispatchable write token or bypasses individual ability
permissions, exact approval tickets and current Host/release certification.
Its `ready_for_dispatch` and `approval_issued` remain false.

### Remote availability is not remote acceptance

- **Mounted** = discovery of a separately governed action on the installed
  version, not authority to run it or proof that its external executor works.
- **Host transport connected** = current nonce-signed Host response from
  the existing enrolled trust root; a source implementation or online
  desktop device name is not sufficient.
- **DB acceptance** = independent, real isolated MariaDB crash/restart,
  interrupted multi-option write and recovery evidence from an enrolled,
  signed Host executor. The current WordPress bridge does not implement this
  Host-side certification workflow; this stage must remain visibly blocked.
- **Package accepted** = immutable exact-HEAD certified artifact and signed
  verdict/accepted offline CI outage evidence, plus installed-hash and
  rollback readback. Queued/cancelled Actions and an older rc.95 artifact
  cannot satisfy the current PR candidate.
- **All stages operationally ready** = only when every individual external
  receipt and original authoritative readback is verified. The coordination
  layer cannot self-sign or assert final acceptance.

A remotely managed Host bridge can avoid wp-admin/manual shell steps **only
after** a trusted Host transport and its executor have been enrolled. The
ability to bootstrap a missing OS-level Host Runner and its signing trust
root is not established solely by WordPress MCP. Never auto-grant or
remote-install a Host identity into a machine controlled by a different
owner. Keep Production, Breakglass and raw SQL disabled.

Native refusal tests:
`tests/brand-core-remote-recovery-runtime.php`, linked to PHP 7.4/8.3
matrix. This isolated fixture is not a live MariaDB/Host certification.

## Host-optional native WordPress recovery rehearsal

**New Feature 007 source contract**: `MAD4B_SCP_Native_Recovery` uses
the existing WordPress Abilities API + Options API + optional WP-Cron,
not a Docker process, SSH, external signer or Host Runner. Exposed via:

- `mad4b/native-recovery-plan`: read-only exact Staging rehearsal plan.
- `mad4b/native-recovery-start`: separately approved, owner-step-up
  governed write to **one private non-autoloading option only**.
- `mad4b/native-recovery-readback`: independent new MCP request
  compares scratch hash, exact WordPress Site Profile binding, fresh
  request identity, 15-minute expiration and original legacy plan.
- `mad4b/native-recovery-cleanup`: separately approved removal of **that
  scratch option only**, with independent MAD4B audit.

Use `mode=probe` to test Option write/read/cleanup without any original
Brand records. Use `mode=shadow` plus the exact 64-character quarantined
source ID to independently read the original `legacy_owner_transfer_plan`
and full original Google Drive `legacy_owner_transfer_evidence` (all
stored file identities) before writing a small **shadow metadata digest**,
not the real source or file contents. During the separate-request readback,
rescan provider evidence and re-check plan and site identity.

Example step order:
1. Discover `wordpress_native` with
   `mad4b/brand-core-recovery-alternatives`.
2. Read exact `mad4b/native-recovery-plan` with `mode=probe`, reason.
3. Independently approve exact SHA; call
   `mad4b/native-recovery-start`, confirmation
   `START STAGING SCRATCH PROBE`. Shadow uses
   `STAGE BRAND SHADOW ONLY` instead.
4. **Make a distinct MCP request**, not the same WordPress request, to
   `mad4b/native-recovery-readback` using the returned `job_id`.
   Same-request readback is intentionally `WP_NATIVE_INCOMPLETE`.
5. Approve exact cleanup:
   `mad4b/native-recovery-cleanup` with
   `CLEAN STAGING SCRATCH ONLY`; only the private option is removed.
   WP-Cron may expire old scratch after 15 minutes; it is never a
   requirement to execute the test or authoritative acceptance.

This proves a **bounded WordPress cross-request functional rehearsal**,
not MariaDB service crash/restart, durable database backup/restore,
Host-controlled root identity, legal right to use Drive assets, actual
ownership transfer or signed package provenance. Do **not** flip
`MAD4B_SCP_CONTEXT_LEGACY_TRANSFER_ROLLBACK_CERTIFIED` after a scratch
PASS: legacy `context/legacy-owner-transfer-apply` continues to require
the separately enrolled signed physical Host and independent rollback
certification. Any future Host-free owner transition requires **a different
explicitly reviewed authority contract, genuinely equivalent rollback
guarantee, owner consent and production isolation**; the scratch route
cannot authorize it.

The existing WordPress `Plugin_Upgrader`-based, exact-package verified
Staging updater is still available separately for signed/certified ZIPs.
It requires no dedicated Host agent when its WordPress-native update
authority is already enrolled and all original package gates pass.
Neither the new scratch nor the native updater signs its own independent
CI receipt.

Source tests: `tests/native-recovery-runtime.php`, plus native reducer
refusal assertions inside the existing PHP 7.4/8.3 fixture
`tests/brand-core-remote-recovery-runtime.php`, and strict native
contract checks in `tests/brand-core-remote-recovery-contract.py`.
Live acceptance is pending until an exact PR #258 package reaches Staging.

## Multi-path fallback discovery instead of dead-end blockers

The new `mad4b/brand-core-recovery-alternatives` read-only MCP Ability
accepts only `domain=all|host_runner|database_recovery|candidate_delivery`.
It compares currently mounted native Ability names with the same
`MAD4B_SCP_Recovery_Alternatives` policy registry used by
`mad4b/brand-core-remote-recovery-status`. Each candidate reports its
independent plan, separately authorized effectful Ability, readback,
executor, missing integrations and exact required evidence. The selected
recommendation is **only a preferred read-only preflight**, never an
autonomous write and never a successful certification.

| Failure domain | Alternatives in descending discovery preference | What remains mandatory |
| --- | --- | --- |
| Host Runner unavailable | Reconnect existing signed Host Bridge; reconcile/requeue previously authorized semantic Host jobs; independent host-owned SSH or managed agent with the same pinned Host identity | No new trust root from WordPress, current signed nonce, exact enrolled Staging target, owner consent, host job receipt |
| MariaDB crash/rollback certification unavailable | Disposable remote MariaDB fault-injection lab; separate provider-managed Staging clone; future typed Host Runner database probe (not currently implemented) | Exact engine/schema, real interrupted cross-request writes, restart and restore, race test, hashes, independently signed receipt. No crashes against site DB or Production |
| GitHub Actions stuck or cancelled | Standard terminal green GitHub verdict; owner-enrolled, independently signed five-gate offline Staging CI-outage candidate; signed standalone external build worker followed by independent release certification; WordPress native update only when the *exact intended* release is already certified | Immutable exact HEAD, complete native test evidence, approved public signing key and freshness, correct ZIP provenance, Staging-specific authority, backup and rollback, deployed SHA readback |

Dynamic registry candidates are also suggested by matching **enrolled operation
capability tags** against the problem domain. They always remain
`UNTRUSTED_EQUIVALENCE_CANDIDATE`, even if their executor registration
reports eligible. A host agent, external connector, self-hosted runner or
new provider **cannot** become a permitted fallback solely because its name
or tool exists. Its exact evidence contract, owner grant, fresh runtime
identity and source/restore scope must be certified separately.

Use this search loop: (1) discover current alternatives, (2) pick the
highest-ranked available *preflight*, (3) inspect missing evidence and
separately enroll provider/Host authority if needed, (4) run the genuine
independent tests and obtain original signed approval, (5) use only the
existing exact native execution Ability, (6) perform fresh independent
readback. If no alternative satisfies the original contract, report a
specific external integration gap rather than auto-lowering hard gates.

For GitHub CI outage see `docs/runbooks/ci-outage-selected-head-staging.md`.
An approved offline signer is a pre-existing certified alternative **only
after** the actual signer/key/evidence channel is enrolled. Neither local
simulation nor an unsigned archive is a recognized certificate.

### Test and rollout status
- `tests/recovery-alternatives-runtime.php`: independent refusal and
  candidate-priority fixture.
- `tests/brand-core-remote-recovery-runtime.php`: the existing PHP 7.4/8.3
  matrix fixture now includes fallback route assertions.
- `tests/brand-core-remote-recovery-contract.py`: existing static CI
  guard now inspects nonauthorizing fallback implementation.
- **GitHub Actions queue state is not PASS**. The independent fixture should
  also be added to the workflow when workflow editing is admitted. The
  tests and the new Ability are not live on Staging until a certified
  selected-head package is installed and read back.

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
### Independent provider identity proof (new read-only acceptance)

The old legacy plan by itself does not prove Google Drive ownership. For
one `source_id` returned by discovery, use
`context/legacy-owner-transfer-evidence` with exactly `{ "source_id": "<64-hex>" }`
**after** the read-only plan. It re-reads the original folder and performs
a bounded, *complete* recursive provider inventory. A successful report must
show `folder_membership_verified=true`, an exact match to the stored
`asset_count` (historically 12 for All Royal Egypt), and a
`provider_proof_sha256` bound to the original source plan, unchanged provider
file IDs, parent identities, MIME types, content hashes and modified versions.
Provider extras can exist; all original files must be present. Missing IDs,
duplicate IDs, different folder identity, truncated API listing, changed
recursive scope, shortcuts and unknown parentage all block adoption.

**Membership is not legal ownership, supplier rights, or editorial approval.**
The evidence reports `legal_owner_or_rights_verified=false` and
`independent_owner_approval_required=true`; it changes nothing in Drive or
WordPress. The existing governed owner/Host approval workflow must independently
confirm the original owner and business scope. Never use the file title,
general Drive access or a free-form note alone as owner attestation.

The separately approved Staging-only
`context/legacy-owner-transfer-apply` now **requires**
`expected_provider_proof_sha256` in addition to its exact plan digest, Brand
ID, original folder ID, evidence reference and confirmation. Immediately
before the locked registry mutation it rescans the actual provider and
recomputes this proof, rejecting `mad4b_legacy_transfer_provider_proof_stale`
when any verified file identity or version changed. Re-run the evidence read
and obtain a new approval; never auto-refresh the proof inside the write.
The Host-signed fresh identity, one-time approval and independently certified
durable rollback gates are unchanged and cannot be replaced by this proof.

Native refusal fixture:
`tests/context-legacy-owner-provider-proof-runtime.php` (12 original
assets + one unrelated extra; missing, truncated, duplicate, shortcut, wrong
folder, parent, scope, canonical IDs, content version drift). Run this in PHP
7.4 and 8.3, followed by independent real provider 403/429/timeouts and
database crash/restart/rollback certification. GitHub Actions with zero
executed jobs is not an acceptance receipt.

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

## Additional invariants for the legacy transfer release gate

- Every unbound legacy `source_id` must equal the original deterministic
  SHA-256 of `site_uuid|google_drive|governed|folder_id|`; every
  `asset_id` and its option-record key must equal SHA-256 of
  `source_id|provider_file_id`. A hexadecimal ID by itself is not evidence.
- Before the locked WordPress update, a **fresh, complete, bounded Drive
  folder scan** must independently find every previously stored file ID
  inside the original folder and its allowed subtree. Missing/truncated/
  timed-out provider inventories deny the transfer. The transfer never
  downloads or copies files into a parallel source.
- A constant claiming rollback certification is insufficient. The same
  call requires a fresh signed, nonce-bound Host identity proof from the
  single currently enrolled Host Runner. **Neither condition is currently
  independently accepted on this Staging deployment.**
- The locked transfer checks plan SHA, Brand identity, registry state and
  source/asset counts, then independently re-reads the new options and
  verifies old approvals were invalidated. A separate
  `context/legacy-owner-transfer-readback` remains read-only and marks
  `provider_readback_performed=false`; it is not proof of cross-request
  crash recovery or license ownership.
- Before removing the rollout block, run real DB transaction/crash
  simulations, provider failure/race cases and independent signed Host
  rollback evidence. A green simulated PHP reducer is insufficient.
- As of the latest read, GitHub Actions runs on PR #258 were failing before
  job execution (empty job lists for sampled runs); treat CI as **unverified**
  rather than PASS. The plugin remains Draft, with no Production change.

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
