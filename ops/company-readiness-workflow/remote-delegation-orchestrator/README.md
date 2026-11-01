# MAD4B Remote Company Delegation Orchestrator — Issue #724

Status: **Staging-only source candidate; no live owner REST credentials or Production writes are configured.**

## Remote workflow

An authenticated original WordPress owner, operating through an approved browser session with WP nonce or a verified first-party OAuth step-up, can run the existing original issuer lifecycle remotely. The workflow never changes the owner, sets an arbitrary WP user, creates an owner token, or treats VPS root/SSH as a company owner.

1. Obtain the exact company + Staging Site UUID + canonical origin from PR #723 Original Core live reconciliation, never from a user-supplied hostname.
2. Call original `mad4b-central/v1/company-environments/{company}/staging/manager-assignment` owner REST endpoint. Verify bound site SHA and CAS revision on every `propose → approve → activate` operation. Re-read Original WordPress state after each change.
3. Call original `mad4b/v1/company-managers/{manager_id}/companies/{company}/delegation` and its transition endpoint. Only `view_company` + `operations_manage` are requested, and the issuer must approve before advancing. The manager ID must be an exact existing registered UUID; no synthetic managers or users are created.
4. Only after both assignment streams confirm `active`, submit a narrowly scoped `reconcile305` signed request to the PR #725 fixed host adapter. Its original WordPress 305 issuer independently validates membership, site scope, signed host policy, and issuer authority.
5. Accept an issued/existing Grant only after independent original 305 status readback. Distinguish `grant_issued`, `grant_registered`, `grant_active`; Staging execution remains gated by original 306/312/313/314.

## Components

- `remote_delegation.py`: original owner REST adapter + exact-site CAS-aware workflow.
- `signed_broker.py`: fixed command broker adapter; Ed25519 signing from an already enrolled root-protected key; no arbitrary command or shell fallback in this authorization lane.
- `test_remote_delegation.py`, `test_signed_broker.py`: isolated regression tests including unauthorized owner, cross-site drift, stale revision, wrong Broker receipts, non-Staging mutation, replay-purpose checks and signature-pinning.

## Use / integration boundary

This folder provides importable source; the host runner must explicitly connect the original owner-authenticated REST session to `OriginalOwnerRest`, then call `Orchestrator(OriginalOwnerRest(headers), SignedOriginal305Broker())`. Credentials must be delivered in memory by an approved, original issuer-connected application and must never be written to CLI arguments, audit logs or Git. Original REST operations will fail closed without a valid owner session. Do not configure a machine-wide login or generic OAuth bypass.

The Broker adapter is fixed to the installed Staging path for PR #725. It **does not install the worker, register a network endpoint, provide full Shell or activate a Native Executor**. Deployment requires a guarded service wiring step, health checks and rollback under the original release process.

## Validation on central VPS (2026-11-01)

- Python syntax: PASS.
- Hermetic tests: **36/36 PASS**.
- Live read-only original environment manager plan:
  - `all-royal-egypt:staging` — `eligible=true`, `assignment_state=missing`, next `propose`.
  - `egypt-tour-gates:staging` — `current_signed_target_missing`.
  - `tours-central:staging` — `current_signed_target_missing`.
- Original 305 Staging registration not activated for any of the three sites. No remote owner writes or new Grants were issued in this test, and no Production mutations occurred.

## Acceptance needed before live operation

- Enroll a valid original owner REST session or first-party OAuth step-up with the exact company scope (not SSH-derived admin impersonation).
- Repair Original signed identity for ETG/Tours Central and host-policy scope for ETG/ARE through their existing issuer.
- Confirm delegated manager membership through Original Core.
- Wire the fixed dispatcher, test a real newly issued 305 Grant, prove independent 312/313 and rollback.
- Keep separate explicit Production approval.

No root-level CONSTRAINTS.md was modified; existing repo quality gates and site-boundary checks remain mandatory.

## Root-only live Staging service (tested on VPS 2026-11-01)

The VPS Staging operator can host `remote_service.py` using
`mad4b-company-remote-delegation.service`. It binds **only** the Unix socket
`/run/mad4b-company-remote-delegation/service.sock`, owned root:root 0600 under
a root-owned 0700 runtime directory. There is no public HTTP listener.

- Exact `status` requests query cached root-protected *site discovery* plus fresh
  original WordPress assignment/305 readbacks. Cached site discovery is not a grant.
- `reconcile305` requires an already authenticated original WordPress owner
  session delivered to the root-only socket in memory. The original owner REST
  issuer still validates it. This service never fabricates membership, a
  WordPress user, or an SSH owner credential.
- All write paths recompute the live Original Core site binding and signed
  Broker admission. Original 305 independently enforces site/host/assignment.
- Unsupported commands and Production requests are denied. Untrusted local
  peers cannot enter a root-owned 0700 directory. There is a four-client bound;
  dropped connections do not crash the daemon.

The service file is a deployment candidate; use the site's governed release
path to reproduce it elsewhere, with backup/restart/rollback. Source being on
GitHub is not proof of production activation.

Actual central VPS acceptance on 2026-11-01: `systemctl is-active` returned
`active`; `status` through the live Unix socket for ARE Staging returned
`READ_ONLY_STATUS` in 7.4 seconds, `assignment_state=missing`, Original 305
`NOT_ENROLLED`, and no Grants. Negative non-authorized and malformed inputs
were rejected in isolated checks. 36 existing hermetic orchestration and
signed Broker tests passed. **No positive live issue-305 canary or 312/313
receipt has yet been verified**.


## Periodic read-only Host Scope freshness

A separate `mad4b-company-host-scope-refresh.service` and systemd timer
(`OnUnitActiveSec=10min`) call the original, merged PR #723 `live_once()`
projector. The refresh uses a root-owned flock, checks original Core freshness
and rejects all rows claiming a mutation, then updates the root-only snapshot
atomically with fsync. On any failure, the previous snapshot remains intact,
and `remote_service.py` marks its Host Scope stale after 15 minutes rather
than claiming an old grant. Status uses this read-only cache, while every
`reconcile305` write still performs fresh Original Core and WordPress checks.

This scheduled refresh does not mint grants, change host-policy files, authorize
manager assignments, write to Production WordPress, or execute a remote job.


## Fresh-scope runtime acceptance (2026-11-01)

The original PR #723 projector verifies upstream Core observation freshness
but intentionally omits `observed_utc` in its returned projection. The
root-owned refresh service stamps its **verified projection completion time**
after the upstream validation succeeds; it never treats an unverified timestamp
or a site-discovery record as an authority grant.

On the central Staging VPS the initial refresh returned:
`Result=success`, 7 targets, `grants_created=false`,
`production_mutated=false`, atomic report mode 0600. The original root-only
Unix service then returned `READ_ONLY_STATUS` for Tours Central with
`host_scope_ready=true`, `host_scope_snapshot_stale=false`, snapshot age
10 seconds and `grant_registered=false`. Both the service and refresh timer
were enabled/active with no service restarts after testing.

