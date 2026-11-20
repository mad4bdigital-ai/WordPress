# PR #814 — Local OAuth REST/MCP acceptance must opt in before WP-CLI boot

**Scope: disposable CI workflow and tests only. Release remains HOLD.**

The Local OAuth matrix calls `wp eval-file runtime-local-oauth-chatgpt-http-smoke.php`
from a normal `WP_CLI` process. This is not an HTTP MCP request. Since the
first-party request-scope hardening, normal WP-CLI jobs register lightweight
MCP Server shells **without** materializing DTO tools, preserving provider
isolation, startup cost and non-MCP request behavior. The Local OAuth smoke
then falsely expected `mad4b/operator-grants-converge` to be in the
pre-bearer tool inventory, even though the Ability was registered, chosen by
catalog budget, and its official MCP Adapter DTO passed independently.

The **already-existing** first-party `MAD4B_SCP_MCP_Request_Scope` contract
supports explicit `MAD4B_SCP_MCP_CLI_REQUEST=1` for authenticated, direct
MCP diagnostics in WP-CLI. The workflow now sets this flag *only on the one
disposable WordPress eval-file process* proving OAuth `initialize`, session,
`tools/list` and `tools/call`; the flag is present **before WordPress loads**.
The required tool must still pass descriptor binding and real Adapter
preflight, and it must remain unauthorized to execute without the verified
OAuth bearer, exact scope and all existing Company/Site/Original admission
conditions. No production plugin setting, capability, signature, operator
Grant, policy bypass or live WP request was modified.

Independent offline PHP 8.5 source contract: `WP_CLI=true`, no opt-in returns
`0`, `MAD4B_SCP_MCP_CLI_REQUEST=0` returns `0`, and `=1` returns `1`.
YAML parsed, and the exact CI command is scoped to one process only.
The revised Local OAuth job must still be verified through GitHub Actions
on its WordPress/PHP version matrix before marking the failure closed.

Historical Original313 signed postimage drift, missing exact-head OWNER
attestation and dynamic readiness evidence remain independent release HOLD
conditions; they are neither fabricated nor waived by this source fix.
