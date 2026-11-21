# CP4 — Source-controlled Tours.dev staging scraping profiles (2026-11-21)

## Exact live code and release scope

`993-mad4b-scraping-source-profiles-v1.php` is a complete byte-for-byte copy of the source-only MU plugin installed on **Tours.dev STAGING** after the source-reviewed 21 November 2026 hotel-category/capacity work. Its official source hosts include Sofitel, Triumph Hotels, Tolip, Sonesta, JAZ, Baron, Marina Sharm and Pyramisa.

**The original module was not tracked in the previous GitHub PR #1028 source tree.** Recording it here prevents loss of the verified staging source configuration without erroneously loading a staging-only WP-CLI helper on **Wayfatours Production**, where the file has not been installed.

This is intentionally stored under `ops/`, **not** `wp-content/mu-plugins/`. **Do not globally promote or auto-install it on other companies** as part of a generic source file-copy release. Staging deployment remains the separately authorized path:
`tours.dev.mad4b.com → wp-content/mu-plugins/993-mad4b-scraping-source-profiles-v1.php`, after checking the exact target site's hash, current WordPress site profile, and the existing original module.

## Contract and test

- Original class: `MAD4B_Scraping_Source_Profiles_V1`, no parallel scraper plugin.
- The mutation gate requires authenticated, enrolled **Staging WP-CLI admin**; the module does not confer WordPress capabilities or Owner signing authority.
- Official hotel source fields: `properties`, `units`, `amenities` reviewed with per-field trust; manual review required, no auto-publication.
- `prices` held, `gallery` and `source_operator` excluded. HTML evidence does **not** authorize photography, invoices, public stocking, inventory, child age policy, Cost Receipts, rate generation or checkout.
- Existing Memphis Tours default preserved.
- Run `php -l 993-mad4b-scraping-source-profiles-v1.php` and `php test_staging_official_source_profiles.php`; repeat live original WP-CLI source-profile `resolve()` readback on the intended Staging site.
- Do not import a source profile's editorial room labels into tour-wide Property Rates. The original Stage Gate must still enforce per-stage destination, level, Unit relation, and proof of supplier cost and availability.

## Verified live data receipts (original WordPress / JetEngine)

Authorized read-only and one-time mutation evidence lives in the VPS private evidence directory:
`/opt/mad4b-company-provisioning/reports/cp4-webscrape-20261121/`.
No API keys, cookies, paid invoices or image blobs are stored in this Git entry.

- Source page SHA-256, source URLs, category headings, and provenance: `verified_pyramisa_marina_official_editorial_evidence.json`.
- New **Tolip Hotel Aswan** Draft Property 71495 with Draft Units 71496–71499.
- New **Pyramisa Isis Island Aswan** Draft Property 71501 with Draft Units 71502–71506; explicit island transfer/operator review needed.
- Pre-existing published **Helnan Marina Sharm** Property 36302 received six *Draft* original Units 71507–71512, with the first three tied as Silver **source candidates only** for package stage 60629/resort-sharm-03. Original published Property title unchanged; current Marina brand continuity and supplier contracts still require confirmation.
- Package 60437/hotel-aswan-02 includes both *external Draft* source alternatives without changing an approved `selected_property_id` or creating a commercial tier.
- All newly staged Units have `unit_available=false`, `unit_readiness=pending_review`, no verified occupancy, cost, inventory or image rights. Relation 223, WPML EN identity, SHA and stage refs re-read successfully.
- Both original sites retained 28 open Cost Readiness alerts, 7/7 source Cruise programmes, zero signed source Cost Receipts, zero stage selected Properties, zero bookable Units and zero generated Rate stages. Source `MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision()` unchanged.
- Two source content reviews remain open: Aswan overnight wording in Package 60437, and Sharm versus Giftun/Orange Bay conflict in Package 60629. Do not silently rewrite the tour itinerary.

## Rollback and future promotion

The original Staging MU plugin's immediate prior bytes are preserved under `/opt/mad4b-company-provisioning/reports/cp4-webscrape-20261121/backup-source-profiles-before-pyramisa.php`. Each one-time data mutation also has a preflight and an apply receipt under the same evidence directory. Staging source coverage is not equivalent to verified supplier costs or public saleability. Owner/Company grants remain unchanged.

This copy should remain a **Draft** source promotion until a staging-only selective release plan is explicitly integrated into the company-specific installer and checked for no Production-wide side effects.
