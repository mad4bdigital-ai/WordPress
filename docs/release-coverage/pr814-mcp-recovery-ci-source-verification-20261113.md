# PR #814 — MCP Recovery CI Source Verification Contract

**Source-only fix:** a hermetic acceptance fixture for
`MAD4B_SCP_MCP_Runtime_Recovery` was stale. The real production
`MAD4B_SCP_Dependency_Manager::mcp_adapter_disk_integrity(true)` reports
separately (1) `ready` and (2) `source_verified`. Both are required before a
privileged recovery lease may be acquired. The old fixture returned only
`ready`, so PHP 7.4/8.3 GitHub Actions failed at the first positive recovery
case with `recovery did not arm next request`.

The fix changes **only test fixtures and regression workflow**. The real
integrity/signature checks, Site Profile/environment policies, OAuth, leases,
transaction locks, filesystem CAS, backups, audit and signed receipts are
unchanged. The updated fixture models the official-source verification result
only for the isolated positive test case. A new `source_unverified` case must
fail specifically with `mcp_adapter_official_source_unverified`, **before**
a maintenance lease is created or a MU bootstrap is written.

Local isolated PHP 8.5 acceptance results:
- 39/39 MCP Recovery scenarios passed, including the new official-source
  denial and the old Production/nonce/lock/rollback cases.
- 43/43 MCP MU recovery boundary scenarios passed.
- 1,825 assertions passed over 160 Site Profile enrollment pairs and 10
  fault cases in the site-profile lifecycle matrix.
- Site Profile general distribution contract passed.

The GitHub CI lanes run against PHP 7.4/8.3; their independent results on the
new commit are still required. These local tests do not issue a signed
Original313 receipt or declare Production readiness. The consolidated
repository still has 14 independently signed historical Original313 postimage
mismatch errors, inherited from the original source. **Release HOLD remains
in force** until authentic new postimage readback and signed governance
admission are verified. Never weaken the real runtime `source_verified` gate
just to make the fixture pass.
