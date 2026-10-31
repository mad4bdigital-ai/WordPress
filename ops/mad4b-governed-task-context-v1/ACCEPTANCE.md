# Acceptance status — Governed Task Context v1

## Proven in isolated tests

- 49 source PHP files parse on Central Staging's PHP runtime.
- Python policy unit tests pass, including table-driven scope/version/
  effect/attempt/legacy proof cases.
- 14 isolated PHP checks pass for original identity, same semantic
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
6. Run GitHub CI/PR review and obtain exact-head owner attestation for
   a release commit before merge.

Current verdict: **SOURCE CANDIDATE / STAGING ENFORCEMENT NO-GO**.
No new production mutation or original grant issuance has occurred.
