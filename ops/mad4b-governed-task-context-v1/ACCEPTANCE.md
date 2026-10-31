# Acceptance status — Governed Task Context v1

## Proven in isolated tests

- 51 source PHP files parse on Central Staging's PHP runtime.
- Python policy unit tests pass, including table-driven scope/version/
  effect/attempt/legacy proof cases.
- 14 original ContentJob Single Flight checks, 27 original-source
  unlinked contract checks, and 8 unlinked reservation/claim tests and 12 Legacy Cutover checks pass for original identity, same semantic
  Work Hash across trigger origins, distinct follower receipts,
  stale revision and forged origin rejection, one leader/no second
  direct reserve, and receipt replay idempotence.
- Original live WordPress Hub remains on its previous SHA256; runtime
  database `etg_commerce_staging` still has 322 Hub tasks. New flight
  tables are absent; enforcement remains disabled.

## Blocking full operational GO

1. The originating Content Job stage adapters must supply
   independently validated source, policy and capability snapshots.
   Attacker-selected strings alone must not imply a new release.
2. Deploy source through the original approved site update channel
   with a bounded SHA check and file rollback.
3. Original trusted Staging actor must install the unique InnoDB schema
   and prove transactional isolation in MariaDB under concurrent requests.
4. Independently re-read legacy active reservations; do not silently
   cancel an existing Job, batch or task.
5. Exercise full enqueue → competing trigger → original claim →
   verified result → follower receipt on staging, with independent
   readback. Test failed/expired lease and rollback as well.
6. Complete the original QA, integration and legacy provenance contracts
   before declaring them eligible for automatic Single Flight.
7. Run GitHub CI/PR review and obtain exact-head owner attestation for
   a release commit before merge.

Current verdict: **SOURCE CANDIDATE / STAGING ENFORCEMENT NO-GO**.
No new production mutation or original grant issuance has occurred.

## Unlinked source-specific outcome (read-only live readback)

- 135 task rows without ContentJob: 132 unverified legacy, 2 original Canonical Gap, 1 QA diagnostic.
- Canonical Gap rows currently fail read-only effect evidence requirements: 2/2 correctly denied for Single Flight, without changing state.
- Integration provider handoff currently has no eligible verified source receipt in this 135-row sample; future work requires an independent registered provider proof.
- The new DB work reservation is SOURCE-ONLY and unproven under real concurrent transactions. No table was created or installed.

## Real WP-CLI Staging concurrency proof

MariaDB 11.8.9: competing insert waited 3.666 seconds on leader transaction held 5.001 seconds, created zero duplicate leaders; changed input in same Work Slot rejected; independent Work Slot rollback restored counts. Two isolated scratch tables were dropped and independently checked absent. No existing Content Hub task was altered.

Legacy Cutover read-only snapshot of 20 outstanding tasks successfully generated and fingerprinted, but not applied; see LEGACY_CUTOVER.md. The original plugin remains unchanged and Full End-to-End test is pending.
