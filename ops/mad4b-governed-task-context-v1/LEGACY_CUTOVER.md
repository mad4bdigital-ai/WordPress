# Legacy Claim Cutover — Staging candidate

Source-only transitional mechanism for PR 680. Not installed, and not authorized to mint permissions.

## Staging readback (WP-CLI, no write)
- 20 nonterminal legacy Hub tasks
- Original fingerprint: fc05a51b899fe53b1d0409b0fb41a0eeea4bbdae893cd7ab74e7537c31accdc9
- Dry run applied=false, new_permission_granted=false, deduplication_inferred=false

The immutable allowlist binds the original task, batch, job, stage, operation, target, dependency JSON, payload JSON and creation time. Only those exact records can enter the original claim pathway once the approved snapshot is locked. Original executor, stage, lease and ContentJob guards remain effective. Newly inserted tasks and modified legacy identities fail closed.

Installing the schema and locking the snapshot require an original authorized Staging administrator, current count/digest and the feature remaining OFF. No cutover was applied during this test. The release operator must pause ingress, make a reversible backup, deploy the approved exact source, install the three additive tables, re-read and lock a current snapshot, test both WP-CLI and PHP-FPM, and only then enable the feature.

## Real database concurrency evidence
Two overlapping WordPress WP-CLI connections against isolated scratch InnoDB tables on MariaDB 11.8.9:
- First leader admitted, transaction duration 5.001 seconds.
- Competing connection waited 3.666 seconds, inserted zero competing leaders.
- Changed work with the same Work Slot rejected, zero rows added.
- Independent Work Slot admitted and ROLLBACK restored the count.
- Scratch tables were dropped; both verified absent.
- Live Hub task count remained 322.

This establishes InnoDB unique-slot and rollback behavior, but does NOT certify the candidate plugin's complete Enqueue-Claim-Executor-Receipt path. That acceptance remains pending.


## Atomic lock hardening (subsequent source candidate)

The legacy allowlist rows and the locked option now write in one InnoDB
transaction. Cache invalidation occurs only after COMMIT. A failed option
insert/update rolls the entire allowlist back; no partial unlocked snapshot
can be mistaken for an approved cutover. This change is exercised by
`test_legacy_atomic.php` using a transaction-aware isolated DB mock.
It is **not** yet an installed-site acceptance test.
