# Feature 007 — Brand Core recovery from A to Z through the existing MAD4B MCP

## Governed Source Onboarding — 14 October 2026

The exact Staging failure `mad4b_integrity_scope_blocked` after **Add Source Folder**
is a deployment identity gate, not a Google Drive folder permission or Managed
policy failure. The HTML form previously called `upsert_source()` directly,
where `with_registry_lock()` must deny any unbound site identity.

**Remediation in this HEAD:** The non-authorizing
`mad4b/context-source-onboarding-preflight` is registered as a governed read
Ability and exposed through `mad4b-read`. It rejects arbitrary input and
Production, then determines the next safe action from the current Site Profile,
actual native Deployment Binding, original Context legacy census, existing
authorized sources and saved Google Drive connection. It reports only bounded
quarantine counts and a hash of the selected folder ID before trusted identity
is established. It cannot grant ownership, alter files, persist options or
issue an approval ticket.

The wp-admin Folder Picker calls the same preflight **before displaying an
enabled Add button**, and the POST handler re-runs it immediately before
registry mutation. When blocked, no registry write is attempted; the page
returns to the selected folder with nonauthorizing display hints for policy
and recursive scope, but **never carries forward an approval ticket, owner
consent, token, source ownership, or a signed plan**. The Source Folders tab
now shows counts of quarantined original records rather than falsely saying
there are no recorded sources. Runtime Obstacle Resolver classifies the
integrity error as `source_onboarding`, and Operator Journeys surfaces the
preflight ahead of native binding, owner transfer, exact source upsert, scan,
review and coverage.

**Expected recovery sequence:** Preflight → independently approved
WordPress-native Deployment Binding Apply → new-request readback → Context
Census → original source and folder/file evidence → native shadow and owner
transfer (if applicable) → readback → explicit managed policy authorization
→ complete source scan → file-by-file governed review → Brand Core Context
Receipt. Re-query the preflight at each stage. Do not infer legal ownership
or certified disaster recovery from a verified WordPress scope.

**As-built validation:** Static delimiter/quote scanner PASS for the changed
PHP files and fixtures, and GitHub content readback confirms the expected
Ability/UI/CI registrations. PHP CLI execution, live mounted plugin
acceptance, exact-head ZIP build and owner-authorized operations remain
independent acceptance gates.

---

## Incident: ETG native binding fails after one-time approval claim (2026-10-14)

**Evidence:** The original ETG native-binding ticket
`868d97c0-30d5-4258-935a-602cf22317e5` was legitimately
AI-approved under the live `ai_autonomous` Staging policy, then advanced to
`failed` when the governed ability entered execution. The append-only audit
showed `approval-claimed` and execution checkpoints, but the independent
WordPress Site Profile read stayed on revision `6` with
`deployment_binding_bound=false` and `mutation_pending_audit=false`.
The ticket is terminal; never replay it or substitute new unapproved input.

**Root-cause correction:** The existing immutable execution boundary
already claims `mad4b/deployment-binding-native-apply` once. The old
`apply()` invoked authorization and `claim_mutation()` a second time on
the **same one-time ticket**, predictably failing once the first claim
entered `executing`. Permission preflight remains separate and
non-consuming. The update removes the nested claim, but does **not** make
`apply()` a direct public write: it fails closed unless an ephemeral
callback proof was minted by the trusted authorization wrapper **after**
its real exact ticket claim and provider-entry checkpoint. The proof
matches the ticket and complete callback input, exists only in that
callback's PHP scope, and is cleared with `finally` on both success and
exception.

**Regression fixtures:** `tests/deployment-binding-native-runtime.php`
refuses direct calls and checks only one outer approval claim. The
separate real Authorization class fixture,
`tests/deployment-binding-claim-context-runtime.php`, checks exact
ticket/input matching, wrong-ticket denial, preflight-only denial,
other-ability isolation, and exception-safe proof erasure. Both are
included in the PHP 7.4/8.3 Context Authority CI workflow.

**Stage rollout boundary:** The GitHub PR is development source, not
the installed Control Plane. `mad4b/control-plane-selected-head-plan`
on ETG reports `eligible=false` for the latest PR:
`exact_staging_binding_required`,
`mad4b_selected_head_certified_artifact_unavailable`,
`selected_head_opt_in_disabled`. The native release channel points
to an older build and is not an upgrade route. Do not force-install an
uncertified archive, downgrade, or replay the failed ticket. Use a
separately governed and testable Staging bootstrap/update route with
fresh exact source artifact provenance, separate owner approval and
post-update readback; only then create a NEW exact binding ticket and
run a distinct-request binding readback and Context Census.
Never infer live PASS from a source commit or an AI-approved ticket.

---

## Feature 007 — full WordPress-native Lifecycle closure (2026-10-13)

**Deployment binding is per site.** The two exact staging environments
(Egypt Tour Gates and All Royal Egypt) each require their OWN fresh
`mad4b/deployment-binding-native-status` → `...-plan` → **human-owned,
exact approved** `...-apply` ticket. Reusing a plan, ticket, Site UUID,
profile digest or secret across sites is forbidden. `apply` returns
`BOUND_NEW_REQUEST_READBACK_REQUIRED`, not acceptance. In a **new** request
call `mad4b/deployment-binding-native-readback` with the expected Site UUID
and the post-commit minimum profile revision. A binding written by the NEW code must return
`WORDPRESS_BINDING_READBACK_PASS` in a distinct PHP request before the
fresh `context/legacy-reconciliation-census`. **Pre-upgrade compatibility:**
a binding already created under the older Staging plugin lacks the new writer
request marker; if the site identity, private key and original profile digest
still match, readback reports `LEGACY_BINDING_SCOPE_VERIFIED_NO_REQUEST_PROOF`,
with `functional_scope_ready_for_census=true` but
`independent_request_observed=false`. This allows the census without
unnecessary secret rotation and NEVER claims fresh-request, physical Host,
MariaDB or rollback certification. Unsupported filesystem layout, mismatched
site identity or a lost secret remain blocked.

