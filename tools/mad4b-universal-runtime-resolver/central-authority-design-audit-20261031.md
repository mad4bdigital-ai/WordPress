# MAD4B Central Authority — Runtime and Source Design Audit (2026-10-31)

Target: `tours.dev.mad4b.com`, Staging only. No Production mutation.

## Verified live evidence

- Exact central Host WP-CLI context: PASS, origin and Site UUID enrolled.
- Resolver host execution: `central_execution=verified`.
- Native central authority diagnostic: `review_required`, independent of source mismatch.
- Central source expected: `73c6852f3a22148cf4e79c73643cbbdeffca7f8a`; installed live manifest source: `56e237f0e24f199ef62d085307e7bf38e8ae4619`.
- Snapshot vs live Control Plane: 907/907 same file paths. Exactly two content differences: `MAD4B-BUILD-PROVENANCE.json` and `class-mad4b-scp-staging-write-authority.php`. The installed PHP file includes **36 additional lines of strict OAuth/Grant preflight** absent from the original source snapshot.
- Live registered OAuth subject for the enrolled WordPress user 1 resolves to `mad4b-delegated-user-1`, enabled, Staging, same user; the governed-write Agent `chatgpt-governed-write` is also enabled and attached to user 1. Treating the OAuth subject as required property of the write Agent creates a false conflict and risks subject theft.
- Original governed-write Agent: 92 exact grants out of 112 abilities, 20 missing. Fifteen first-party Content operations are outside the existing exact approved ability/provider list.
- Candidate binding is `unbound`, rather than evidence of a foreign owner: `stored_bound=false` and `identity_completeness=unbound`. Initial candidate enrollment must use the original exact binding workflow after deploying its new source identity.

## Corrections implemented and tested in a local Git worktree

Git source root: `/opt/staging/mad4b-central-source-native-fix-20261031`

Local commit: `02ad44fd5cbda478274a2a5f39f3cd9e494a5325` (based on 73c6852).

The source preserves **all** live preflight protections and adds:

1. An exact delegated OAuth recognition check: only the same WordPress user, Staging environment, enabled `mad4b-delegated-user-<id>` Agent and enrolled user policy are valid. A foreign or disabled Agent remains blocked. No OAuth subject is transferred or duplicated.
2. The existing write-Grant reconciliation path skips OAuth rebinding when an exact delegated identity already owns it. It does not assign write grants to the delegated Agent.
3. The fifteen exact `core` Content ability/provider pairs are enrolled in the *source policy*. This does not issue any grant until the original per-operation approval/authority/readback is established.
4. Six isolated PHP positive/negative cases PASS; both affected PHP sources lint PASS.
5. A separately verified canonical ZIP producer recreated the **entire original 907-entry archive with identical SHA-256**, `ff685fe9221c2848038240ff7e8a36d7e8c6e286c9a984540116031bc9e0d146`. This proves byte-equivalent deterministic packaging of the old release.
6. New deterministic source package: `/opt/staging/mad4b-source-native-releases-20261031/central-native-authority-02ad44f.zip` (906 manifested files + provenance, 907 ZIP entries), SHA-256 `8d2aff4c2ed9e7810d7e9b6a2733fc81b4b8042554fa1883d78356d6da91a029`, package manifest `0db90e68be889d0ef58a6a51a978163f69e22a4ca5b0bb36513d5bd3d3e4d98a`, build fingerprint `1e7374a327706a01601fca179ea2872ce40b34a58ea9819c2c285ec359dab39e`.
7. Candidate ZIP vs the copied installed plugin: only the 2 modified PHP files and the provenance differ; no added/deleted files.

A source-only patch is in `central-control-plane-source-fix-02ad44f.patch` on this PR.

## Anti-patterns not to reintroduce

- Never use `--user=1` as authentication for privileged VPS host operations; preserve the original authenticated Host execution context and exact operation admission.
- Never require a Native Owner plan for independent Host Operator operations; separate capability readiness from transport readiness.
- Never interpret a working OAuth/MCP, SSH or Broker connection as a WordPress write grant.
- Never seize or silently reassign an OAuth Subject that is already owned by an exact legitimate delegated-user Agent.
- Never drop installed preflight security checks when resynchronizing a local source snapshot.
- Never mark `missing=0` by widening wildcard privileges. Enroll exact approved ability/provider pairs and preserve original apply checks.
- Never change `expected_source_commit_sha` to whichever SHA happens to be installed without a verified new package and a readback receipt.
- Never hide original Agent conflict diagnostics behind release source-pin failures. Observation and mutation admission are separate.
- Do not treat an initially unbound Candidate as a foreign owner conflict. Bind it through the original workflow after the new source is live and matches its provenance.
- Host Authority is not a general-purpose background WordPress queue shell. Every new operation needs exact scope, original preflight, audit and readback.

## Fixed: live central Host execution readiness and dual backup (2026-10-31)

Installed `central_execution_route.py` no longer checks a hard-coded
one-day-old receipt. It validates the **current root-owned Host Authority**
policy and performs a fresh, exact WordPress WP-CLI readback of Site UUID,
canonical Origin, environment and database. Historic receipts remain proof
of past operations only; a future write still needs exact independent
operation preflight. Current live result: `central_execution=verified`.
Eight regression tests PASS (revocation, foreign site, wrong database, wrong
origin, missing operator, unavailable WP-CLI, and current-state success).

The original blanket backup attempt was replaced with two exact, independently
checked routes. Source is
`tools/mad4b-universal-runtime-resolver/central_staging_backup.py`,
installed at `/opt/mad4b-company-transport/central-staging-backup.py`.

Commands from the authenticated original Root SSH session:

```shell
python3 /opt/mad4b-company-transport/central-staging-backup.py plan
python3 /opt/mad4b-company-transport/central-staging-backup.py backup
```

The backup runner prefers authenticated WP-CLI (`mysqldump`) when available,
otherwise automatically uses `mariadb-dump` inside the enrolled MariaDB
container, including runtime fallback if the first dump fails or is invalid.
For plugin files it uses `docker cp`, with a fixed-source `docker exec tar`
fallback when needed. All backup destinations are new, under a private
Root-owned backup directory. Existing backup files are never overwritten.
Eight isolated failure/fallback tests PASS.

Verified Staging snapshot:
`/opt/staging/mad4b-managed-backups/central-staging/20261031T173943Z-09fc13b4`.
Receipt reports 907 plugin files, seven SQL tables, WordPress data unchanged,
no Production mutation. SQL SHA-256:
`89d0d123749bfe302086f31eef5e57b058ad1e4d9a52d40f3949d0f8b5d6f330`.
This verifies an export and file manifest, **not a restored backup**.

## Remaining release gates

The candidate Control Plane package was **built, linted and compared but NOT
installed**. The earlier backup blocker is resolved by the verified Staging
snapshot above. Installation and original governed-write Agent reconciliation
are separate workflow steps with their own approval, rollback and readback.
Do not claim the 20 missing grants or OAuth/Agent conflict have been fixed live.

On any subsequent Staging-only deployment: verify candidate ZIP SHA and a
matching independent backup, exact Source Commit, runtime manifest, stale
status, candidate binding, persisted Agent/Subject identities, grant counts and
WordPress operation health. Production must not be modified implicitly.

## CI

PR #670 contains Resolver code, the source patch and its tests. `Release Verdict` may separately require exact-head owner attestation; do not falsify that evidence or interpret a failing release gate as a runtime test failure.
