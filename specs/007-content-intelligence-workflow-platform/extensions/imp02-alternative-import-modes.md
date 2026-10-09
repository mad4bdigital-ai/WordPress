# IMP02 — Pluggable Import Modes, Dynamic Discovery & Approved Fallback

10 October 2026 · Feature 007 child PR #366 · code delivery only · Production disabled.

## Purpose

A **Mode** is an explicit, versioned transport route from one source through one review/preparation engine to one destination. Modes are site-independent; commercial semantics, mapping, post types, currencies, translations, and source ownership belong to the site's **Content Experience Profile → Activity Contract**, not to a travel-specific importer.

The source can be XLSX, CSV/TSV, Google Sheets, Drive, FTP/SFTP, HTTP, object storage, WordPress Media, email/CRM/automation webhook, or a previously configured WP All Import job. The destination can be review-only Inbox, existing WP All Import configured import, or another future certified profile write driver.

**Mode discovery is not evidence of operational certification.** The returned `state` is `review_only_ready`, `handoff_or_adapter_required`, or `not_configured`; it never promotes untested effects. `detected` is a prerequisite indicator, not proof that an import job or credential is authorized.

## Mode catalog

| Mode | Integration channel | Current effect |
|---|---|---|
| admin_csv_upload | WP Tools, authenticated admin upload | Implemented reviewed intake, exact Staging only, 1 MiB / 500 records |
| admin_xlsx_convert | XLSX/XLS/ODS guarded converter | Parser needed; manually convert to CSV, retain source checksum |
| wp_media_csv | WP Media attachment | Bound attachment parser adapter needed |
| local_managed_file | Host-managed file path | Directory allowlist + host read adapter needed |
| https_csv_pull | Remote HTTPS URL | Bounded safe HTTP pull, SSRF and redirect prevention adapter needed |
| sftp_ftp_pull | FTP/SFTP | Host fingerprint + managed credential adapter needed |
| object_storage | S3/GCS/Azure | Managed object/version connector needed |
| google_sheets_oauth | Managed Google Sheets OAuth | Read adapter / scopes / revision certification needed |
| google_drive_file | Managed Google Drive | Export/read adapter needed |
| google_apps_script | Apps Script + HMAC | Signed reviewed intake implemented; needs real host secret |
| signed_generic_webhook | Make, n8n, Zapier, Pabbly, BitFlows, custom app | **Same** signed reviewed intake implemented; no separate vendor account needed |
| wordpress_authenticated_rest | Authenticated REST push | WP REST admin adapter needed; never enable anonymous content write |
| email_attachment | Mail-driven import | Sender authentication and attachment safety gateway needed |
| wp_all_import_wizard | WP All Import installed admin UI | Native file/template configuration, real import execution delegated to plugin |
| wp_all_import_from_url | WP All Import FTP/SFTP/URL source | Existing plugin-approved exact data source required |
| wp_all_import_manual_rerun | Existing WP All Import job | Admin Run Import in installed plugin |
| wp_all_import_cron | WP All Import trigger + processing | Two secret URLs in protected host scheduler; never expose in ChatGPT |
| wp_all_import_wpcli | `wp all-import run ID` | Installed WP-CLI + WP All Import; host-authorized only |
| wp_all_import_auto_schedule | WP All Import scheduling subscription | Configured licensed service & import ID |
| wordpress_cron_worker | WordPress cron | Dedicated durable job executor not yet certified |
| action_scheduler_worker | Action Scheduler | Installed scheduler and durable receipts/leases needed |
| woocommerce_product_csv | Native WooCommerce importer | Product-specific UI; not generic travel price importer |
| governed_profile_apply | Content Experience Profile plan/apply | One-row approved native action; bulk/relations driver needs acceptance |

**Twenty-three built-ins**; trusted WordPress-installed code can add up to 37 more manifest-only entries through `mad4b_activity_import_mode_manifests`. Extension declarations are validated, deduplicated, bound to the enrolled site, and **cannot declare automated WordPress writes certified** through a manifest alone. Any actual custom driver must separately satisfy permission, source provenance, request authentication, CAS/readback and staging operational acceptance.

## Dynamic profile configuration

Optional governed `activity_contract.import_modes`:

```json
{
  "enabled_modes": [
    "admin_csv_upload",
    "signed_generic_webhook",
    "google_apps_script",
    "wp_all_import_wizard",
    "wp_all_import_cron",
    "google_sheets_oauth",
    "sftp_ftp_pull"
  ],
  "preferred_mode": "admin_csv_upload",
  "fallback_modes": ["signed_generic_webhook", "wp_all_import_wizard"],
  "manual_review_required": true
}
```