**Two distinct recovery tiers.** `mad4b/brand-core-remote-recovery-status`
now exposes `functional_lifecycle` and `disaster_recovery` separately.
The existing eight historical stages remain an independent, non-certified
host/disaster assessment; they no longer need to be treated as the status
of the WordPress-only workflow. Neither functional readiness nor an owner
transfer receipt is proof of MariaDB crash/restart or a restored backup.
Commercial release and Production remain separately gated.

**WordPress scratch expiry and recovery.** The native `shadow` is
single-active-job and valid for 900 seconds. The readback now exposes
`expires_at`, `remaining_seconds`, and explicit expired/cleanup states.
New owner-transfer plans reject a shadow with less than **90 seconds**
remaining. A failed/expired attempt is restarted only after the separately
governed `mad4b/native-recovery-cleanup` and a fresh exact shadow plan.
WP-Cron can clean up expired scratch data but does not approve a write;
its deletion now needs a successful append-only audit intent. Without that
audit it retains the scratch for the owner's explicit recovery path.

**Avoid stale proof under lock.** The complete Google Drive folder scan,
original file matching, native shadow proof and the final prelock proof all
finish **outside** the 45-second Context Registry lock. The locked phase
rechecks local source/asset CAS state and requires a prelock result not older
than 10 seconds, so another invocation cannot keep the lock through
unbounded network I/O. External file mutations after the last provider
observation are still possible; the readback does not certify them away.
Owner approval, exact plan, provider file-version digests, and canonical
Site/Brand identity are mandatory.

**Transfer post-commit receipt.** `context/native-owner-transfer-apply`
records functional-only provenance **inside the original Context source
record** (not a parallel registry): approved native plan hash, provider proof
hash, hashed WordPress shadow job ID, hashed human approval ticket ID,
actual WordPress owner ID, and false crash/rollback flags. It also returns
`next_readback_ability=context/native-owner-transfer-readback`.
A separate call with original source ID, original job ID and native plan hash
must return `FUNCTIONAL_TRANSFER_READBACK_PASS`. Until then there is no
functional transfer acceptance. After a PASS, re-scan source content,
normalize existing assets, approve each exact file separately, verify
`brand_strategy`, `tone_of_voice` and `editorial_guidelines` coverage,
and only then enter independently approved draft/publication gates.

**Tests scheduled for PHP 7.4 + PHP 8.3:** binding plan/apply/readback
and cross-site denials; native shadow expiry and audit-before-cron-cleanup;
Context source/proof/job/owner-ticket denials and functional readback; and
two-tier recovery status. Passing pure fixtures is not equivalent to
real WordPress Staging verification, backup restoration or an approved
exact-head plugin package.

---

## WordPress-native functional owner transfer policy (2026-10-13)

This is a **new opt-in governed Staging lane**, deliberately distinct from
`context/legacy-owner-transfer-apply`. It never claims independent physical
Host verification, MariaDB crash/restart certification, independently restored
backup, or disaster-recovery rollback certification. The existing
Host-certified write lane remains unchanged and fail-closed.

### Exact operator lifecycle (no independent Host Runner for this lane)

1. Establish exact enrolled **Staging** Deployment Binding with
   `mad4b/deployment-binding-native-status` → `...-plan` →
   separately owner-approved `...-apply`. The current Context Census
   `UNVERIFIED_DEPLOYMENT_BINDING` on ETG and ARE blocks reliable legacy
   owner discovery until this occurs.
2. Discover original legacy sources by
   `context/legacy-owner-transfer-discover`. Select an eligible, canonical
   unbound source; never create a new Source or a duplicate Google Drive file.
3. Read `context/legacy-owner-transfer-evidence`: its complete
   original-folder scan must prove *every existing stored file ID*, current
   original parent and source/brand/site registry identity. Review the legal
   and business ownership evidence independently; Drive membership alone is
   **not** permission to adopt someone else's content.
4. Plan an in-WordPress scratch shadow via
   `mad4b/native-recovery-plan` (`mode=shadow`), obtain separately governed
   permission for `mad4b/native-recovery-start`, and read back with
   `mad4b/native-recovery-readback` in a *different HTTP request*.
   This does not change original Context registry or claim crash safety.
5. Call `context/native-owner-transfer-plan` with exact
   `source_id` and `job_id`; receive `plan_sha256`,
   `legacy_plan_sha256`, `provider_proof_sha256`, site identity,
   brand, original folder ID and file count. Fresh shadow + provider + source
   are reverified, including a post-change read.
6. Human Staging owner explicitly confirms
   `APPROVE WORDPRESS FUNCTIONAL TRANSFER ONLY`, supplies an auditable
   `owner_evidence_reference` (12–500 characters), and separately approves
   a **one-time, exact, claimable write ticket** for
   `context/native-owner-transfer-apply`. The same mapped WordPress owner
   must hold OAuth authority-step-up; AI approval or a generic ChatGPT token
   is not accepted.
