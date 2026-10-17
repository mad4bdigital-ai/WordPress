# Staging recovery: deployment lifecycle and operator UX acceptance

**Change line:** `0.4.0-rc.97`. **Scope:** ETG and All Royal Egypt Staging; each site remains an independent security boundary.
**Production:** never authorized here. **Developer/Breakglass/Raw SQL:** excluded.

## Evidence and limits

- The installed `rc.96` on both sites reported exact source `4fea0bd09f58bb189b91bd9bd51db7a84a099b70` during the October 15 diagnostic. This document does **not** claim `rc.97` is installed or operational.
- Local isolated PHP runtime and source contract tests do not certify a deployed website, external OAuth edge or rollback.
- No pending Google revocation can be cleared solely because Google returns `HTTP 400 invalid_token`; ETG must retain `revocation_pending` until independently verified.
- No owner approval automatically authorizes a separate host-binding ticket. A ChatGPT conversation is not an authenticated site-secret signature.

## User journey: expected screen and action

| Step | What the operator sees | Authoritative source | Only allowed next action | Acceptance |
|---|---|---|---|---|
| 1. Verify installed build | Installed version, exact SHA, manifest status, site UUID, environment | Installed `session-safe-diagnostics` + Site Profile | Read-only refresh | `rc.97`, matching source and manifest, Staging exact origin |
| 2. Identify blocked preflight | Specific blocking state and current folder | `context-source-onboarding-preflight` | Open resolved setup destination, never generic Overview fallback | Correct destination and explicit failure text if no destination |
| 3. Host binding | `READY_FOR_OWNER_APPROVAL` or existing binding | `deployment-binding-native-status/plan` | Initiate **separate** governed approval ticket for native apply | Approved exact ticket with scoped actor/site/plan, no local-key disclosure |
| 4. Binding verification | Host-local binding written / new request readback | `deployment-binding-native-readback` | Independent read-only verification | Identity preserved; secret outside webroot, 0600; no claim of independent Host attestation |
| 5. Governed Write | Exact candidate SHA, Write inventory, blocker list and owner review digest | `staging-write-authority-convergence-handshake` | One explicit, step-up-authorized exact-plan apply using `owner_review_digest` **or** legacy receipt | Same-request audited signed short-lived intent, claimed once; no Developer/Breakglass |
| 6. Write readback | Source and grant inventory now current | New request handshake plus Write Authority status | Check postconditions, do not retry blindly | All exact grants match and candidate bound; fail-closed on drift |
| 7. Context source | Current Google state, legacy ownership, source policy | Google status + Context Authority + source preflight | Review Google independently, then submit source if eligible | Folder membership verified and source policy persisted/read back; no implicit write consent |
| 8. Staging acceptance | Explicit PASS / BLOCKED by lane | Staging certification, browser and provider tests | Manual certification decision | Real live OAuth/MCP, backup and rollback evidence, browser parity, performance, external checks |
| 9. Promotion | Signed release verdict, owner and required reviewers | GitHub exact-head CI and release policy | Merge/promote only via existing gates | No draft, no failures, independent owner approval; Production separate authorization |

### UX changes in this branch

- For `DEPLOYMENT_BINDING_RECOVERY_REQUIRED`, **Open Staging deployment binding instructions** links to `admin.php?page=mad4b-operational-remediation#mad4b-staging-deployment-identity` rather than Context Overview.
- Wrong Site Profile maps directly to `admin.php?page=mad4b-control-plane-site-profile`.
- Google blockers map to the Google Drive tab; ownership blockers map to Source Folders.
- Unknown preflight codes do not display a fake fix button or silently route to Overview. The technical code remains visible and saving remains disabled.
- Handshake exposes the exact `owner_review_digest`; execution of that exact plan under enrolled owner OAuth step-up generates the independently audited signed one-time intent **inside the existing Write apply**, eliminating manual copying of `owner_intent_sha256`. The legacy receipt path remains compatible.
- The hostname-specific native binding requires its **own** approved ticket and independent readback; a Write owner intent never creates or claims that ticket.

### Critical restrictions

1. Render and preparatory GET calls are read-only; no preflight is a grant.
2. All Write execution requires one enrolled owner, exact ChatGPT OAuth client, `mad4b:authority:step-up`, match on full SHA, fingerprint, package manifest, Site Profile revision/digest and exact immutable plan.
3. `owner_review_digest` is **not** itself a receipt, ticket, or authority. Only `attest()` under verified owner OAuth writes a signed and audited short-lived intent. Claim is atomic, nonreplayable.
4. Never accept both `owner_review_digest` and `owner_intent_sha256`; never accept neither, stale digest, wrong site, disabled audit or insufficient scope.
5. Never create binding via direct static `apply()`, local secret upload, database option or a read-only MCP dispatch. Approved WordPress Ability claim boundary is mandatory.
6. Deployment Binding modifies Site Profile revision. Any previously prepared Write plan is now stale and must be re-read **after** binding readback before Write convergence.
7. An application version bump is needed for normal WordPress update discovery. `rc.97` is newer than the deployed `rc.96`; a version badge alone is still insufficient without exact source SHA readback.
8. Candidate changes or post-apply uncertainty require fresh read-only reconciliation and, if necessary, a new scoped approval; no blind retries.
9. Google OAuth connection, provider revocation and Context asset ownership remain separate authority boundaries and cannot be inferred from Write readiness.
10. No alteration to six baseline-owned CI workflows in PR #389; source admission and workflow governance follow their separate review paths.

## Release sequence and evidence checklist

