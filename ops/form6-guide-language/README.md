# Fluent Forms #6 — Guide Language preference

Purpose: add a **single, optional** `guide_language` dropdown to the existing published **Single Tour Order Form (#6)** without changing payment readiness or price authority.

- Existing taxonomy: `guide-languages`.
- Form field name: `guide_language`; stored value: **term slug**, not a site-specific numeric term ID.
- Options are generated from actual terms on each site at migration time, excluding `QA ONLY` fixtures.
- Insert after the travel date section, immediately **before `no_of_adults`**.
- No selected language is a valid preference. No charge, availability promise or supplier quote is inferred by this migration.
- Future price preview must server-validate the selected slug against the tour's eligible languages, resolve to a term ID, and use a single guide-cost component with verified price provenance; the existing legacy guide-language surcharge must not also be applied to that same cost.

## Execution

In the target site's WordPress/PHP container:

```bash
php -l /tmp/mad4b-form6-guide-migrate.php
wp eval-file /tmp/mad4b-form6-guide-migrate.php --allow-root
MAD4B_FORM6_APPLY=1 wp eval-file /tmp/mad4b-form6-guide-migrate.php --allow-root
```

Dry-run is the default; apply saves previous exact JSON to a timestamped file in `/tmp` and verifies the saved new JSON. Copy the backup to the restricted host backup directory after each apply. The script is idempotent and refuses to modify an existing `guide_language` field or an unexpected form layout. All other Form #6 fields and existing per-company choices are preserved.

Roll back only via an exact, authorized restore of the backed-up `form_fields` after checking for subsequent form edits; do not overwrite later changes blindly.

Applied 19 Nov 2026 to Tours.dev Staging and Wayfatours Production. Both returned one rendered `guide_language` select with Arabic, English, French, German, Spanish and published status. No booking submission, supplier-price activation, or checkout was exercised.

## Default WPML language identity (19 Nov 2026)

`assign-identity.php` stores the canonical guide language ISO identity in taxonomy term meta
`_mad4b_guide_locale_code` on each verified WPML translation of a Guide Language term.
The site-neutral MU plugin `mad4b-form6-guide-locale-default-v1.php` reads the **display**
locale from `wpml_current_language`, matches it to term identity metadata, and selects
the matching value from the form's existing options. It does not inspect display
labels, visitor nationality, or pricing currency. It never creates or prices a new
guide language. Missing language, e.g. Italian without Italian Guide term, stays
unselected. A visitor can change the auto-selection.

Run `assign-identity.php` with `wp eval-file` first in dry-run and then with
`MAD4B_FORM6_APPLY=1` per enrolled tenant, and retain the generated JSON backup.
No payment or guide-cost authority is inferred from this default.
