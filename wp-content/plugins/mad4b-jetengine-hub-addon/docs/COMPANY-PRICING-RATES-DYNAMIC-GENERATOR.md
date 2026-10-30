# MAD4B Content Hub — Source Prices, Product Rates and Per-Company Repricing

**Implementation date:** 2026-10-30  
**Central environment:** `https://tours.dev.mad4b.com/` (Staging)  
**Add-on version:** `0.5.0`  
**Contracts:** `mad4b.hub.pricing-sources.v1` and `mad4b.hub.company-pricing.v1`

## Owner decision and scope

The signed Content Hub integration must preserve **both starting prices from each product's genuine fields AND all applicable source Rate records**, for any product type whose provider/field ownership has been explicitly discovered and mapped. The central Control Plane manages independent consumer-company repricing rules and the native Rate Generator's draft preparation. Source values remain immutable. Currency, dates, source-rate IDs, post type, rate stop-sale and availability assertions are not guessed.

The **mutual signed P-256 REST company connection** is sufficient authority to share selected **reference price data**. It is not an automatic authority to enable checkout, publish rates, mark a stale rate as current, copy private customer data, or overwrite provider CPT/JetEngine fields.

## Sources integrated on Staging

The new `MAD4B_Hub_Price_Sources_V1` is a **read-only adapter registry**, not a replacement for the existing `MAD4B_Pricing_Source_Registry`, Shared Product Core, Booking Experience, or WooCommerce.

| Parent product | Starting price field candidates | Rate provider | Current note |
| --- | --- | --- | --- |
| `tours-and-activities` | `start_price`, `egyptians_start_price` | `tour-rates`, `related_tour_id` | Separates `tour_base_currency` from `tour_egyptians_currency` |
| `properties` | `prop_start_price` | `property-rates`, `prop_related_property_id` | Draft/non-current provider rates are not considered publishable; Accommodation Booking remains checkout authority |
| `transportations` | `trans_start_price`, `trans_sale_start_price` | `transport-rates` by `source_post_id`; JetEngine CCT `jet_cct_transport_rates` by `related_transport_id` | `stop_sale`, dates and CCT status preserved |
| `product` (WooCommerce) | `_price`, `_regular_price` | WooCommerce/registered future rate adapter | Woo product amount is only a reference; no generic external rate guessed |
| Tourism signed Nile reference | **Not present in existing remote signed catalog** | `nile_rate_history.read` (364 stored historical rows) | Existing signed historical rates are *not* certified current prices |

The plugin filters `mad4b_hub_pricing_source_descriptors` provide **future-provider extension** to additional public product CPTs, native starting field names, currency fields, rate CPTs, exact parent relation fields, date fields and price fields. **Unknown product type is rejected** until its adapter is verified. This is deliberate: "any product type" means an extensible typed provider contract, not guessing that every numeric Meta Field is a sale price.

Rate identity is **provider-qualified**: e.g. `post:66570`, `jet_cct_transport_rates:1` or `tourism-almallahgroup:nile-rate:24901`; numeric IDs from different providers are never interchangeable. If numeric IDs collide, a company rule must identify the exact provider-qualified `rate_key`.

A signed Tourism historical Rate may contain occupancy columns `single_price`, `double_price`, `triple_price` and the separate `egyptians_*` prices. Valid lower-case ISO currency codes from the legacy source are normalized to upper-case **without any foreign-exchange conversion**. Values are always `reference_only`, `validated_current_price=false`, `checkout_ready=false`.

### Price precedence / no implicit arithmetic

Direct Starting Prices and all valid Rate entries are projected **separately**. The Hub never sums or substitutes them; source priority selection still belongs to the existing shared price arbitration provider. Amounts and currency stay attached to each source field and Rate. If a mandatory currency or amount is absent, the candidate is omitted, not guessed. Source flags such as `stop_sale`, QA-fixture markers and Rate dates survive into the read-only quote.

## Per-company pricing

`MAD4B_Hub_Company_Pricing_V1` stores company policies under the central **non-autoloaded** `mad4b_hub_company_pricing_policies_v1` option. Identity is `source_company|consumer_company|product_type|source_product_id`. Other companies never inherit those rates by accident.

A policy contains:
- `enabled` (reversible).
- A source-field-scoped `starting_rule`, e.g. only `start_price`.
- `rate_rules` targeting an **exact `rate_key` or `rate_id` and price field**, with an optional language/market/channel/nationality/residence/date condition.
- Optional `default_rate_rule` for all non-overridden Rate entries.

Rule modes: `inherit`, `fixed`, `percent_adjust` (signed basis points), `fixed_adjust` and `rate_generator` (calculated proposal). Currency must match the source unless a separately approved FX engine is integrated; no implicit WCML conversion is performed inside this Hub. All money is computed at four-decimal precision internally (1/10,000 unit integer); amounts and source price fields are never written to Woo or the source provider.

Policy mutations require the central WordPress `manage_options` capability and an **expected revision**. A global atomic registry lock prevents concurrent writes. Every later revision must match the actual previous revision, otherwise HTTP **409**. The end user may define different consumer company policies for the same source product and override any selected Rate independently.

