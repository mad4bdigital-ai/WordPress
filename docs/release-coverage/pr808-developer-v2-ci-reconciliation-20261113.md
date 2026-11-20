# PR #808 — Developer V1 policy migration and CI reconciliation

**Disposition: SOURCE-VERIFIED / RELEASE HOLD.** This is not an owner approval,
executed grant, Original312/313 receipt, or Production authorization.

## Canonical plan integrity

The `MAD4B_SCP_Developer_Authority` V1 class and its exact plan SHA computation
are unchanged. Its historical `production_allowed=false` plan attribute remains
immutable, so previously signed V1 plans, approvals and Grant associations are
not reinterpreted or overwritten.

`MAD4B_SCP_Developer_Authority_Policy_V2::project()` is a read-only companion
versioned contract. It verifies the complete V1 plan SHA, current registered
operation preflight, same site UUID, environment, Site Profile revision and
digest, and current governance-policy digest. The resulting object explicitly
says it issues no approval, grant or authorization. The Operator plan response
contains this sidecar while preserving `canonical_plan_sha256`, exact-five
allowlist, OAuth step-up, canonical V1 Apply and independent grant readback.

A future V2 *authorizing* plan format is a different release/schema migration.
It must be registered, signed, read back independently, and explicitly approved
before it can replace V1. This additive sidecar is not that migration.

## Skills parity and narrowly-scoped hardening

The canonical manifest contained 21 Skills but both tracked Skill locations
contained only 13. Exactly eight missing Markdown files were restored verbatim
from two verified, clean Git source objects: source revisions
`4851edc1e6e2c4910ca07f030e6b1a35cb127c07` and the Mobile cache source
`0a28670...` (full commit in `pr808-portable-skills-provenance-20261113.json`).
No existing Skill was replaced. The provenance manifest pins each Git SHA and
content SHA256, and tests require 21/21 byte-equivalent canonical/portable
copies. This does not copy runtime plugin source or mutate a site.

`MAD4B_SCP_Skill_Seeder` now rechecks exact enrolled Site Profile origin and
site URLs at *all three* mutating worker entrypoints (automatic reconciliation,
full reconciliation and batch reconciliation), instead of trusting only the
Registry caller. Negative hermetic tests check no option/file writes when
origin or URLs diverge. Managed system-generated Skills remain independent of
the user-editing UI switch; the stale editor-enabled assertion was reconciled.

## CI: targeted fixes vs outstanding unrelated failures

Verified locally on the source-only branch with PHP 8.5 and a network-isolated
container: `developer-runtime-contract.py` and `dynamic-skills-contract.py`
PASS; the V2 policy-projection and Skill negative tests PASS; the original
Operator and Enrollment tests PASS; root Python source-integrity tests PASS.
These results do not replace new GitHub-hosted CI.

The prior GitHub PR commit `d1876d4e2cc0` showed eight failing workflows. CI
logs identify additional unresolved problems outside this change:

- Web MCP Lifecycle: `mad4b-read` not registered after web `rest_api_init`.
- Runtime Integration: `apply_filters()` undefined in an isolated Media Rights
  contract fixture (`class-mad4b-scp-remote-media-rights.php`).
- General Distribution and external Staging test lanes: a recovery handshake
  failed to arm a subsequent request; external live HTTP readiness must be
  evaluated independently, not mocked as green.
- Feature 007: the Schema fixture lacks `MAD4B_Test_Schema_WPDB::get_row()`,
  and Brand Context invokes missing `brand_owner_evidence()` in one MariaDB
  upgrade lane.

The prior package failure involved missing canonical Skills; 21/21 files have
now been reconciled in source, but the new GitHub package workflow must verify
this before it can be marked resolved.

**Do not use source tests or a Draft PR as an Original306/312/313 receipt.
Do not merge to master or install on Tours.dev/Wayfatours without passing
independent GitHub CI, first-party enrollment and the signed Release Verdict.**
