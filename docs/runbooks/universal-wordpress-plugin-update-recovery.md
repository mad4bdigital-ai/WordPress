# MAD4B — Universal Plugin Update Recovery / CI-Outage Federation

## Scope

The CI-outage pattern from Control Plane PR #258 is now provider-neutral.
Every installed WordPress plugin — including single-file plugins and those
without a source contract — can be *discovered*. Only a source-reviewed,
uniquely certified provider can be *planned for installation* using the existing
`mad4b/plugin-package-plan` and `mad4b/plugin-package-apply` pipeline.

**Queued GitHub Actions is informational, not an installation precondition.**
Neither a queued check nor an Actions failure means that tests passed.
A confirmed native/security test failure is always a blocker until the exact
source is fixed and retested. The special signed CI-outage route is limited
to Staging; it cannot waive GitHub merge policy or Production promotion.

## MCP entrypoints

| Ability | Effect | Description |
| --- | --- | --- |
| `mad4b/manual-workflow-discover` | read | Optional `include_plugin_updates=true` combines canonical manual workflow and bounded per-plugin inventory |
| `mad4b/plugin-update-recovery-discover` | read | Enumerates installed plugins, certification status, version, provider and available original planner/executor |
| `mad4b/plugin-update-recovery-plan` | read | Resolves plugin file to exact source-reviewed provider, delegates to existing certified package planner, returns unchanged authoritative `plan_sha256` |
| `mad4b/plugin-update-evidence-verify` | read | Verifies source-approved per-provider Ed25519 Staging evidence for an exact plugin/version/archive/source/site |
| `mad4b/plugin-package-plan` | read | Original source-authoritative package planner |
| `mad4b/plugin-package-apply` | governed write | Original central authorization, exact package download/hash/critical files, backup, activation, disk readback and rollback |

The generic recovery layer **does not add a second installer**, cannot
register an arbitrary executor and never accepts a caller-controlled ZIP URL,
filesystem path, public signing key, GitHub workflow success label, PHP
callback, shell command or WordPress database writer.

## Per-plugin evidence selection

| Plugin / source class | CI outage approach | Update authority |
| --- | --- | --- |
| Certified repository artifact | Independently verified exact SHA-256 and critical-file hash from certified provider contract; no external GitHub CI verdict dependency | Existing `plugin-package-apply` |
| Certified vendor upstream release | Fixed trusted upstream identity and version + downloaded SHA-256/size/critical files | Existing `plugin-package-apply` |
| Certified WordPress update offer | WordPress-resolved package offer must match exact source-owned version/digest | Existing `plugin-package-apply` |
| Certified local archive | Source-reviewed private archive digest plus install readback | Existing `plugin-package-apply` |
| Source-approved provider with `offline_update_attestor_public_key` | Independent Ed25519 Staging receipt bound to provider ID, component, plugin file/version, package SHA, source SHA, test bundle, site UUID/origin and short expiry | Evidence is **non-authorizing**; use original certified installer separately |
| Uncertified, conflicting or unknown plugin | Discoverable with explicit need for source/adapter enrollment | No generic apply route |

A public signer key can only come from a reviewed provider contract in
`config/certified-providers.json` (or its version-scoped certified equivalent)
under field `offline_update_attestor_public_key` with canonical Base64
encoding of the 32-byte Ed25519 public key. The private signer key must
remain outside WordPress and chat. Updating a public key requires normal
source review and contract governance. The existence of a key by itself
does **not** certify an arbitrary ZIP or grant writes.

The signed provider-native claim uses
`mad4b.plugin-update-native-evidence.v1` with keys, in exact order:
`contract, provider_id, component, plugin_file, version, archive_sha256,
source_commit_sha, site_uuid, site_origin, environment, issued_at, expires_at,
evidence_bundle_sha256, test_gates, owner_reviewed`.
Five mandatory test gates:
`source_exact, php_syntax, package_integrity, plugin_runtime,
rollback_readiness`; all must be `PASS`. Expiry is at most 24 hours.
Wrong plugin/provider/version/archive/site, stale evidence, failed test,
missing signer or Production environment must be denied.

A verifier result is **not an authorization to install**: run the separately
governed `plugin-package-plan/apply` with unchanged exact plan digest, API
authorization, owner consent, Staging admission and preexisting rollback
procedures. Native evidence is accepted as separate validation rather than
forging a successful GitHub CI result.

## Assistant routine

1. Discover all plugins and current blockers via
   `mad4b/plugin-update-recovery-discover`.
2. Match the user's plugin request to an exact installed `plugin_file` and
   source-reviewed provider contract; never infer trust from plugin display
   name or arbitrary WP admin route.
3. Use `mad4b/plugin-update-recovery-plan` to retrieve current certified
   source and *unchanged* underlying package `plan_sha256`.
4. If CI is delayed, distinguish `queued/unavailable/infrastructure_failure`
   from `test_failure/security_failure`. For source-owned provider packages,
   the original package digest/critical-file acceptance is the authority.
   For additional independent signed native evidence, verify through
   `mad4b/plugin-update-evidence-verify`, without reinterpreting a
   caller-asserted PASS as proof.
5. Only on eligible Staging plan and after explicit owner approval, invoke
   the *original* `mad4b/plugin-package-apply` with `provider_id`,
   `component`, `source`, `reason`, `expected_plan_sha256`. Do not send
   the recovery layer's diagnostic fields to the installer.
6. Verify installed version/disk digest/activation, fault records, WP/PHP
   compatibility, no unexpected dependencies and availability. Use the
   original rollback mechanism if postconditions fail.

## Expansion and limits

Adding another certified provider to the source-owned provider registry
automatically makes its installed plugin discoverable and its exact package
planner available — without rewriting the assistant. Single-file or
uncertified plugins are included in discovery but require a **reviewed
provider/adapter** before privileged installation. The model can identify
the missing enrollment and propose it, but cannot independently approve it.

These patterns are deliberately WordPress-MCP-specific. The new code does
not claim to control any arbitrary unrelated MCP, CMS, cloud provider or
third-party plugin that does not expose an approved update surface.

All new abilities require installing the updated Control Plane on Staging and
refreshing the MCP catalog. Existing installed plugin code does not gain
these abilities simply because the source files exist in a GitHub PR.

## Acceptance required before live use

- PHP 8.3 lint both new classes and their test fixtures.
- Run `plugin-update-recovery-runtime.php`: installed provider inventory,
  single-file/disallowed paths, composite providers, duplicate conflicts,
  CI queued/native-signature behavior, unknown provider refusal and canonical
  package-plan parity.
- Run `plugin-update-evidence-runtime.php`: real sodium signature pass and
  wrong plugin/provider/site/Production/time/failed test refusal cases.
- Execute canonical v7 exact-HEAD plugin build and ZIP verifier, then
  independently verify Staging plugin package plan/apply readback and rollback.
- Confirm a real vendor/WordPress/local archive path separately. Real tests
  must not be declared PASS solely because a workflow was scheduled.
- GitHub PR #258 is a Draft feature branch; no Production promotion.
