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
