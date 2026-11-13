# PR #814 — Endpoint Diagnostic: Canonical Server Inventory, No Fixed Count

This is a **test-contract-only** update. The pre-existing
`endpoint-diagnostic-runtime.php` expected nine server registrations, while the
current governed `MAD4B_SCP_Servers::expected_server_ids()` declares ten,
including the separately scoped content transport. The same failure was
reproduced on the unmodified `34c92caca339` PR #814 source; it was not caused
by the new catalog identity collision hard-fail patch.

The updated acceptance test derives the exact expected IDs from the current
first-party Server Registry, confirms each appears exactly once in the
non-mutating Adapter construction response, and still requires that **only**
the authorized `mad4b-developer-breakglass` server receives the exact
fixture tool. All other Servers must receive an empty tool selection. Signed
MU routing proofs, nonce/capability checks, foreign Adapter exclusion,
no-certification assertions and bounded evidence remain unchanged. No new
operation allowlist or privileged runtime write route is created.

Offline PHP 8.5, official bundled MCP Adapter: 12/12 Endpoint Diagnostic
cases passed; full Post Update Continuation, Maintenance, Catalog evidence,
MCP schema preflight, peer inventory, projection and Endpoint UI checks also
passed. This is source-only: no installed WordPress site, signed Original313
receipt, Original312 Grant, Production change, master merge, or branch closure.
The Original313 historical target postimage drift and separate Release Verdict
owner attestation remain unresolved and cannot be waived by this test fix.