7. Execute `context/native-owner-transfer-apply` with exact
   `source_id`, `job_id`, `expected_native_plan_sha256`,
   `expected_plan_sha256`, `expected_brand_id`,
   `reviewed_external_root_id`, `owner_evidence_reference`,
   `expected_provider_proof_sha256`, and explicit confirmation.
   The original Context CAS lock and compensation/audit path are reused.
   A fresh proof and matching source plan are checked **again inside lock**.
   Existing source ID/file IDs are preserved, source made read-only, every
   previous content/AI approval invalidated, transferred files marked stale.
8. Run `context/legacy-owner-transfer-readback` from a new request;
   check original source count and that no approved/reviewed legacy asset was
   inherited. Governed new scan (`context/source-scan-plan` and separately
   approved apply) is required before file-by-file
   `context/remote-owner-review-plan` → approved human ticket →
   `context/remote-owner-review-apply` → readback.
9. Only the three exact reviewed Brand Core categories may pass
   `context/brand-core-coverage`. Content drafting/publication still has
   separate release and user-approval gates.

**Risk and scope:** WordPress native Options/Cron is enough for **functional**
shadow testing. It does not establish physical durability after a killed
MariaDB process. A successful native transfer receipt explicitly returns
`functional_only=true`, `mariadb_crash_certified=false`,
`independent_backup_certified=false`, `rollback_certified=false`;
it does **not** make Production ready. Existing independent disaster
recovery acceptance is still required before risky Production promotion.

**Source checks:** `tests/context-native-owner-transfer-runtime.php`
is added to `.github/workflows/mad4b-context-authority.yml` on PHP
7.4/8.3. The fixture checks a positive exact source/shadow plan and
fail-closed paths for stale Drive version, wrong source, same HTTP request,
Production environment, AI/nonmatching ticket, stale plan, and the unchanged
legacy Host gate. Runtime CI success and real Staging acceptance have
not yet been demonstrated.

---

## WordPress-only functional recovery and per-file approval mode — 2026-10-13

**Live Staging observation (2026-10-13):** ETG and ARE both returned
`UNVERIFIED_DEPLOYMENT_BINDING` from the privileged Context census;
`legacy-owner-transfer-discover` yielded zero eligible candidates and one
unclassified/quarantined source per site. Existing native binding preflight
returned `eligible=true` on both sites but the write requires separate
owner-governed authorization. No binding was created by this review.

**Mode:** `mad4b/brand-core-wordpress-mode` (read-only, Staging administrator,
existing Context Authority registry, no Host Runner/SSH/independent worker).
This is an **operational mode**, not a replacement for a real MariaDB
crash/restart and independently restored backup. The legacy ownership mutation
remains protected by its existing independent Host/rollback gate.

1. Call `mad4b/brand-core-wordpress-mode` with `{}`. It first
   inspects the WordPress Site Profile and Context census. If
   `UNVERIFIED_DEPLOYMENT_BINDING` is observed, **stop file approval** and
   use existing WordPress-native `mad4b/deployment-binding-native-status`
   → `mad4b/deployment-binding-native-plan` → separately owner-approved
   `mad4b/deployment-binding-native-apply` → status/census readback.
   This creates a host-local staging binding without SSH or editing wp-config;
   it is **not** independent physical Host or crash attestation.
   Once scope is verified, the mode auto-discovers the *single* eligible
   original selected folder. Supply `source_id` only if multiple eligible
   folders remain. The API never demands individual Google Drive file IDs.
2. Set `include_provider_scan=true` after confirming the selected folder.
   WordPress uses its existing Google Drive provider and
   `mad4b/selected-folder-scope-proof` to validate the complete original
   file membership. The result is **folder scope**, not file ownership.
3. Optional functional test: use the existing `mad4b/native-recovery-plan`
   with `mode=shadow`, followed by a separately approved
   `mad4b/native-recovery-start`, fresh **different WordPress HTTP request**
   to `mad4b/native-recovery-readback`, and governed
   `mad4b/native-recovery-cleanup`. WordPress Options/CRON are sufficient
   for *this scratch proof*, no local CLI/Host Runner. The scratch does not
   mutate Context assets or certify MariaDB recovery.
4. **Legacy quarantined source transition remains distinct:** the existing
   `context/legacy-owner-transfer-plan` → owner evidence/one-time exact
   approved `context/legacy-owner-transfer-apply` →
   `context/legacy-owner-transfer-readback` does require its original Host
   identity and rollback proof; this new mode MUST NOT bypass those gates.
   If this requirement is undesirable, a different operational trust tier
   needs an explicit governance/security design and independent approval,
   rather than silently changing the meaning of crash certification.
5. When source ownership is legitimately established, call
   `context/source-scan-plan` and a separately governed
   `context/source-scan-apply`. The fresh scan must normalize complete
   content and establish category/authority before review.
6. Call `mad4b/brand-core-wordpress-mode` again. Its `files[]` is a
   bounded queue populated **only from site-owned governed Context assets**,
   with `asset_id`, `content_hash`, `exact_plan_sha256`, and review
   eligibility. No copy of the source registry is created.
7. Each eligible file can be approved, rejected or sent for changes by the
   authenticated WordPress owner through the existing
   `context/remote-owner-review-plan` →
   one-time human-approved governed mutation ticket →
   `context/remote-owner-review-apply` →
   `context/review-queue` readback. Each decision remains separately
   approved. A WordPress admin can alternatively use the **existing
   `review_asset` UI path**, which still validates the exact content hash.
   This orchestration does not auto-approve a folder or a batch.
