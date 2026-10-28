# QA40 — Engine and Product Context Management Diagnostics

**Execution:** 2026-10-28, Hostinger primary VPS, ETG WooCommerce **Local Lab only** (`etg-woo-lab-php`, `etg_woocommerce_lab`, `SHARED_PRODUCT_LAB_ONLY=true`). No staging or Production mutations, no orders, payments, bookings or customer changes.

## User problem and design

Administration needs a single source-aware view of pricing engines, booking engines, WooCommerce product identity, source CPT/Rate policies, Elementor/JetEngine Dynamic Tags and operator workflows — without adding a competing pricing/booking engine or implicitly selling a product.

Installed QA40 directly in the existing Commerce Composition Lab source:
- `CommerceEngineInspector`: two **admin-only, read-only** REST routes:
  - `GET /wp-json/commerce-composition/v1/management/engines`: 14 native engine/provider registrations, company-domain states, incomplete pricing-rule catalog and Site Profile authority blockers.
  - `GET /wp-json/commerce-composition/v1/management/product-context/{source_id}`: explicit published company-allowlisted CPT source; optional `rate_id` and strict `travel_date`. Reuses native ServiceProductIdentity, RateProvenance / TourRateEligibility and FormRouter. Returns source identity, Rate relationship, form-mode evidence, registered Dynamic Tag provider presence and **checkout_authorized=false**.
- `CommerceEngineDashboard` under **WordPress → Tools → Commerce Engines**: source-backed diagnostics for one selected product, pricing rule blockers, owner/status matrix and the next review boundary. No customer PII, raw Rate price, order write or alternate source of truth.
- Existing `CompanyManagementRest` exposes admin-only inspection context derived from the canonical MAD4B Site Profile and site-bound company registry. **Company-registered CPT lists**, not embedded vendor/domain IDs, control eligible source types. Unregistered/foreign/private source pointers fail closed.
- Composer bootstrap only loads/registers both classes; no new database tables or background jobs.
- In the Lab's `wp-content/mu-plugins/transport-component-price-tags.php`, changed Elementor role label from misleading **Current final** to **Current indicative**. Engine pricing output or source currency was not altered.

### Live findings (read-only)

- Pricing registry is present and supplies **indicative** traceable quotes; supplier cost/margin, tax/fees, financial manager approval and other factors remain uncertified/partially covered. Registration of a component is not evidence of sellable availability.
- Core rate/quote providers are mounted for tours, accommodation and transport; WooCommerce HPOS is mounted; Elementor, JetEngine and JetSmartFilters are active.
- Inspected Tour IDs `60475` and `60629`: CPT/Woo identity readable. Tested Rate `60820` is eligible for a preview only. **Composition Policy missing or invalid** for these inspected form contexts, so the contract reports `requires_review` and `booking_available=false`.
- Sampled published property and transport services: Woo CPT mapping not enabled for their parent types; FormRouter cannot certify a booking policy for these examples. This observation is about selected samples, not every post.
- Rendering providers for Tour / Extra and Transport and Company Option dynamic tags are loaded, but **actual browser/page-template rendering has not been independently certified**. UI diagnostics explicitly set `dynamic_render_certified=false`.
- The canonical Site Profile reports an origin-binding mismatch, `authority_ready=false`, and `profile_authority_quarantined=true`. Unified management writes therefore remain correctly disabled. No silent rebinding.
- Do not turn a `booking_available` or a default Woo product meta price into traveler-accepted quote, paid order, supplier booking or stock hold.

## WP-CLI and HTTP evidence

Re-ran previous and new behavior suites **109/109 PASS**:
- QA40 engine/product REST/UI: **20/20**.
- Dynamic Tag/display source safety: **12/12**.
- Extension: transport context, allowed sources, indicative label, invalid dates: **7/7**.
- QA39 REST: **33/33**, Catalog: **11/11**, Roles: **9/9**.
- QA38 Lifecycle: **17/17**.

PHP lint for changed plugin and MU-plugin files, installed/source parity, two independent patch replays onto pre-change snapshots and `git diff --check`: PASS. Local homepage HTTP 200; anonymous admin-engine REST HTTP 401. No new orders/requests/claim/task writes during inspection tests.

VPS backup and replay evidence: `/opt/commerce-components/evidence/etg-commerce-engines-qa40-20261028/`.
- `QA40-Commerce-Engine-Inspector.patch` SHA256 `1afb541f177af25c7cd399003d17b57806d08005eea1ecc8dd9595755d39e7bf`
- `QA40-Transport-Elementor-Price-Label.patch` SHA256 `fa84cefde21f4693b0022b40591147ce0d40e0997e46ed8243a4c65b6d9f1836`
- `QA40-Commerce-Engines-Product-Context-REST-Handoff.zip` SHA256 `a6873cac93738fc3230f7e7a44105808bad762d0833ca274d93c57e98d3d9c6a`.

## Next release gates

1. Onboard complete Commerce Composition plugin + relevant MU-plugin sources into a canonical, deployable, versioned repository. The GitHub **patches are not a full plugin release**.
2. Per-company exact Site Profile / CPT / Rate / provider enrollment. No copy-pasted UUID or site-bound secrets; resolve Lab origin mismatch under independent authority.
3. Pricing domain certification for supplier costs, inventory, taxes, nationality/age, final commercial quote digest and manager approval.
4. First-party traveler acceptance; certified Woo Order/Payment and supplier confirmation. Keep every financial mutation disabled until independent proof.
5. Manual desktop/mobile browser acceptance of Elementor templates, archive cards, selection states, JetSmartFilters, Dynamic Tags and WPML; the CLI verified plugin and provider functions only, **not pixel/responsive behavior**.
6. Fix unassigned operational tasks / failed workflow by exact source reconciliation; review legacy operator privileges with a least-privilege migration.

**PR is documentation/source-patch handoff only. Leave Draft; no merge, rollout, or Production write approval implied.**
