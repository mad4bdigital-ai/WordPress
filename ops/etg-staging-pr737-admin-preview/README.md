# ETG Staging pricing admin preview — PR #737 runtime source

This folder snapshots the exact MU-plugin implementations verified on `https://tours.dev.mad4b.com` on 2026-11-02. It is **site-local operational source**, not part of the packaged Shared Product Core and not an instruction to publish to Production.

## Runtime activation
- Only the centrally configured `MAD4B_COMMERCE_RUNTIME_ENABLED` permits the preview modules to activate; WordPress environment type is not an activation allowlist.
- AJAX handlers remain WordPress authenticated and require `manage_options` and their existing `check_ajax_referer` nonce.
- Server-side quote generation reads canonical product/rate policy; it does not write rates, cart, payment, orders or checkout.
- Adult-only partial Tour quote: `display_amount` is a nonbinding reference, `total=null`, `checkout_ready=false`; validate component sum and reject mismatched or forged partial status.
- Full legacy quotes retain numeric `total` handling; accommodation quotes remain shadow-only.

## Files installed on ETG Staging
- `102-lab-pricing-preview.php`
- `103-lab-preview-registry.php`
- `115-lab-unified-quote-preview.php`

Deployment target is `wp-content/mu-plugins/` on the registered ETG Staging site. Take an exact-hash backup and verify WordPress boot, PHP syntax and all three AJAX hook registrations before and after any update. Do not deploy this folder automatically to other sites.

## Evidence / limits
- Admin HTML rendering through WordPress authenticated capability simulation: 5/5 PASS.
- Isolated PHP partial-quote tests: 5/5 PASS; live WordPress envelope checks: 4/4 PASS.
- Staging GET returned HTTP 200; the three AJAX hooks were registered.
- Actual authenticated browser click-through remains **unverified** because the connected Browser context is unavailable and server Playwright Chromium failed at startup. Do not claim browser acceptance.
- The separate 136-file environment-guard audit is retained on the Staging host; staging/site identity and privileged security gates must be assessed individually, not blanket-replaced.
- No Production changes, pricing data mutations, new orders, or payment operations are covered here.

Rollback source on the authorized host: `/opt/staging/mad4b-pr737-cross-env-safe-admin-preview-20261102/original/`.
