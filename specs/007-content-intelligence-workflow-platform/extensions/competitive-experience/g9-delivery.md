# Feature 007 G9 — Release/Fleet and Restore/DR Implementation Evidence

Status: `REPOSITORY_FOUNDATION_WITH_GUARDED_RUNTIME_WIRING; STAGING_ACCEPTANCE_PENDING`

Integration target: PR #258. Child implementation: PR #288. This document never conveys authorization to merge or to mutate a real site.

## Implemented source

- `class-mad4b-scp-resilience-context.php`: site-origin, blog, runtime-generation, artifact, profile, registry and restore-epoch binding; immutable observation digests; code-pinned local reader.
- `class-mad4b-scp-resilience-anchor.php`: external bounded CAS/lock revision journal; monotonic clock floor, symlink/path checks, append-only scopes, complete write/readback and DB loss marker. No historical event can be evicted or rewritten by the transition closure.
- `class-mad4b-scp-g9-resilience-gates.php`: independent fleet inventory and cohort diff, clone detection, exact ring identity, health/coverage thresholds, revoked-provider and unrewound-effect denial, baseline/restore comparison.
- `class-mad4b-scp-g9-release-fence.php`: exact locally refreshed release plans, default-off fixed internal admission, external duplicate-safe reservation, uncertain-outcome inspection, and passive native signed Execution Receipt + canonical Execution State View correlation requiring an explicit independently read terminal journal link. The native producer of that G9 link is not yet implemented. Neither a reservation nor an execution receipt confers release acceptance.
- `class-mad4b-scp-g9-restore-convergence.php`: read-only drift and post-restore workstage projection across DB, files, runtime package, Site Profile, registry, epoch and external effects; no reactivation of stale grants.
- `class-mad4b-scp-g9-local-reader.php`: code-owned passive reader that deliberately reports provider, host, health and external effect verification as incomplete until real certified observers exist.
- `class-mad4b-scp-g9-read-surface.php`: three locally bound read-only WordPress abilities (`mad4b/g9-site-observation`, `mad4b/g9-restore-status` and `mad4b/g9-closure-status`) without arbitrary selectors; registered from plugin bootstrap.

## Hermetic evidence

`tests/g9-resilience-gates-runtime.php`: typed identity, path/lock, immutable histories, cloned sites, stale health, incomplete inventories, production ring denial, partial rollback/external uncertainty, clock rollback and corruption.

`tests/g9-resilience-state-runtime.php`: exact current capture, single-reader pinning, CAS reservations, existing authority denial, stale plans, idempotency, foreign site reads, restore drift, real core Execution State View and Execution Receipt builder/verifier against distinct native UUID/request/target identities, rejection of unlinked current claims, a synthetic future journal-link fixture, read drift and registered read-only abilities.

Workflow: `.github/workflows/feature-007-g9-resilience.yml`; matrix PHP 7.4 and PHP 8.3. CI results must be checked on the final exact HEAD; queued tests are not pass evidence.

## Boundaries and unfinished operational acceptance

- Source introduces no release executor, new WordPress write Ability, new grant, plugin installation, generic HTTP/shell/raw SQL, Production privilege, tool mount or automatic restore.
- Release fence reservation remains disabled unless a host explicitly configures `MAD4B_SCP_G9_RELEASE_FENCE_ENABLED` and the existing central authority independently admits the exact internal action. This is not a recommendation to enable it before acceptance.
- The built-in observer is deliberately **not** a certified provider/host/health/effect observer; therefore it cannot open a real promotion gate by itself.
- Native release/rollback execution remains owned by the current governed executor. Provider-native external-effect reconciliation, host isolation proof, per-site signed acceptance, rollback readback and actual DR drills remain pending.
- G7/G8 predecessor integration, cumulative #258 CI and governed Staging/browser/provider acceptance are not implied by passing repository fixtures.
- No G9 task is marked DONE; real runtime parity, Production authority and merged release readiness remain false.

## Task ownership

G9 owns T4066-T4070 and T4091-T4095 only. Runtime code covers a conservative repository foundation for each family, while execution/acceptance gates remain separate and require exact evidence. Changes to other G groups' ownership are forbidden.

## Independent source review

See `g9-review.md` for severity-ranked findings and outstanding Staging, cross-host, cryptographic and Hub admission gates. The plugin exposes three read-only G9 Abilities including `mad4b/g9-closure-status`. No G9 source file makes a runtime Production acceptance claim.
