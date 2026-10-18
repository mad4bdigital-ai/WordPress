# WordPress-native update and ticket recovery — rc.99

This update extends the existing verified release channel in the WordPress Plugins screen. **Check MAD4B updates** is a safe link from `wp-admin/plugins.php` to **Runtime Components → Runtime Update**, which owns a standalone, nonce-protected **Check for updates** POST. This avoids invalid nested forms inside WordPress' plugin bulk-action form. The check is read-only and requires administrator visibility, not the `update_plugins` mutation capability. Verified update notices link back to the certified runtime update screen; installation still requires `update_plugins` and the existing exact pair/provenance, maintenance, readback and rollback gates. No arbitrary WordPress.org update transient or automatic core-update enrollment is added.

## First-time host binding after upgrading an active plugin

WordPress updates an active plugin without invoking its activation hook. On an exact eligible Staging profile without a host binding, the Plugins screen now presents **Complete Staging host setup** alongside existing update controls. It is an explicit admin POST, not a passive GET or remote MCP mutation. Before generating any local key it verifies the original nonce, operator capabilities, exact WordPress handler, installed package provenance and Site Profile.

The existing audited host-binding writer retains 0700 private directory / 0600 key, exact Site Profile CAS, rollback on audit failure, identity-preserving revision increment, and rejection of unsafe filesystem state, a foreign site or mismatch. An already valid binding is reused; no key rotation or grant changes. A later independent request must verify the binding before any governed Write planning.

This explicit continuation is also available after updating through the native WordPress plugin installation UI; it does not depend on the old runtime claiming a new activation hook has executed. Production is never authorized by the Staging setup action.

## MCP approval planner discovery

The direct ChatGPT tools/list deliberately does **not** publish the underlying approval-plan Write schema while authority is awaiting reconciliation. A live Dynamic Skills integration gate specifically rejects that exposure, including for a newly enrolled Staging Site Profile. The original governed **mad4b/write-execute** dispatcher is the permitted planning transport where its existing authority and grant preconditions are satisfied; otherwise use the exact authenticated WordPress Plugins POST for first-time **local** host-binding setup. Neither path automatically approves a ticket. The remote approval-plan ability, NHI grant, source candidate, owner decision and central mutation audit remain separately enforced.

The MCP host's registered tools and any connected client's cached list are independent: a direct tool advertised by an older connector may still return Tool not found until the current host registers it and the client refreshes. The original governed write dispatcher remains the sanctioned transport; neither local setup nor this catalog patch bypasses a missing ticket.

## Verification and limitations

Tests: deployment-binding-wordpress-update-runtime.php (wrong context, missing nonce marker, tampered provenance, Production, wrong capability, new binding and idempotency); deployment-binding-native-runtime.php (ticket executor); activation/profile-save bootstrap tests; deployment-binding-wordpress-update-contract.py; PHP 7.4/8.3 lint; full spec and delivery fingerprints.

Live Staging must separately demonstrate: actual WordPress-update UX, pending ticket creation and authorized approval, independent binding readback, re-planned Write, Context onboarding and browser acceptance. A successful local test is not real MCP client registration or a Production release decision.

**No change to Production authorization, Developer, Breakglass, raw SQL, external Google grants, or automatic WordPress core updates.**

## Staging branch / exact commit workspace

Runtime Components > Runtime Update offers an opt-in Staging-only source picker. It accepts branch HEAD, exact commit, PR HEAD, or an exact commit proven as an ancestor of a named branch (GitHub compare read: `compare/<SHA>...<branch>`). The preview pins source SHA, independently certified GitHub release manifest, existing upload plan digest and Site Profile digest. A nonce-protected administrator action only **previews**; selected-head install remains a separate administrator POST which delegates to the existing `Selected_Head_Update::apply` and therefore must satisfy the enrolled owner, OAuth Step-Up, exact authorizing ticket, archive digest, independent evidence, backup, rollback and readback checks. No caller-supplied package URL/archive and no arbitrary source tarballs. The site never caches OAuth tokens. Staging-only UI is hidden outside exact staging Site Profile + WordPress environment.

The existing recurring WP-Cron recovery is bound to the signed stable master release channel; it does not pick arbitrary PR/branch/commit or convey the owner's OAuth Step-Up. This Staging picker does not enqueue a Cron mutation or reinterpret a read-only preview as authorization.

### WordPress Plugins-screen popup (Early Zero-Touch compatible)

A lightweight popup class boots before the fastpath return on ordinary wp-admin/plugins.php GET/HEAD. The page stays zero-touch: no full Control Plane boot, remote provider call, database migration, or cron scheduling. The update-review action uses accessible native HTML dialog, responsive CSS, Escape/close focus restoration, and a valid fallback admin URL if JavaScript is disabled. Dialog markup and its nonce-protected forms are emitted in admin_footer-plugins.php outside WordPress's bulk-action form.

The MAD4B plugin row can explicitly refresh the signed release channel or preview a specific Staging Git source; neither preview installs anything. Once separate Cross-Plugin PR #421 is also deployed, reviewed installed plugin rows gain the same popup and submit to the existing cross-plugin read-only plan. An absent plugin has no WordPress row and uses Plugin Deployment. Production never shows the Staging selected-commit form.

Integration dependency: PR #420 adds the popup; PR #421 supplies the reviewed cross-plugin source registry and independent Admin/MCP planner. On a #420-only site, only MAD4B's popup is visible. Reconcile both PRs and retest the resulting exact merge head before deployment.

## Exact ticket claim on Browser Apply (PR #420)
The Staging admin selected-head POST now requires an exact UUIDv4 one-time owner approval ticket and routes execution exclusively through the *registered* `WP_Ability::execute` for `mad4b/control-plane-selected-head-apply`. The provider PHP `apply()` refuses a direct call unless central Authorization marks the callback as claimed and an Execution Fence is active. Its local defensive re-check converts the platform's structured `preflight_allowed` decision using the central permission interpreter instead of incorrectly comparing the decision array with boolean `true`. The nonce, Staging environment, owner enrollment, update_plugins permission, plan digest and typed confirmation remain mandatory. If the browser has no verified OAuth step-up or owner ticket, the UI is nonauthorizing and directs the operator to Remote MCP. No automatic install or Production path is added.