This is only a mode allowlist/preference; no tokens, secrets, cron URLs, credentials, arbitrary PHP, or provider endpoint URLs are stored in the governed Profile. `auto_execute=false` is enforced. A missing policy retains legacy discovery behavior, not authorization to write. Disabled selected modes fail closed. Fallbacks are **suggestions for an entirely new explicit plan**, never automatic route switching on a write or conflict.

## Interfaces

- `mad4b/business-activity-import-modes`: returns current 23-mode registry with accurate provider detection and state.
- `mad4b/business-activity-import-mode-plan`: exact selected `mode_id` + `profile_slug`, checks enrolled staging, declared profile allowlist, prerequisites, and gives digest; **no content writes**.
- `mad4b/business-activity-import-plan`: read-only normalized row preview; compare identity, approved Meta mapping, currency, WPML groups, pricing and history.
- `mad4b/business-activity-wp-all-import-plan`: validate selected existing WP All Import ID, matching strategy and controlled option groups, no private import runner internals.
- WP Tools → MAD4B Import Review: alternative modes table, profile-specific mode selector, prerequisite view, Staging CSV upload with WordPress nonce, review and immutable receipt archive.
- Signed REST: `POST /wp-json/mad4b/v1/activity-import/intake` with HMAC-SHA256, 5-minute timestamp, nonce, site UUID, `source_mode=google_apps_script|signed_generic_webhook`. Generic clients from Make, n8n, Zapier, Pabbly or BitFlows sign **raw identical JSON bytes**; never place secret in URL/body/log.
- Scripts: `tools/feature007/google-apps-script/mad4b-activity-import-push.gs`, `tools/feature007/import-modes/signed-generic-webhook-push.mjs`. Both stage **review only**, never execute WP All Import.

## Workflows and guards

### UX
1. Open WordPress Tools → MAD4B Import Review, choose the Content Experience Profile.
2. View current mode state; explicitly select preferred or fallback mode; review prerequisites and profile restrictions.
3. For available reviewed intake, upload CSV or sign an external push; for WP All Import, configure the existing import job through its official UI, CLI or manual scheduler.
4. Resolve flagged currency, status, translations, relations, data freshness and commercial tier issues before approving a separate import operation.
5. Independently verify imported WordPress content and WPML linkages. Archive review snapshot without deleting prices.

### Security
- Staging-only source intake; `environment_allowed(['staging'])` must pass. Production requests are rejected even with correct HMAC.
- No automatic writer, production promotion or cross-mode fallback. State `review_only_ready` is a staging *proposal* transport, not an execution license.
- Admin CSV: `manage_options`, WP admin nonce, real PHP upload, .csv extension, 1 MiB and max 500 rows, 80 columns, no formula prefixes or control characters, exact profile Meta allowlist, no raw row retention.
- Signed push: HMAC raw-body, managed secret, HTTPS, fresh timestamp, replay nonce, size bounds, configured profile, exact scoped site. Review inbox rejects overwrite until audited archive.
- Profile allowlist applies to all preview destination fields. Externally provided `post_id`, image URLs, cron keys, SQL fragments, scripts or executable formulas are **not** approved by being present in a spreadsheet.
- WP All Import cron requires independent **trigger and processing** schedules. WP-CLI `wp all-import run ID` can run a *saved* import job, but does not edit its wizard. Internal plugin PHP action classes are not a general stable public configuration API.
- Google Sheets `values.batchUpdate` is not general compare-and-set with a required revision field; merely reading a value hash or taking Apps Script LockService does not prevent independent editor races. Never claim safe conditional writes without an explicit version/fence driver.

## Acceptance gates

1. Source contract + isolated PHP 7.4/8.3 fixture: exact mode count/IDs, unsupported/duplicate modes rejected, safe profile preference and disabled-mode refusal, signed mode identity, staging rejection, status/digest consistency, review archive.
2. Native WordPress Staging tests: CSV file upload 180-row sample, corrupted/oversized files, formula injection, duplicate identity, exact WPML 36×5 grouping, restore/rollback, persistent object cache.
3. WP All Import installed-version and license discovery; Import ID, mapping, status, selected-field update, scheduling, WPML connector; run only when explicitly approved. Confirm triggering is followed by processing and post-import readback.
4. Google managed OAuth/Drive Sheets freshness, webhook HMAC replay denial and real Make/n8n/Apps Script client; SFTP/FTP host trust and object storage are separately certifiable adapters.
5. Brand Core authority review (tone_of_voice, editorial_guidelines, brand_strategy) and rate owner approval. Do not auto-approve assets or prices.
6. No Production writes, #366→#258 merge or #258→master merge as part of these source changes.

## Provider documentation

- https://www.wpallimport.com/documentation/cron/
- https://www.wpallimport.com/documentation/wp-cli/
- https://www.wpallimport.com/documentation/update-import/
- https://www.wpallimport.com/documentation/import-types/
- https://developers.google.com/workspace/sheets/api/reference/rest/v4/spreadsheets.values/batchUpdate
