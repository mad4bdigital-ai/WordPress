# MAD4B Multi-Company REST and Nile Source Reference Contracts

The Staging administrative hub is **tours.dev.mad4b.com**. Existing company WordPress instances own their own databases, WPML translations, JetEngine data, media URLs, account configurations and customer/order information.

## Verified company identities

| Company ID | Exact canonical HTTPS origin | Marketing section variant |
| --- | --- | --- |
| `all-royal-egypt` | `https://www.allroyalegypt.com` | Multi Country Tours |
| `egypt-tour-gates` | `https://egypttourgates.com` | Daytour Your Way |
| `tourism-almallahgroup` | `https://tourism.almallahgroup-mg.com` | Discover the Nile, Your Way |

The former typo `tourism.almallhgroup-mg.com` is not a registered origin.

## Mutual-authentication architecture

The *active* identity protocol is **ECDSA P-256 via PHP OpenSSL**, not the earlier HMAC/shared-secret proposal. Each company site and the central hub generated its EC private key **locally**, stored it in a non-autoloaded local WordPress option, and exchanged only its **public key** over previously pinned SSH host keys. No private keys or shared symmetric secrets were transmitted.

- Central registry: `central/160-mad4b-central-companies-rest.php`.
- Central mutual verifier: `central/170-mad4b-central-company-public-key-pairing.php`.
- Remote company identity: `remote/170-mad4b-company-public-key-identity.php`.
- New Tourism source: `remote/180-mad4b-tourism-nile-source-read.php`.
- New central source connector: `central/180-mad4b-central-nile-reference.php`.

The active remote identity route is **POST** `/wp-json/mad4b-company/v1/identity-v3`. The original GET-v2 endpoint was vulnerable to an unintended LiteSpeed cache hit even with a no-store response, therefore it was removed from WordPress and its URL cache was specifically purged on all three company hosts. The old URL now returns 404 (cache miss). POST-v3 uses DB-enforced unique nonce admission; ECDSA signatures bind HTTP method, endpoint, timestamp, random nonce, source company and canonical host.

## Central administrative API

Authenticated WordPress administrators with `manage_options` can use:

- GET `/wp-json/mad4b-central/v1/companies`.
- GET `/wp-json/mad4b-central/v1/companies/{company}/status`.
- POST `/wp-json/mad4b-central/v1/companies/{company}/verify-public-key`.
- POST `/wp-json/mad4b-central/v1/companies/tourism-almallahgroup/nile-preview` with JSON or REST params `{"scope":"nile_cruise_catalog.read","page":1}`.
- POST `/wp-json/mad4b-central/v1/companies/tourism-almallahgroup/nile-reference-sync` with `{"scope":"nile_cruise_catalog.read"}`.

The two Nile operations are **admin-only**, require exact explicit scope and are **bound to the Tourism company**; they are not a general remote REST proxy.

## The three limited source read contracts

The Tourism-hosted signed endpoint is **POST** `/wp-json/mad4b-company/v1/nile-source-read-v1`, with strict JSON keys `scope`, `page`, `per_page`. `per_page` is limited to 20, `page` to 100, and three scope strings only:

1. `nile_cruise_categories.read`: source-owned `tour-types_jet` Nile Cruises hierarchy (EN and AR), taxonomy IDs, slugs, parent IDs, labels. **18 records** in the source.
2. `nile_cruise_catalog.read`: published source tours related to the taxonomy, localized title, public summary, WPML translation reference, source taxonomy term IDs, sanitized itinerary days and cruise-run program references. No images copied, no WordPress posts/products created. **35 records** (19 EN, 16 AR).
3. `nile_rate_history.read`: archived/reference-only source tour-rate records linked to those Nile tours, including source rate and tour IDs, program/occupancy references, currencies, source date fields and historical occupancy price values. **364 records**. Each is **reference_only=true, for_sale=false, checkout_enabled=false, validated_current_price=false**.

The source signs each paginated payload hash with its local EC private key. The central connector independently verifies the public-key signature, exact request nonce/timestamp/host, payload hash, scope, company ownership and sales-disabled flags. The bound request JSON is also included in the request signature, so parameters cannot be changed without a valid private signature. Both authentication and the content route reject exact nonce reuse using **atomic SQL unique-key admission**; transients or caches are not relied upon.

## Staging reference snapshot storage

`nile-reference-sync` fetches *all* bounded pages for one explicit scope, verifies identity and digest on every page, checks exact total counts and unique source IDs, then writes a **complete snapshot only** to the non-autoloaded Staging option:

`mad4b_nile_source_reference_v1`

Snapshot keys are the three exact scopes, with a source company owner, source-page digest receipts, retrieval date, source IDs, and **explicit non-publication / no-checkout controls**. Existing company sites are never changed by the sync. It does **not** register products, WooCommerce rate records, bookings or inventory, and does not copy an entire company DB.

Verified on 2026-10-29:
- Three scopes retrieved with **HTTP 200** and validated source signatures.
- Complete snapshots: **18 categories (1 page), 35 tours (2 pages), 364 reference rates (19 pages)**.
- All 417 reference IDs unique within their own source scope and source ownership exact.
- Catalog includes programs (33 source cruise-run variants and two itinerary-day tours); WPML source languages 19 EN and 16 AR.
- WordPress option stored with `autoload=off` (~405 KB); **zero product records created and zero prices activated**.
- Tourism unsigned POST returns 403, source GET not available (404), source nonce replay returns **200 then 403**.
- Source and central admin endpoints enforce permissions. An anonymous central REST attempt may return 423 due to the separate MAD4B Staging runtime-admission guard, which is **fail closed**, not an authorization grant.
- All four public homepages continued responding with HTTP 200.

