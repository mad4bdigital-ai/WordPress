# Staging parity: Rate Generator, WPML, and Tour UI — 2026-11-04

## Target
- Runtime: `tours.dev.mad4b.com`, **Staging only**.
- Source: `mad4bdigital-ai/WordPress`.
- This directory is a **reconciliation bundle**, not an automatic release.
- No production changes or direct master updates are implied by this branch.

## Full source files carried in the branch
1. `wp-content/mu-plugins/mad4b-rate-naming-and-note-v1.php`: Rate naming modes (inherit / auto / custom / source), localized display names, Look & Feel accommodation note, Elementor Dynamic Tag, per-tour controls.
2. `wp-content/mu-plugins/mad4b-tour-multilingual-interface-v1.php`: localized JetEngine listing labels, query counts, occupancy text, price prefixes and units, RTL formatting, confirmed broken attraction CTA fallback, non-empty featured-attraction section handling, and foreign-tenant exploration text override.

Both were fetched from the *actual running Staging files* and read back byte-for-byte from this branch.

## Patches requiring canonical-source reconciliation
The five files under `patches/` are unified diffs against pre-change Staging backups:
- `rate-generator-naming.patch`: Rate Generator administration and draft label.
- `fluent-rate-projection.patch`: projected form choices from the Rate naming policy.
- `rate-label-and-dates.patch`: translated JetEngine Rate/term names, date labels, From prefix.
- `quote-price-bidi.patch`: price amount direction attribute (no quote calculation changes).
- `localized-term-details.patch`: read localized package term metadata, not source-language metadata.

Their exact target runtime files may not yet exist at the same paths in this GitHub checkout. **Do not blindly apply**; reconcile into their current module owners and avoid duplicate callbacks. The live Staging originals/backups remain on the authorized VPS.

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

## Pending gates
- Validate UI in a real Desktop and Mobile browser (including RTL, AJAX updates and empty lists).
- Audit untranslated Tour posts and destination relationships before enabling missing-language listings; do not invent counts.
- Confirm this repository's canonical module ownership and **merge the patch files into those owners** before considering this a deployable source parity PR.
- Validate full WPML workflows, restore/redeploy behaviour, release tests, and safe rollback.
- Production deployment and PR merging require separate actions.
