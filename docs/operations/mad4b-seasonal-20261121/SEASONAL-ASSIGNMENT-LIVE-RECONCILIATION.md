# Seasonal Assignment & Effective Rate Date — live-first reconciliation

## Applied live, 21 November 2026

The MAD4B pricing registry module \`includes/package-seasonal-assignment.php\`
provides native stage + canonical package level + exact Property + Season
assignment overrides, with \`inherit\` (no stored rule), \`eligible\`, or
\`excluded\`. The source of the calendar is the existing enabled
\`season\` taxonomy Term Meta. No Term IDs or dates are copied across
sites. Every rule applies only to a verified Property candidate; Property
destination and package tier checks remain mandatory.

The read-only Tour Rate calendar helper now resolves:
1. complete explicit rate dates, even when stored as ISO rather than Unix UTC;
2. otherwise **exactly one** manually assigned enabled Season term;
3. partial explicit periods, missing or multiple Terms, and invalid dates fail closed.

The native Package Stage Guard filters both detailed Unit candidate references
and plan-only OR alternatives by the effective stage dates, including arrival
offset and the last night. An excluded optional alternative does NOT invalidate
a remaining eligible alternative; the selected hotel or an all-excluded list
still blocks the rate. No rule means base assignment unchanged.
A staged controlled test created and permanently deleted an ephemeral Draft
Tour Rate and rolled back the seasonal override; 24/24 pass. It also tested
Winter 2026/27 crossing years and stale revision rejection.

Admin UI: Dynamic Pricing Controls → Package & Rates → Seasonal hotel assignment.
AJAX and the same REST resource \`/wp-json/mad4b-pricing/v1/package-seasonal-assignments\`
are restricted to an authorized site admin; mutations enforce edit-post scope,
candidate identity, supported active Season, max 250 rules and stored revision
compare-and-swap. Anonymous REST read was rejected with HTTP 401 on both sites.
There is no Property inventory, cost authorization or WooCommerce write.

## Similar gaps fixed live, not in the source plugin package by default

The actual shared runtime differs per site:
- Tours.dev: \`wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/pricing\`
- Wayfatours: \`wp-content/mu-plugins/shared-product-core/pricing\`

The shared runtime PHP files were modified **selectively** on both sites:
\`package-stage-rate-guard-v1.php\`,
\`rate-date-eligibility-readonly-v1.php\`,
\`price-resolver.php\`,
\`audience-resolver.php\`,
\`booking-addons.php\`, and
\`rate-planner.php\`.

The latter four previously bypassed the new effective rate calendar when
showing prices or add-ons, or reusing existing Rate sources.
The 35 published Tours.dev Rate visibility samples were identical before/after
these read-path changes, and Wayfatours had zero published Rates in the sample.

These shared runtime files are NOT wholly represented by current
\`packages/mad4b-shared-product-core\` Git source. In particular the live
\`booking-addons.php\` depends on the more recent
\`optional-tour-relations-readonly.php\`, while the older Git package still
contains the legacy functions. **Do not replace the newer runtime with the
older Git copies.** Their minimal live-relative patches and exact before SHA
are stored alongside this note, to be reconciled into the current canonical
shared-core source separately without discarding newer behavior.

## Availability and season-specific supplier exceptions

\`eligible\` means the hotel may be selected, not that a unit is bookable.
Room capacity and dated inventory remain separate supplier proof.
\`peak\` is supplier-specific: it does not have one global Season term.
Do not auto-convert notes such as Christmas/NYE, gala meals, blackouts,
optional meal additions or short mid-season price changes into eligibility
until exact dates and cost bases are independently confirmed.

The current eight English package plans contain 28 stages across hotels,
cruises and sleeper trains. The active 24 package/tier reviews remain
commercially blocked by supplier costs, Units, occupancy and native cruise/
train providers. This patch does NOT create Tour Rates or change checkout.

Source/release checkpoint and rollback journal:
\`/opt/mad4b-package-checkpoints/2026-11-21-seasonal-assignments/\`.


## Shared-core release parity sentinel

The native guard now defines MAD4B_PACKAGE_STAGE_SEASONAL_GUARD_V1.
The admin catalog rejects edits with seasonal_assignment_runtime_parity_missing
when a subsequent release provides a stale guard or omits the shared
effective-date resolver. Reconcile the separately pinned native core patches
before advancing the production package.
