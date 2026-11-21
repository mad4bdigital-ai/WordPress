# Accommodation Length-of-Stay Profit Margin — live selective delivery

Date: 21 November 2026. Scope: original MAD4B Dynamic Pricing Controls, source Tours.dev and target Wayfatours.

## Original-source implementation

- PHP financial policy is implemented within the existing `wp-content/mu-plugins/mad4b-accommodation-weight-policies-v1.php` module as `MAD4B_Accommodation_Stay_Margin_V1`. It is **not another pricing engine or Rate CPT**.
- Admin UI is mounted inside the existing Accommodation/Unit panel via `assets/mad4b-accommodation-stay-margin-v1.js` and `.css` and depends on the existing accommodation-weights admin script. It reuses the original Property/Unit selector and the existing stay-night input. No duplicate Property listing or front-end JS.
- The original `mad4b-unified-pricing-policy-registry-v1.php` now projects saved stay-margin policies as a read-only `accommodation_stay_margin` family, with original scope, bands, kind and configured/inherited status. It never changes or authorizes a rate.
- Each policy is stored under original WordPress option `mad4b_accommodation_stay_margin_global_v1` or the original taxonomy/Property/Unit/Property Rate metadata key `_mad4b_accommodation_stay_margin_v1`. It does not reuse or overwrite room occupancy weight metadata, supplier costs, or price fields.

## Scope and calculation

- Selection priority: **Global → Property Type → Destination → Property → Unit → Property Rate**. A band applies only to its inclusive number-of-nights interval; if the narrower scope has no band for this stay, a matching higher-level policy is used.
- A policy contains one financial mode: **Target gross margin** or **Markup on cost**. It supports up to 24 non-overlapping 1–365-night inclusive bands, with gaps allowed for inheritance. `Inherit` has no financial rule.
- Target margin is capped at 80%; markup at 100%, matching the current native Supplier Repricing markup ceiling. These caps are financial guardrails, not default values.
- Target margin calculation: `estimated_sell = cost / (1 - percent/100)`. Markup: `estimated_sell = cost × (1 + percent/100)`. For 2 nights at USD 100 per night, 25% margin estimates USD 266.6667 selling price, whereas 25% markup estimates USD 250.0000 and a realized 20% gross margin.
- Reference cost is entered *per night* in the admin preview. A per-person source does not silently change to per-room, and a stay crossing seasons has no verified night-by-night supplier cost proof in this component.
- Explicit native selling prices and published selling Property Rates take precedence. The component refuses additional margin on such a source and never writes to native `property-rates`, `tour-rates`, checkout, tax, meals, stock or currency conversion. Cost-only preview results always carry `supplier_cost_approved=false`, `season_verified=false`, `rate_written=false`, `checkout_ready=false`.

## Live verification

- Source: `https://tours.dev.mad4b.com`; target: `https://wayfatours.com`. PHP lint on both, Node JS syntax check, admin asset registration and dependency on native accommodation module, live JS/CSS HTTP 200.
- Two-site controlled QA on original **draft QA ONLY Property 67063**: saved four bands (1–2 / 25%, 3–4 / 22%, 5–7 / 18%, 8–365 / 15%), checked exact winning scope per night, tested `200/(1-0.25)=266.6667`, `500/(1-0.18)=609.7561`, switched to 25% markup yielding `200×1.25=250`, rejected stale revision, and restored its prior inherited policy.
- QA **56/56 PASS on Tours.dev**, **56/56 PASS on Wayfatours** before final cap alignment. After adjusting markup cap to the original supplier engine's 100%, targeted **read-only** validation on both companies confirmed 100% allowed, 101% rejected, 25% target margin allowed, and overlapping bands rejected. A second write-based QA re-run was blocked by execution safety checks, so it is **not** claimed to have passed after that last adjustment.
- Original accommodation weights regression suite **65/65 PASS source** and **65/65 PASS target** after the financial release; both live registry and AJAX inventories were read back successfully. The unified registry shows `configured=false` while no commercial policy has been manually assigned; one QA-only Inherit record remains for source traceability.
- Live deployed SHA256 on both:
  - PHP core: `2e47ded12d184565579f35d3f5e8ab82ed15cf539da3a5a1b3f510a7584f8ecb`
  - Unified policy registry: `02c2be78ff8e5577259529112561fcd1694a2ec451fcdc75091c1e72ff930b0d`
  - Stay margin admin JS: `bc0a261cb26ea2abb2842a98542e68340fadaf73f7a0f9916a042cac8319fc13`
  - Stay margin CSS: `d074b4fbe3a5529aa1920ea7626bc151cf86493537f93b09a5c4781b352943bd`
- Pre-deploy backup of original MU PHP and registry exists under `/opt/mad4b-company-provisioning/reports/accommodation-stay-margin-20261121/` (restricted host directory).

## Boundaries to commercial activation

This **implements policy authoring, inheritance, configured policy registry, and cost-only calculations**; it does *not* activate any percentage automatically or integrate the final proposal with published Rate authoring or checkout. The project still requires approved per-night supplier cost receipts, actual seasonal date/cost variation, verified Unit class, occupancy and room/board terms, and explicit commercial release authorization. Logged-in browser visual interactions were not end-to-end verified; PHP/JS asset and AJAX tests are not a substitute for that.

PR remains Draft and separate from the source-independent selective live deployment.
