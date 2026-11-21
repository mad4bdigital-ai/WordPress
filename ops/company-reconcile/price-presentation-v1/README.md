# Company price-tag source and runtime reconciliation (read-only)

## Canonical owner

`wp-content/plugins/mad4b-commerce/modules/commerce/tour-component-structured-price-tags.php` is the one source-owned formatter. The actual price, eligibility, currency, booking readiness, and rate policy continue to be resolved by the native `tour_component_price_evidence` provider. The plugin bootstrap registers its Elementor tags for Presentation-only sites without enabling Woo checkout, inventory, or order mutations. The full Commerce source continues to use the original module map and `require_once` guards.

## Company Reconcile coverage

`price_tag_contract.py` obtains independent, profile-bound live WordPress readbacks for source and enrolled target; it tests module SHA-256 equality, plugin active, template use, runtime callable, Elementor tag registration, and a nonempty indicative price-evidence sample. For the enrolled Tours.dev ↔ Wayfatours pair it verifies EN and ES Featured Tours cards through actual public archive HTML without caching shortcuts; a partially priced carousel is a HOLD, not a PASS. A separate locale warning identifies untranslated price labels without inventing a price or treating a translation failure as missing financial data.

`company-live-semantic-discovery-v1.py` adds this observation to the existing semantic comparison, and turns failed or unavailable evidence into a P1 blocking finding. `company-reconcile-inventory-refresh-v1.py` projects the current readback without changing the previous full inventory timestamp. `company-reconcile-semantic-admission.py` requires a fresh, site-UUID-bound readback for Wayfatours production; the semantic report alone never signs a grant or certifies Original305/312/313. `company-price-preview-reconcile-observer.py` reports the price-tag state independently of any policy PLAN failure.

## Fleet behavior and governance

Only a site bound by the original registry and backed by an authorized execution/readback route can receive a verified result. Other companies report **TARGET_PRICE_TAG_NATIVE_READBACK_PENDING**, not a synthetic PASS. Generic `mad4b-commerce.php` source and tenant bootstraps intentionally differ because tenant-specific Presentation controls are not necessarily part of the central full-Commerce bootstrap. Never overwrite the whole tenant bootstrap based on a SHA mismatch. Promote any exact target file only through the approved, exact-preimage company code release with Original305/306/313 evidence; these read-only probes do not change its permissions or allowlisted targets.

## Tests

- `python3 -m unittest discover -s tests -v`
- `python3 /opt/mad4b-company-provisioning/bin/company-live-semantic-discovery-v1.py --no-publish`
- Read `reports/company-live-semantic-reconciliation-current.json` and check `comparison.price_tag_contract.state` is `PASS_VERIFIED` before any readiness decision.

Known independent finding: WooCommerce price labels in Wayfatours ES currently resolve as English; preserved as `price_tag_untranslated_languages=['es']`. The pre-existing FastCGI TTL test has an unrelated 1800s vs live 120s mismatch and is not silently corrected here.

## 17 November selective Wayfatours repair — source-first

The source-owned structured price formatter now localizes the *display labels only* for WPML Spanish, without mutating indicative Rate evidence, currency conversion or Woo orders. The central source and Wayfatours production modules have identical hashes, `4e4ef66c60846d8a58365e4b7abbcd08d7314bd1af084df357be17e2ddce78a7`.

On source and target, JetSmartFilters `23352` (ES Tour Types) now inherits verified Query Builder `181` from English sibling `16065`, and the published Package Level siblings `57372` / `57376` have taxonomy fallback `package_levels` while retaining Query `351`. The experimental LAB filter `66104` is untouched. Preimages and readbacks reside under `/opt/mad4b-backups/wayfa-selective-20261117/` on the VPS.

The root-owned, source/tenant Docker broker policy contained retired container names; only their exact `container` fields were corrected after independent `/var/www/html` mount verification. The source-owned mode pricing policy itself was **not changed**. The existing target row order is a UI preference and no longer produces a false semantic `CONFLICT`; changed strategy, enabled status or traveller bounds still block. The price-mode script remains read-only in PLAN and cannot acquire original execution authority.

Wayfatours deliberately has four EN/ES tour pages in Draft although the central source parents are Published. Sixteen child Rates remain published for staging/editorial parity, with no Rate edits made here. All four target draft routes were 404 to a public guest; a direct public Rate REST lookup also returned 404. Do not bulk-publish those tours or draft the Rates without editorial review.

Original313 file release is still separately gated; these checks do not grant the missing signature or authorize an unattended overwrite of the tenant Commerce bootstrap. The original code-release allowlist is untouched. The source Git branch remains the handoff for an approved merge/release.

## 17 Nov 2026 — Geographic operating scope shared by source + Wayfatours

Company **operating countries** now live under the *existing* JetEngine `service-activation` option, in the scoped keys `operating_country_ids` and `country_operating_scope_version=1`. This is a company authorization distinct from `destination-activation.active_countries` (catalog/discovery eligibility). A JetEngine save preserving unrelated service fields cannot erase the operating countries; the original option remains the owner. No `woocommerce_allowed_countries`, currencies, supplier or Rate rules were changed.

