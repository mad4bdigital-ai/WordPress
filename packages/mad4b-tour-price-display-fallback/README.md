# Optional company-scoped nationality pricing (WordPress display policy)

**Business rule:** passport nationality is customer context, not a price tier. A company without an explicitly approved site policy uses the general published price, irrespective of whether the visitor selected Egypt or another country. This includes tour listings, dynamic structured prices and transport previews.

## Site-bound opt-in

An existing public WordPress site may set the site-local option `company_tour_price_audience_policy_v1`:

```json
{
  "schema_version": 1,
  "site_uuid": "USE_CURRENT_REGISTERED_SITE_UUID",
  "mode": "configured",
  "rules": [
    {"nationality": "EG", "audience": "egyptians"}
  ]
}
```

The option is intentionally **unset by default** and must be provisioned by the company's policy administrator in a separate governed workflow. The site UUID must match the `commerce_company_policy_registry_v1.site_uuid` in the same WordPress site. Do not infer the policy from a brand name, domain, language, geolocation, or a country/passport switcher. Do not copy a policy option between sites.

Only the existing `EG → egyptians` mapping is supported by this V1 resolver. Its schema allows multiple explicit nationality rule objects for future versions, **but new audiences must not be enabled until a pricing source and quote resolver exist**. An activated mapped audience with missing or invalid price remains unavailable. It must **never silently substitute the general price as an Egyptian booking price**.

The Maltah/Mallah company can opt into its existing Egyptian tier through a site-specific governed setup. Other companies, including Wo Vacation staging, remain on general pricing by default. No Mallah option was changed here.

## Authority boundaries

- Tour auto-display chooses `general` by default, or a configured, validated site-scoped audience for the selected passport.
- Explicit `general` and `egyptians` requests preserve their meaning; this change only resolves `auto`.
- Tour Rate validity, site-wide stop sale, source priority, price unit, currency conversion and security restrictions remain in the existing engine.
- Transport display no longer rejects every Egyptian passport. The canonical transport quote provider is still responsible for rate/date/quantity/market eligibility.
- **Neither display path sets booking prices.** Booking quotes and checkout authority are unchanged. Future nationality matrices and customer price rules need separate governed extension of the booking engine, migration and editor options, not automatic deductions from a passport.

## Executed staging acceptance (2026-10-29)

On `tours.dev.mad4b.com` (Wo Vacation), test with a simulated `EG` passport:
- Unconfigured policy: tour 60477 resolves 130 USD, 60465 resolves 70 USD, 60463 resolves 120 USD, each per person.
- Unconfigured policy with `FR`: same general result.
- Configured but wrong site UUID: returns general price.
- Exact matching site policy with EG mapping: enters existing Egyptian provider; no unconfigured Egyptian quote becomes a fabricated price.
- Existing explicit `general` and `egyptians` requests, Price On Request where genuinely unsupported, and rate validation remain unchanged.
- Transport price needs eligible date/rate; no automatic nationality-blocking shortcut.

Deploy only the tenant-specific reviewed source files. No other tenant, Production environment, stored rates or booking orders should be modified.
