# Market-aware Selection Manifest + Query Plan

**Implemented:** 2026-10-30 · Staging · MAD4B JetEngine Hub Add-on **0.3.0**  
**Contracts:** `mad4b.hub.capability-selection.v1`, `mad4b.hub.market-policy.v1`, `mad4b.hub.selective-market-queryplan.v1`  
**Source:** `includes/class-mad4b-hub-market-policy.php` + `class-mad4b-hub-discovery-planner.php`; MAD4B Control Plane enforces the market plan in `admit_runtime()`.

## Purpose

Distribute **specifically selected** source records by source company, destination company, approved market, locale, nationality segment, residence segment, point of sale, target country, channel, currency and validity window. This is content distribution **planning**, not a sales-price engine. All changes are additive to the previous provider discovery, JetEngine Query/Listing/Filter selection, WPML term mapping and DENY-WINS policy inheritance.

## Market registry authority

`mad4b_hub_market_registry_v1` is a server-owned, **non-autoloaded recommended** options registry for separately approved company market definitions. A registry entry includes `source_company`, `status=active`, `valid_from`, `valid_until`, and allowed sets of:
- `languages`
- `nationality_countries` (country codes representing *policy cohorts*, **not customer personal data**)
- `residence_countries`
- `point_of_sale_countries`
- `destination_countries`
- `channels`
- `currencies`
- `source_record_ids`

This local registry is **not sufficient for publication**. It has no signed source-grant authority. Control Plane's runtime admission additionally needs `mad4b_scp_hub_consumer_market_authority` to return **true from trusted server-side code**, as well as the existing signed company and native JetEngine binding proofs. All three default to false. No active market registry has been created for the real Staging company. Synthetic `egypt-consumer`/`gcc-consumer` fixtures in tests use `pre_option_*` filters and are not saved.

## Selective market request

`Selection Manifest` retains specific source tour IDs, target WPML canonical category IDs, language list, native Query/Listing/Filter/Form IDs, field projections, and company UUIDs. Add `markets` as a list of explicit market selections:

```json
{
  "markets": [
    {
      "market_id": "egypt-consumer",
      "valid_from": "2026-10-01",
      "valid_until": "2027-10-31",
      "languages": ["en"],
      "nationality_countries": ["EG"],
      "residence_countries": ["EG"],
      "point_of_sale_countries": ["EG"],
      "destination_countries": ["EG"],
      "channels": ["web"],
      "currencies": ["EGP"],
      "source_record_ids": [24900],
      "pricing_authority": "reference_only",
      "import_strategy": "remote_reference",
      "commercial_status": "not_bookable"
    }
  ]
}
```

The sample is a **test fixture**, not a declaration that Tourism has approved Egyptian nationality, prices, countries or dates. Actual market definitions and signed permissions must be supplied by their owner and verified in Control Plane. Source record `24900` is a selection example, not an assertion about its commercial eligibility.

### Validation rules

- No `markets` property = `UNSCOPED_DENY`, **never** `all markets`.
- Market must appear in the local registry, be `active`, and belong to the **exact source company**.
- All dimensions are explicit nonempty lists (record IDs may be empty only when the overall source record selection is empty, e.g. definition-only plans). No `*`, `ALL`, guessed market, free-form credentials, unknown field, duplicate market or duplicate selection IDs.
- Date window must be ISO `YYYY-MM-DD`, valid, unexpired and contained within the market registry window. Future dates are marked as scheduled plan-only.
- Each requested dimension must be a **subset** of the registered values and every inherited `market_limits` declared at platform, source company, consumer company, provider, content type, market and selection levels. Any widening returns 422, not a silent fallback.
- Selected market languages must already be selected in the base manifest. Selected market source IDs must already be in the base manifest; there is no implicit wholesale distribution.
- `reference_only` + `remote_reference` + `not_bookable` are required. Currency is a **display/market selection constraint**, never a transfer of rate authority or authorization for checkout.
- Proposals are **not** signed source grants. An admin-supplied `allow_read=true` is not proof. Source item identity, rate, taxonomy, source market validity and dates must be certified at the source before runtime.
- Distinct policies for nationality, residence and country of sale are accepted as abstract **market cohort rules**; no visitors' passport/residence data is read or exported by this engine.

### QueryPlan output

`POST /wp-json/mad4b/v1/hub-selection/validate` (administrator only) returns `market_plan` and `query_plan`. Each market slice has an exact filter predicate of company + market + dates + nationality/residence/POS/destination/languages/channel/currency + source record IDs. The same slice carries approved **local** Query IDs, Listing IDs and Filter IDs, a SHA256 preview digest, cache partitioning requirement, and `execution_state=BLOCKED_PENDING_VERIFIED_GRANTS`.

Query semantics are explicit: **AND across market dimensions, OR within each allowlisted list**. No native JetEngine Query is mutated. Do not incorrectly assume an existing Posts Query, REST Query or a JetSmartFilter shares execution semantics. Native endpoint capability, pagination/total-count/filter binding and signed grants remain distinct acceptance requirements.

### Policy inheritance

Order: platform → source company → consumer company → provider → content type → market → selection. The original six-layer manifests remain readable but **do not gain market authorization**. Each layer can add `market_limits` for `market_ids` and any of the market dimensions. All inherited limits intersect; no lower-level rule may enlarge an upper bound. Denials have precedence. The returned `policy` includes `runtime_approved=false` and `source_read_proven=false` unless separately authenticated by a server-side workflow.

### No wholesale import

The data stays with the owning company. Only signed selective REST references may feed native provider Queries. No bulk WordPress/database import, taxonomy duplication, copying Options Page **values**, Fluent Form submissions, Woo checkout pricing or source media. Layout schemas may be reusable subject to company policy.

## Testing and deployment

- Staging version **0.3.0** loaded successfully.
- Test fixture: one Egyptian market with source record `24900` and explicit dimension subsets, returned `MARKET_SCOPED_DRY_RUN`.
- **22 market-policy negative cases** rejected including unknown market, wrong company, nationality/residence/POS/destination/currency/channel outside authority, duplicate scope, wildcard, invalid/expired dates, unselected record or locale, pricing/booking activation and arbitrary extra instructions.
- Missing markets produced `UNSCOPED_DENY`.
- Existing 203-Query discovery / WPML / Options Page / Graph tests passed unchanged.
- Control Plane directly rejected valid native plans missing `selection_manifest`, rejected an unscoped manifest, and continued to block unauthorized outbound Hub REST.
- No real market registry installed, no content import, no new Listing/Filter/Query, no Production change.

**Next phase:** register **actual approved market policies per source company** with source-signed evidence and versioned expiration, then certify date ranges, WPML, nationality vs residency vs POS precedence, distribution caching, query provenance, and channel-specific commercial/pricing approval. Until then this is a tested **dry-run planning/validation layer**, not an enabled sales or publishing integration.
