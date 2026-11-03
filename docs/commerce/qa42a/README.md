# QA42A/B — Commerce Composition canonical source and tenant-safe quote preflight

Date: 2026-10-28. Source imported from authorized VPS ETG Woo Lab, WordPress local DB etg_woocommerce_lab. No Production mutation or deployment.

## Source ownership

- Full actual PHP runtime under `wp-content/plugins/commerce-composition/`: composition.php, 61 includes, workflow-queue entrypoint, README/CHANGELOG. 40 plugin PHP tests under tests/. No mutable Lab evidence files or database exports are included.
- For the initial 105-file snapshot, every Git Blob SHA from VPS matched its corresponding GitHub SHA (105/105). An additional admin-only tenant-binding inspector was installed with a 106th source file and an updated composition.php.
- `commerce-composition-runtime-manifest.json` contains SHA256 and byte counts for the 65 runtime files. The ZIP built from those exact files is kept on VPS at `/opt/commerce-components/evidence/etg-commerce-qa42a-20261028/commerce-composition-qa42a-source.zip`. ZIP SHA256: `ab21d7d6454256f0a675fdb3e63cf9d727f980fd1861513c078f78b13fcde8b3`. Artifact is a **source build**, NOT a certified deploy.
- PHP 8.3 syntax validation on the installed Lab plugin: 62/62 runtime PHP files pass. GitHub CI checks all PHP and the exact release manifest.

## QA42B commercial preflight status

- Existing `CommercialExecutionPreflight` consumes the CompanyCommercePolicy resolver, native RateProvenance and the first-party `shared_product_pricing_quote_contract_v2` preview; no parallel price calculation or Woo Order engine was introduced.
- It uses either the parent Tour Product identity or the existing independent Rate Product identity as a **single choice**, not two sale lines. A preview is explicitly indicative; margin, supplier cost, taxes, inventory and final traveler/manager acceptance remain uncertified.
- **Critical current blocker discovered during re-verification:** MAD4B Site Profile v2 is currently authority-ready/origin-matched in the local Lab, but the saved company definition and commercial policy belong to a DIFFERENT site_uuid and a different Site Profile revision/digest. This is **not** merely a stale timestamp. The preflight correctly returns `policy_company_binding_invalid`; its formerly passing historical test is **not currently valid**.
- It is unsafe to silently replace the old UUID or copy old company pricing policies to the new enrolled Site Profile. A governed ownership/provenance check and explicit re-enrollment is required. Keep the existing policy draft unchanged and all Woo/cart/order/payment/supplier authority disabled.
- New `GET /wp-json/commerce-composition/v1/management/commerce-binding-audit` is `manage_options + manage_woocommerce` only, read-only, no raw UUID/digest, no customer PII and **never rebinds identity**.
- Audit suite on the current Lab: **12/12 PASS**, including different company UUID, different policy UUID, authority health, denial of execution, private REST ACL, no business mutations.
- The original preflight suite, rerun against the current binding mismatch, **FAILED (3/9)** due to the correct fail-closed policy check; do not present its earlier 18/18 snapshot as current certification.

## Acceptance / next release gates

1. Current tenant authority: verify Site Profile enrollment source, company registry ownership and policy origin; issue an owner-reviewed migration or new empty company policy; capture exact metadata and rollback receipt.
2. Re-run current preflight suite with exact source identities and a fresh site-bound Draft policy; 18/18 must pass **again** in the same site context.
3. Use registered supplier cost, tax, inventory and availability providers to create evidence-backed final Quote with digest and expiry; no empty value or zero placeholder should count as a verified offer.
4. Independent policy-driven manager and traveler approvals, verified Woo Product/Rate Cart and Order Item, payment and supplier confirmation with idempotency and rollback/reconciliation.
5. Site-specific Staging/Production release and source certification; Draft PR only until independently approved.

The GitHub branch is **source-onboarding and fail-closed diagnostic**; it authorizes no live sales or Production changes.