8. Finally, require `context/brand-core-coverage` to prove three
   current, exact, reviewed categories: `brand_strategy`,
   `tone_of_voice`, `editorial_guidelines`. Only then may a distinct
   governed draft plan be attempted. Publishing needs separate approval.

**Threat model:** Staging only; origin and profile exact; bounded 30-file
review page; no caller-provided success flags; repeated read of census,
coverage and site profile; stale/foreign/uncategorized assets are denied;
old reviews not inherited on ownership transition; no production promotion,
self-issued rollback certification, automatic approval or delegated legal
ownership inference. Requires PHP/WordPress and real-site acceptance before
shipping. `source_count + quarantined asset_count` is diagnostic, not proof
that those files are current or authorized.

---


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

## MCP approval transport repair — direct Staging bootstrap (11 Oct 2026)

After the native host-local binding became `READY_FOR_OWNER_APPROVAL`
on All Royal Egypt Staging, all 95 governed write grants were reconciled
and Managed Skills returned READY. The two-step write sequence failed at
the approval ticket bootstrap:

- The MCP connector exposed `mad4b_approval_plan` as a normalized tool
  but returned `Tool not found: mad4b-approval-plan` on that direct path.
- `mad4b/write-execute` with
  `ability_name=mad4b/approval-plan`, pinned schema,
  classification, authority scope and preparation receipt returned an
  opaque internal exception.
- `mad4b/approval-plan-reconcile` returned `found=false`, no
  pending exact target ticket. This is not a usable approval and must
  never be replaced with an invented ticket UUID or a skipped gate.

The coding gap was two-tiered in
`includes/class-mad4b-scp-servers.php`:
the direct ChatGPT core tools did not register the approval planner, and
even adding them to the core inventory alone would not work because
`chatgpt_base_tools()` deliberately allowlists only approved direct
mutation transports. Adding them to the unrestricted allowlist would
also risk exposing privileged actions without step-up.

**Bounded code repair:** `chatgpt_approval_bootstrap_tools()` admits
exactly `mad4b/approval-plan` and, only if currently eligible,
`mad4b/approval-ai-decide`. Prerequisites: exact Staging environment,
effective candidate-bound governed Write, registered abilities with
read-only=false, and a mounted `mad4b-write` target. The tools are
added to both unified and fallback direct mutation allowlists **and**
the reviewed **optional** direct step-up set. Existing
`MAD4B_SCP_MCP_Catalog_Diagnostics` only serializes these privileged
direct tools under the enrolled client bearer with
`mad4b:authority:step-up` and keeps required base tools available when
an optional schema/budget fails.

This fix changes **transport discoverability only**. It does not issue
OAuth credentials, create an NHI grant, approve a ticket, change AI
decision policy, alter the target operation or authorize production.
Existing `MAD4B_SCP_Staging_Write_Planning_Guard` and
`MAD4B_SCP_Approval_Tickets` still require exact authority checks,
resource guard, candidate-bound one-time ticket, AI delegation,
classification, budgets, audit and executed-target readback.

### Deployment and acceptance checklist

1. Certify the immutable PR #258 head and install its ZIP on **Staging
   only** through an already authorized update path. G9 fingerprint
   verification must stay strict; CI queues are not PASS.
2. Reconcile governed Staging Write and Managed Skills to that
   installed head. Confirm no Developer/Breakglass/Production grants.
3. Refresh MCP tools/list / reconnect the exact enrolled ChatGPT
   client, including the existing authority step-up token scope.
   Read tools/list on a fresh request and verify that
   `mad4b-approval-plan` appears only when the step-up bearer is
   current, and disappears without it.
4. Verify `mad4b/deployment-binding-native-status` returns
   `READY_FOR_OWNER_APPROVAL` and obtain fresh
   `deployment-binding-native-plan` data.
5. Create the exact pending approval ticket with the source-approved
   `mad4b/approval-plan` input (never fabricate a ticket).
   Verify `approval-plan-reconcile` for the same agent, target
   fingerprint and operation payload. If plan returns an opaque
   error, inspect reconciliation and do not blindly retry a
   potentially successful mutation.
6. Use `mad4b/approval-ai-decide` **only if the live
   `operation-classify` result is `ai_autonomous`** and its
   configured Agent/NHI/delegation match; otherwise use the human
   approval handoff and stop automated approval.
7. Dispatch `mad4b/deployment-binding-native-apply` with the
   freshly approved one-time UUIDv4 ticket, the same exact plan
   values and receipt. Follow with an independent new-request
   `site-profile-status`, `deployment-mode-status`,
   `legacy-reconciliation-census` and
   `operational-acceptance-evidence` readback. The Site Profile
   revision will advance; reconcile identity-bound grants again.

**Live status at source repair:** No ticket had been created, no
new Host key or WordPress Site Profile mutation was proven. Installing
source and running E2E remain independent. The fix is a reviewed direct
transport option; if the connected host does not refresh tools/list
or rejects the new direct tools, do not bypass authorization.

## Simplest alternative: one approved WordPress-native Host-local binding

Use the newly added `MAD4B_SCP_Deployment_Binding_Native` when the
Staging host's PHP user can create a private directory **outside the real
web document root**. This path requires **NO SSH, NO new connector,
NO wp-config.php edit, and NO user-visible secret**.

WordPress-native owner workflow:
1. `mad4b/deployment-binding-native-status {}`: read the current
   `READY_FOR_OWNER_APPROVAL` or exact refusal reason. It checks
   the persisted Staging Site Profile, real `ABSPATH`, server
   `DOCUMENT_ROOT`, and candidate host filesystem parent.
