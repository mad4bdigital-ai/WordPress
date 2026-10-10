# IMP14 — Atomic Source Authorization, Replay Protection and CSV Export Fencing

10 October 2026 · Feature 007 · PR #366 · Source implementation on Draft branch, NOT native WordPress certified.

## Verified root cause

On modern WordPress, `add_option()` uses an SQL `INSERT ... ON DUPLICATE KEY UPDATE` statement that may update an existing option during a race. It is not a safe primitive for exclusive lock ownership or an immutable acceptance receipt. A successful `get_option()` check after insertion does not prove another worker did not temporarily overwrite the same row. This issue impacts the original source batch mutex, source snapshot and approval records, and signed webhook nonce reuse.

WordPress reference: https://developer.wordpress.org/reference/functions/add_option/

## Implemented source corrections

1. **Atomic Profile mutex** — `MAD4B_SCP_Batch_Atomic_Mutex` uses `INSERT IGNORE` into the registered `$wpdb->options` table. Success requires exactly **one inserted row** and an exact raw SQL readback of the random holder token. A duplicate returns zero, never overwrites the incumbent. Lock scope binds enrolled site UUID and exact Profile slug. Locks do not expire implicitly.
2. **Owner-only release** — `DELETE ... WHERE option_name = ? AND BINARY option_value = BINARY ? LIMIT 1` deletes only the matching holder. Requires exactly one removed row and independent SQL readback of absence. An unexpected exception leaves a lock held rather than guessing whether an earlier operation committed.
3. **CSV export vs archive race** — the full manual chunk export now acquires the **same mutex** as batch begin/append/approve/archive and holds it through exact full-batch readback, approval matching, CSV encoding, HTTP headers and stream transfer. `export_chunk_unlocked()` no longer calls `exit`; the wrapper releases the holder's lock before the admin handler exits. If the byte count differs, the result records partial transfer; bytes already delivered cannot be recalled.
4. **Snapshot/approval immutable records** — single-source `MAD4B_SCP_Activity_Import_Snapshot::stage` and `approve` now use `insert_immutable`, which inserts exactly once, verifies raw serialized receipt bytes, never overwrites a previous source record and clears the source option cache. No existing snapshot is modified to accommodate a new review.
5. **HMAC nonce anti-replay** — `receive_signed` reserves a site/key/nonce-specific record with `reserve_signed_nonce` and an insert-only SQL statement after sender authentication and bounded timestamp validation. Two simultaneous HMAC calls using the same nonce cannot both insert the same marker. The configured expiry job still retires a marker after the existing acceptance window.
6. **Local isolated test adaptation** — `imp01-import-preview-runtime.php` now models the unique-key SQL option store instead of assuming normal `add_option` is a real SQL mutex. `imp08-batch-review-runtime.php` models two contenders and invalid owner-token release, with 502-row source and archive rejection cases.
7. **Real Staging contention harness** — `tests/imp14-batch-mysql-concurrency.php` starts two independent PHP processes against the same enrolled Staging SQL options table and synthetic Profile lock. It expects precisely one winner, one `mad4b_batch_mutation_locked` rejection and an unlocked state after the winning process releases. It never writes business posts. It **must be executed on a real authorized Staging host**; not run automatically as an isolated/preflight test.
8. **Static source guard** — `imp14-batch-atomic-source-contract.py` fails if the old add_option mutex reappears, export releases early, or the real contention harness disappears. Registered with `feature007-manual-preflight.py`.

### Adversarial acceptance grid

| Scenario | Expected | State |
|---|---|---|
| Two workers attempt begin/append/approve/archive lock simultaneously | Exactly one inserted option; loser cannot modify incumbent | SQL primitive implemented; real MySQL race **NOT_RUN** |
| Download/archival overlap | One holds lock during entire download; other is denied until release | Code implemented; concurrent HTTP fault-injection **NOT_RUN** |
| Wrong worker tries to release owner's lock | Exactly zero deleted rows; owner remains | Source and isolated fixture |
| Worker crashes after successful insert | Lock preserved for explicit incident review, no TTL takeover | Code implemented; crash simulation **NOT_RUN** |
| Two admins approve same source concurrently | Exactly one immutable approval receipt | Atomic unique insert implemented; true DB race **NOT_RUN** |
| Two signed webhooks reuse exact site/key nonce | Exactly one marker; rejected replay | Atomic unique insert implemented; real concurrency **NOT_RUN** |
| HTTP response drops during CSV streaming | No extra provider write; partial transfer surfaced; a previously delivered byte cannot be revoked | Fail closed; browser/transport **NOT_RUN** |
| WordPress persistent cache retains a negative option entry | Refresh per-key cache and `notoptions`; raw DB remains authority | Source safeguards; Redis/object-cache **NOT_RUN** |
| Existing third-party WP All Import writes same record | **Not fenced by this mutex.** Requires a shared lower-level writer gateway or independently verified provider participation | **NOT_DELIVERED** |
| Google Sheets other editor races with a MAD4B source write | Requires Sheets-specific conditional CAS; a WordPress SQL lock cannot provide it | **NOT_DELIVERED** |
| Native JetEngine CCT/WPML changes partially fail | Pre-image + adapter-specific compensation + native post-restore readback | **NOT_CERTIFIED** |

## Hard release gates

- Run isolated PHP lint/fixtures with PHP 7.4 and 8.3, including updated IMP01, IMP08 and IMP14 source guards.
- Run real `imp14-batch-mysql-concurrency.php --wp-root=/path/to/wordpress` with two independent workers and record the PHP/MySQL versions and exact Git SHA, followed by browser download-vs-archive collision and crash/failure injection.
- Validate source staging, immutable approval and signed HMAC replay against a real WordPress database and object cache.
- Certify exact installed WP All Import 5.1.0, JetEngine 3.8.15.4, native WPML link readback and cross-provider rollback separately.
- Complete true Google Sheets CAS, MSR02 / third-party writer fencing, Brand Core rights, an approved Content Experience Profile and owner authorization before any automatic import or Production promotion.

**Implementation boundary:** This closes the demonstrated `add_option` overwrite vulnerability for these source acceptance operations and protects batch export from internal archival races in code. It does not establish a globally coordinated transactional importer or a runtime release certificate. PR #366 remains Draft and unmerged.
