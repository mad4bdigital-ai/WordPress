# QA42B — Domain-bound company enrollment (ETG Woo Lab)

Date: 2026-10-28. Exact domain: https://tours.dev.mad4b.com.

## Executed on VPS

- Site Profile v2 current canonical_origin and WordPress home/siteurl all match the domain.
- Current Site Profile binding reported exact, authority-ready, origin/deployment/environment matches.
- Replaced the single domain-local WordPress option `commerce_management_company_definitions_v1` with a **fresh definition**, revision 1, strictly bound to the current Site Profile UUID, revision 2, digest and local environment.
- Discovered six local CPT types (Tours, Properties, Transportations and their three Rate types), published Fluent Forms #6/#7/#8, and registered WordPress role-personas from live WordPress.
- New **draft** company commerce policy at `commerce_company_policy_registry_v1`, revision 1. All cost, margin, tax and availability aspects require review; manager and traveler approval required; manual Woo order timing, supplier booking timing and deposit review. `rate_sale_mode=both` is an intended-mode definition, NOT authorization to sell.
- Old site-bound company definition and old policy are **archived untouched** in two separate, nonautoload options, plus a receipt tracking previous/new checksums. Both primary options were swapped under a guarded atomic SQL transaction and verified by DB readback. **Old business rules were NOT copied or re-enrolled.**
- Private raw backup containing old options and Site Profile metadata remains on VPS only, chmod 600: `/opt/commerce-components/evidence/etg-company-domain-enrollment-20261028/backup-before.private.json`. Do NOT upload to GitHub or public media.
- Installed QA42B `CommercialQuotePlan.php` in the Lab plugin and added its native boot hook only after exact Git Blob validation. GitHub source SHA matches installed Lab PHP source.

## Verification

- New company definition, REST, previous-record archive, custom CPTs, role/form references, quote Preflight: **33/33 PASS**.
- QA42B Quote Plan, same-source parent/independent Rate choices, auth, no payment/order/inventory writes: **25/25 PASS**.
- **Total 58/58 PASS**, rerun after installation.
- Local HTTP homepage 200; unauthenticated company-definition REST 401.
- Site binding diagnostic now reports `site_bound` and `policy_resolution_available=true`, **commerce_write_authorized=false**.
- Existing Woo Products Tour 60629 and Rate 60820 are mapped, but both remain `is_purchasable=false`.
- No new Woo Orders, Commerce Requests, FluentBoards Tasks or inventory holds from the tests.
- Source and installed Commerce Composition `composition.php` / `CommercialQuotePlan.php` identical.

## Rollback / governance

Enrollment receipt option: `commerce_domain_enrollment_receipt_20261028_2836db85410d`.
Archived definition option: `commerce_company_definition_archive_20261028_2836db85410d`.
Archived policy option: `commerce_company_policy_archive_20261028_2836db85410d`.

A rollback must be authorized separately and only performed against matching expected current option checksums, via an atomic transaction. Never restore a foreign UUID into an enrolled Site Profile without a governed owner decision. Local Lab only: no Production or other-tenant mutation.

## Explicit limits

The fresh policy is **draft**, cost/tax/inventory/manager-traveler evidence not yet certified, no signed final Commercial Quote, no accepted Woo Order or supplier confirmation. This release does not authorize checkout, collection or provider booking.

The next commercial milestone is provider-backed final Quote with policies for each company, followed by manager/traveler evidence, Woo Order creation and supplier booking only after independent approval.