2. `mad4b/deployment-binding-native-plan {}`: bind a source plan to
   current Site UUID, origin, environment, record revision and profile
   digest, and the SHA of an internally derived host file path. **No
   arbitrary location or secret is accepted in MCP arguments.**
3. Approve `mad4b/deployment-binding-native-apply` with the
   original one-use MAD4B owner ticket plus the exact plan fields and
   `confirmation=BIND...` contract phrase
   `CREATE HOST-LOCAL STAGING BINDING`. This is **one governed write
   transaction**, not a request for the owner to copy a password.
4. On the Staging PHP host itself, the action creates an exclusive
   `0700` private directory in the parent of WordPress document root
   and a new `0600` 48-byte CSPRNG binding key file. It never
   writes the key in the DB, logs, Media Library, GitHub or MCP response.
   `Site_Profile::deployment_binding_secret()` reads this file
   on subsequent requests without a configuration edit.
5. Reuses the existing audited `Site_Profile::commit_record_with_audit`
   CAS. The update changes only binding digest, revision and timestamp.
   Same Site UUID, canonical origin, OAuth users, App ID, and all feature
   flags are retained. It verifies the result and requests a separate
   new MCP read of site profile and `mad4b/deployment-mode-status`.
6. A DB commit failure compensates a newly created, unmodified private
   key file. Replays, key rotation, Production, missing owner ticket,
   unsafe/missing web document root, nonwritable parent, existing
   private file, bad directory/file permissions and symlinks are denied.

**Security distinctions and limitations:**

- This is an **on-host key isolated from a DB-only clone**, not a
  separately enrolled host-owned root trust credential or real
  MariaDB crash certificate. A full clone of **both WordPress files
  and its database** may carry this key if backup configuration
  wrongly includes the adjacent private directory. Keep the private
  directory excluded from transferable site backups.
- Auto provisioning is allowed only when the parent of the actual
  WordPress root is outside `DOCUMENT_ROOT` and writable by PHP.
  The plugin cannot prove alternate vhost aliases or host ownership;
  if the host cannot demonstrate the boundary, the native route
  fails closed and the existing owner-approved protected Host config
  remains the alternative.
- On many shared hosts parent directories are intentionally not
  writable, and on WP-CLI requests `DOCUMENT_ROOT` may be absent:
  do not invent values or force the creation into `wp-content`.
  The native path is optional and site-specific.
- The operation changes only a **binding**. It does not grant new
  write permissions, approve Brand Core or transfer the original
  Google Drive assets. After successful Staging binding, independently
  rerun the trusted Context Census and acceptance evidence.
- Operators may use the existing `mad4b/deployment-binding-setup-*`
  flow for an independently provisioned host secret. A key already
  present or bound is never silently rotated by this native option.

Source tests:
`tests/deployment-binding-native-runtime.php` runs alongside
`tests/deployment-binding-setup-runtime.php` in Feature 007's
PHP 7.4/8.3 CI, and the Brand Core static contract checks
approval/host isolation. **CI queued is not PASS, and source on GitHub
is not installed Staging acceptance.**

## Setup Deployment Binding — host-secret-backed, no site identity replacement

**Primary incident:** All Royal Egypt `https://staging.allroyalegypt.com`
already has an enrolled Staging Site Profile (UUID, origin, OAuth users,
app ID, feature flags and current writes). Its independent deployment
binding is missing. The resolver refuses Brand scope under
`SITE_DEPLOYMENT_BINDING_NOT_ENROLLED`.

New PR #258 native MCP contract:
`MAD4B_SCP_Deployment_Binding_Setup` registers:

- `mad4b/deployment-binding-setup-status` — read-only, no key material
- `mad4b/deployment-binding-setup-plan` — deterministic Staging
  exact-profile + current host key *digest* plan; returns no plaintext key
- `mad4b/deployment-binding-setup-apply` — separately approved current
  owner ChatGPT OAuth step-up and one-time governance claim; atomically
  advances existing Site Profile revision, changes **only**
  `deployment_binding_digest`, `revision`, `updated_at`, and
  preserves UUID, origin, app ID, OAuth users and all existing feature flags

### 1. Host setting: provision independently, outside WordPress

Use Hostinger hPanel, *for the exact Staging hosting account only*:
Websites → Dashboard → Files → File Manager. Choose "Access all files"
where permitted, locate the actual Staging WordPress document root and
protect a **sibling private directory outside** `public_html`. On
plans with SSH access, you may use an enrolled host terminal. Hostinger
Agency plans can restrict remote access to `wp-content` and require
temporarily unlocking `wp-config.php` editing in the File Manager.
If the host does not permit a directory outside the webroot, use a
provider-managed protected environment variable/secret service instead.
**Never** put the secret in Media Library, WP option, database, source,
chat messages, a public web route or an Actions log.

In an authorized PHP CLI shell on the hosting account, from a trusted
copy of the repository, run the provisioner **on the host itself**:

```sh
# Use your actual inspected paths; examples are not authoritative paths.
mkdir -m 700 /YOUR-HOST-ACCOUNT/private/mad4b-staging
php tools/mad4b_provision_deployment_binding.php \
  /YOUR-HOST-ACCOUNT/staging-site/public_html \
  /YOUR-HOST-ACCOUNT/private/mad4b-staging
```

