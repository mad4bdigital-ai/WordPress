# MAD4B — Operational Security Providers (Staging-only proposal)

## Reuse, not replacement

The optional providers are third-party WordPress.org plugins, not MAD4B-native scanners or firewalls. MAD4B reuses the existing `mad4b/plugin-transaction-plan`, `mad4b/plugin-package-plan`, `mad4b/plugin-package-apply`, central Write authorization, WordPress `Plugin_Upgrader`, plugin lifecycle policy, and `MAD4B_SCP_Audit`.

The server-owned catalog is `config/security-operational-providers.json`. Initial entries: Wordfence, WPVulnerability, Activity Log, Query Monitor and Plugin Check. It contains **no pinned version**, download URL, credentials or plugin code. An unknown provider ID cannot become an installer target merely because it has a name or is installed.

## Operator workflow

1. On an **enrolled Staging site**, request `mad4b/plugin-transaction-plan` with `action=install`, `provider_id=security-wordfence`, a reason and optionally `activate_after_install=true`. This routes to the existing package planner with `source=wordpress_org`.
2. Review `native_plan` and its exact `plan_sha256`, current WordPress.org version, package origin, PHP/WP compatibility and blockers. An occupied plugin directory blocks a new install; use existing update/lifecycle routes instead.
3. Apply via the **existing** `mad4b/plugin-package-apply` ability with `provider_id`, `source=wordpress_org`, the same `activate_after_install` value and `expected_plan_sha256`. This requires the existing exact high-impact Write permission and approval; a plan is not an authorization.
4. WordPress's own upgrader installs the official package. If activation was selected, both `activate_plugins` and the **existing** `MAD4B_MCP_PLUGIN_LIFECYCLE_ENABLED` + lifecycle allowlist must admit the plugin; this is verified in the read-only preflight.
5. Read back the actual installed main file, version, site activation, provider-reported health, and security effect separately. `installed` or `active` is **not** `operational_verified` or `security_enforcement_certified`.

`security-activity-log`, `security-wpvulnerability`, `security-query-monitor`, `security-plugin-check` share the same flow using the catalog IDs.

## Operational observation

The existing `mad4b/security-site-posture` Read surface exposes `operational_security_providers` with five catalog entries: installed main-file identity, version, site/network activation, and explicitly **unverified** telemetry/enforcement. This is a WordPress-core plugin-state readback, not an undocumented Wordfence/WPVulnerability API or proof of malware detection. Read capabilities can be added later only when the provider officially exposes them and MAD4B can observe them safely.

## Provider evidence — on-demand, non-authorizing

The existing `mad4b-read` server now mounts `mad4b/security-site-posture` and `mad4b/security-provider-evidence`. ChatGPT's narrow direct catalog is **not expanded**; use normal read discovery/dispatch and retain the exact caller's read permissions.

Call `mad4b/security-provider-evidence` with `provider_id` and optional `period` (`24h` or `7d`) or `surface` (`core`, `plugins`, `themes`). The provider must already be installed, active and unambiguous. No automatic scan or plugin installation occurs.

- **Activity Log:** Delegates to its native `activity-log/summarize-activity` and `activity-log/detect-suspicious-events` Abilities. The extension's **"Expose activity log to AI agents"** setting must be enabled, and its own permissions still apply. MAD4B shows only event counts, supported request-channel counts and bounded suspicious-rule/severity/count aggregates; it never copies actor names, IPs, raw object values, emails, metadata or individual log records.
- **WPVulnerability:** Internally dispatches a fixed, current-user-authenticated `GET /wpvulnerability/v1/{core|plugins|themes}` route. Uses the provider's own permission callback. Returns only number of vulnerable components and vulnerability counts, with no descriptions or component slugs. This provider route **may perform outbound requests internally** according to its own cache and settings; MAD4B makes no direct external HTTP call. Results are a bounded sample when more than 250 affected components are returned.
- **Wordfence:** Its free plugin does not expose a verified stable public results Ability in the reviewed documentation. MAD4B can optionally consume **read-shaped** `wpcli-for-wordfence/issues-count`, `scan-status` and `firewall-status` Abilities if a *separately installed third-party companion* registers them and independently authorizes execution. MAD4B does **not** install or certify that companion. Results are explicitly `observed_unattested` rather than malware/WAF attestation; absent or rejected Abilities yield `unavailable`.

Set `provider_id=all` to collect all three sources in one bounded request with separate evidence rows and a coverage breakdown (`observed`, `unattested`, `unavailable`). The combined `state` is `partial` or `observed_partial`; it intentionally has no risk score or clean verdict.

The response `state` is `observed`, `observed_unattested`, or `unavailable`. `complete`, `site_clean_certified` and `enforcement_verified` are always false in this first read-only slice. **Zero findings does not mean a clean site.**

Provider contracts are capability-driven, not version-pinned. Missing or downgraded capabilities disable only the respective provider's evidence lane. Credentials, shell, direct SQL against plugin internals, filesystem changes, and generic REST URLs remain disallowed.

## Important boundaries

- Download only the reviewed WordPress.org slug and an HTTPS package URL under `downloads.wordpress.org/plugin/` derived from the official directory API. No client-supplied URLs, local archives, shell, file manager or arbitrary plugins.
- WordPress.org directory metadata does not provide a preapproved archive SHA-256 in this workflow. It is **not** an exact certified repository artifact. An actual ZIP digest is computed after download and recorded after completion. Do not misrepresent this as supply-chain or malware certification.
- New installation only: never use this path to overwrite, force-update, downgrade or replace installed files.
- Production, Multisite network activation, automatic WAF configuration, virus remediation, suspicious file deletion, and native provider database scraping remain unsupported by this source slice.
- A timeout, unexpected main-file/version readback, activation error or final Audit error leaves an **uncertain** outcome. Do not retry installation blindly; inspect the existing plugin and audit first. No new recovery ledger, scheduler or background agent has been introduced.
- Existing provider read continuity remains capability-first. An installed plugin does not grant permission or imply its internal data is accessible through MCP.
- The plugin package runner's normal authorization must still be backed by an exact approval and runtime grants. The `plugin_package_apply` entrypoint is reused; no new write grant/Ability is introduced.

## Validation and release

- Hermetic test: `php wp-content/plugins/mad4b-site-control-plane/tests/security-operational-providers-runtime.php` on PHP 7.4 and 8.3.
- Provider evidence test: `php wp-content/plugins/mad4b-site-control-plane/tests/security-provider-evidence-runtime.php` on PHP 7.4 and 8.3; re-run the existing `security-diagnostics-runtime.php` fixture.
- Additional checks: `php -l` for modified PHP files, the existing plugin-package CI, and the original source-boundary gates.
- Require independent Staging acceptance **separately** for ETG and All Royal Egypt: plan, approval, install, optional activation, subsequent-request readback, API/Health evidence, and safe recovery on a failed install.
- This work is intentionally isolated from the PR #400 recovery sequence. No Production mutation or actual plugin installation is carried out by this source change.
