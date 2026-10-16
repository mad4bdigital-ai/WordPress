# MAD4B — Version-independent provider read continuity

## Problem

WordPress MCP Adapter and other installed plugins may be upgraded or downgraded on ETG or All Royal Egypt independently of the MAD4B release. The old Control Plane overused the list of exact adapter package versions to classify **read connectivity** as blocked, even where the runtime class, official plugin provenance, and WordPress Abilities were operational. A plugin change should not disconnect unrelated providers.

## Behavior implemented

- **Dependency Manager:** `read_ready` and `read_blockers` now represent an operational, version-neutral lane. They are derived from actual installed plugin identity (no ambiguity), loaded Adapter runtime, reflection-confirmed official plugin provenance, Abilities API availability and supported per-site activation. The previous strict `ready`, `hard_blockers`, package critical-file hashes and exact-integrity requirement remain for mutation certification.
- **Connection Status:** both shallow and deep read status use current runtime evidence when the adapter is not on an exact certified version. A valid official runtime does not become a local/remote read blocker solely because of version drift. The exact provider certification condition remains in `certification_blockers`, and full connection certification remains false until exact evidence exists.
- **All providers:** Provider Contracts now expose `read_continuity` for version-independent discovery of **observed, read-shaped, registered native Abilities**. Write-looking verbs are never considered read evidence. No unknown provider is automatically certified. Provider Compatibility already has structurally probed read behavior; no behavioral/rollback certification for writes is relaxed.
- **Plugin fleet:** Provider Closure Matrix exposes one bounded policy per discovered plugin: revalidate currently visible read capabilities on activation/upgrade/downgrade, deny unknown writes, and recertify only relevant changed contracts. Version changes in one plugin do not constitute reasons to block unrelated plugins.
- **Admin notices:** when read is operational but exact adapter certification is outstanding, show a warning instead of an error asserting that all Control Plane use is blocked. It never silently installs or overwrites the Adapter.

## Security boundaries

This does not infer OAuth identity, add a write grant, bypass owner approval, waive exact archive SHA-256 verification for mutation, change Production, or enable Developer/Breakglass. A plugin that disappears, fails structural probing, has an ambiguous installation, or runs from an untrusted source still cannot claim a verified read runtime. Unknown version numbers never inherit old mutation certification.

## Acceptance

Regression: `tests/provider-version-neutral-read-runtime.php` on PHP 7.4 and 8.3 covers 0.6.1, 0.7.1, unknown future version, absent runtime, forged provenance, conflicting plugin identities, unsupported network activation, native read verbs and no write promotion. `tests/provider-version-neutral-read-contract.py` and existing Connection, Adapter Compatibility, Dependency Integrity and Provider Fleet tests guard the paths. Live ETG/All Royal acceptance requires an exact-head Staging package upload, runtime readback and an upgrade/downgrade test with no loss of read connectivity.

## Not included

Automatic mutation across unknown plugin versions, automatic deployment, or retesting every plugin's write behavior in Production.