- [ ] Review this PR against the latest base head; keep new source isolated from the six protected workflows.
- [ ] Run hermetic negative tests (missing/dual/stale proof, wrong scope, replay, Prod, Breakglass) and Context route regression.
- [ ] Run PHP 7.4 and PHP 8.3 compatibility tests; run Feature 007 spec, cumulative manifests and complete PHP lint.
- [ ] Record release artifact's canonical exact source SHA, version `rc.97`, fingerprint, manifest and independent ZIP verification.
- [ ] Satisfy exact-head GitHub workflow checks and owner/reviewer approval; draft PR is not a release authorization.
- [ ] Take/verify Staging backup and rollback path *before* updating either site.
- [ ] Install on one Staging site, read back source identity, OAuth and MCP; only then update and verify the other site.
- [ ] Perform separate per-site owner ticket approval for host binding, post-binding independent readback, then recompute Write plan.
- [ ] Apply exact approved Write plan and independently read back candidate and grants; reject any result marked pending.
- [ ] Test Context selected folder, disabled Save and corrective link after recovery; ETG Google revocation must remain blocked until real provider proof.
- [ ] Complete browser/service acceptance and release verdict. Do not claim Production readiness or auto-promote.

## Remaining P1 gap

This release improves error routing and one-call Write owner intent, **but does not yet provide a complete one-screen native-binding ticket creation/approval/apply wizard**. The existing protected ticket process is retained. Treat `one-click end-to-end recovery` as NOT DELIVERED until that separate owner workflow has an audited, executable UI with exact ticket creation and lifecycle readbacks.

## Expanded scope — Unified Staging Recovery UX (PR #395 follow-up)

The operator-facing Staging Recovery Center is mounted inside **Operational Remediation**.
The Context Source Folder preflight's deployment-identity action links to
`admin.php?page=mad4b-operational-remediation#mad4b-staging-recovery`.
It must not fall back to Context Overview or silently pretend that an approval has occurred.

The decision model `mad4b.staging-recovery-center.v1` is read-only.
It observes the current exact Staging Site Profile, WordPress-native host binding,
independent binding readback, governed Write status, Google provider status and
quarantined Context ownership. Its decisions are:

| Live state | UI outcome | Security property |
|---|---|---|
| Site is not exact enrolled Staging | Verify Site Profile | No staging writer, Production, Developer or Breakglass grant |
| Binding is present and independently verified | Reuse binding; continue to Write/Context | **Never rotate a valid host key during plugin updates** |
| Binding present, independent readback missing | Verify existing binding | Never trust an old write response alone |
| First-time eligible binding | Show owner approval path and native binding plan | No ticket is created by GET or silently approved |
| Foreign / inconsistent / unsafe binding | Block, offer exact diagnostic | Never overwrite a key, invent an attestation or downgrade to database storage |
| Current binding + stale write | Offer a fresh exact Write handshake | Separate old `owner_review_digest` step-up proof from native binding ticket |
| Current binding + Write + Google revoke pending | Show Google revocation blocker | HTTP 400 is not proof of provider revocation; keep Drive gated |
| Current binding + Write + legacy ownership | Show Source Folders review | Do not auto-transfer archived/quarantined source or file ownership |
| Current identity, Write and prerequisites observed | Proceed to source onboarding | Do not infer live acceptance, certification or Production promotion |

The console is a consolidated **status-and-next-action user journey**,
not yet a single authorization/execution endpoint. Its Approval Decisions link
opens existing tickets; it does not generate a new approved native binding ticket.
All dangerous operations are still owned by their exact registered governed
executors and need explicit site-bound plan/ticket/actor verification.

### End-to-end P1 acceptance to reach true one-action recovery

- A reviewed composite intent may *display* both host binding and Write tasks
  together, but must authorize the two distinct operations separately and
  atomically according to each executor's existing policy.
- If host binding already exists, the orchestrator must **skip** binding
  creation and approvals on every subsequent package upgrade.
- If new binding is required, the plan must use `deployment-binding-native-plan`;
  an explicitly authorized creation of an immutable, per-site scoped ticket
  must occur through the existing governed approval service and must be approved
  by the enrolled owner before callback execution.
- A successful native apply is **not** acceptance; require an independent new
  request readback (site UUID, host digest and revision).
- Because native binding increments Site Profile revision, the Write plan must
  be regenerated after readback, not reused from before host binding.
- Invoke governed Write only after verified owner OAuth step-up and exact plan
  confirmation. The server creates its existing signed single-use owner intent
  in the same request; unauthorized, ambiguous, expired or replayed proofs fail.
- Present one clear progress timeline, per-stage audit receipt and reversible
  recovery action. Never retry unknown mutation results without readback.
- After Write verification, independently check Google OAuth/revocation and
  Context ownership/sources. Do not block safe read-only siblings due to a
  separate pending provider lane.
- Required tests: WordPress-integrated approval ticket creation/approval/claim,
  browser navigation, PHP 7.4 + 8.3, replay/parallel requests, external Google
  errors, stale source SHA, host filesystem permission errors, site clone
  mismatch, backup + rollback, latest exact-head CI and installed-source readback.

### Evidence in this branch

- `tests/staging-recovery-center-runtime.php` covers the decision model.
- `tests/staging-recovery-center-ui-runtime.php` verifies actual passive
  WordPress admin HTML, Google pending state, absence of mutation and Production
  refusal.
- `tests/staging-write-one-step-review-runtime.php` covers owner proof
  single-use compatibility; the separate native binding runtime tests remain
  required.
- `tests/staging-write-catalog-parity-contract.py` covers the new helper
  without weakening the immutable one-claim and OAuth checks.

**Not yet delivered by this PR:** automatic ticket approval, automatic
native-bound filesystem writes, or certification of ETG/ARE Staging `rc.97`.
