# MAD4B Pricing-Aware Cache Inheritance v1.1

Source-backed package already verified on Tours.dev Staging and Wayfatours Production.

## Native architecture
The existing JetEngine pricing controls page is the authority for mode
financial rules, source priority and original Price/Rate calculations.
The MAD4B pricing cache inherits decisions from company, mode,
program, season, tour, rate and existing Policy Registry rules (when present).

The new MU pricing cache module stores **only normalized pricing policy
definitions** in site-scoped Redis, with a content/options-generation
fingerprint. No quote amount, payment fee amount, live availability,
market-adjusted amount, booking/cart/checkout, or rendered rate HTML
can enter the shared cache.

The two original Core functions remain available as
shared_product_mode_policies_uncached() and
shared_product_price_source_policy_uncached(). The public functions call
those originals on bypass or failure; no pricing amount owners change.

Default cache TTL 1800s, maximum 2400s. Scoped overrides are configured
per company via Tools → Pricing Cache Inheritance; admin action requires
manage_options plus nonce. The page also links the existing Dynamic Pricing
Controls and Cache Lifecycle panels.

## Price change invalidation
The plugin signals only real pricing policy, product metadata and
supported taxonomy changes at shutdown. Each site-local event is verified
by the host worker, which uses its fixed company container to collect the
published WPML public URLs, excludes private endpoints, then calls the
pre-existing socket to update FastCGI path generations. No Redis FLUSHDB,
no customer data in receipts, no incoming command payload from WordPress.

The PHP route collector is a **durable site asset** under MU assets.
The host worker's file path points to that asset, not container /tmp.
Host source and Wayfa paths are deployment-specific; new tenants need
their own authorized service registration after Company Provisioning.

## Acceptance evidence
- 4 canonical Mode rules and 5 original Rate presentations per site
  matched exactly before and after deployment.
- Cached Mode + Source Rule representations matched uncached outputs.
- No shared quote, rate amount, or availability.
- 857/857 Staging path generations after a no-price-change synthetic
  policy-save event; 408/408 Wayfatours Production.
- Native public HTTP 200 at both sites, runtime observer PASS 2/2.
- Microbenchmark: 25 repeated reads each of modes & source rules.
  This is policy lookup cost, NOT page TTFB improvement.
- Required source modules are SHA-pinned in a host-owned observer.

Do not overwrite any site-local policy option or customer pricing data
while distributing this package. Rollback original PHP files using the
VPS snapshots and disable new host watcher units if necessary.

## Policy cache context v1.2 — source-only until approved deployment

- Blueprints take a validated Tour/Rate/Program/Season context, and Redis keys are separated by effective TTL and inheritance trace.
- `private_only` and `disabled` override Redis reads and preserve the original quote computation.
- Original functions accept backward-compatible optional contexts. Version 1.2 must be verified on Tours.dev first and is **not yet deployed**.
- Wayfatours has a distinct Booking v2 file: apply the small contextual call-site change, never overwrite that file from Tours.dev.

## Site-specific core call-site integration (v1.2)

The updated Redis wrappers by themselves are backward compatible; scoped Tour/Rate behavior becomes effective only when call sites pass validated context. The checked-in `tools/apply_contextual_pricing_calls.py` has an exact-replacement, dry-run-first integration plan for strategy resolution, financial policy, Booking v2, source arbitration, Rate Planner, and Extra adapters. It preserves each site's entire Booking v2 file (including different Last Minute behavior), modifies no pricing amount formulas, and creates exact rollback backups only with an explicit `--apply --backup-dir`. Deployment should run on Tours.dev Staging first, compare 5+ price fixtures and multilingual quote parity, then separately check Wayfatours Production. Never deploy this source package just because the GitHub PR exists.
