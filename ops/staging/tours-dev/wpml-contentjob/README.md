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
