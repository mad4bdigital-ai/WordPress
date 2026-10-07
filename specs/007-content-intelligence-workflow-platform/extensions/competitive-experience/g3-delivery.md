# G3 Delivery — Declarative Adapters, Shadow, Reversible Canary and Certification Packs

Status: **repository core implemented; live acceptance pending**

Integration target: PR #258 (`spec/007-competitive-experience-20261006`)
Implementation branch: `feat/007-g3-adapters-shadow-canary-packs-20261007`
Baseline Hub head: `a6e39275009e7db32b0c4c8623886acd1a48e170`
Reviewed master ancestor: `dccb0889799eb255f0d64d6959d9687252801c5b`

The detached seed `b93968a29ccb3060453b9ff84a22a7aac3ef2e21` was replayed by file changes onto the latest post-G2 Hub. Its old Hub ancestry is not imported into the child. Exact source head identity is supplied by CI, avoiding self-referential hashes.

## Scope and delivered behavior

| Workstream | Capability | Tasks | Repository behavior |
| --- | --- | --- | --- |
| ACFMAN / declarative-adapters | CE043 | T4011–T4015 | Validates signed, immutable manifests and input schemas; previews bounded typed plans using existing admitted strategies. |
| ACFSHADOW / shadow-certification | CE045 | T4021–T4025 | Runs bounded, governed exact-input dual reads, returns proven public active output only, and signs comparison receipts. |
| ACFCANARY / reversible-canary | CE046 | T4026–T4030 | Extends the existing governed wrapper with signed L3 Staging recipes, exact disposable fixture binding, one execution and mandatory cleanup/readback. |
| ACFPACK / certification-packs | CE047 | T4031–T4035 | Validates typed immutable signed packs and maintains locked versions, lineage and revocation tombstones. |

All twenty tasks are **PARTIAL**. The extension ledger becomes 60 PARTIAL, 115 OPEN, 0 DONE. Fixtures prove repository contracts; they do not establish real provider or live browser parity.

## Execution and authority boundaries

- Manifests validate purpose-specific signatures, artifact and descriptor bindings, runtime generation, input schema, declared effects and integer zero-cost limits. Interpretation emits a plan; it does not dispatch, generate PHP, invoke arbitrary symbols or create grants.
- Shadow targets require signed zero-effect/non-secret assertions, eligible read descriptors, exact input schema and provider structural read certification. Both paths use the existing governed child execution fence. Distinct workloads are bounded to twelve samples; object mismatch, private/uncertain output and generation drift fail closed. Field-specific provider comparators and real effect/quota proof remain pending.
- Reversible Canary uses the existing one-time Staging write authority and append-only audit preflight. It adds no independent mutation ability. Site, subject, exact input, artifact, capability, generation, restore epoch, rollback contract and disposable target are bound to the signed recipe. Cleanup is attempted exactly once in `finally`, including provider/observation exceptions. Unknown observation, failed cleanup, state/effect drift or changed generation cannot issue promotion evidence or trigger blind retry.
- Pack activation methods are internal core APIs; new registered abilities provide private admin preview/status only. Typed schema compatibility and signed binding checks do not establish arbitrary payload execution. Automatic refresh eligibility is limited to proven monotonic restrictive/equal policy overlays with no unknown fields; other payloads and authority effects require governed review. Retention overflow fails closed without dropping lineage or revocation history.
- Signatures and receipts remain non-authorizing. Production, new grants, tool mounting and promotion are not authorized by this delivery.

## Verification

CI runs exact-head PHP 7.4 and 8.3 contract fixtures for manifest, shadow, reversible canary and pack registry, plus existing canary execution/authorization and crypto profile compatibility. Extension validation binds all PARTIAL tasks to code, tests and spec digests and rejects false parity or live-acceptance claims. Existing G1/G2 evidence hashes are refreshed after the cumulative ledger snapshot is regenerated; previous history entries and acknowledgements are preserved.

## Remaining acceptance

1. Complete exact-head child CI, owner attestation and separate merge authorization; rerun cumulative Hub CI after integration.
2. Prove real manifest strategy/input/output/effect/readback/rollback integration and actual signing key trust, expiry, rotation and revocation.
3. Prove shadow workload coverage, independent samples, field comparators, exact privacy, zero side effects and provider quotas/cost on representative Staging providers. Integrate receipts into existing eligible read exposure without authority changes.
4. Prove disposable fixture provisioning, exact one-time write authority, native hooks/external-effect restoration, observation failure, cancellation, stale identity/generation and incomplete cleanup on Staging.
5. Prove live pack database locking, concurrent activation/revocation, corruption, replay after restore, key revocation, expired active state and per-type interpreter/consumer behavior.
6. Complete relevant operator/browser/RTL journeys and inspect persisted state independently. No live WordPress connection is made from this development window.
7. Complete G4–G9 and the final frozen release closure obligations before making release-ready or runtime-parity claims for #258.

Production remains unauthorized; #258 remains Draft.
