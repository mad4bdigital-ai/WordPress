# MAD4B Source Hub — open-source / open-data source acceptance (2026-10-24)

## Verified remotely on ETG WooCommerce Local Lab

Three public sources were retrieved **live from the authorized MAD4B host-side worker** to its site-bound private spool, then imported by the plugin into `pending_review`:

| Source | Project/data openness | Exact tested endpoint | First import | Classification |
| --- | --- | --- | ---: | --- |
| Wikivoyage recent edits | Wikimedia open travel guide; reuse requires observing source attribution and share-alike terms | `https://en.wikivoyage.org/w/api.php?action=feedrecentchanges&feedformat=atom&limit=15` | 10 items | `travel_reference_update` |
| Nager.Date OSS releases | GitHub project MIT-licensed source; feed contains project release metadata | `https://github.com/nager/Nager.Date/releases.atom` | 10 items | `open_source_release` |
| Nager.Date EG public holidays | Open-source holiday project with public country API; content accuracy remains source-dependent | `https://nagerholidays.com/api/v4/Holidays/EG/2027` | 10 items | `national_holiday` |

A second live Wikivoyage fetch advanced the feed from 10 to 11 items during testing; this is expected for a frequently updated public feed. Do not rely on an exact stable item count for live regression. Source Hub now uses a **normalized per-item SHA-256 and source revision** for deduplication, not just the full feed body hash. Running the same receipt again returned 0 changes for all three tested sources.

## Safety and isolation

- No remote Google OAuth, private calendar credentials or changes to `WP_HTTP_BLOCK_EXTERNAL` were needed. The companion worker handled approved public HTTPS sources from the authorized host; the site WordPress plugin validated site-specific receipts.
- All 3 temporary `qa-oss-*` source approvals, 31 temporary review items and their spool receipts were removed after the live acceptance. Two temporary feed host trust entries were also revoked.
- The earlier 3 production-candidate example sources remain draft-only. `approved_sources=0`, `pending_items=0`, and no source fetch or inbox import scheduler was activated.
- Open-source source code licensing does **not** automatically grant unlimited commercial republication of feed articles. Wikivoyage content must follow its Wikimedia license and attribution requirements. GitHub release notes and provider metadata should be linked/reviewed, not copied into marketing content by default.
- Wikivoyage's recent changes are **travel reference edits**, not fact-checked tourism news. Project releases are `open_source_release`, not local news or offers. Nager.Date dates remain unconfirmed until cross-checked for business use, particularly tentative cultural/religious dates.

## Automated regression

`tests/source-hub-open-source-regression.php` — 8/8 PASS, with fixture-only tests for Atom classification, provenance URL, MIT project release classification, untrusted kind rejection, holiday classification, and final clean runtime state.

`tests/source-hub-runtime.php` — 13/13 PASS (existing Source Hub contract, secure XML parsing, JSON provider, draft/approval safety).

The external ingestion demonstration is **not** a license/copyright audit, broad CDN security certification or production-grade signed provenance. WordPress content publishing, Brand Authority and marketing/season approval remain deliberately separate.
