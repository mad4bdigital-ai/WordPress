# WPML → MAD4B ContentJobs — Central Staging pilot

This folder is a **site-bound Staging source snapshot**, not an installable global release. The native Control Plane authority fix is reviewed separately in [PR #741](https://github.com/mad4bdigital-ai/WordPress/pull/741); it must be reconciled against the current deployed plugin before any promotion.

## Scope and authority

- Target: `https://tours.dev.mad4b.com`, WordPress `staging`, exact site UUID validated in the host runner.
- WPML source: native `icl_translate_job` entries with `translated=0`, plus pending Elementor localization outbox entries from engine 301.
- One canonical `content_type=translation` ContentJob per `site_uuid + post_type + source_post_id + locale`. Local WordPress option `mad4b_wpml_content_job_map_v1` retains the mapping, native WPML task IDs, and gap codes.
- Only `MAD4B_SCP_Content_Jobs::create_job()` and `transition_job()` write ContentJob records; no manual inserts into ContentJob tables.
- `349` provides the default-enabled site-level Master Switch and per-post/translation exclusion; `301` and `305` are the local engines wired into the same deny-only guard.
- Every mutation requires `MAD4B_SCP_Policy::can_mutate()`. Anonymous WP-Cron/CLI cannot create a native ContentJob. The native host runner uses a verified WordPress administrator principal and checks environment, site UUID, login, and live policy each run.
- ContentJobs start at `NEW` and are transitioned to `QUEUED` through the native state machine. **No translation, assignment, completion, or publication is performed by this intake adapter.**
- The read-only governed ability `mad4b/wpml-contentjob-binding` resolves an exact ContentJob ID to source post, translated post, language, WPML task IDs, localization gap statuses, and readback evidence. It cannot mutate or publish.

## Files

- `301-mad4b-elementor-localization.php` — Staging source snapshot of the localization scanner / MCP claim-ack outbox.
- `305-mad4b-elementor-style-parity.php` — Staging source snapshot of interactive style parity.
- `349-mad4b-wpml-design-reconciliation.php` — site master control and source-to-linked-translation design parity.
- `350-wpml-job-adapter.php` — native ContentJob intake, deduplication, safe reconciliation, scoped binding ability.
- `ops/mad4b-wpml-intake-tours-dev.sh` — scoped, single-run host worker. `--user=26` is a pilot-specific **existing administrator**, not a universal operator or credential. The worker checks the actual login and site identity rather than trusting ID alone.
- `ops/mad4b-wpml-content-intake-staging.{service,timer}` — host-native recurring intake; 5-minute cadence.
- `tests/wpml-native-intake-readback.php` — tests no-write preview, Master OFF, post exclusion, native idempotency, and source/locale binding.

## Acceptance

Run **only in the enrolled Staging container**:

```bash
docker exec -u www-data etg-commerce-staging-php wp mad4b-wpml-jobs preview --user=26 --path=/var/www/html --skip-themes
docker exec -u www-data etg-commerce-staging-php wp eval-file /tmp/wpml-native-intake-readback.php --user=26 --path=/var/www/html --skip-themes
systemctl status mad4b-wpml-content-intake-staging.timer
journalctl -u mad4b-wpml-content-intake-staging.service -n 25
```

The observed live readback suite passed 108 assertions on 2 November 2026, and the public homepage returned HTTP 200. This is **not** translation-executor certification or general multi-company rollout acceptance.

## Rollback and incident response

- Stop future intake immediately: `systemctl disable --now mad4b-wpml-content-intake-staging.timer`.
- Master OFF stops this adapter accepting new work; per-post exclusion prevents source/target intake. Existing native ContentJobs are **not** deleted automatically.
- The live Control Plane service's before-image is retained in `/opt/staging/mad4b-wpml-content-jobs-bridge-20261102/class-content-jobs-before.php`. Keep the post-change acceptance evidence.
- The map and native ContentJobs must be reconciled by `job_id` and `wpml-ref:<sha256>` before replaying an uncertain create. Never blind-retry a failed or unknown COMMIT.
- WPML translation assignments, content QA, verified receipt processing, and native completion transitions require a separate, permitted executor. Do not infer successful translation from `QUEUED`.
- No Production installation or GitHub merge is authorized by this pilot alone.


## 2 November 2026 live follow-up

- Site-root execution confirmed MU-Plugin `356-mad4b-jetengine-count-locale.php` v0.1.1 is deployed. Direct HTML readback of `/ar/`, `/fr/`, `/de/`, `/es/`, `/it/` returned HTTP 200 and the expected locale-specific count suffix. The v0.2 singular/plural refinement in this PR is **source-only**, not deployed: deployment attempt was denied by the execution channel.
- Found two obsolete native translation ContentJobs for Elementor template `25909` (`ar`, `fr`). Fresh `301` discovery returned `resolved_after_readback`, translated template links exist, no English fixed heading/fallback in translated Elementor settings, and neither job had an underlying WPML translation job. Cancelled both using `MAD4B_SCP_Content_Jobs::cancel_job()`, verified `CANCELLED` via native get, and persisted map resolution as `obsolete_fresh_translation_readback`. **Not** reported as translations executed.
- Latest inspected map: 92 mapped jobs, 90 `QUEUED`, 2 `CANCELLED`. Source discovery reported 150 eligible items (the native recurring intake continues). No verified translation receipt was present for sampled queued jobs; work is **not completed**.
- Unit tests: 43 singular/plural/idempotence/noninterference assertions passed using container PHP. Counts and grammar include ar 1/2/6/16, singular French/German/Spanish, and Italian.
- The independent WordPress timer is the authorized Staging-only intake/QA readback workflow; the absence of a translation provider with verified receipts prevents promotion to `WAITING_REVIEW` or `COMPLETED`. Do not mark queued editorial work complete without a verified receipt.

## Dynamic frontend-first priority — locally tested 2 November 2026

The priority engine and public HTML crawler were derived from an exact SHA-256 snapshot of current Staging files, staged in the host-isolated local lab at `/opt/staging/mad4b-wpml-priority-local-lab-20261102`. The lab has read-only source files, tests, and rollback hashes. Changes were syntax-checked and exercised offline **before** the Staging-only rollout.

- **Dynamic languages:** discover locale links from the public WPML home page; native importer cross-validates locale keys against `wpml_active_languages` and the configured default language.
- **Observed visibility, not publication alone:** inspect public HTML `data-elementor-id`, `data-listing-id` and `data-post-id` on the locale homepage, discovered tour archive and rotating linked pages.
- **Dynamic coverage:** switch which public URLs are probed on every scan; retain up to 28 previously confirmed pages per locale, bounded to 36 hours, with **per-page** observation timestamps and freshness decay.
- **Refresh and failure isolation:** enrolled Staging host worker refreshes visibility every ~20 minutes; scanner uses an exclusive local lock and bounded concurrent HTTP fetches, rejects off-host redirects, writes JSON atomically, and refuses to replace known-good evidence when new coverage is insufficient. A failed crawl cannot make a previously observed page invisible.
- **Fairness:** new native ContentJobs favor observed homepage and archive dependencies, while unobserved work retains a low-priority fallback. Receipt review reserves half its slots for top-priority work and rotates the other half through the outstanding queue; no job is closed without authorized translation receipts.
- **Local acceptance:** 93 Python fixture assertions (rotation, lock-friendly output model, expiry, no-overwrite outage, foreign-host rejection) and 19 PHP assertions (real captured HTML evidence, dynamic locale extension, priority sorting, negative coverage and outdated evidence). Live public-HTML read-only lab crawl returned 31 verified pages across five languages.
- **Staging readback after rollout:** native priority import reports 31 pages and five languages, WordPress timer active, all five locale homepages HTTP 200. The priority mechanism does not translate or publish content.

**Current scope:** the site/host/service identity remains explicitly bound to the authorized `tours.dev.mad4b.com` Staging pilot. Do not treat this source snapshot as globally deployed or as Production approval. No production site changed.

**Rollback:** stop `mad4b-wpml-content-intake-staging.timer`, restore `357-mad4b-wpml-frontend-priority.php` and `live-frontend-probe.py` from the lab's `live-source/` and exact SHA-256 manifest, then restart the timer. Priority option state is independent from translation content and from native ContentJobs.

## Silent draft masking prevention — Staging verification (2 November 2026)

A real live mismatch existed: \`wpml_get_element_translations\` omitted drafts while \`wpml_object_id\` resolved them, so the 301 scanner repeatedly emitted \`loc_missing_XX\` with \`target_post_id=0\`. Native ContentJobs correctly remained \`WAITING_REVIEW\`, but the outbox called the draft "missing" and still allowed a translation executor to pick it up.

The repair reads the WPML object ID for a **draft only** when all of these match: current source's translation TRID, target language, post type, and draft status. It never treats the draft as published or as a final translation receipt.

- The original issue key and native ContentJob ID are retained; the gap code \`loc_missing_XX\` still correctly indicates **missing published translation**.
- The existing outbox row now has \`status=needs_review\`, \`stage=WPML_DRAFT_REVIEW\`, \`operation=review_unpublished_wpml_draft\`, \`review_state=draft_unpublished_pending_review\`, and a concrete target ID.
- Draft rows remain discoverable in \`MAD4B_WPML_ContentJob_Adapter::discover()\`; its preview counts \`draft_pending_review\`. They are intentionally **not** offered to the regular MCP execution pickup that only returns \`queued\` work.
- \`MAD4B_WPML_ContentJob_Adapter::binding()\` reports target draft status, review blockers, and \`readback_verified=false\` until real publication/receipt checks pass.
- A read-only \`mad4b/wpml-review-dashboard\` WordPress ability and \`wp mad4b-wpml-jobs review-dashboard\` CLI command expose all pending review work, separating unpublished draft and published-target editorial review states.
- After applying on Staging, the real review dashboard returned **17/17** linked drafts in \`needs_review\`, **9** published-target jobs still awaiting editorial review, **0** unmapped drafts, and 121 other outstanding translation jobs. It preserved **154** existing mapped jobs and native states, without generating replacement jobs, publishing drafts, changing Production, or auto-completing tasks.
- Exact representative issue: \`Home Properties Search Box\`, French, draft ID \`68832\`: "WPML translation draft #68832 exists but is unpublished. Elementor references, locale rendering and editorial review remain pending". Stage \`WPML_DRAFT_REVIEW\`; reference \`review_unpublished_wpml_draft\`; public homepage HTTP 200.

This makes the review task explicit in the underlying WordPress ability, Outbox, and ContentJob evidence. An independent Control Plane dashboard frontend must consume the ability to render a dedicated panel; **ability registration alone is not proof that a visible dashboard UI has been wired**. Continue to distinguish technical review handoff from completed translations.

## Control Plane visible WPML review ledger — Staging pilot, November 2, 2026

The live Control Plane at `tours.dev.mad4b.com` (plugin `mad4b-site-control-plane` 0.4.0-rc.118) has an existing Content Readiness admin screen. The added Staging-only MU plugin `358-mad4b-control-plane-wpml-review-ui.php` registers a native submenu under `MAD4B Control Plane → WPML Reviews`, and a contextual warning/link under `MAD4B Control Plane → Content Readiness`. The admin URL is:

`https://tours.dev.mad4b.com/wp-admin/admin.php?page=mad4b-wpml-review-dashboard`

The view reads `MAD4B_WPML_ContentJob_Adapter::review_dashboard(100)` and displays exact WPML target IDs, localized post language, post status, native ContentJob ID and state, outbox statuses, review blockers, and normal WordPress editor links. Accessible GET filters for locale and review type; no new writes, approval shortcuts, publishing or brand-context dependencies. Requires WordPress `manage_options` **and** MAD4B read authorization. Its cached data remains protected by a fresh authorization check.

Live acceptance following installation: PHP syntax PASS; exact installed SHA-256 matched source; 16 UI integration checks PASS; native admin submenu and Control Plane route registry PASS; dynamic staging-only acceptance **58 assertions PASS**, with 17 unpublished drafts, 9 published targets requiring approval, 121 other pending native ContentJobs. The real webpage requires a normal authenticated WordPress session; a logged-out HTTP probe correctly redirected to `wp-login.php`. The HTML markup and admin registration were tested using authorized WordPress CLI, but no authenticated interactive browser screenshot was captured. Frontend homepage HTTP 200, intake timer active, Production unchanged.

The independent regression test lives in `tests/wpml-review-ui-acceptance.php`; run with the site's enrolled WP-CLI user, not an unauthenticated or fabricated principal. The review page intentionally cannot transition `WAITING_REVIEW` to `COMPLETED` or publish a draft.
