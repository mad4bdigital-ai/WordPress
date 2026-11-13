# PR #814 — MCP Tool Identity Collision: Catalog-Wide Fail-Closed

**Scope: first-party Control Plane source candidate only. Production release HOLD.**

The existing real MCP Adapter preflight checked whether a wire tool name was
empty, claimed another Ability identity or matched a previously validated
tool name. It correctly recorded an `mcp_identity_collision` failure but then
used its general optional-fault isolation path, returning `ready=true` when
at least one valid required tool had been built. A collision with a later
optional tool was therefore reported as `degraded` rather than denying the
whole catalog, contrary to the prior registered DTO acceptance contract.

The source change tracks **only** `mcp_identity_collision` as a critical
catalog integrity error, sets `blocker=mcp_catalog_identity_collision`,
`ready=false`, `degraded=false` and `tools=[]`, while preserving bounded and
non-sensitive failure diagnostics for the exact offending Ability. Missing
optional Abilities, optional oversize DTOs and schema errors still follow the
existing independent quarantine path without disabling valid required tools.
There is no new static operation allowlist, execution authority, policy change,
OAuth change or permissive fallback.

## Evidence

- Official bundled MCP Adapter materialized from the repository ZIP in an
  offline isolated directory (313 files; archive SHA256
  `1c3cd47c32e99b4e7d8690a44a7890256e92a8b96f61776cbe1894e5483cf676`).
- Real Adapter DTO check: **29 production required schema conversions passed**;
  invalid third-party naming, optional-vs-required name collision,
  required-vs-required collision, empty input and bounded size errors behaved
  as asserted, including zero tools available on any identity collision.
- MCP catalog evidence, connection readiness, registration diagnostics,
  preflight registration failure and adaptive budget offline tests passed.
- Control Plane source tests 14/14; Native Source tests 203/203.
- Root Python suite: 299 tests, 0 new failures and 14 pre-existing historical
  independently signed Original313 postimage errors.
- The later `endpoint-diagnostic-runtime.php` catalog selection case was also
  re-run on the **unmodified PR #814 parent**, where it fails identically.
  It is a separate baseline CI problem and is not suppressed or claimed fixed.

This source-only patch does **not** install or activate any WordPress plugin,
change registered companies, publish a signed Original313 receipt, issue
Original312 Grants, merge to master or authorize branch deletion. Final
approval depends on independent PHP 7.4/8.1/8.3 CI and the original signed
release governance/readback gates.
