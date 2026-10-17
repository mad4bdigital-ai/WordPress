# MAD4B — Dynamic Provider Opt-in and WordPress-Native Performance

Date: 2026-10-17. Source baseline: 1edf2a13575801b191516cd026b00c90ddce14c0.
Scope: code and tests only, with no live site mutation or Production permission.

## Auto-triggered provider setup (read-only)

On every registered MAD4B admin page, a single collapsed notice appears when a registered provider needs owner setup. Discovery draws on the existing Provider Connection Center (Google and registered adapters), already configured but unverified Search connections, and Browser Acceptance. Additional modules can provide bounded descriptors through the existing WordPress filter interface. Safe handoffs use only registered same-origin WordPress routes, never arbitrary external links.

The Browser Acceptance preference page now shows the selected runner's required *environment-variable names* dynamically and requires explicit owner opt-in to select a non-auto runner. No API key or signing private key is entered, returned, or persisted in that page. The runner itself and signed Browser Acceptance evidence still require independent validation.

The new read-only ability mad4b/provider-opt-in-status is not an OAuth consent or a grant. It cannot make untrusted providers runnable or change Write permissions.

## Verified native WordPress performance diagnosis

Live Staging readbacks on ETG and All Royal Egypt before this change:
- WordPress admin-query indexes: READY on both sites; no duplicate index DDL needed.
- Query Monitor DB attribution drop-in: READY on both sites.
- Stored frontend profile: available=false on both sites; current metrics violate performance budgets without attributable SQL evidence.

Changes:
1. For a real signed, exact and unexpired **Staging frontend acceptance probe** only, WordPress native wpdb SAVEQUERIES is enabled at plugins_loaded. Core query logging stops at a bounded 400 rows. Existing Query Monitor / MAD4B evidence normalization stores only hashes, safe caller names, counts and timings. Raw SQL is never included in the public status. Production, admin, REST, cron and regular frontend requests bypass instrumentation.
2. Ordinary, unsigned Staging frontend traffic uses request-local deterministic 1-in-8 sampling of MAD4B telemetry rather than updating the site telemetry option on every uncached page. Signed acceptance probes are always captured. This reduces MAD4B's own extra option writes but does not resolve the underlying plugin-generated 459–1750 SQL requests.

## Acceptance: do not force green

- CI must pass at the exact PR head for PHP 7.4/8.3, provider opt-in, Browser preference CAS, native probe, and G8 delivery fingerprint.
- After separately authorized Staging rollout, collect signed probe data and distinguish partial first-400-row attribution from complete SQL traces. Identify exact expensive callers and optimize only verified native WordPress query paths, with native object caching / metadata batching / query argument tuning where appropriate.
- Compare three or more **uninstrumented** fresh frontend samples at each site against unchanged budgets (server elapsed <= 2s, DB queries <= 100, peak memory <= 128 MiB), plus browser filter, AJAX, and WPML parity.
- Do not disable plugins, purge caches, loosen authority, alter DB indexes, globally enable SAVEQUERIES or touch Production as a speculative fix.

## Task ledger

| ID | Status | Task |
|---|---|---|
| OPT-001 | CODED; local tests PASS | One automatic setup handoff across MAD4B pages |
| OPT-002 | CODED; local tests PASS | Browser provider dynamic field names and explicit external opt-in |
| OPT-003 | CODED; local tests PASS | Provider Connection registry and bounded cross-module extension |
| PERF-001 | CODED; local tests PASS | Signed and bounded WordPress-native query attribution |
| PERF-002 | CODED; local tests PASS | Sampled Staging frontend telemetry to reduce MAD4B overhead |
| QA-001 | PENDING GitHub | Exact-head CI, architecture and delivery manifests |
| QA-002 | PENDING separate deployment approval | Upload to ETG and ARE Staging, verify readback |
| PERF-003 | BLOCKED on fresh caller evidence | Fix verified SQL hot spots and re-measure both sites |
| QA-003 | BLOCKED on trusted runner | Browser Acceptance signed E2E and replay protection |

Release decision: Draft PR / source-only. No claim of full staging performance acceptance or external Browser certification yet.

## WordPress native WP-Cron mode (source only)
An explicit owner opt-in in the existing Browser Acceptance page selects WP-Cron mode and queues one read-only, exact same-host HTTPS smoke in Staging. No external credentials, JavaScript engine, arbitrary URLs, HTTP redirects or response bodies. Signed browser evidence and JS/AJAX parity remain unverified. The scheduler may require normal WP-Cron traffic. No automatic Production changes.

## Provider behavioral recertification planning
The outer approval provider for mad4b/provider-behavioral-recertify is core, regardless of whether the nested tested provider is elementor or jetsmartfilters. The planner now defaults the canonical core mount if omitted and refuses an explicitly wrong outer provider without silently changing assertions. Nested provider semantics are classified using the exact target ability: Elementor text remains brand-bearing; JetSmartFilters existing _query_var is operational; unknown fields/providers remain review-required.
This is source-only. Live staging approvals, disposable test targets and rollback have NOT been executed and must not be marked accepted.

Tasks: CRON-001/002, RECERT-001/002 source implemented CI pending; RECERT-003 live acceptance pending independently approved Staging.

## Branch-wide security review — 2026-10-17 follow-up

The source head 1edaff7b37ba96d1673d144ee2150654b80c14f0 failed the real MariaDB 11.8 parallel isolated journal test: two distinct signed operation tombstones were observed for one site. This is a P0 release blocker, not a cosmetic flaky test. The candidate fix adds a direct DB readback of the site-scoped lock after add_option reports success and before a signed operation tombstone is created, denying any false-positive WordPress options claim. The CI matrix now explicitly accepts this new fail-closed loser code but retains count == 1 and exactly one successful worker. An isolated regression injects nominal add_option success while the lock belongs to someone else. This is only a candidate correction until repeated MariaDB CI proves it.

Generalized provider behavioral recertification now requires: mounted mad4b-write target ability + matching mounted provider + known exact semantic contract + consistent nested provider_id, not just a JSON field contract. Added matrix cases for Elementor, JetSmartFilters, JetEngine, WooCommerce and SEO, with unknown/unmounted abilities failing closed. This is classification protection only; it does not create a behavioral test ticket, grant authority or call the providers.

No merge, production change, site deployment, new Provider credentials, or live plugin mutation is authorized by this source correction.
