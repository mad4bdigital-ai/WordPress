# MAD4B Global Runtime & Lifecycle Governance v1.1

**Scope: cross-domain, source-controlled shared policy.** Applies to Control MCP,
operation registry, company actions, Content Hub, SEO/Media, WordPress/WooCommerce,
provider selection, queue work, recovery and infrastructure adapters. This is a
*state/progress projection*, **not** a competing executor, authorization authority
or deployment permission.

## State semantics
- READY: next read/verification candidate, not an execution receipt.
- WAITING_DEPENDENCY / WAITING_PREFLIGHT / QUEUED: ordinary progression; not a hard blocker.
- RECOVERING: transient provider or resource failure; defer to native backoff.
- BLOCKED_AUTHORITY: verified original permission/certification denial.
- BLOCKED_DEPENDENCY: observed missing required native dependency.
- NEEDS_RECONCILIATION: commit or rollback uncertain; never blindly repeat writes.
- VERIFIED: source-bound target receipt plus current readback; not mere registration.
- SKIPPED_NOT_APPLICABLE: source-proven scope omission; not silent success.
- ACTIVE / WAITING / PARTIAL / BLOCKED: aggregate *workflow* views; only terminal
  VERIFIED when every required stage is verified or proven not applicable.

The global projector validates a bounded topologically ordered DAG, isolates
failed branches and exports ready/waiting/blocked lists. It does not call native
Abilities, alter grants, mutate journals, publish WordPress content, deploy
Production, or retry unknown writes.

## Ownership and migration
The central contract is owned by Site Control Plane and can be consumed by any
MAD4B client. Staging Lifecycle Policy v1 uses the shared projection when the
new central class is available. Legacy fallback remains conservative until an
approved Control Plane update is installed.

Native dispatch/admission stays with the existing provider and operation owner:
exact actor and company scope, adapter permissions, CAS, authoritative Journal,
readback and rollback. Adapter authors emit exact stage evidence; they do NOT
use the projector to obtain authorization.

## Rollout acceptance
1. Source contract, legacy regression and static CI on the exact PR head.
2. Staging readback verifies WAITING does not inflate BLOCKED counters.
3. Independent stages continue when a different provider is denied; retry is
   bounded when busy; no automatic write when authorization absent.
4. Native dispatch, undo and endpoint-to-provider receipts proven separately.
5. Production rollout only on independently approved release.

## Native owner integration (v1.1 increment)
- Scheduler Admission maps ADMIT to WAITING_PREFLIGHT, rate/concurrency throttling to QUEUED and quota saturation to RECOVERING. A native authority denial remains BLOCKED_AUTHORITY.
- Scheduler Backlog status and Remote Work Queue list expose each item's canonical observed lifecycle state, plus an aggregate count. Claimed/leased is RUNNING; expired claim is NEEDS_RECONCILIATION; completed queue work is WAITING_VERIFICATION until original provider postcondition readback.
- Adaptive Operation Router describes the canonical selected dispatch route as WAITING_PREFLIGHT; a route is not an execution grant. Unknown write outcome freezes replay; a platform safety denial never yields an alternate mutation route.
- Recovery Route Resolver exposes a global projection for recommended diagnostic recovery, without executing fallback actions or minting approval.
- Unresolved conditional and optional capabilities are PENDING, never false hard dependency blockers.

No new queue, Dispatcher or fallback executor was created. These are **actual hooks into existing native owner readouts**, not claimed autonomous executor certification. The authoritative owner remains responsible for claims, handoff, native execution, publisher side effects, durable journal and rollback. Source-only regression simulates QUEUED → RUNNING → WAITING_VERIFICATION → VERIFIED but is not a live Staging provider transaction.