- Source Tours.dev operating countries: canonical IDs 665 (Egypt), 2764 (Jordan), 2759 (Saudi Arabia), 2769 (United Arab Emirates), consistent with its multi-country source catalog.
- Target Wayfatours: canonical ID 665 (Egypt) only; the same four catalog entries remain available as data and future expansion candidates, not permission to sell or publish.
- Country evidence uses canonical `destinations` term ancestry + `_mad4b_required_country_ids_v1` itinerary-country meta. For source and Wayfatours, EN/ES Egypt→Dubai and Egypt→Jordan tour records were explicitly backfilled; Egypt→Saudi Arabia ID 60625 was also backfilled. Source-only IT Draft 60681 got Dubai evidence. Original tour statuses and all financial Rate records were preserved.
- MU `020-mad4b-company-country-operating-scope-v1.php` is installed on both sites at identical SHA. It validates Combined Tours have explicit itinerary-country review, enforces country scope on WordPress publication saves, provides a public sale/product navigation filter and prevents direct public rendering of an already-published outside-scope Tour. A newly enabled company country never auto-publishes held Draft tours.
- Service/Destination Activation screens receive a read-only scope summary linking to a dedicated country editor under the existing Pricing Workspace; the workspace has one added `Company Country Scope` card. The target Pricing Workspace is an intentionally smaller, company-owned source variant: only the same card was patched into its original, leaving other company-specific sections intact. Editors have a nonce-protected Tour country meta box; updates append the matching WPML destination country term and never modify order/Rate or payment amounts.
- `country_scope_contract.py` checks source vs tenant by independent bound, read-only WP runtime results, module fingerprints, company operating scope, required country evidence and unpublished Rate parent classifications. A source Published vs Wayfatours Draft country hold is **intentional** and must not trigger a synchronization publish. The 16 published Tour Rates linked to these Draft parents remain an auditable `ADVISORY` only after all 16 have verified country holds; any non-country hold remains P2. Failures in this contract become P1; there is no automatic corrective write.
- The ongoing Company Reconcile projection, Pricing Preview, and Semantic Admission read this contract independently. The site admin's Pricing Workspace decision is not itself a permission to run Original313 or checkout. Original313 release/merge/grant remains independent and gated.

Current audit: source has 221 published tours and Wayfatours 84, with **0 published outside the recorded operating scope**. For Wayfatours, 5 country-held Draft tours (60619, 60621, 60625, 60663, 60665) and all 16 child published Rate records are deliberately preserved. Source-only Italian translation draft 60681 remains editorial Draft. The 3 separate semantic code differences and the inactive LAB filter 66104 were **not** overwritten by this scope change.

Backups and action logs: `/opt/mad4b-backups/country-operating-scope-20261117/`. The source/target metadata readbacks and a signed release acceptance are separate concepts: this local audit is not a signed Original313 release.

**Tenant-owned option write boundary:** a later source clone that includes source `service-activation.operating_country_ids` cannot expand Wayfatours' operating countries through a generic `update_option` path. The existing company scope wins under `pre_update_option_service-activation`, even if the source supplies a new key value. Only the scoped nonce-checked Operating Countries admin form can authorize a different value; a saved source option is never silently copied into the company. The central configuration registry v2 already declares `copy_source_values=false` and `overwrite_tenant_values=false`; its signed/fingerprinted native JetEngine field count was deliberately left unchanged because this country selector is a governed, company-owned overlay on the *same* option, not a duplicate JetEngine page.


## 17 Nov 2026 — WPML cross-locale country identity regression fixed

A full 6-language/2-language audit exposed a false country hold that the previous P1/P2 semantic report missed. For example, the WPML locale-sensitive get_term(125) returned its Spanish Governorate child whose parent was Spanish Egypt 669, even after the id had been translated to canonical English 125. The country scope engine incorrectly returned 669 rather than canonical Egypt 665. Wayfatours ES thus classified all 42 published Tours as outside the Egypt-only operating scope, and its sale-ready country header menu became empty. The source had a corresponding regression for DE/FR/AR/IT/ES.

The contract now reads canonical native term_taxonomy parents directly after resolving the source term, independently of current WPML locale. Cross-language tour-type identity likewise uses the WPML canonical combined-tours source; published EN Combined Tour itineraries are inherited by translated siblings. Divergent explicit translated requirements are held for review, rather than widening the company operating scope.

The central company-country-scope-runtime-contract observer now enumerates the site's actual active WPML languages and audits **every published Tour** for a geographic HOLD in each language. A missing language scan or even one unexpectedly held published Tour produces a P1 country scope HOLD on semantic admission. Source has six active languages, Wayfatours two; all current published Tours are IN_SCOPE. Wayfatours' five intentional cross-border Draft Tours remain Draft and non-public, with 16 attached published Rate records retained. Tests and live frontend route evidence: ops/company-reconcile/experience-unity-v1/ and /opt/mad4b-backups/geographic-country-wpml-fix-20261117/tests/.

This fixes a read-only/preview/publication-scope classification defect. No rate amounts, Woo orders, Woo checkout, country activation options, or Tour post statuses were mutated. Original313 independent code-release approval remains outstanding.