The helper demands a real WordPress root, a writable directory that
is **neither under nor an ancestor of** the public site directory, strict
private permissions, and an absent target file. It generates 48 bytes
using PHP `random_bytes`, stores the hex-encoded value only in a
non-public `0600` PHP file, never prints it and never rotates an
existing file. The helper prints only a path-bound
`require_once` line for the existing `wp-config.php`.

Insert that exact `require_once '/actual/private/path/...php';` line
**before the `require_once ABSPATH . 'wp-settings.php';` bootstrap** in
the EXISTING Staging `wp-config.php`. Preserve all database salts, URLs,
environment settings and Site UUID; do not overwrite the config.
Back up `wp-config.php` to a restricted location first. Confirm PHP
can read the private file using the same site PHP user. On managed
hosting with a secure environment-secret feature, inject a CSPRNG key
there and use the existing
`MAD4B_SCP_DEPLOYMENT_BINDING` constant or audited
`mad4b_scp_deployment_binding` filter to read it during WordPress
bootstrap. Do not derive it from WordPress salts or clone a database key.

### 2. Governed MCP enrollment after hosting configuration is ready

1. Call `mad4b/deployment-binding-setup-status {}`. It should report
   `READY_FOR_EXACT_OWNER_APPROVAL` and
   `host_secret_configured=true`. If it reports
   `HOST_SECRET_NOT_CONFIGURED`, STOP; no WordPress write can provision
   a truly independent host key. If `STORED_HOST_BINDING_MISMATCH`,
   STOP; rotation needs a different reviewed process.
2. Call `mad4b/deployment-binding-setup-plan {}` to obtain exact
   `plan_sha256`, `expected_profile_digest`,
   `expected_revision`, and `expected_site_uuid`.
3. Obtain normal **one-time owner-approved write ticket** for
   `mad4b/deployment-binding-setup-apply`. The exact ChatGPT OAuth
   owner step-up, currently bound candidate/grant, capability descriptor,
   Site Profile CAS and append-only audit must all pass. Supply the
   readback binding arguments from step 2 plus
   `confirmation="BIND EXISTING STAGING SITE IDENTITY"` and
   `_mad4b_approval_ticket_id`. No secret value is an input.
4. Independently call `mad4b/deployment-binding-setup-status {}`,
   `mad4b/site-profile-status {}`, then
   `mad4b/deployment-mode-status {}`; verify
   `deployment_binding_configured=true`,
   `deployment_binding_bound=true` and
   `deployment_binding_match=true`, original UUID and canonical
   origin unchanged, and no pending mutation. Any remaining
   `BRAND_PROFILE_UNRESOLVED` is a **separate** blocker, not a reason to
   pretend identity binding failed.
5. Reconcile current write/managed-skills candidate bindings if the
   intentionally incremented Site Profile revision makes current
   grants stale. Then rerun
   `context/legacy-reconciliation-census` and
   `mad4b/operational-acceptance-evidence`.
   Do not auto transfer quarantined records or approve media rights.

### Safety restrictions and installation state

This PR delivers the operator workflow and host helper **as source**;
it does **not** modify the active Staging host, write raw server keys,
approve `developer-filesystem`'s human-only lane, bypass GitHub
signing or install an uncertified plugin ZIP. New `mad4b-write` tool
registration requires grant/approval on the **installed** exact head.
An already bound identity is explicitly **not rotated** by this path.

Tests: `tests/deployment-binding-setup-runtime.php`, in the Feature 007
PHP 7.4 and 8.3 matrix. The fixture proves no secret in MCP, no
premature write, fails on old plan/approval/nonadmin/Production,
preserves original option fields and refuses rotation/replay.

## Legacy census repair when Staging deployment binding is absent

Incident observed on All Royal Egypt Staging, 11 Oct 2026:
`mad4b/deployment-mode-status` returned `BLOCKED`,
`SITE_DEPLOYMENT_BINDING_NOT_ENROLLED`; live
`mad4b/site-profile-status` returned
`deployment_binding_configured=false` and
`deployment_binding_bound=false`. Healthy MCP/Skills/Write Authority
diagnostics do **not** prove independent deployment-bound Brand scope.
Both `context/legacy-reconciliation-census` and
`mad4b/operational-acceptance-evidence` fail at scope capture.
This is **not** proof that the legacy registry is empty.

### Safe compatibility read introduced in PR #258

When and ONLY when the resolver reports this exact missing-binding
reason, WordPress is the current enrolled Staging site, an administrator
is reading, and both legacy options are arrays of at most 1,000 records,
`legacy_reconciliation_census` returns privacy-preserving aggregate
counts. Every existing record remains `scope_unverified`. No source ID,
Drive file or provider credential is exposed; the response has
`scope_fingerprint=""`, `operational_scope_verified=false`,
`migration_authorized=false`. A second read detects options/profile
drift. A malformed option, Production, foreign profile or a different
resolver failure remains blocked.

Aggregated counts are **NOT** certification. Both Brand Core Recovery
and Operational Acceptance require a true scope SHA-256 checkpoint
and refuse `UNVERIFIED_DEPLOYMENT_BINDING` as accepted census evidence.
Independent owner consent, media rights, real MariaDB restore and signed
package evidence are not weakened.

### Steps to establish full trusted scope

1. Read `mad4b/site-profile-status` and
   `mad4b/deployment-mode-status`; inspect configured/bound/match
   flags, exact environment/origin and enrolled profile revision.
