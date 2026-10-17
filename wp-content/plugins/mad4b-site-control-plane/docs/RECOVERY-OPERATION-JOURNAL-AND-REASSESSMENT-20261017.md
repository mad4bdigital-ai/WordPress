# MAD4B — Operation Identity, Recovery and Reassessment
Date: 2026-10-17 | Stacked after PR #397 | **Source-only / non-authorizing**

## Objective and semantics

Make the Staging Write Convergence operation identifiable, recoverable by reading prior evidence, and fail-closed on timeout, duplicate submission or partial execution. The implementation provides **at-most-one admitted attempt** for a specific exact plan and a **site-wide atomic active-operation lock**, NOT a distributed exactly-once transaction across WordPress, OAuth, MariaDB and external side effects.

The existing exact CAS, signed owner review, Audit, site/environment identity, grant plan and independent postcondition checks remain mandatory. Production and Developer/Breakglass are excluded.

## Implementation
- `MAD4B_SCP_Staging_Operation_Journal` derives a deterministic `operation_id` from the Convergence operation, exact plan SHA, site UUID, source commit, build fingerprint and package manifest digest. Diagnostic-only blocker changes do not create a new ID.
- `add_option()` claims an atomic per-site active lock and an immutable per-operation tombstone in WordPress's unique option namespace. Any previously uncertain/suspended lock or operation prevents **new plans** as well as identical ones from blind apply.
- An HMAC-signed option records `EXECUTION_UNCERTAIN`, `RECOVERY_REQUIRED` or `VERIFIED` and includes only bounded metadata. A separate site-specific latest-operation pointer is **not** an authority source; the signed operation row is verified.
- `Convergence::apply()` checks for a previous reservation **before** generating another owner intent. It reserves the operation after exact owner claim and before first write/audit side effect; every unverified exit preserves the recovery lock. Only after independent in-request Convergence readback plus append-only completion Audit may the journal change to `VERIFIED` and release the active lock.
- If a crash occurs between side effect and postcondition, the journal remains uncertain. A new user or MCP attempt receives a readback-first error, never automatic re-execution.
- The existing owner OAuth read-only diagnosis now shows latest operation status and chooses recovery readback rather than requesting a fresh owner approval. Site-level verification remains independent.
- Owner Review exposes a separate canonical **diagnostic** scope SHA based on stable site/source/build and grant fingerprints. It helps explain Admin vs OAuth drift, but does **not** replace `plan_sha256`, `review_digest` or CAS for mutations; different exact plans are never silently equated.
- Operational readback selection now requires `wp_get_ability` registration, explicit readonly metadata **and** successful `WP_Ability::check_permissions( array() )` for the current actor. It never invokes the readback tool as part of permission discovery.
- Previously expired signed Owner Intent markers and claim options are retired only after a newer confirmed owner intent has been saved/audited. Immutable Audit and operation tombstones are preserved.

## Previously observed CI blocker
The inherited PR #397 Pre-Staging Hybrid Audit failed because the `class-mad4b-scp-servers.php` legacy facade reached 1051 lines against the strict 1050-line policy. One redundant blank line was removed, without increasing the budget or changing business logic.

## Regression and delivery tests
- `tests/staging-operation-journal-runtime.php`: exact plan duplicate, changing diagnostic fields, concurrent/different-plan site lock, different site independence, successful release, sticky recovery, tampered HMAC, orphan lock, invalid input, old-plan replay.
- `tests/staging-operation-journal-source-contract.py`: integration order validates plan CAS, owner gate, unique reservation before write effects, completion after Audit, fail-safe finally and mandatory CI.
- `tests/staging-write-owner-intent-runtime.php`: signed owner plan, canonical scope, OAuth/step-up split, audit commit marker, TTL, existing owner-intent cleanup.
- `tests/operator-primary-next-step-runtime.php`: permission-denied registered readonly tool, mismarked writer, absent tool and safe discovery.
- The existing Pre-Staging Hybrid Audit runs recovery fixtures under both PHP 7.4 and 8.3, alongside the original 7.4 full gate.
- G8 delivery manifest tracks the revised workflow SHA and file length. G2 file source budget must pass unchanged (≤1050 lines). Verify `rebind-delivery.py` drift_count=0 and local/CI tests before promotion.

## Fresh risk assessment and honest release boundary

| Finding | Source status | Live acceptance gate |
|---|---|---|
| P0 duplicate Apply after timeout / new owner review | **Mitigated**: operation and site locks, fail-closed on uncertain state | Real OAuth/MCP timeout with simultaneous calls against one Staging install |
| P0 concurrent plans on same site | **Mitigated**: atomic site lock before governed side effects | Real MariaDB/WP object-cache contention |
| P1 owner/OAuth canonical plan identity | **Diagnostic normalization only**; exact CAS retains full plan | Compare actual local and remote plan inputs and fix actual differing provider snapshots |
| P1 Readback permission | **Source guarded** using public WordPress check_permissions() | Real registered provider ability and OAuth subject |
| P1 crash/partial write recovery | **No blind replay; durable state retained** | Implement/validate owner-governed unlock after independently certified postcondition or rollback |
| P1 cross-version CI | PHPUnit/PHP fixtures explicitly gated on PHP 7.4/8.3 matrix | New exact-head GitHub checks must all succeed |
| P2 historical receipts and cleanup | Old uncommitted receipts fail closed; bounded expired *auxiliary* marker cleanup | Live migration and bounded retention behavior |
| P2 facade size budget | 1050-line source corrected without bypass | Architecture maintainability CI |
| Production | Unchanged and excluded | Separate Production approval and readiness gate |

**Still not a complete distributed exactly-once guarantee.** The operation lock must never be deleted merely because a timeout elapsed; a separate explicit, owner-approved recovery action with independent write/grant/binding/browser evidence is required before any release of uncertain state. The implementation intentionally prioritizes fail-closed safety over automatic retry.

## Release sequence
1. Exact branch CI, unchanged host policies and complete release hashes.
2. Merge stacked draft PRs only after exact owner approval and review.
3. Build certifiable plugin archive from exact merged source and verify package identity and Staging-only authority.
4. Deploy independently to ETG and All Royal Egypt Staging; no changes to Production.
5. Verify read-only owner diagnostic on both sites, local-vs-OAuth canonical/exact plan hashes, unchanged grants, observed operation identity and auth gates.
6. Execute one new owner-approved reversible operation, prove concurrent submits/timeouts cannot duplicate, inspect postcondition in an independent fresh request, and test a failed operation's lock remains held until governed recovery.
7. Finish Browser Acceptance/rollback; only then publish a Staging acceptance certificate.

No site mutation, OAuth grant, manual approval, Production change or plugin deployment is claimed by this source review.
