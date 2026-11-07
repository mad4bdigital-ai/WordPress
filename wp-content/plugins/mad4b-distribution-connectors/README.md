# MAD4B Distribution Connectors · Company-local channels

Version 1.3.0 · Distribution Core → Meta Catalog Feed Mapper / approved partner adapters.

## Default behavior

- Registers 11 channel definitions and two private catalog profiles, if missing.
- Channel onboarding is **company-local**; central Company Management may inherit definitions, never credentials or partner accounts.
- No external vault or external wallet service required.
- API tokens are encrypted at rest in a non-autoloaded WordPress option. Keys are derived from the company's WordPress authentication salts and bound to its canonical site URL/blog ID.
- Do **not** export or clone `mad4b_distribution_local_secrets_v1` when creating a company. If the database is cloned, ciphertext from the source cannot decrypt on a different site identity, but the stale option should still be excluded during provisioning.
- WordPress-salt rotation or site-domain migration requires re-entering credentials; back up and handle separately before rotations.
- An actor with full WordPress PHP runtime access can still access the WordPress salts. This is encryption against database-only disclosure, not a sandbox against compromised site code.
- No credentials are echoed via REST, admin interfaces or audit receipts; only saved/missing and readiness states are shown.
- Credential write/delete is restricted to authenticated WordPress administrators and CSRF-protected native admin forms or authenticated REST; no use of `wp --user` to impersonate owner sessions.
- Delivery and provider verification stay **OFF** unless separately approved and implemented by a compatible authenticated partner adapter.

## Live management

- `Tools → MAD4B Distribution Channels`: create marketplace, configure public account metadata, add a local encrypted credential, revoke or rotate it, link channels, inspect readiness.
- `Settings → Catalog Feed Studio`: edit existing feed mappings, plus the private tours/destinations profiles. Previously configured feed slugs, WordPress options and rewrite paths remain unchanged.
- Site-local REST `GET /wp-json/mad4b-distribution/v1/channels` for authenticated administrators.
- Site-local REST `POST|DELETE /wp-json/mad4b-distribution/v1/channels/{id}/credential`; never returns a token.
- Central Company Management: `Distribution & Marketplaces` holds provisioning plans only, not credentials, external provider identities or remote job receipts.

## Adapter contract

- `MAD4B_Distribution_Local_Credentials_V1::for_adapter($channel,$purpose)` is denied by default. Trusted site-local provider code must explicitly grant the purpose-bound `mad4b/distribution/allow_local_credential_access` filter.
- `mad4b/distribution/adapter/{channel}/verify` is a provider-specific extension point. No partner login or availability can be claimed without a real provider readback and consent.
- Google Things to do export eligibility remains gated by weighted-price/fees/occupancy/availability/checkout proof, plus provider enrollment and an authorized channel transport.

## Failure and rollback

The package is portable, and original source/target copies are backed up under `/opt/mad4b-company-provisioning/backups`. Do not copy source-company credentials to targets. Existing channels and feed mappings are preserved by idempotent re-provisioning. For full site restoration, keep the matching WordPress salts alongside the original encrypted options.

## Catalog Feed Studio v1.4.0 — admin action lifecycle

The `Settings → Catalog Feed Studio` page is powered by the original Meta Catalog Feed Mapper storage, field resolution and export URLs. `Settings → Feed Mapping (Advanced)` preserves the complete legacy editor. WP All Export Pro and WP All Import Pro are linked through their **native admin pages**, not by calling undocumented private AJAX handlers or copying their code.

| Control | Action and verified postcondition | Failure / safety |
| --- | --- | --- |
| New feed | Scroll to company-local create form | No write until submit |
| Create private draft | Validates source and unique slug; writes only a new `mad4b-managed-*` MCFM profile; independent readback | Unknown source, invalid slug, or duplicate rejected; no external delivery |
| Save mapping draft | Checks field syntax, duplicate columns, type/source compatibility; updates same existing feed and verifies readback | Validation receipt automatically becomes stale when fingerprint differs |
| Save feed settings | Updates format, language and term scope with readback | Changes invalidate previous validation receipt |
| Validate current mapping | Checks structure and retrieves at most 5 eligible output rows from the **first 24 source candidates**; stores only issues/warnings/count/fingerprint | No eligible initial candidates blocks ready; means "not proven", not "whole feed empty" |
| Preview 5 rows | Runs the MCFM field resolver with WPML language; private 10-minute per-user preview; output fields clipped and secret-like columns redacted | Does not produce a full export or upload to any provider; no automatic preview at page load |
| Mark ready internally | Requires a current successful validation receipt, same mapping hash and a nonempty sample | `READY_INTERNAL` only — **not** Google/Meta/OTA product eligibility, external publishing or provider acceptance |
| Duplicate as draft | Copies feed definition to new private slug | No receipts or external connections copied |
| Download mapping JSON | Calls the original MCFM nonce-protected `mcfm_export_template` action | Exports template definition, **not** bulk records or external feed submission |
| Review import | Parses uploaded JSON/CSV template, checks mapping and stores a 10-minute user-scoped review | Original feed remains unchanged |
| Confirm import mapping | Requires matching review token, source fingerprint and admin nonce; writes mapping then invalidates readiness | Rejects expired/changed review, never overwrites feed identity or secrets |
| Advanced Mapper | Opens existing `mcfm_admin_page()` under a separate menu slug | Preserves original import/export and raw mapping features |
| WP All Export / WP All Import | Opens existing active provider's own admin UI | Native previews and import jobs managed by those plugins, not silently run here |
| Other configured feeds | Opens legacy non-`mad4b-managed-*` feeds in Advanced Mapping | No accidental conversion to a private MAD4B feed |
| Distribution Control Center | Opens the separate company channel console | Credential/provider readiness and external sending remain separately governed |

