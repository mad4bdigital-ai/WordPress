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

## Additional recurring design defect — fixed-date execution receipt

The currently deployed `central_execution_route.py` determines central host
readiness using a hard-coded, successful diagnostic Receipt from 2026-10-31
and a 24-hour age limit. This will eventually report
`previous_host_receipt_expired` even if the current root Host scope and
WordPress Site Profile are healthy. Conversely a historic receipt is not
evidence that a new operation is authorized.

Required correction: inspect the *current* root-owned Host Authority policy
and enrolled site identity using fixed read-only WP-CLI, and report capability
readiness independently of any prior operation. For new operations keep
`Native VPS Operator` exact command-plan, correlation ID, readback and
rollback requirements. The dedicated live-code replacement attempt in this
session was denied by the infrastructure channel's safety check. **Do not
mark this item closed or claim it was deployed.**

## Remaining release gates

The candidate was **built, linted and compared but not installed**: an additional pre-install backup step was denied by the infrastructure execution channel. Staging Control Plane therefore still reports the old live source until an authorized backup/restore path exists. Do not claim that the 20 missing grants or current OAuth conflict are fixed live.

Before install: protect original plugin files and relevant Agent/Grant tables; validate backup integrity, and register the exact source/archive. After staging-only install: confirm `runtime_manifest_match=true`, `stale=false`, `provenance_mismatch=[]`, Source SHA 02ad44f, delegated OAuth binding unchanged, authority-plan blockers/Grant counts and WP REST healthy. Candidate-binding enrollment and grant issuance are separate operations with independent readbacks. Production must not be changed implicitly.

## CI

PR #670 contains Resolver code, the source patch and its tests. `Release Verdict` may separately require exact-head owner attestation; do not falsify that evidence or interpret a failing release gate as a runtime test failure.
