# MAD4B Company Runtime — Live Convergence Checkpoint (2026-11-06)

**Scope:** two *existing* companies/sites; **no new-company provisioning, database snapshot, secrets, media, customer records or plugin bulk-upgrade**. This bundle preserves and reconciles changes verified on `tours.dev.mad4b.com` (Staging) and `wayfatours.com` (Production) and sits on the RC121 release source branch. The canonical Control Plane version remains **`0.4.0-rc.121`** even though the live site source still declares rc118. Do not downgrade package version to copy a production file byte-for-byte.

## Canonical source and target mapping

| Component | Source in this repository | Deployment target / scope |
| --- | --- | --- |
| Company Operations universal policy bootstrap | `wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php` and `includes/class-mad4b-scp-company-operations-policy.php` | Original MAD4B Control Plane for Staging and Production. When Company Portfolio Manager is active, load its own native policy once; otherwise use Control Plane's fallback. Do not start staff/migration modules. |
| Production communication fallback | Same canonical Control Plane bootstrap | Loads native company sender/communication/email adapters only on Production without active Portfolio Manager. Sender readiness and WooCommerce native fallback stay governed; no sending during deployment. |
| Site-specific current Production snapshot | `overlays/wayfatours-control-plane-main.php` | **Evidence/rollback snapshot only. Do not overwrite newer RC121 canonical plugin with its rc118 header.** The production-specific working behavior has been reconciled into canonical source. |
| Tour Rate view resolver and per-Tour override | `wp-content/mu-plugins/141-mad4b-tour-rate-view-inheritance.php` | Both existing sites. Company Look & Feel / Tour Mode / per-Tour override; exactly one Elementor native Listing. |
| Company Settings Alignment UI | `wp-content/mu-plugins/146-mad4b-settings-alignment.php` | Both sites, read-only owner/consumer map, JetEngine/WooCommerce/company links. |
| Native WPML language lifecycle | `wp-content/mu-plugins/003-mad4b-lifecycle-access.php` | Both sites; do not call page-dependent language API at `wp_loaded`. WPML setup flag is DB-native and **not** embedded in Git. |
| Tour rate cache signal | `wp-content/mu-plugins/147-mad4b-tour-rate-cache-lifecycle.php` | Both sites, signal only for exact Wayfatours host; no shell/Docker grants in PHP. |
| Shared Product rate consumers | `packages/mad4b-shared-product-core/mu-plugins/shared-product-core/adapters/presentation-context-v1.php` and `.../local-tour-rate-filter-provider-router.php` | Shared-core packaging source; deploy through the existing Shared Product Core lifecycle, not by copying files to unrelated companies. |
| Tours.dev hybrid selection current snapshot | `overlays/tours-dev-hybrid-native-selection.inc.php` | Evidence/porting candidate for the existing site-specific cart-variations lab; not an automatic replacement for universal quoting. |
| Skills v17 & managed reconciliation | `wp-content/plugins/mad4b-site-control-plane/config/skill-seed-manifest.json`, `includes/class-mad4b-scp-skill-seeder.php`, `skill-seeds/` | `wordpress-settings-lifecycle` plus improved diagnostics/JetEngine/Elementor/change safety/browser acceptance/release Skills. Respect site profile and user-owned skill files. |
| Host Nginx FastCGI invalidation | `host-cache/purge-wayfa-tour-rate-public-cache.py`, `.path`, `.service` | Wayfatours **host**, under `/opt/mad4b-company-provisioning/bin/` and `/etc/systemd/system/`; the exact host uses container `nginx-guxhp9y6vgh7oouuw8plxrlr`. Adjust container identity after any Docker redeployment. Never purge non-public caches. |

## Proven live checks at snapshot