The Studio does **not** automatically create Google Things to do approved offers, verify fees and occupancy, or send to marketplaces. Price and availability continue to be resolved by existing MAD4B engines and must be checked again by a real provider adapter before any external delivery is enabled. A validated internal mapping is not a promise of future provider acceptance.

## Security and review hardening v1.5.0 (7 November 2026)

- Private `mad4b-managed-*` feed downloads require `manage_options` at both the MCFM template endpoint and the MAD4B adapter. WooCommerce Shop Managers with only `manage_woocommerce` are deliberately not authorized to download company-private feeds.
- Receipt fingerprints now include the **entire normalized feed definition**, including its CSV delimiter, combined with a company-local source data epoch. External provider readiness is **not** implied by `READY_INTERNAL`.
- Source epoch changes are collected at shutdown for watched post types/taxonomies on post saves, relevant meta changes, term edits and term relationship changes. This adds no database writes on an ordinary read request.
- Internal validation receipts expire after one hour. The source epoch only covers WordPress write hooks on the company; supplier-side price changes not reflected in WordPress or invisible external availability changes require fresh validation and an explicit provider adapter check.
- Sensitive output column names (tokens, credentials, passwords, private keys) are rejected. The internal Preview also redacts recognized secret fields.
- The native Google Things to do pricing/readiness and Content Readiness/Fluent Boards workflow remain authoritative for fee/tax, occupancy, availability and checkout eligibility. Sample price evidence is advisory, not an offer or external feed approval.
- `tests/test_mad4b_catalog_hardening.php` exercises epoch, delimiter, role-sensitive assumptions, receipt expiration and price-state separation without changing live WordPress options.

## Catalog concurrency / WPML / outbound activation v1.6.0

- `Catalog Feed Studio` forms now include a current feed fingerprint (`feed_revision`), and the admin-post handler refuses stale revision submissions. An atomic `add_option` gate serializes competing **Studio** writes, with a 90-second abandoned-lock recovery. Legacy MCFM Advanced Mapping uses its original save path, so cross-editor CAS is **not yet absolute**; don't operate both editors against the same feed simultaneously.
- Preview accepts a selected active WPML language. It scans at most 4 pages of 24 candidates, stops after five eligible rows, preloads term/post metadata and restores the original WPML language on completion. A preview is not a full-export completeness guarantee.
- The original MCFM XLSX generator passed an isolated OOXML ZIP/XML test and a native Wayfatours WordPress export of 43 spreadsheet rows on 2026-11-07. No generator code change was required.
- The `Tools → Distribution Control Center` channel inspector now has **Request activation**, **Verify provider connection**, **Activate verified delivery**, and **Disable delivery** actions. These use `manage_options`, a WordPress nonce, the company-local channel configuration and a fresh provider verification receipt.
- Activation is fail-closed. It requires approved enrollment, company account, site-local credential for API/SFTP, a registered and verified provider adapter, linked channel readiness, and a real send adapter. Google Things to do additionally requires current internal tour-feed mapping and provider-specific fees/occupancy/availability/checkout evidence.
- Activation intent does **not** send data or mark a provider approved. Even `external_delivery_enabled=true` is only a capability for independently implemented adapters; actual sends must call `MAD4B_Distribution_Activation_V1::enforce_gate($id)` before performing transport.
- No production provider credential, partner enrollment, adapter or receipt is fabricated by provisioning. Company-local credentials remain non-portable.

## Reconciled feed editor and WPML batching v1.6.2 (2026-11-07)

- The original Advanced Mapping editor and the Catalog Studio admin-post handler now coordinate via one per-database MySQL advisory lock (`GET_LOCK`/`RELEASE_LOCK`). Advanced Mapping forms also carry a SHA256 snapshot of the original feed-list configuration; stale form submissions are rejected before any mutation. Studio retains its own per-feed revision and shorter-lived option mutex. Other non-editor writers that bypass both editors should migrate to the same lock API.
- No WordPress user/nonce rights are bypassed. The lock covers in-request writes only and is released in a `finally` clause. It neither grants credentials nor affects provider approval or outgoing channel settings.
- WPML term translation performance: the Mapper calls WPML's installed `WPML_Term_Translation::prefetch_ids()` against at most 512 taxonomy term-taxonomy IDs in one request-local batch before term enumeration. The same helper runs for both full native exports and the five-row Studio preview; other datasets fall back to ordinary WPML resolution. No persistent price, term or language cache is created.
- Proof: a read-only Wayfatours probe on five destinations changed 153 `icl_translations` SQL requests to 2 while preserving the SHA256 digest of the mapped output. A post-deployment parity check remains mandatory before final performance sign-off.
