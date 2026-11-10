# Environment-neutral operations: governed migration, November 2026

## Principle

WordPress environment labels (`staging`, `production`, `local`, `development`) are deployment metadata, not standalone authority grants. The named Site Profile must be enrolled and verified, the targeted operation must be permitted, and its independent safeguards must still succeed. This migration is **not** a blanket removal of authentication, host identity, scoped grants, signed sender proofs, CSRF checks, or rollback/restore evidence.

## Verified code migrations on tours.dev and Wayfatours

**Native company/content modules (15):**
- Company-owner activation, tenant-role migration, and two-person company-migration decisions now use enrolled Site Profile status and matching identity rather than staging literals. Browser cookie/session, REST nonce, owner capabilities, plan/revision checks, review separation and rollback remain unchanged. The stale `0.4.0-rc.109` exact release pin was replaced by a check that the active plugin release can actually be determined.
- Structural Content Job QA and original CWA removed the fixed staging host/environment gate. Exact post rights, site UUID, live checkpoint, independent browser document proof and original Central Write Acceptance remain mandatory.
- Market Profiles writes require admin rights and enrolled authority, with unchanged explicit profile review/revision and site UUID. Research Groups and Research Task Bridge retain admin/source-job actor checks, exact draft target and scoped research identifiers.
- Readiness Outbox schema setup requires enrolled site and operator/admin capability; actual email dispatch is still disabled by its independent mail policy.
- Signed external Activity Import Intake and encrypted Import Snapshot are accepted by exact enrolled authority rather than a staging label. Verified HMAC, sender identity, replay nonce, AES-GCM, source/profile hashes and review-only behavior are unchanged.
- Brand Draft preflight uses enrolled site status; exact signed Context Receipt, content hash, and preflight quality validation remain mandatory.
- Google Drive incremental inventory uses enrolled site + exact integrity checkpoint UUID rather than staging; current token, registered source, delegated identity and hash scope are still verified.
- Company local WP-CLI inventory uses enrolled site UUID + integrity checkpoint instead of a fixed demo host. The operation remains read-only and restricted to two fixed list commands.
- Central SERP policy runs on any Central Hub-enabled site, not only staging; this **tightens** ingestion consistency.

**Separate earlier media modules:** site-neutral Remote and Contextual Media Rights (`advisory` default; `enforced` optional); optional Content Job Media Rights Intake on exact relevant attachments.

## Runtime coverage

Both source and Wayfatours have identical changed file bytes. All changed PHP files lint and smoke tests passed. However, some modules are **not currently bootstrapped on Wayfatours**, including Company Owner Activation, Structural QA, and Readiness Outbox. File parity does not imply an activated workflow; original module registration and provider dependencies must be verified before execution.

## Remaining environment-dependent references

`audit_environment_gates.py` produces a read-only inventory. A match is **not automatically a defect**: many are environment reporting, metadata binding, test fixture restrictions, or development-only provider code. Unresolved privileged/critical execution flows require a separate original-host proof and negative tests. Examples:

- Developer Workspace promotion, agent/developer grants, operator grant convergence, and OAuth-bearing scopes — privileged grants must remain independently verified and cannot be enabled by globally stripping environment conditions.
- Upgrade read-only continuity: updated for Production when the original owner, exact origin, snapshot issuer, and saved tenant identity agree; no write or Breakglass authority is restored. The  isolated `upgrade-continuity-runtime.php` regression suite passed, including foreign-Production negative tests. Destructive restores, host environment synchronization and host mutation remain separately protected by backup/signed principal/recovery proof.
- Local/experimental models, staging fixtures and provider canaries — may genuinely require local infrastructure or isolated test data. These are technical environments, not administrative restrictions.
- Company Sender Policy — the current staging-specific fallback blocks sending when Company Communication is missing. Removing its condition indiscriminately on Production could block transactional email; sender admission and mail routing need independent tests.

## Verification and rollback

Each live code replacement was guarded by a SHA-256 readback and a permission-restricted file-level backup at the VPS report directory. No WP settings, company products, customer orders, production grants or database migrations were altered by this code rollout. For high-privilege operations, do not infer an ability is enabled solely from its file; check original runtime registration, delegated principal, audit storage, and signed receipts.

The migration is **partially closed**, not a claim that all remaining environment-dependent code is safe to remove. The inventory must continue to be checked before any release that changes privileged, recovery or credential operations.

**Runtime hardening:** Research Groups now checks native research tables before any SQL read/write. On sites without its optional schema, it returns `mad4b_research_schema_not_ready` rather than attempting SQL; no table is silently created on Production.

## 2026-11-11 — privileged lane continuation

Developer Runtime/Authority, Developer Workspace, Operator Grant Convergence, optional AI-only review and Agent Grant Lifecycle no longer treat the WordPress environment label as standalone permission. They instead require exact enrolled Site Profile authority, managed runtime and the relevant write eligibility, with original actor/subject binding, OAuth client step-up where necessary, signed approval tickets, original exact grants, breakglass kill switches, and provenance checks unchanged. `expected_environment` remains a checked runtime binding and accepts `production` as a legitimate scope; it is not an authorization grant.

**Live negative tests:** `ops/site-neutrality/tests/test-privileged-grants-negative.php` passes against tours.dev and Wayfatours. The source proceeds from site admission to signed-build/source checks; Wayfatours remains denied (`managed_runtime=false`, `write_enabled=false`), with no new grants or mutations. The Developer runtime source contract passes. The optional AI-only mode isolated suite passes 23/23, including managed Production exact content-review grant, unmanaged Production denial and revoke-only-owned behavior. AI-only review remains `off` on both live sites.

**Not part of this rollout:** Self Update remains on its existing signed release-channel implementation, because the `self-update-dual-channel-contract.py` and deployment handoff still mandate staging-only candidate channels and explicitly disabled Production remote apply. Changing one PHP gate without its signed manifest, channel contract, per-target release plan and independent regression tests is unsafe. No Control Plane self-update, Breakglass execution, credential change, or Production grant issuance was attempted.

**GitHub:** local git push through VPS was unable to authenticate. The existing `fix/composite-media-signed-apply-20261110` branch was not found on connected GitHub, so GitHub branch equivalence is unverified. Keep commits locally until the source-to-GitHub release reconciliation and exact review pass.

## 2026-11-11 — signed Control Plane Self Update policy

The normal signed release lanes now use enrolled site authority + managed runtime + original write eligibility, regardless of environment label. The shared handoff, PHP `Self_Update`, and hermetic tests are reconciled. Bootstrap candidate drift is similarly site-bound but requires original exact grants and owner step-up; unpromoted PR Candidate Upload remains separate and explicitly opted-in, not a normal Production release feed. See [`SELF_UPDATE_RELEASE_POLICY.md`](SELF_UPDATE_RELEASE_POLICY.md) for required unchanged hash/manifest/backup/rollback checks, negative tests, and deployment proof. No Control Plane release was actually applied; this update only changes the future release eligibility policy.