2. Provision a CSPRNG-generated, independent binding secret outside
   the WordPress database. Supply `MAD4B_SCP_DEPLOYMENT_BINDING`
   via protected host configuration or audited
   `mad4b_scp_deployment_binding` filter. The secret must be
   32–1024 bytes without whitespace/control bytes. Never store it in
   chat, the Media Library, GitHub source or a WordPress option.
3. Under existing **owner-authorized** Site Profile enrollment,
   re-save the existing canonical origin, site identity, Staging
   environment, users and feature flags, with revision/CAS and
   append-only audit so that the enrolled digest binds the root.
   Do not invent a new Site UUID or change Production.
4. Confirm bound/configured/match flags are all true and
   `mad4b/deployment-mode-status.status=RESOLVED_FOR_REVIEW_ONLY`.
   Then rerun census and the aggregate evidence reader through a
   separate MCP request.
5. The installed `mad4b/developer-filesystem` remote ability has
   a **human-only** approval lane and an exact ticket. Its existence
   does not authorize arbitrary configuration writes. Do not silently
   derive a pseudo-root from copied database state, WordPress salts,
   request labels or the site's visible hostname.

Standalone refusal tests:
`tests/context-unbound-census-runtime.php` in the Feature 007 PHP
7.4/8.3 matrix. Source repair does not certify installation.

## Unified operational acceptance evidence, read-only by design

PR #258 now adds `mad4b/operational-acceptance-evidence` through the same
`mad4b-read` MCP and canonical Operational Remediation boot as other
read-only diagnostics. The user no longer has to combine eight disparate
status reports manually. Every response includes:

- **Eight bounded stages**: legacy source inventory; original Google Drive
  file membership; independent owner attestation; fresh signed Host identity;
  real independent MariaDB restart/rollback certification; exact-source
  signed package/installed readback; three-category Brand Core review; and
  persisted native CPT draft acceptance
- For every stage, its exact registered read/plan/approved-write/readback
  Ability, mounted/not mounted indicators, any evidence actually observed,
  missing certification and next read-only planning route
- A deterministic `snapshot_sha256` bound to the exact Staging Site
  Profile, original source ID, source/asset registry census, provider
  membership proof (if requested), brand review coverage, Host challenge
  (if requested), current installed commit and static route mapping
- An **independent second WordPress registry and site read**, failing closed
  as `SNAPSHOT_DRIFT_REPLAN` when another request changed observed
  WordPress state mid-collection
- Clear separation of observed facts from legal rights, owner approval,
  signed release evidence, real MariaDB crash receipt and draft acceptance

### Suggested exact remote flow

1. First call `mad4b/operational-acceptance-evidence` with `{}` to
   discover installed Abilities and current Brand review/legacy counts. No
   expensive Google Drive or Host contact runs by default.
2. Obtain the exact original 64-character source ID from
   `context/legacy-owner-transfer-discover`, and exact 40-character
   selected commit from GitHub.
3. Repeat with `{ "source_id":"<64-hex>",
   "candidate_sha":"<40-hex>", "include_provider_scan":true }` to scan
   the **original provider root**, not a guessed replacement Drive folder.
   A full provider scan may be expensive; this is opt-in.
4. If a real enrolled Host exists, `"include_host_challenge":true`
   uses the existing signed Host live verification. It is optional; a
   verified nonce does **not** substitute for database crash testing.
5. Preserve the returned `snapshot_sha256`. On a subsequent separate
   request, send exactly the same options and the
   `"expected_snapshot_sha256"` value. A mismatch returns
   `mad4b_operational_evidence_snapshot_changed_replan`; discard the
   earlier preflight and gather fresh proof.
6. For missing stages inspect `next_read_or_plan_ability`. If
   `effectful_ability` is nonempty, its write must still independently
   satisfy current grant, user step-up, approved exact plan, issuer,
   one-time ticket, and postcondition readback. **No write is dispatched
   by the evidence collector.**
7. Consult `mad4b/brand-core-recovery-alternatives` for fallback options
   if a normal remote executor cannot run. Alternative discovery is not
   equivalent independent certification.

### Non-negotiable proof distinctions

- Census observed while 1 source / 12 assets remain quarantined is merely
  inventory, not a transfer.
- Google Drive membership proof verifies original identities, not legal
  ownership, consent or publication rights.
- The source-transfer postcondition (if any) is exposed only as a
  *supplemental observation*. It cannot self-issue the missing owner
  attestation or reuse old approvals.
- A WordPress Options API scratch PASS is not a MariaDB service crash,
  cold restart, durable rollback or cross-request loss test.
- Matching installed Git SHA is not a package signing certificate,
  accepted CI evidence or independently verified rollback.
- Existing general acceptance and browser acceptance result endpoints
  may be consulted separately. Their mere registration does not bind a
  particular live Brand Core or content receipt to the current site/release.
- The response intentionally keeps `full_operational_acceptance=false`
  until those independent signed/legal/approval adapters are actually
  implemented, enrolled, and verified. Production and Breakglass stay
  disabled. **Do not change the current legacy transfer hard gates**.

Fixture: `tests/operational-acceptance-evidence-runtime.php`, required
in the Feature 007 PHP 7.4 / PHP 8.3 CI matrix, plus the existing
`tests/brand-core-remote-recovery-contract.py` source/privilege guard.
The standalone fixture is not a real Host, Drive OAuth, MariaDB crash or
browser acceptance environment.

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


## 2026-10-14 incident: package identity, pending Google revoke and folder onboarding

Evidence supplied by the operator:

