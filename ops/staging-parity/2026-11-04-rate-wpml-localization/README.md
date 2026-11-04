# Staging parity: Rate Generator, WPML, and Tour UI — 2026-11-04

## Target
- Runtime: `tours.dev.mad4b.com`, **Staging only**.
- Source: `mad4bdigital-ai/WordPress`.
- This directory is a **reconciliation bundle**, not an automatic release.
- No production changes or direct master updates are implied by this branch.

## Full source files carried in the branch
1. `wp-content/mu-plugins/mad4b-rate-naming-and-note-v1.php`: Rate naming modes (inherit / auto / custom / source), localized display names, Look & Feel accommodation note, Elementor Dynamic Tag, per-tour controls.
2. `wp-content/mu-plugins/mad4b-tour-multilingual-interface-v1.php`: localized JetEngine listing labels, query counts, occupancy text, price prefixes and units, RTL formatting, confirmed broken attraction CTA fallback, non-empty featured-attraction section handling, and foreign-tenant exploration text override.

Both originate from running Staging. The JetSmartFilters localization update was published via the persisted host bind mount and the GitHub blob was verified byte-for-byte with `git hash-object` (`d5e5e3e170be93867409b9430f66becc4f76b55a`). The rate-naming module remains a separate source file.

## Runtime overlays and remaining source reconciliation
The legacy patches below are unified diffs against pre-change Staging backups. The frozen Local Lab snapshot under `packages/mad4b-shared-product-core/mu-plugins/shared-product-core/` must remain unchanged: its original SHA256 manifest is CI-enforced. Two owner-mapped runtime overlays are separately tracked under `packages/mad4b-shared-product-core/runtime-overlays/etg-staging-20261104/`: `rate-generator-naming.patch` and `quote-price-bidi.patch`.

Original diff inventory:
- `rate-generator-naming.patch`: Rate Generator administration and draft label.
- `fluent-rate-projection.patch`: projected form choices from the Rate naming policy.
- `rate-label-and-dates.patch`: translated JetEngine Rate/term names, date labels, From prefix.
- `quote-price-bidi.patch`: price amount direction attribute (no quote calculation changes).
- `localized-term-details.patch`: read localized package term metadata, not source-language metadata.

**Source ownership reconciled (2026-11-05):** the full active `mad4b-commerce` plugin source tree outside the mirrored `legacy-mu-tree` is now tracked under `wp-content/plugins/mad4b-commerce/` (89/89 exact original source blobs verified). The owner now contains `modules/compat/local-presentation-fluent-projection-v1.php`, `modules/commerce/tour-rates-dynamic-function.php`, and `modules/compat/tour-rate-native-display-tags.php`. Four dependent PHP assets and the popover JS have been enrolled under this same owner and its display-tag loader adjusted to use local paths. Original patches are preserved as historical diffs only, not queued runtime changes. Source checksum inventory `wp-content/plugins/mad4b-commerce/source-files.sha256` validates 94 owned runtime files. See `SOURCE-OWNERSHIP.md` and the dedicated Commerce Runtime source CI. The legacy core snapshot remains untouched.

A separate WPML-equivalent age-policy patch is documented but **not deployed**; the Arabic Rate 422 is unresolved. PHP syntax alone is not a booking release proof.

## Database and Elementor state (NOT contained in the Git tree)
- Package taxonomy `package_levels`: 4 English terms translated into AR/FR/DE/ES/IT (20 links); Badge, description and benefits localized.
- Destination taxonomy `destinations`: 5 featured attractions translated into AR/FR/DE (15 new links); ES/IT already existed.
- `tour-types`: `Half-Day` translated into AR/FR/DE (3 new links).
- Tour Rates `61052/61054/61056/61058`: reunited with source `61050` in WPML `trid=1218516`; 3 missing `related_package_term_id` values populated using the verified canonical `2354` term.
- Existing translated Rates elsewhere already had canonical term IDs on audit; no price amounts or currency fields were changed by this reconciliation.
- Six Elementor templates were updated to render `[mad4b_tour_accommodation_note]`; Look & Feel / Tour Page Options gained note and naming fields.
- The business data and translation taxonomy changes above require separately supported **idempotent migrations** if promoted to a fresh environment.

## Verified behaviour
- Arabic and French example Tour pages: 8/8 focused page checks each for locale rate details, badges and occupancy headings; both HTTP 200.
- Arabic homepage: 8/8 scoped checks for localized price labels, query counts, featured badge, search/subscribe CTA, legacy Half-Day display and broken archive link; HTTP 200.
- Reconciled Rate source group: 4/4 WPML object mappings and financial metadata readback successful.
- WordPress still uses native currency conversion / quote price logic; UI i18n must not derive a price.

## Verified additional work — 2026-11-04
- JetSmartFilters labels for package, season and availability were published to the Staging bind mount; PHP lint and strict byte-for-byte Git blob parity passed. The render fixture validated the three labels in EN/AR/FR/DE/ES/IT (18/18).
- Playwright completed 6 basic real-browser cases (Arabic/German Tour and Arabic homepage, each Desktop/Mobile), no JavaScript page errors or horizontal overflow; both Rate toggles opened. An actual rate selection triggered German age-policy HTTP 200 but Arabic HTTP 422 (`age_rate_context_invalid`).
- 54 idempotent Tour-translation Content Jobs were created and read back as `QUEUED`: AR 22, FR 16, DE 16. No automatic translation publication. The attempted first execution returned the single-flight 409 overlap refusal; do not bypass or blindly retry.
- Source quality and Commerce Suite CI failed when two frozen snapshot files were edited; those files were restored byte-identically to master, and the same changes preserved as owner-scoped runtime overlays outside the frozen snapshot. Subsequent source-quality and Commerce Suite checks passed on the prior overlay commit.

## Pending gates
- Resolve exact Content Jobs single-flight ownership and editorial dependencies; translate missing Tour pages and validate their WPML/JetEngine relations before publication.
- [DONE on Staging] Enroll `mad4b-commerce` sources, move local Rate display assets into plugin ownership, and verify four PHP includes resolve exactly once from the owned path. New Commerce Runtime CI verifies source owner contract, 94 checksum entries, PHP 8.3/8.5 and JS syntax.
- [SEPARATE BLOCKER] Apply and verify the age-policy fix through an approved Staging release with positive and negative tests; Arabic age-policy HTTP 422 remains unresolved, independent of source ownership. Do not relax cross-tour authorization.
- [SEPARATE RELEASE GATES] Validate full AJAX/RTL/editor save/redeploy behaviour and release checks. The frozen snapshot checksum must stay unchanged. Basic mobile Playwright checks on Arabic/German Tour pages passed after source migration (HTTP 200, zero JavaScript page errors, zero horizontal overflow, Rate toggles opened).
- This PR remains draft; no Production deployment or merge is authorized.
