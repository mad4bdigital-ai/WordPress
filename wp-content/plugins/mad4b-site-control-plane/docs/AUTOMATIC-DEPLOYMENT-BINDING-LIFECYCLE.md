# Automatic Deployment Binding bootstrap — `0.4.0-rc.98`

**Scope:** explicit local, authenticated WordPress admin activation and verified Site Profile enrollment/save on an **exact Staging** site. This is not a remote MCP approval bypass or a Production readiness grant.

## Expected operator experience

1. Plugin installed and activated through normal WordPress Admin: `MAD4B_SCP_Plugin::activate()` completes schema, append-only audit and crypto setup before invoking `MAD4B_SCP_Deployment_Binding_Native::activation_bootstrap()`.
2. If the exact Staging Site Profile already exists and the private host path can be proven outside the public document root, the plugin creates the host key **once** (`0700` directory, `0600` file), stores **only its SHA-256 digest** in the audited Site Profile revision, and returns a requirement for a **separate-request readback**. No generic WordPress GET, frontend, MCP read, or cron can invoke this writer.
3. If the Site Profile is not yet enrolled at activation, the plugin does **not** create a key against an untrusted/undefined identity. The nonce-verified WordPress **Site Profile Save** (including AJAX and the existing explicit Autopilot Save) invokes `profile_save_bootstrap()` *after* a successful persisted identity readback, when host environment configuration is already aligned.
4. If a valid host binding is already present, reactivation and subsequent Site Profile saves return `EXISTING_BINDING_REUSED`: **no key rotation, new ticket, profile revision, or grant**.
5. Ordinary plugin updates **do not mint a new host secret** from an old HTTP request or passive page. An existing exact binding is reused; update reconciliation rechecks package, MCP, Skills, Write candidate, Google/Context and new-request readback. A missing binding following an update is exposed as a **local admin activation/profile-save path**, not silently trusted from a copied DB.
6. Remote MCP provisioning remains governed by `mad4b/deployment-binding-native-plan` → an exact Staging approval ticket → `mad4b/deployment-binding-native-apply` → independent readback. **No change** to the remote write permission callback or claimed one-time ticket boundary.

## Security gates

- Require **actual WordPress activation hook** for the exact MAD4B plugin, `is_admin()`, both `manage_options` and `activate_plugins`, and no WP CLI / AJAX / cron impersonation. A direct static call outside that current hook is rejected.
- For Site Profile save, require an exact `admin_post_mad4b_site_profile_save`, `wp_ajax_mad4b_site_profile_save`, or `admin_post_mad4b_site_profile_enable_staging_autopilot` hook on POST, with the original handler's WordPress nonce and permissions already verified.
- Before host write, require exact enrolled Staging Site Profile, revision/digest, WordPress environment/origin match, no pending audit mutation, proven filesystem separation, writable parent and no preexisting directory/secret or conflict.
- Recheck immutable binding plan, use exclusive `fopen(..., 'x')`, strict permission checks, fail-closed Site Profile CAS + audited mutation and compensating rollback if the database commit fails.
- Existing private key, externally configured key, symlink, cloned/foreign Site Profile or matching-key mismatch **never get overwritten**; privilege scope is unchanged.
- A DB clone with a valid profile but **no independent external host identity** cannot be automatically trusted on an ordinary update; explicit local admin activation/Site Profile enrollment provides the bounded local trusted action. This is not independent host hardware/provider attestation.
- Both Staging and Production retain separate write authority boundaries. Native binding bootstrap never enables Governed Write, Developer, Breakglass, Google grants or external approval.
- A successful same-request host write is **not** independent operational acceptance. On a new HTTP request verify site UUID, private-key digest, profile revision and request marker. Do not claim backup/restore or Host attestation from this.
- Production is intentionally read-only for this feature. A Production update cannot run Staging bootstrap or infer Production promotion from a Staging Site Profile.

## Regression / acceptance tests

- `tests/deployment-binding-activation-bootstrap-runtime.php` — trusted admin activation, deny ordinary GET/update/anonymous/Production, audit rollback, exact profile scope, no secret leakage, second-request readback and idempotent reactivation.
- `tests/deployment-binding-profile-save-bootstrap-runtime.php` — first valid Site Profile save after earlier activation, deny GET and wrong environment, repeated save idempotency and unchanged Write feature.
- `tests/deployment-binding-native-runtime.php` — original MCP immutable ticket boundary unchanged, same request cannot certify, readback and legacy keys protected.
- Existing Context onboarding and admin routing tests remain required; a read-only standalone fixture does **not** establish real WordPress install acceptance.

## Deployment sequence

- CI and exact-head source admission/release verdict before release; build a deterministic verified plugin ZIP with `rc.98` source fingerprint.
- Verified backup and rollback on each Staging site; update the plugin and read back source SHA, version, package manifest, adapter, OAuth/MCP, Skills and Site Profile.
- **Local WordPress activation** or verified Site Profile Save initiates bootstrap if still eligible. No extra Deployment Binding approval ticket should be needed **for this local lifecycle path**.
- Verify binding **in a separate request**, then re-plan exact Governed Write against the *new* Site Profile revision; execute only with its existing enrolled owner step-up and independent grants readback.
- Verify Source Onboarding and quarantine without ownership transfer, and live browser/response/performance acceptance.
- Keep Production completely unchanged until its separately approved release.
