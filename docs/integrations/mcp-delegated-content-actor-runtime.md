# Delegated WordPress User — Governed Content Draft Execution

**Source:** MAD4B Site Control Plane core plugin. **Default:** `delegated_user` for verified enrolled WordPress OAuth/app-password principals; the existing `independent_nhi` mode remains selectable and untouched.

## Scope of this increment

Only the **Staging** runtime can automatically create a user-owned governed actor after a verified **write attempt**. It is an internal audit/budget/ticket identity (not an independent service NHI impersonating the user). It can receive only the exact `mad4b/content-apply-bundle` grant, on the two documented mounts `mad4b-content` and `mad4b-write`, for the current Staging environment. It is linked to the real enrolled WordPress `wp_user_id`, an already verified OAuth/app-password fingerprint, and the same site profile. It never creates raw content or approval tickets at enrollment.

Two legitimate entry routes:
- **Direct content MCP** requires a verified, explicitly write-scoped OAuth principal, a valid user role for the exact draft CPT, and the normal central exact write/approval/budget policy. It does not accept a read-only bearer as direct write authority.
- **ChatGPT compact MCP** uses `mad4b/write-execute` with `mad4b:read` OAuth, but actor enrollment happens **after** successful signed preparation classification, exact schema hash, exactly `mad4b/content-apply-bundle` as the target, an approval ticket reference, and live WordPress capability checks. The existing nested dispatcher still validates its independently authenticated bearer, exact target/schema/receipt, ticket, transport constraints, runtime gates, signed context, source rights, budgets, replay policy, and actual WordPress write permissions. The read token is not a general-purpose write scope.

A delegated draft can be created only with the target post type's `create_posts` permission and a draft/pending status. Existing drafts require exact `edit_post(id)` permission. Public/private posts and direct publish are prohibited; publication remains a separate independently approved `mad4b/content-update-post` operation. Custom/unmapped admin, theme, plugin, filesystem, SQL and Developer abilities are never inherited through this content bridge. The actor keeps fixed current-site/current-Staging grants only. Removed grants or disabled subjects/agents are **never automatically restored**, and the global write kill switch is checked before any actor record is provisioned.

## Security and limits

- An arbitrary `wp_set_current_user()` on WP-CLI, forged header, or raw JWT claim does **not** establish the verified OAuth/app-password request-local context.
- User enrollment, exact site origin, real WordPress capabilities and token proof remain mandatory.
- A signed preparation receipt and one-time approval ticket remain mandatory for compact ChatGPT dispatch; creating a managed actor is **not itself** approval or successful content execution.
- Only Staging can auto-provision actors in this iteration. Production never auto-provisions, and existing Production principal policy is unchanged.
- Any partial actor provisioning failure leaves a disabled principal for operator reconciliation; no retries of revoked authority.
- Existing self-update convergence and MCP 503 recovery stay independent of user capability inheritance.

## Tests

`wp-content/plugins/mad4b-site-control-plane/tests/mcp-delegated-content-actor-hermetic.php` explicitly fails for expired/unverified bearer, missing write scope on direct operation, site disabled, Production provisioning, unauthorized draft type/status, revoked WP role, post-ID mismatch, disabled subject, non-content tool, and incorrect mode. It confirms signed-prepared read scope is a distinct narrower route and checks idempotent two exact grants. Legacy `mcp-delegation-mode-runtime.php` and `mcp-delegation-connection-policy-runtime.php` remain required.

A live acceptance requires a **real authenticated MCP session**, separately approved and bound exact supplier/editorial QA payload, a genuine approval ticket and final read-back. WP-CLI role switching is deliberately not an acceptable substitute. Until those tests pass, do **not** claim content was saved or published.

## Promotion

Branch is based on PR #632's delegated user mode. It must remain a stacked PR until #632 is approved/merged, CI, explicit exact-head owner attestation and production-independent Staging integration proof are complete.