A sample admin-side policy body:

```json
{
  "expected_revision": 0,
  "policy": {
    "source_company": "tourism-almallahgroup",
    "consumer_company": "all-royal-egypt",
    "product_type": "tours-and-activities",
    "source_product_id": 24900,
    "enabled": true,
    "starting_rule": null,
    "rate_rules": [
      {
        "rate_key": "tourism-almallahgroup:nile-rate:RATE_ID",
        "field": "single_price",
        "mode": "percent_adjust",
        "percent_basis_points": 1500,
        "conditions": {"language":"en"}
      }
    ],
    "default_rate_rule": null
  }
}
```

**Replace `RATE_ID` with a real source Rate ID** discovered through the preview; the example is *illustrative*, not an instruction to save a rule for a source record. At present there are **zero live per-company pricing policies saved** on Staging. The only write test created an isolated non-existent product fixture, confirmed revision-1 creation, HTTP 409 stale-revision rejection and revision-2 disable, and immediately deleted the entire test registry.

## Existing native Rate Generator is reused

There are **two separate capabilities**, intentionally:

1. **Hub dynamic repricing:** a `rate_generator` policy produces a price-adjusted, non-bookable Rate *proposal in the quote output* for a chosen source rate.
2. **Native CPT Rate draft preparation:** `POST /wp-json/mad4b/v1/hub-pricing/rate-generator-preview` calls **`Shared_Product_Rate_Generation::prepare()`**, not a new implementation. It validates provider-owned product type and parent, glossary price unit, amount, currency, date window, accommodation / transport / tour program constraints. Its resulting provider plan has an immutable fingerprint, `post_status=draft`, `checkout_ready=false`, `commercial_ready=false`. It does **not** call `Shared_Product_Rate_Generation::create()` or write a Rate post.

The existing native Rate Generator supports `tour`, `accommodation`, and `transport` for local CPT parents. Creating **consumer-site** rate drafts *remotely* via a signed, scoped consumer agent and approval/review is not implemented; the central preview cannot silently create posts in a different company's WordPress. WooCommerce does not become a new rate generator by virtue of its product type alone.

## Admin REST API (central Staging only)

All endpoints require an authenticated WordPress admin. Without auth they return 401. All price reads remain private:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/wp-json/mad4b/v1/hub-pricing/sources` | Discover typed price/rate providers and existing MAD4B Pricing Registry sources |
| GET | `/wp-json/mad4b/v1/hub-pricing/policies` | List consumer-company policy identities and revisions, not customer data |
| POST | `/wp-json/mad4b/v1/hub-pricing/policy` | Save or disable one company policy with optimistic revision |
| POST | `/wp-json/mad4b/v1/hub-pricing/quote-preview` | Read local starting fields/native rates or signed historic Tourism rates and preview consumer pricing |
| POST | `/wp-json/mad4b/v1/hub-pricing/rate-generator-preview` | Read-only native `Shared_Product_Rate_Generation::prepare` handoff |

`quote-preview` accepts `origin=local_product` with exact local `post_type` and `product_id`, or `origin=signed_historical_nile` with `source_company=tourism-almallahgroup` and exact source `product_id`. The response separately includes `starting_price_options`, `rate_entries`, source rate keys, applied per-field company pricing and a quote digest. Historical Tourism's initial product `start_price` is **not yet exposed in the signed remote source contract**, so it is not fabricated.

The existing Control Plane native consumer `DATA_FIELDS` allowlist now recognizes `direct_starting_prices`, `rate_entries`, `currency`, `price_effective_state`, `pricing_policy_revision` and `source_rate_key`, but **the public signed distribution GET remains HTTP 403** and current native JetEngine Query/Listing/Filter binding has not been activated. Making these fields selectable does not imply that the remote provider supplies live, valid prices.

## Acceptance / release

Staging Add-on **0.5.0** loaded; the original 417 Hub reference records and frontend were preserved. Native Woo/MAD4B pricing source discovery found four base product descriptors. A signed historical Tourism product returned two signed-rate snapshot entries; an existing Staging transport product returned two direct starting-price candidates and four related Rates (two WP Rate CPT and two JetEngine CCT). Independently selected company overrides and condition-based dynamic pricing worked; invalid date/nationality, cross-product repricing, source currency mismatch, unverified FX and sale promotion were rejected. The existing native Rate Generator prepared a transport-rate **draft** without creating it.

Real REST acceptance: all four existing private admin routes returned 401 when anonymous; administrator inventory/quote paths returned 200. The policy REST saved, rejected stale revision 409, then disabled the **test** policy and cleaned it. No actual consumer-company policy was left active. Source/production websites were not modified; original Woo prices and Rate CPTs were not changed.

**Remaining for end-to-end rollout:** expand *source-signed* remote provider contracts to include initial price fields and live approved native Rate records for each company and product type; send those through the authenticated Hub feed to the consumer's native JetEngine Query/Listing/SmartFilters; enforce real-time rate validity, inventory, source priority, FX provider (if needed), per-consumer market pricing and checkout-side revalidation through the existing Booking Experience engine. Historical rates cannot be silently published for sale.