- Built source: `1e77615ae6a6ce6054040add40d75003569d89fe`.
- Offline ZIP SHA-256: `a9b21ff07959ffeff60684c78e35bb1418845d1274ab003a1d07ef8f1f233232`.
- Local PHP 8.3 tree lint: 1,042 files. This does not certify CI or installation.
- Last separately observed live source on both Staging sites: `7c369c1b…`.
- Screenshots show the old deferred MCP inventory panel. Its zero peer count is
  not a completed inventory. Audit Events are per-site history counts, not causes.

The `1e77615a…` source already reuses the authority request's MCP peer inventory,
shows actual foreign route/plugin evidence, and preflights source onboarding.
Do not diagnose an unreviewed foreign transport from a deferred UI placeholder,
and do not disable unrelated plugins or broaden grants without exact route evidence.
A built ZIP is not proof that the active PHP request runs that source.

Additional source corrections in this follow-up:

1. The Google remote Retry callback consumes the immutable wrapper's exact
   ability/ticket/input proof. It does not reauthorize or claim the same ticket a
   second time. Direct PHP calls, mismatched claims and cross-ability borrowing
   fail before provider contact. Proof is erased on return or exception.
2. Pending revoke status retains at most two credential-type / HTTP / allowlisted
   OAuth error entries. Response descriptions, bodies and tokens are never
   projected. An HTTP 400, including `invalid_token`, remains unconfirmed;
   it never clears pending state or enables Drive or reconnect.
3. Operational Integrity preserves the deployment resolver's bounded reason.
   Source onboarding exposes that dependency and the read-only
   `mad4b/deployment-mode-status` next step. An unavailable census reports unknown
   counts rather than zero and cannot authorize a new Managed source.
4. Independent PHP 7.4/8.3 recovery jobs run even if another Context architecture
   test fails earlier. Existing mandatory architecture tests are unchanged.

Acceptance order (separate evidence for each site): installed source and full
manifest verification in a fresh request; current MCP inventory with exact route
and plugin list; separately approved native deployment binding and later readback;
source onboarding preflight and legacy ownership reconciliation; Google revoke
outcome and disabled-state readback; fresh consent only after confirmed revocation;
original folder/file evidence, Managed policy approval and registry readback.
Existing stored grants and a Staging checkpoint do not replace these gates.

No live WordPress calls, provider revocation, installation, permission changes,
master merge or Production action were performed in this source-only follow-up.

Follow-up validation in an isolated PHP 8.3.6 CLI (Ubuntu package bytes verified
against the package index): nine incident fixtures PASS without warnings; all
12 changed PHP files pass lint. These are hermetic/fake-WordPress tests, not live
MCP, database, Google or installation certification.

Pre-existing CI limitations observed on `1e77615a…`: Context Authority job
`115631056032` fails before runtime on `trusted-brand-scope-runtime.php` with
`Stale transfer accepted`. Admin Performance job `115631055117` reports existing
route-registry, post-update-continuation, Context atomicity and admin recovery
fixture failures. The existing Brand Core source contract also expects the old
literal tasks-only admin predicate; current source supports tasks plus controls.
Delivery fingerprint check finds 22 pre-existing stale bindings, none to the
runtime files changed in this follow-up. These issues are not certified repaired
by the nine incident tests and remain release blockers.


### Follow-up source review after `ffff29ca` (2026-10-14)

The branch advanced by 49 commits after `a033272d`. Review used the current
`ffff29ca2287a460357028c4780a3b644079b65f` source and its GitHub Actions logs,
without connecting to either WordPress site.

The concurrent `625abcb1` head already corrected the misleading unknown-MU-loader
automatic-recovery notice; this follow-up preserves that implementation.
The existing administrator-only POST recovery route is now reachable through its
nonce, current-build fingerprint, exact observed-file SHA and explicit confirmation.
Cron and convergence requests still cannot replace a foreign loader. Existing
runtime recovery fixtures retain the backup, audit, lease, CAS and rollback denials.

Content Experience Ability definitions and payload validation moved unchanged into
loaded traits. The registry, definition, runtime and validation files are respectively
863, 589, 803 and 170 lines; each remains below the existing 900-line domain-service
budget. Contract checks inspect both halves and verify their loading, without raising
the budget or changing callbacks, schemas or permissions.

The remaining fixture corrections exercise the actual resolver revision, reject
Context callbacks before entry on a blocked scope, require provider-projected native
build rather than a static core bypass, and distinguish the strict Browser Acceptance
input-schema rejection from the domain's bounded unsupported-field rejection.
Disposable content tests preserve the Brand Core publication denial while checking
draft Page/Trip creation and season assignment. They do not certify live publication.

Only source hashes and byte counts were refreshed across 224 existing delivery
bindings; all authority and acceptance fields were compared to the prior committed
JSON and remained unchanged. The ETG installed-content manifest updates only the
changed BrowserAcceptanceProvider hash. These updates do not grant runtime or
release certification.

Local PHP 8.3 checks passed for all changed PHP files and all nine Google,
binding, folder-scope and MCP inventory regression fixtures. The gateway mutation
guards, complete Content Operations source contract, G6/G9 integrity checks,
operational-scope runtime/source contracts, owned-MU recovery contracts and ETG
build-identity smoke also passed. The real WordPress Browser Acceptance, dynamic
projection and content-season fixtures still require exact-head CI readback.

Known independent gates on the reviewed head include protected workflow/source
contract drift, Feature007/ETG path boundaries, missing exact-head owner attestation,
and unreachable external WPML diagnostics. Governance PR #385 remains an independent
draft review, not an approval. No gate was relaxed and no site was deployed.
