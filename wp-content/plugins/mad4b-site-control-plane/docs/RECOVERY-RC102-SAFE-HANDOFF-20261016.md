# Recovery rc.102 — safe Cron handoff, direct-vs-gateway route evidence, and live hold

Date: 2026-10-16. Scope: ETG and All Royal Egypt Staging. This document is a development handoff, not release or Production authorization.

## Implemented source changes (not yet installed)

1. Owner opt-in no longer requeues a **ready** runtime graph when a historical `pending_manual_resume` record persists. The historical record remains for explicit closure/independent audit.
2. Cron replacement does **not** clear an existing event before scheduling a later one. A failure to schedule leaves the original event untouched.
3. Safe-phase continuation now first queues a **non-authorizing Cron wakeup** while the checkpoint remains `pending_manual_resume`; only afterwards does CAS promote it to `pending_safe_phases`. This removes the failure mode where CAS succeeds and Cron scheduling fails, leaving an unintended auto-retry checkpoint. CAS races leave only an inert wakeup; runtime workers must independently check the checkpoint and obtain a fresh G8 SLO admission.
4. Recovery route discovery now distinguishes directly observed MCP mounts from gateway prepare/read-execute capabilities that have not been independently tested. An absent direct mount is no longer represented as evidence of unavailable remote execution.
5. Negative-path tests validate Cron replacement failure, no silent clear, verified replacement and source ordering; PHP 7.4/8.3 regression checks cover safe opt-in and route classification.

## Live readback — hold state

- Both sites reported plugin rc.102 and exact source `068b83761b25a78d22f57f44cf1da37ac959253a` before these source-only edits.
- All Royal Egypt previously reported current Skills persisted checkpoint and exact Write grants. Its historical automation checkpoint and Context source decision remain separate.
- ETG reported persisted Skills `rc.96` identity, not the current rc.102 identity. Its corrected native Skills reconciliation attempt returned an internal transport error with unknown target effect. A separate subsequent diagnostic still showed an rc.96 persisted record.
- Importantly, independent ETG diagnostics at about 03:35 UTC subsequently classified **effective governed Write as blocked_current_drift**, candidate match still true, with `exact_write_grants_incomplete` and `exact_write_grants_missing`. Earlier 102/102 success is not sufficient for current acceptance. This is an observation, not a proven causal consequence of the Skills request.
- Do **not** issue further Skills reconcile or Write apply until a new independent grant snapshot, current permission/approval plan and relevant append-only audit prove the exact existing outcome. A transport error is not a successful mutation receipt.

## Gates deliberately NOT changed

- No Staging or Production package installation, no site-profile update, no host-key rotation.
- No Automation Kill Switch opt-in; the prior Governed Write approval does not authorize that policy change.
- No Google folder selected, no Context ownership transferred, no provider OAuth grant refreshed.
- No Production promotion, Development/Breakglass grant or backup/restore executor run.

## Required acceptance after source review

1. CI checks for the new exact head and full release manifest rebind; a new certified package needs its own immutable identity and controlled deployment.
2. Independently diagnose ETG exact Write-grant drift before any mutation, then certify current Skills persistence, with audit proof and no blind retries.
3. For each site, read exact wildcard/safe-phase switch scopes, require distinct owner opt-in before a signed change, then verify Cron/SLO/checkpoint readback independently. Do not requeue an already-ready graph.
4. Resolve per-site Google provider status and explicitly approved source folder, verify legacy ownership before Context mutation.
5. Browser-admin acceptance, WordPress/MCP/OAuth parity, performance comparison, and standalone Staging final certificate.
6. Production stays a separate signed deployment-family, backup/restore/rollback and independently approved release project; sharing one Hostinger account is not a trust proof.


## Additional ETG provider-certification drift closure — source follow-up

- JetSmartFilters reversible behavioral probe on filter `16032` was reported verified and restored its original `tour_cities` value. Newly eligible `jetsmartfilters/update-filter-meta` raised eligible governed-write inventory from 102 to 103 while grants remained 102. This is an exact grant policy mismatch; **it is not an approval to create a grant**.
- Added precisely `jetsmartfilters/update-filter-meta => jetsmartfilters` to the source-reviewed **Staging-only** reconciliation allowlist, with regression tests proving Production and unrelated provider operations remain denied.
- Once this source is installed under a verified new exact package identity, run an independent **read-only** grant reconciliation plan. Only an exact single-provider missing-grant plan, current NHI owner approval, one-time claim and immutable audit may converge 103/103; a count alone is not sufficient.
- Elementor `mad4b_elementor_widget_policy_denied` was reproduced as an intentional restriction: native `heading` was absent from the bounded settings policy. Added a probe-context-only plain-text `heading.title` exception with target-input binding; ordinary Heading mutation stays denied. Tests cover wrong target, HTML, overlong title, other setting keys, removed setting and post-probe denial. A live isolated reversible probe and rollback must still be certified on site.
- Draft post `65969` is not to be deleted by source build or local tests. Trash and purge only after governed exact-target rollback/owner approval and independent readback. Template `23210` must remain unchanged.
- Cached homepage/REST `HTTP 200` alongside internal homepage `HTTP 500` is **not** a passed browser acceptance test. Keep browser/AJAX render acceptance pending until a noncached end-to-end test correlates edge and origin request IDs.
- Nothing here modifies the ETG runtime, grants, filters, Draft, browser, or Production. Packaging demonstrates source integrity, not live operational acceptance.
