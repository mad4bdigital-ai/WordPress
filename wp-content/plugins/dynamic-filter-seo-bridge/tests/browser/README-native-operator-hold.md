# DFSB + native JSF operator acceptance — 2026-11-03

## Scope
Integrated candidate of PR #733, #751, #748 and #753 on master
5c72e4893e36e992f056c90cfc75908b3a8f7785.
Release: Draft / source-only; no Production deployment or merge.

## Root cause and fix
JetSmartFilters 3.8.5.1 itself supports an explicit checkbox
data-relational-operator="AND" and natively appends operator_AND when multiple
checkboxes are checked. The Elementor filter for tour-styles has the default
OR behavior; the direct permalink operator was previously discarded while
JSF initialized its checkboxes.

DFSB now uses the native jet-smart-filters/before-init event to pass the
explicit, validated permalink operator to matching checkbox widgets before
JetSmartFilters constructs filter instances. It does not write currentQuery,
forge AJAX or compute result counts.

The local Staging DFSB path parser returns a comma-separated string; the
canonical #733 parser returns an array for explicit operators. The bootstrap
accepts both after strictly validating native group, taxonomy, known URL
term values, uniqueness, and only terminal operator_AND / operator_IN.

## Live Staging acceptance
- Native WP_Query truth: styles 287+286 => AND 27, IN 31.
- Previously Staging dropped operator_AND from permalink/currentQuery and
  native AJAX returned 31. AFTER the scoped fix:
  browser native-operator-and-acceptance.py PASS on unmodified live assets.
  Page and native currentQuery keep operator_AND; signed JSF AJAX has
  ["287", "286", "operator_AND"] and HTTP 200/found_posts 27.
- Explicit IN native AJAX HTTP 200/found_posts 31. Ordinary OR URL also
  preserves native selection and URL state; on SSR pages it may not issue
  an initial AJAX request.
- Trusted interaction: add historical-heritage-tours and remove it:
  both native AJAX responses HTTP 200 / 27; signed query retains
  terminal operator_AND; no JS console errors.
- Localized permalink readbacks: ES and AR on **live Staging** retain
  operator_AND and resolve their native translated term IDs and EN
  translation links. The FR/DE/IT candidate-script browser checks also
  preserved AND and translated links. Localized result counts differ due
  to localized published content; do not require EN 27 on other locales.
- Node suite 14/14 PASS, including 7 dedicated pre-init bootstrap cases
  for string/array parsing, AND/IN, missing terms, unrelated provider,
  duplicate terms, and invalid operators.
- PHP syntax previously verified 11/11, ZIP integrity PASS and
  Shared Product Core SHA-256 manifest parity PASS.

## Read-only acceptance
python3 wp-content/plugins/dynamic-filter-seo-bridge/tests/browser/native-operator-and-acceptance.py

## Release boundary
The operator hydration issue has passed live Staging acceptance. This does
not authorize merging the entire source candidate or deploying it to
Production. Keep deploy_automatically=false and source-only status.
Require exact-HEAD CI, upstream PR reconciliation and an independently
authorized release decision. A repository workflow may still require a
separate exact-HEAD owner attestation; do not invent it.
Preserve Staging-only runtime configuration; the Local-only canonical
Shared Core must never be used as a blanket replacement for the live MU
adapter. No Elementor/WPML database migrations were made.