Evidence lives in `evidence/STAGING-NILE-SELECTIVE-READ-20261029.json`.

## Governance and acceptance limits

This is **reference ingestion**, not a live catalog cutover. The company remains the content authority. Historic prices are evidence to update and validate, not current selling prices: dates, occupancy, nationality, currencies, refundability and sale periods need a separately reviewed and approved price transformation before any sale feature uses these values. Existing company account/email/GTM/Trustindex/QUIC.cloud and media remain local, and no full WordPress DB import is required.

The earlier `remote/160-...identity.php` HMAC variant and `/verify` endpoint are **deprecated and unpaired**, not supported for operational use. Exact reviewed code in PR #631 is a Draft until CI and owner authorization; installing scoped read adapters on the sites does not imply Production source promotion of the full plugin.

Rollback is removal of newly introduced 180 MU adapters and the isolated reference option from **Staging only after an approved snapshot retention decision**. Keep existing central and remote identity pairing intact; no need to touch company content or credentials.


## Mandatory deduplication and source reuse before publication

All new company source ingestion plans must use the rules in **[SOURCE-REUSE-AND-DEDUPE-POLICY.md](SOURCE-REUSE-AND-DEDUPE-POLICY.md)** before any materialization. The Tourism Nile publisher now enforces this with `central/195-mad4b-hub-source-reuse-gate.php` from the same Staging PR.

Verified: 18 incoming category records reused 18 existing `tour-types` terms representing 9 existing bilingual WPML groups; no native term was created. Source WPML language and translation group IDs are now signed in the remote category payload. Four existing destination tour slug matches without explicit source-post provenance remain `native_slug_collision_requires_owner_review`; no slug-based merge or native tour update. The previous 417 Content Hub publication entries were reconciled in place with canonical term links and review states; zero new entries, 417 unchanged on repeat dry-run. All 364 historical rates remain published **in the admin-only Hub** but non-bookable, never sale-authoritative.

Seven negative cases rejected on Staging: foreign tenant, wrong taxonomy, source/target language mismatch, broken parent hierarchy, duplicate term ID, unsafe URL slug and missing tour. See `evidence/STAGING-SOURCE-REUSE-POLICY-20261029.json`. Enforcement is currently wired to the **Tourism Nile ingestion path**; other company ingestion paths must integrate the same gate independently before release.

## Public Nile categories and catalog presentation on Staging

The user-visible `tours.dev.mad4b.com` homepage previously used Elementor templates `25917` (Tours), `54880` (Half Day) and other JetEngine listing sources. No homepage widget consumed the Tourism Content Hub records, so `publish` in the private `mad4b_hub_nile` CPT was **not** public visibility.

The Staging-only `central/220-mad4b-tourism-nile-catalog.php` registers shortcode `[mad4b_tourism_nile_catalog]` for **verified public rendering without enabling public REST / CPT access**. It reads exact source-company-owned, published Hub entries with SHA256 payload checks; category metadata is linked to existing `tour-types` and WPML mappings. No new native taxonomy terms or cross-tenant media are created.

- 9 EN categories, 9 AR translations; **19 EN tours + 16 AR tours** from Tourism.
- Home: Elementor page `35138` receives **one shortcode widget** `b7a92de` immediately after Tours template `25917`; 9 clickable category cards + 6 preview tour cards + full catalog link, with existing Elementor templates unmodified.
- Catalog EN: `/nile-cruises-catalog/` (page `67883`), all 19 EN entries.
- Catalog AR: `/ar/nile-cruises-catalog-ar/` (page `67884`), all 16 AR entries.
- Both pages are paired in WPML `trid=1219637`. Filtering uses the allowlisted `nile_category` URL parameter, and `nile_tour` opens a source-authoritative text/program detail without historical rates or checkout.
- Visual style inherits Elementor/Astra color and typography tokens; category cards and cruise cards use desktop/tablet/mobile responsive grids. Content/media are owned by the Tourism company; source-media images have **not** been copied or shared.
- 364 historic rates are not part of the public listing or live booking flow; all 417 Hub reference posts remain in their original status.
- Home Elementor JSON pre-change private backup: `/opt/mad4b-company-release-prep/20261029/central-rest/HOME-35138-ELEMENTOR-PRE-NILE-20261029.json` (SHA256 `d0b04f58cf0bb6f46c6d8a33cc43b11943d5cd2e4e623f8c76b2095ce545125e`). New verified metadata SHA256 `d2b092338f60dcd4ec2241095808169b5f3f2d52c3287e056d18a621087a3d07`. Backup is not in the public web root.
- Targeted LiteSpeed homepage purge and Elementor CSS cache clear executed. Public homepage HTTP 200 and rendered **9 category links, 6 tour cards**, company ownership attribute present.
- Acceptance file: `evidence/STAGING-PUBLIC-NILE-CATEGORIES-20261029.json`.

For rollback, restore exact verified Elementor `_elementor_data` from private backup with WordPress metadata slashing and remove only this shortcode widget, then revert/remove the new Staging-only catalog MU adapter and its two isolated catalog pages under change control. Preserve all existing source/content records. PR #631 is still Draft until its exact-head CI and release authorization pass.
