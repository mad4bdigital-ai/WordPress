# WordPress-native update and ticket recovery — rc.99

This update extends the existing verified release channel in the WordPress Plugins screen. **Check for updates** is a nonce-protected POST and resolves the signed immutable release pointer; **Update now** uses the existing verified-archive, maintenance-lease, backup/readback/rollback coordinator. No arbitrary WordPress.org update transient or automatic core-update enrollment is added.

## First-time host binding after upgrading an active plugin

WordPress updates an active plugin without invoking its activation hook. On an exact eligible Staging profile without a host binding, the Plugins screen now presents **Complete Staging host setup** alongside existing update controls. It is an explicit admin POST, not a passive GET or remote MCP mutation. Before generating any local key it verifies the original nonce, operator capabilities, exact WordPress handler, installed package provenance and Site Profile.

The existing audited host-binding writer retains 0700 private directory / 0600 key, exact Site Profile CAS, rollback on audit failure, identity-preserving revision increment, and rejection of unsafe filesystem state, a foreign site or mismatch. An already valid binding is reused; no key rotation or grant changes. A later independent request must verify the binding before any governed Write planning.

This explicit continuation is also available after updating through the native WordPress plugin installation UI; it does not depend on the old runtime claiming a new activation hook has executed. Production is never authorized by the Staging setup action.

## MCP approval planner discovery

A strict initial-binding Staging state may publish the existing approval-plan tool while Write authority is awaiting reconciliation. This corrects a circular catalog gate that previously hid the only ticket-planning path. Visibility is not a grant: existing WordPress Ability permission callback, NHI grants, candidate/source validation, one-time tickets, owner decision and central mutation audit remain required. AI approval is not enabled through this exception.

The MCP host's registered tools and any connected client's cached list are independent: a direct tool advertised by an older connector may still return Tool not found until the current host registers it and the client refreshes. The original governed write dispatcher remains the sanctioned transport; neither local setup nor this catalog patch bypasses a missing ticket.

## Verification and limitations

Tests: deployment-binding-wordpress-update-runtime.php (wrong context, missing nonce marker, tampered provenance, Production, wrong capability, new binding and idempotency); deployment-binding-native-runtime.php (ticket executor); activation/profile-save bootstrap tests; deployment-binding-wordpress-update-contract.py; PHP 7.4/8.3 lint; full spec and delivery fingerprints.

Live Staging must separately demonstrate: actual WordPress-update UX, pending ticket creation and authorized approval, independent binding readback, re-planned Write, Context onboarding and browser acceptance. A successful local test is not real MCP client registration or a Production release decision.

**No change to Production authorization, Developer, Breakglass, raw SQL, external Google grants, or automatic WordPress core updates.**