- Company Operations route on both sites: anonymous `401`, native administrator `200`; ordinary home pages `HTTP 200`.
- WPML translated Wayfatours Tour pair `60629` / `60655`: native languages EN/ES restored; Tours.dev native six-language switcher working.
- Tour 60629: saved Accordion renders one widget; simulated `tour_mode` policy-first Grid renders one Card Slider with two Swiper slides; no tour meta writes from test.
- Tour cache watcher: one simulated settings-change signal caused two anonymous Tour-detail objects to be removed, while homepage and archive keys remained; no setting was persisted.
- Skills v17: Tours.dev runtime registered; Wayfatours Production Site Profile still blocks Skill runtime activation, **not solved by Git**.

## Deployment rules

1. Reconcile actual target plugin/module versions and dependencies **before** installing this branch. The branch includes RC121 as an ancestor; it is not a production-ready blanket site restore.
2. Always use native provider APIs, keep WPML translations and WooCommerce financial settings intact, and preserve originals for rollback. Never copy live `wp-config.php`, credentials, DB dumps, content/media or the site `wp-content/cache/` directory into Git.
3. Install shared files through existing MAD4B Company Management/Shared Product Core deploy workflow. Verify the local Site Profile, enabled capability, owner permissions and native receipt, then test under the real HTTP request, not just WP-CLI.
4. If restoring the production company main from an older snapshot, reconcile its **behavior** into the RC121 source first; do not copy the snapshot over the canonical plugin and downgrade its version.
5. For the host watcher, deploy `.service` and `.path` with the exact Wayfatours host target, run `systemctl daemon-reload`, enable `.path`, then test a signal with an unchanged saved option. Verify cache key scope and subsequent warm/cold URLs. The watcher is not intended for Tours.dev.
6. Use `python3 ops/mad4b-company-live-convergence-20261106/tests/test_source_convergence.py` and native PHP lint on changed `.php` files before any promotion. Inspect the release manifest checksums. Updating a source file requires an intentional manifest update.
7. Source/GitHub publication is not a company runtime release. Record release Commit SHA, company application receipt, effective site profile/version and independent frontend acceptance separately.

## Open operational items

Wayfatours Skill Site Profile (origin and ChatGPT App ID) still requires legitimate company profile enrollment; the bundled Skill must not silently flip Production editor or grant privileges. Outbound email transport and commercial checkout readiness have separate incomplete company data and are not fixed by copying settings. Source capture does not automatically alter existing WordPress DB options or publish a new company.

## CI source-contract follow-up

The consolidated rate-view adapters intentionally change two files in the shared-product-core frozen source archive. Update `packages/mad4b-shared-product-core/source-files.sha256` with their exact new hashes and keep `tests/verify-snapshot.py` strict. Restore the certified `skill_source_discovery` egress purpose from the original master configuration; it requires HTTPS, TLS verification, zero redirects and unsafe-URL rejection. These changes restore tests rather than relaxing them.

## Existing `mad4b-experience` live-only footer coexistence snapshot

The exact Staging-bootstrap snapshot, reversible patch, SHA-256 checks and two-part independent test were included from source commit `89ce66f7225ca397958642c87f2ba3daa1847b89` (cherry-picked into this branch as its own commit). Read `ops/company-readiness-workflow/host-provisioning-runtime-v1/patches/README-experience-footer-coexistence-20261106.md`. The code skips Footer class declaration while `mad4b-footer-bridge` is active or the class already exists; it does not enable `mad4b-experience`, install plugins, run job 305/313, or modify Wayfatours Production. The full live-only `mad4b-experience` plugin is **not** added to Git; deployment requires a separate, verified package and original authorization.

## RC121 CI regression convergence (2026-11-06 follow-up)

