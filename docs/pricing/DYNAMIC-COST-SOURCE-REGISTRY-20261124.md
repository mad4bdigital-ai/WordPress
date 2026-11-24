# Dynamic Cost Source Registry and product review ledger

## Contract

Tenant-local option `mad4b_cost_source_registry_v1` contains validated mappings keyed by kind, registered slug and amount field. The native 10 product/Rate types remain supported even when no mappings are configured. Additional registered admin-visible post types can extend the same review surface. Orders, refunds, bookings, attachments and other protected non-product records are not supported by the generic registry.

- Post type: read own cost or explicitly referenced source post IDs from an existing product meta field. Source post type and edit permission must match. No arbitrary query or executable expression.
- Taxonomy: read existing assigned terms of that taxonomy with term-level permission. A term contributes a review reference, not an automatically selected or summed charge. No arbitrary term scan.
- Positive numeric amount, original currency and calculation basis; optional currency/basis field mappings override fixed values. Invalid or missing mapped fields remain missing. No FX conversion, quantity multiplication, commercial inclusion, supplier approval or publication by generic mappings.
- Registry configuration requires both manage_options and commerce_manage_pricing, nonce and tenant-scoped lock. Native Cost Receipt Gate, approved-price contract and legacy rate lifecycle are unchanged.

## Admin surfaces

Pricing Workspace: Cost Source Registry (`mad4b-cost-source-registry`), Cost Review Ledger (`mad4b-product-cost-review`). Product editor metabox/list links and Pricing Workbench shortcut use the same review page. Native source editors remain canonical. History uses separate appended WordPress postmeta records, latest 25 per page, older entries retained. Review records contain actor, UTC timestamp, revision, source fingerprint, review context and note, never signature or release authority.

## Verification and deployment

- 23/23 registry assertions; 7/7 history assertions covering 30 appended revisions and older-page readback.
- PHP lint, live WordPress isolated runtime preview and HTML renderer checks on both tenants. In-memory additional CPT registered, four real assigned guide-language term rows discovered (blank override values correctly missing), native guide resolver preserved. Pilot 60627 Gold / 2027-02-01 / 2 adults / 1 room remains USD740 indicative hotel subtotal. Actual reference Property Rate values read in temporary mapping tests.
- Both MU-plugin files installed on Tours.dev and Wayfa, matching SHA256; WordPress runtime confirms both loaded, save handlers registered, existing Rate Generator enabled. No real registry mapping, review record, Rate, supplier receipt or order was created by the test/deployment.
- Authenticated browser QA and real operator save remain outstanding. Commercial composition and the independently verified Cost Receipt/approved-price providers remain outstanding. Generic references cannot be used in commercial totals until the corresponding native provider and approval contract support that component. JetEngine custom relation IDs, arbitrary queries and taxonomy-specific review histories are not implemented by this generic mapping version.
