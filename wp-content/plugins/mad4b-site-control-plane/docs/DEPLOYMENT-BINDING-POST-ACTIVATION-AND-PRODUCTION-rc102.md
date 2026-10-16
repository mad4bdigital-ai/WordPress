# Deployment Binding — Post-Activation Handoff + Production Inspection (rc.102)

## Live evidence before the patch

Two Staging sites accepted native host setup **manually**, without a new ZIP: **ETG** Site Profile revision **7**, **All Royal Egypt** revision **4**. In a separate MCP request, both `mad4b/deployment-binding-native-readback` returned `WORDPRESS_BINDING_READBACK_PASS` with `independent_request_observed=true`, original site UUID unchanged and no need to reprovision. The remaining Write issue is a distinct **stale exact candidate/grant reconciliation**, not a missing secret. After binding, ETG Context preflight advanced to `FOLDER_SELECTION_REQUIRED`; never select an unrelated Google folder automatically.

## WordPress Staging lifecycle

1. WordPress Plugins GET renders a lightweight, non-mutating navigation link to Operational Remediation.
2. The full admin page reads `mad4b/deployment-binding-native-status` and displays the actual native setup POST only for an eligible Staging host and a user possessing `manage_options` and `activate_plugins`.
3. The POST checks the current WordPress admin session, nonce, request origin, exact installed package provenance, Site Profile identity, host directory outside webroot and CAS/audit readiness; an existing secret is preserved.
4. The shared writer creates the private key, stores **only** its SHA256 digest and request-marker digest in Site Profile, commits a new revision under compare-and-swap and append-only audit, and rolls back only its own matching key on commit failure.
5. The result redirects to **Operational Remediation** on a new HTTP request, not the passive `plugins.php` shell.
6. Result mapping distinguishes `EXISTING_BINDING_REUSED`, `BOUND_NEW_REQUEST_READBACK_REQUIRED`, `POSTCOMMIT_RECONCILIATION_REQUIRED`, explicit errors and unexpected/unknown results. **Only the first two are non-error outcomes**. An unexpected/partial commit is never displayed as a success.
7. Operational Remediation runs `mad4b/deployment-binding-native-readback` for the current Site UUID and minimum profile revision, displays the independent proof state and only on `WORDPRESS_BINDING_READBACK_PASS` points to `mad4b/staging-write-authority-convergence-handshake`.
8. Re-plan the newly installed exact candidate and **separately** get authorized owner approval before converging Governed Write grants. The Site Profile revision changed during binding; any earlier plan is stale. Context source onboarding requires its own selection/approval and independent provider checks.

The old `HOST_SECRET_NOT_CONFIGURED` legacy wp-config readout is explicitly labeled optional when the **native** path is eligible or already bound. This resolves contradictory instructions without changing the existing external-host alternative.

## Production — isolated read-only support, no deployment

`mad4b/deployment-binding-production-status` is a private, administrator-capability read Ability. It checks that the actual WordPress environment, configured Site Profile and stored environment are all exactly `production`; compares current Site UUID, origin, revision and audit state; reads the existing Host binding digest via the normal Site Profile accessor; compares it with the stored SHA-256. It never exposes the secret/path, creates a file, changes Site Profile or enables a grant.

Production returns:
- `PRODUCTION_HOST_BINDING_VERIFIED` — existing Host binding matches its stored digest and site identity.
- `PRODUCTION_HOST_BINDING_NOT_ENROLLED` — no enrolled digest.
- `PRODUCTION_HOST_BINDING_MISMATCH` — host does not match the registered digest.
- `PRODUCTION_PROFILE_IDENTITY_MISMATCH` — environment/origin/UUID/revision/audit prerequisites failed.

The **Production Admin UI** displays this diagnostic and states that creation, replacement, migration or release of Production host credentials must be a **separately approved Production change**, with protected Host configuration, site-specific plan, independent readback, backup/recovery evidence and Production mutation policy; it never shows the Staging create button.

**This version intentionally does not implement a new Production mutating executor.** It adds the production recognition, fail-closed diagnostics and safe release handoff while Production remains unchanged. Do not advertise it as a Production rollout/automatic setup.

## Required separate live acceptance

- On both Staging sites after installing this new version: WordPress authenticated Plugins → Operational Remediation → PASS readback shown on independent GET; no setup button when binding already exists.
- Re-plan and approve the current exact Staging Write candidate, and verify zero stale grants; do not claim success based on planning alone.
- Verify remote OAuth-subject capabilities and Context/Google provider independently; choose source folders explicitly.
- On an actual Production site, inspect `deployment-binding-production-status` as an enrolled administrator first. A Production mutation/release must not occur without a separate explicit owner decision and evidence.

No Developer/Breakglass, implicit grants, Production mutation or Google grant changes are introduced.