The combined PR's first full CI execution yielded 51 passing / 33 failing workflows. Distinct source-level regressions included task-aware Skill abilities not being registered or attributed to the Skills adapter; the source-selection unit test still mocking the deprecated GitHub `/git/commits/` path; a missing isolated test import of the existing catalog budget; PHP 8-only property-promotion syntax in PHP 7.4-targeted test fixtures; and older exact-schema checks expecting v13 despite the RC121 schema migration to **v14**. The follow-up restores only the native read-only task and source abilities, with explicit bounded input schemas and the existing governed read permission; no write grant, public surface, or Production configuration. Fixture expectations continue checking strict GitHub source and schema migration identities, now against actual native APIs and v14 receipts.

The dedicated CI contract job runs these PHP tests on 7.4 and 8.3. Other RC121 checks (notably actual signed owner attestation, transport, tenant neutrality, and governed write authority) remain separate blockers; **do not change their expectations to simulate authorization**. Do not merge the aggregate PR before reviewing failed checks and release provenance.

## Portable Skill reconciliation and zero-touch checks

The canonical Control Plane Seed v17 has 13 Skill entries. Reconcile their **exact instructions** into the distribution snapshot under `plugins/mad4b-wordpress/skills/` (including the new `content-jobs-ui-reconcile` and `wordpress-settings-lifecycle`), rather than weakening the existing portable/canonical equality test. This is a package-source edit, **not** a Production Site Profile enrollment, a capability grant, or a write to any user-owned runtime skill.

Native `plugins.php` zero-touch loading must not invoke the unavailable WordPress filter API in hermetic/early context; the real WordPress `allowed_options` hook still registers normally. Excel fixture tests must require the shared workbook normalizer, as the actual plugin bootstrap does. Verified locally: Dynamic Skills source contract PASS; PHP7.4/8.3 native admin fixture PASS; media workbook/CSV twelve assertions PASS with ZIP-enabled PHP.

## Follow-up: exact-head CI and isolated source-contract dependencies

- Updated `mad4b-company-settings-source-convergence.yml` so every checkout pins the exact PR HEAD and concurrent runs cancel superseded attempts. Repository-wide workflow hygiene now reports zero issues.
- The isolated `context-media-normalization-runtime.php` and `gateway-regression-runtime.php` fixtures now load their actual core PHP providers (`Context_Local_Document_Normalizer` and `MCP_Catalog_Budget`) before executing their read-only contracts. XLSX/CSV and PDF/TXT/MD tests pass using ZIP-enabled PHP; Gateway contract passes on PHP 7.4 and 8.3.
- `dependency-certified-runtime-contract.py` now asserts the native certified-integrity call with exact installed plugin identity **and** retains the dynamic official-release inspection path for uncatalogued adapter versions. The complete Staging post-deployment certification static contract passes.
- The approval-plan contract now requires authorization through its *actual* `mad4b-write` server, plus exact OAuth identity, Agent/grant, capability and remote plan validation. The older `mad4b-admin`-lane expectation contradicted the registered execution lane. The full `staging-write-authority-contract.py` (including nested transport tests) passes; no grants, tickets or Production changes are made by these source tests.
- **Remaining security gate:** `full-staging-authority-contract.py` still flags missing explicit `mad4b_full_authority_raw_sql_breakglass_denied` marker in the core source. Do not remove/relax this denial; assess its actual policy path independently. Real owner-signed release attestation and the broader RC121 platform tests also remain separate.

## Full Staging Authority Raw SQL safety gate (verified, source only)

The composite Full Staging Authority tool was hidden from ChatGPT direct projection when generic Raw SQL Breakglass was active, but its read-only `plan()` had not included that activation among the hard blockers. The native Plan now emits `mad4b_full_authority_raw_sql_breakglass_denied` **before** `ready_to_apply` is computed. The existing `apply()` already refuses any plan with hard blockers before audit, Site Profile writes, candidate binding or Developer grants; this protects authorized Host CLI as well as OAuth. Regression asserts the denial occurs in Plan before readiness, not simply in a UI/ability description. This is a restrictive safety repair, not an activation of any Breakglass capability. Tests: `full-staging-authority-contract.py` PASS, PHP 7.4/8.3 lint PASS.
