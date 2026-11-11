# Pricing Registry age-input parity and Composite Media source preservation

Date: 2026-11-11. Scope: isolated local WordPress Git reconciliation. State: SOURCE_ONLY / HOLD.

## Pricing inputs

Tours.dev's original MAD4B Pricing Registry accepts child_ages in the accommodation-rate preview dispatch allowlist, but the full-WordPress integration candidate still rejected that field. Updated the candidate's read-only dispatcher to pass child_ages through unchanged to the original, provider-owned BookingExperience unified accommodation preview function. This is an input-contract correction, not a new children price calculator. Invalid age/count shape, unpriced children and inventory remain the provider's responsibility.

The registry's existing check still rejects unknown fields, rejects a provider quote claiming checkout_ready=true, returns mutation_performed=false and checkout_authorized=false, and does not create orders or charge cards. Eight offline PHP cases verify forwarded ages, native-owner invalid count/age errors, unknown field failure, zero children and unsafe provider response rejection. This uses a stub provider for contract checking and does NOT certify the actual installed BookingExperience provider on either live site. The original MAD4B plugin name is preserved.

## Exact safe content reconciliation

Two Pricing Registry PHP files differed only in a docblock; synchronized their exact bytes to current live source. One Direct MU tour-media-reconcile PHP file differed only in a final blank line and was synchronized. Three nonfunctional byte mismatches were resolved without modifying behavior or site state.

## Media source preservation

Two other Direct MU files intentionally remain different, not blindly overwritten:

- mad4b-media-composite-integrity.php in Git retains per-repeater-slot value_format in the read-only JetEngine schema audit. The live source lacks this metadata.
- mad4b-unified-media-apply.php in Git retains signed composite post and term registered adapters and dispatch through MAD4B_Composite_Media_Adapter_V1. This includes missing-adapter denial and original credit/write compensation ordering. The live source omitted both adapters.

File SHA digests and review disposition are pinned in pricing-and-media-semantic-review-20261111.json. Four static tests check preservation, but this is not a live signed Media execution or proof that non-media company data can be overwritten.

## Deployment limitations

No plugin files on Tours.dev, Wayfatours or other companies were changed by this Git reconciliation. No WooCommerce order, payment, pricing modification, Media/JetEngine post-meta write, approval or Original313 signed receipt occurred. Original318 v82, approved code release authority, live browser AJAX and company-specific metadata migration remain separate HOLD gates.
