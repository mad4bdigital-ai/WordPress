# MCP Principal Modes — Delegated WordPress User (default) and Independent NHI

## Default authorization model

`MAD4B_SCP_Delegation_Mode::selected_mode()` defaults to `delegated_user` without silently rewriting existing options. WordPress administrators can explicitly change the site-local `mad4b_mcp_principal_mode` setting using **MAD4B Control Plane → MCP Principal Mode**, with `manage_options` and a WordPress nonce. `independent_nhi` preserves the previous NHI-only behavior. Invalid/malformed values fail to independent NHI rather than granting delegation.

Delegated mode applies only to already verified OAuth `user:<id>` subjects or authenticated WordPress Application Password subjects for the **same enrolled site and current user**. The verified identity is checked against the server-side, request-local OAuth mapping or WordPress application-password authentication event; subject fingerprints, the WordPress user, active OAuth verifier, required read scope, origin and site enrollment must match. Bare WP-CLI, cookies, unverified JWT strings, headers, anonymous subjects and Developer NHI identities do NOT acquire delegation from this setting.

An enrolled WordPress user with the `read` capability may connect under delegated mode without inheriting `manage_options`. Tool access itself stays subject to each Ability's WordPress permission callback; in particular, the general administrative `mad4b-read` surface is **not** exposed to arbitrary subscribers. For a delegated mutation the policy first enforces real WordPress capabilities as an *upper bound* for the exact operation: `edit_post(id)` and post-type `publish_posts` when relevant; `edit_posts` for draft creation; explicit plugin and media capabilities where reviewed. Unknown abilities are denied rather than guessed.

## Independent NHI remains supported

`independent_nhi` stays available for autonomous service principals and as a selectable mode for the site. NHI and Developer subjects are independent even while `delegated_user` is the global default.

**Important:** This change adds verified-user inheritance to principal selection and capability checks **without disabling MAD4B's preexisting exact NHI agent/grant registry, OAuth scope, approval tickets, schema readiness, budget, tenant, candidate binding, runtime or publication checks**. The centralized write executor still requires a valid bound agent, a matching exact grant and any required approval. Thus WordPress role permission alone is *not* a completed MCP write authorization and no automatic user-to-agent grant provisioning is performed. Any future removal of the additional agent-grant requirement for delegated identities requires a separate reviewed design covering audit, ticket ownership, budgets, per-tenant grants and claim/commit invariants.

## Testing and rollout

`php -d zend.assertions=1 -d assert.exception=1 wp-content/plugins/mad4b-site-control-plane/tests/mcp-delegation-mode-runtime.php` verifies:
- default mode, explicit NHI mode, invalid-option fail closed;
- authenticated OAuth user and exact matching issuer-verified mapping;
- revoked/bogus bearer, subject mismatch, site enrollment and current WordPress user mismatch;
- editor draft vs publish and exact-post checks, admin post publishing, sensitive/unmapped ability denial;
- nested write dispatcher target checks; no automatic production mutation.

Use only on the enrolled Tours Commerce Staging runtime after PHP lint, source parity and plugin-loading smoke tests. No Production mode change, client credential rewrite, missing agent grant synthesis, or forced publication. Update the mode only through normal WordPress administration by the authorized site administrator.
