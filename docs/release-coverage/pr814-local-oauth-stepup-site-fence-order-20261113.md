# PR #814 — Local OAuth direct step-up denial ordering

**Status: CI acceptance correction, not an authorization or production change.**

After the first-party explicit WP-CLI MCP request-scope fix, the real
WordPress Local OAuth smoke successfully found and built the reviewed
`mad4b/operator-grants-converge` tool. Its read-only OAuth bearer still
could not execute the tool. The returned permission denial was
`mad4b_developer_authority_site_not_ready`, because the disposable
OAuth-only Site Profile is intentionally **not** enrolled for Managed Runtime
writes. First-party Developer Authority checks this prerequisite before
Operator Convergence inspects exact bearer step-up scope and client identity.

The old source acceptance had demanded that **every** error code contain
`scope`, `step_up`, or `client`, which incorrectly rejected an earlier,
stronger exact Site Profile denial. It is now valid only if all of the
following are true: (1) the registered tool's `tools/call` denied execution;
(2) the independent permission callback returned `WP_Error`; (3) the
site readback confirms managed runtime is **not** ready; and (4) the code
is exactly `mad4b_developer_authority_site_not_ready` or
`mad4b_operator_grants_site_not_ready`. Every other condition still
requires an explicit scope/client denial and must not pass otherwise.

The static regression test separately proves the **production** source
continues to check `MAD4B_SCP_Registered_Write_Admission::preflight`, the
verified `mad4b:authority:step-up` bearer, the exact ChatGPT OAuth client,
Developer Authority and Site Profile. No real tool permission, runtime
callback, write policy, WordPress database, Production plugin, signed
original313 receipt or Operator Grant has been changed.

The Local OAuth disposable WordPress matrix must still verify this revision.
The larger historical Original313 postimage drift, missing owner-signed
exact-head Release Verdict and remaining source readiness issues remain
independent **RELEASE HOLD** blockers.
