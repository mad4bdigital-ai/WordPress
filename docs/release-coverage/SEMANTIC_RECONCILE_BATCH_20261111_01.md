# Focused semantic reconciliation — Translation Jobs, Country Switcher, GTM, Form #6 age hints

UTC date: 2026-11-11.
Source: Tours.dev Staging live code + candidate integration branch.
**Status: SOURCE_ONLY / DEPLOYMENT HOLD.**

## Correctness and source ownership

1. The existing exact-site, notification-only translation deferral policy now gates the original atomic ContentJob transition, after row revision checks and before artifact, state or stage writes. It does not broaden a site-specific policy to Production or other companies. An isolated PHP fixture exercised 8 scenarios including stage changes, artifact attachment, no-op and wrong-site rejection. The original WPML local artifact, revision/CAS, transactional and actor guards remain present.
2. Country Switcher now renders WooCommerce's actual country catalog, with Market Profile rights still resolved separately. The public/shared HTML does not embed a private visitor MaxMind result; visitor-specific context stays on the private AJAX read. The script reuses an existing visitor preference batch, falls back to an original country-state request and preserves explicit user choice. The source plugin's provider contract check and WooCommerce / Control Plane dependency are intentionally kept stricter than the live plugin, to avoid silently dropping entitlement boundaries. Nine offline PHP tests and five source checks passed.
3. Existing inline hints for age ranges in Fluent Form #6 now inherit Fluent Forms' native label font and positioning without modifying child/infant price logic.
4. Google Measurement bridge references the existing exact-account GTM consent audit behind the original Central operator/nonce/confirmed-source provisioning controls, and separately distinguishes a missing resource binding from an invalid Site Profile. Existing central GTM static consent contract passed; no external Google API request was performed by this reconciliation.

## Five-axis change review

- **Correctness:** localized tests confirm read-only data paths and failure on invalid site bindings; unchanged ContentJob and marketing entitlement contracts were explicitly checked.
- **Readability:** source-specific guards sit at original canonical owners; no duplicate rate, SMTP, provider or translation workflow was added.
- **Architecture:** existing WordPress class interfaces and Woo country, WPML, GTM and original job engines remain authoritative.
- **Security:** no new write/grant capability, no secret storage, no company cross-tenant ownership bypass, no Source-to-Production authority propagation.
- **Performance:** avoids resolving Market Profile for every country option; index-selective, bounded DOM updates and original asynchronous batch handling remain.
- **Limitations:** WordPress runtime callbacks and live mobile browser were not certified. Google consent audit includes an existing authenticated admin POST to create a dedicated source GTM container only when its explicit authorization/nonce criteria are met; it was not invoked.

Six exact pre-existing source-difference files were reconciled with explicit SHA-256 readback. Existing additive source regression continues to verify 63 previous divergent candidate files are byte-identical to the earlier Git preimage. Remaining conflicts require separate semantic review; none were overwritten as a side effect.

The Original318 v82 publisher, Host Issue/Revoke, durable signer and independent Original313 receipt were not activated. **Global release remains HOLD.**
