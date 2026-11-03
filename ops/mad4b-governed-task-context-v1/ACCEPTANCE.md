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


## Additional isolated atomic cutover verification

Added transaction-scoped write of the immutable legacy lock option and
post-commit cache invalidation. The new `test_legacy_atomic.php` exercises
failure+ROLLBACK, successful insert+COMMIT, immutable claim readback,
tamper denial, repeated apply denial and an existing disabled option.
Source-only PASS does not change the operational NO-GO or create authority.

## First-party original readback certification and isolated WordPress acceptance

A subsequent source candidate includes `MAD4B_Hub_Original_Context_Evidence`.
It independently re-reads the original ContentJob and binds its immutable
scope/revision/stage/source-artifact fields to the original provider implementation,
the original SCP policy, original Queue receipt verifier and exact readback
capability implementation. A client-supplied source, policy or capability hash
is not accepted as evidence. Certificates are re-evaluated at admission and
claim; policy revocation, source drift and executor changes fail closed.

**Certified at this stage:** `workflow_inspect` and `content_job_read` with
`read_only` effect, using their **original independent result readbacks**.
Other ContentJob operations continue to require their own trusted source,
policy, capability and effect evidence. Generic staged commercial operations
are NOT certified by a read-only certificate.

On the SAME VPS, a separate, isolated `mad4b_semflight_qa_20261101`
MariaDB clone of Central Staging was created and paired with a QA-only
WordPress/plugin code mirror; no live Hub files, original Queue/Grants,
Production DB, or original update channel were modified.

- Three original-compatible InnoDB tables installed on the QA DB.
- Frozen 20-row legacy snapshot was applied ATOMICALLY to the QA DB and
  independently re-read at original exact digest
  `fc05a51b899fe53b1d0409b0fb41a0eeea4bbdae893cd7ab74e7537c31accdc9`.
- Real original `enqueue → duplicate follower → claim → independently verified
  finish → replay denial` passed with self-certified original job context.
- Original finish rejected an incorrect job revision, then acknowledged a
  verified readback, and refused a second use of the lease receipt.
- Two real, competing WP-CLI processes admitted ONE original Queue leader and
  ONE follower receipt for the same source Work Slot. Readback: 1 executable
  task and 2 trigger receipts, no duplicate leader.
- Stale-lease completion denied; original Claim moved uncertain effects into
  `NEEDS_RECONCILIATION` (no blind replay).
- 15 isolated original-context proof assertions passed, including provenance
  forgery, effect escalation, missing policy and changed source denial.
- `certified_only` staged mode was verified on QA: the two certified
  read-only operations receive strict Single Flight, and other unproven
  original operations retain their unchanged Queue admission/claim semantics.
  This is intentionally NOT full cross-operation enforcement.

**Important:** Some Staging WordPress plugin settings report QA-only
decryption notices when the isolated mirror loads; these were not ignored as
authorization evidence. The certified fixture uses original authorized
WordPress and SCP readbacks, not the QA notices.

The original live Hub remains unchanged until its exact-source Staging
release, original backup, current snapshot readback, PHP-FPM parity, rollback
and formal authorization gates pass. Full `enforce` mode is still NO-GO
for uncertified AI, publishing, pricing, integration, unlinked and provider
effects. This QA result is proof of the read-only candidate only.
