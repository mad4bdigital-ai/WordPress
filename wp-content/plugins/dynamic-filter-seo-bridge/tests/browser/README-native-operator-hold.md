# DFSB and JSF integrated-source acceptance — 2026-11-03

## Integration scope
Candidate based on master HEAD 5c72e4893e36e992f056c90cfc75908b3a8f7785
combines the scoped source from PR #733, #751, #748 and #753.
Status: HOLD. Source-only; no release, merge, or Production deployment.

## Verified
- Node tests for editorial lifecycle, native-state preservation, and
  direct-link coalescing: 7 PASS (including explicit AND array handling).
- PHP syntax valid for catalogue context, WPML language switcher and JSF parser.
- Candidate JS injected in a browser without modifying Staging live files:
  EN Aswan+Cairo+El-Fayoum sent one native listing AJAX request with
  IDs 122, 123, 721; HTTP 200; 20 found posts and 20 first-page cards.
- Existing Staging PHP request context cache separately verified at 1.66-2.05s
  per direct AJAX request. The staging runtime has additional source guards
  not yet incorporated into canonical Shared Product Core.

## Blocking regression: native AND deep link
Route:
/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/tour-styles:city-tours,cultural-tours,operator_AND/

Native WP_Query: term IDs 287/286 return 27 for AND and 31 for IN.
Both unchanged live Staging and candidate-JS browser checks show that native
JetSmartFilters hydration drops operator_AND from the URL and currentQuery.
The resulting native AJAX query lacks the operator and returns 31.
This is a pre-existing runtime bug and remains a required explicit fail gate.

Reproduce:
python3 wp-content/plugins/dynamic-filter-seo-bridge/tests/browser/native-operator-and-acceptance.py

Do not restore the unsafe practice of rewriting native currentQuery from
untrusted pretty URL slugs. Preserve native JSF authority and require
independent readback before claiming AND/IN parity.

## Boundaries
No Elementor/WPML database migrations, no Production write.
Keep deploy_automatically=false and source-only release status.
Do not deploy the Local-only canonical Shared Core as a blanket replacement
for the Staging MU adapter, which has extra runtime and preview controls.
