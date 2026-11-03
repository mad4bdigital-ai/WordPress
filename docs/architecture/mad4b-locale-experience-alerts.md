# Browser-evidenced multilingual Content Readiness alerts

## Scope and constraints
This is an additive site-neutral provider inside the original MAD4B SCP Content Readiness Ledger, not a second alert database. It produces a bounded, deduplicated *investigation candidate* and a source-bound `PRIORITY_INTAKE` request. It does **not** create native ContentJobs, publish translations, change Elementor/JetEngine data, repair images, reassign WPML relationships, or authorize external scrapers.

A separate Chromium collector reads the site public home and configured archive page for every **active WPML language** and desktop/mobile. An exact site-bound original WordPress operator imports a **complete** 24-screen evidence set for ETG staging. Partial screenshots or failed pages must not clear existing issues. Freshness expires after six hours. The scheduler is every two hours, with overlap control and no credentials in collector input.

## Initial Staging acceptance, 3 November 2026
ETG Staging `https://tours.dev.mad4b.com` with six locales (`en,ar,de,es,fr,it`), 2 viewports, 2 page surfaces. First complete scan had 23 issues: 15 high, 8 medium. Codes (distinct locale issues): `lexp_asset_http_error` 5, `lexp_cross_locale_link` 6, `lexp_filter_visibility_drift` 4, `lexp_untranslated_ui_candidate` 3, `lexp_visible_term_parity` 5.

Examples: 404 preloads for hero `home-mix.jpg.webp` on five non-default language prefixes; carousel cross-language navigations; a visible English `Half-Day` label on Arabic UI; apparent first-slide-count parity of 7 en vs 5 ar / 3 de / 3 fr / 6 es / 6 it. The carousel discrepancy is **not** proof of translation loss: check `is_public`, `is_featured`, WPML TRID, query inclusion and source/translated term relations before any content mutation. Archive filter differences are also **investigation candidates** pending actual native JetSmartFilters query/provider conditions.

Each `content_job_request` is unadmitted intake only; original ContentJobs own any actual task creation and separate provider write authorization. Priority ordering: missing published asset 94, cross-language navigation 91, filter parity 88, suspicious label 82, candidate listing parity 78. This precedence can be refined using traffic and revenue evidence; do not infer these values as objective business impact.

The original Staging ledger API `GET /wp-json/mad4b-content-readiness/v1/issues?status=open&code=lexp_asset_http_error_fr` returned HTTP 200 and one row, with priority 94, 404 request details for desktop/mobile, original source SHA, and `PRIORITY_INTAKE`. Live Staging negative/positive validation passed **17/17**. The hermetic provider test `tests/locale-experience-provider-contract.php` passed **23/23**, including malicious cross-tenant and URL evidence, incomplete sample, stale snapshot, missing operator and SHA tampering.

The exact Staging running code also has other runtime patches (including Geo Quality / pricing / variable pickup) not yet present in PR #744's canonical Content Readiness file. This feature branch **intentionally patches the GitHub parent source additively**; it is not a claim that the whole GitHub file is byte-identical to the richer Staging runtime. Before deploying a full release, reconcile those independent runtime source differences so no site feature regresses. Do not deploy this old file wholesale over Staging just because the provider tests passed.

## Portable deployment contract
Code: `tools/dfsb-neutral/locale-experience/collector.py` (requires Playwright) and `run.sh`. Per-company configuration must supply the real WordPress container, authenticated operator, environment, Site Profile UUID, current WPML languages, default language, site URL, actual archive route, and optional visible-label review hints. Do not assume `/fr/` language URL routing on unrelated companies: this collector currently expects WPML directory prefixes. Its source can be adapted with a signed site URL mapping before other WPML routing modes are enabled. No Production site rollout is implied here.

Exact original Staging timer **`mad4b-locale-experience-staging.timer`** on the VPS ran successfully at 2026-11-03 02:00 UTC, read 24 screens and recorded 23 alerts with no publications. Running timer is independently configured and site-scoped; it is not a Production enrollment. The portable source script in GitHub is the template for future governed multi-company rollout, not evidence that other sites have been enrolled.

## Regression and CI
`.github/workflows/mad4b-locale-experience-source-ci.yml` performs PHP lint, the hermetic provider contract, Python collector compilation, shell syntax and source linkage assertions. Source CI cannot prove a browser's current visual state, native ContentJob completion or business publication approval. The Staging WP-CLI evidence is separately checked with `tools/dfsb-neutral/locale-experience/staging-readback-acceptance.php`.


## Live-to-source reconciliation: 2026-11-03, PR #749

The Staging Readiness source contains additional originally executed features absent from the earlier GitHub source: configurable Geo Quality; station/tour/cruise/transport-rate research and nonblocking price opportunities; source-bounded variable hotel pickup; Pricing Workbench source admission and non-authorizing ContentJob requests; source-revision change detection; original board linking to native job IDs; and source-impact sorting within an unchanged severity tier. The canonical original source now incorporates all corresponding live behavior, not a replacement that strips either Geo/Pricing or the locale provider.

The original GitHub origin research previously generalized its fields across post types; the Staging runtime contained an older hardcoded `tours-and-activities` intake. The reconciled source retains the **original generic configurable field/taxonomy source**, with a narrow conditional snapshot-compatibility path only if the original Tour-specific configured source matches the existing native owner schema; this does not restrict other post types, companies or environments.

New canonical source: `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-geo-quality.php` (actual Staging implementation), enabled per site by `geo_quality_enabled`, never globally. Geo can produce 9 specific research-only codes and never fills coordinates, assumes a cruise berth, changes taxonomy, publishes content, or invents supplier pricing. Native Write/ContentJob admission remain separately authorized.

**Live read-only parity proof:** Both the unchanged running Staging and the reconciled candidate source returned complete source discovery with **1,096/1,096 identical issue IDs and SHA-256 payloads**, identical original rules, zero extra/missing/different issues. The old Ledger remained unchanged. This was a direct WordPress WP-CLI read of the actual Staging data in an isolated PHP process, not a simulated HTML scrape. The original Staging source remains in place; the candidate was run read-only.

Hermetic negative and positive coverage includes `tests/geo-quality-provider-contract.php` (**17/17**), `tests/readiness-source-parity-contract.py` (**26/26**, including fail-closed source mutations), and the previously existing locale provider (**23/23**) and original Staging acceptance (**17/17**). These protect the next deployment from dropping production features, but they do not authorize site writes or Production release.

Deployment caution: this parity certifies **the Readiness module's current discovery output** and scoped dependencies, not every unrelated plugin/MU file or every future runtime version. Reconfirm the Staging source SHA and re-run the checks whenever the live site changes; do not overwrite newer site code with a stale review tree.
