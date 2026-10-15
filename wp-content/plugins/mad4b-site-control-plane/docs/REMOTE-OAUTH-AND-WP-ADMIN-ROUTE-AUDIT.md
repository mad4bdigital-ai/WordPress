# MAD4B — separate remote MCP and WordPress UI route audit (rc.101)

## Root cause: invisible Staging host setup link

On the native WordPress `plugins.php` GET/HEAD, the plugin deliberately exits after `MAD4B_SCP_Native_Plugin_Admin_Menu::boot()`, before `MAD4B_SCP_Self_Update::boot()` can register `plugin_action_links`. Therefore a setup button registered solely in Self_Update was structurally invisible. It was **not** evidence that Deployment Binding or the user's permissions were unsupported.

The fix registers only the exact MAD4B row link in the zero-touch shell. It performs no Site Profile, authority, database, audit, provider or filesystem read. The link opens **Operational Remediation** on a new full-runtime request. WordPress's native plugins list already sits inside the bulk-action form, so the row must be a simple anchor, **not a nested HTML form**. The remediation page renders the real, nonce-bearing POST only when the current local administrator has `manage_options` and `activate_plugins`, and native binding status is eligible.

### WordPress UI journey (cookie-authenticated WordPress administrator)

| Route | GET render | Mutation | Authority |
| --- | --- | --- | --- |
| `plugins.php` | Tiny navigation + specific MAD4B row link, no heavy runtime | None | WP capability check controls link only |
| `admin.php?page=mad4b-operational-remediation` | Native binding eligibility and proposed action, independent of remote MCP | None | `manage_options` plus `activate_plugins` to see mutation form |
| `admin-post.php?action=mad4b_complete_staging_host_setup` | Not a GET workflow | Create/reuse a private exact Staging host key, CAS + audit + rollback | Verified WP session + POST + nonce + installed source provenance + exact enrolled Staging Site Profile + secure filesystem |
| New-request readback | Read original Site UUID, revision and host binding digest | None | Read-only |
| Governed Write reconciliation | Present fresh exact package/identity plan | Only with separate owner authority | No Developer, Breakglass or Production auto-grant |

All states must provide a meaningful failure reason. Rendering never creates keys or quietly grants permissions. WordPress OAuth for ChatGPT does **not** automatically create an authenticated WP-admin browser cookie or bypass a POST nonce.

### Remote MCP journey (OAuth bearer)

| Stage | Source / control | Safe behavior |
| --- | --- | --- |
| Transport admission | Cryptographically verified bearer on exact resource audience | Deny invalid issuer, expired bearer, wrong audience or wrong Site UUID |
| Subject mapping | `MAD4B_SCP_OAuth_Subject_User_Bridge` | Bind the verified `user:<id>` to the existing, currently enrolled WordPress user; never assume the compatibility/default admin |
| Capability evaluation | `MAD4B_SCP_Policy` + original Ability permission callbacks | Evaluate WordPress capabilities for **this mapped user on this request**, together with registered NHI/grants and provider/tenant/site policy |
| Discovery | Governed `mad4b/read-execute`, `mad4b/write-execute` and operation discovery | Discovery is informative, not authorization; unapproved direct write schemas must not leak into ChatGPT tools/list |
| Approval lifecycle | Exact plan → owner approval ticket → claimed execution → new-request readback | No simulated user consent, no direct ticket approval or remote host key through a UI link |
| User switch / revoke | New verified token / updated WP role or site enrollment | Deny immediately if user no longer enrolled or required capability/grant withdrawn |
| Production | Separate production policy | Never inherit a Staging grant or use a Staging recovery shortcut |

**Effective remote authority is an intersection, never a union:** verified OAuth subject and resource scopes ∩ current WordPress user capabilities ∩ Site Profile/tenant/provider policy ∩ granted NHI/operation scope ∩ required owner approval/step-up. OAuth account consent must never be interpreted as unrestricted WordPress administrator authority.

## Regression coverage

`native-plugin-management-setup-action-runtime.php` proves the actual plugins.php GET boot loads only lightweight registration, shows the exact row link for a capable user, and never embeds a nested form or discloses the link to insufficiently privileged users. The previous native GET zero-touch runtime and nonce-protected post-update bootstrap fixture continue to pass.

`oauth-multi-user-tenant-runtime.php` verifies that two independently enrolled OAuth subjects map to their own WordPress users, not the compatibility administrator; the editor is not an administrator; a revoked WordPress capability becomes ineffective; deleted enrollment and wrong Site UUID are denied.

This code change repairs WordPress UI discoverability; the existing per-subject OAuth mapper and permission callbacks supply dynamic **server-side** delegation. No new role, write grant or elevation is created by this patch. Before declaring full live acceptance, inspect an actual logged-in administrator screen and two distinct OAuth delegates with appropriately differing WordPress capabilities, then verify the complete remote plan/ticket/readback on both Staging sites.

## OAuth route completeness in rc.101

Previously, verified-subject mapping ran only on `mad4b-chatgpt` and `mad4b-enrollment`, although the OAuth resource bridge also protects `mad4b-developer` and `mad4b-developer-breakglass`. The mapper now binds all four resources to the **exact enrolled token user** once the cryptographic bridge accepts the bearer. No OAuth token, role or transport can enable Developer/Breakglass on its own; original provider/NHI/host gates remain mandatory. Multi-route tests assert that an editor remains a non-admin on each protected route, and current WordPress capability withdrawal is respected on the next check.
