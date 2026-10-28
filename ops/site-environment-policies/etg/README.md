# ETG environment isolation policy — security repair (2026-10-29)

This is a **site-specific MU-policy source**, not a distributable default for every MAD4B company. The tested target is ETG Commerce Staging (`tours.dev.mad4b.com`, `etg_commerce_staging`, WordPress `WP_ENVIRONMENT_TYPE=staging`).

## Runtime contract

- Host environment is a bootstrap fact; the exact native `WP_ENVIRONMENT_TYPE` must be explicitly declared and match WordPress' native effective value. The MU phase cannot trust an unbootstrapped Site Profile; a missing or conflicting declaration enters **`unverified_host`**, which blocks HTTP, mail and Cron. Site Profile independently governs later capabilities, write approvals and rollout.
- `local`, `development`: private/metadata URL safety checks, Local-only AI exceptions for `local` only, and mail/Cron holds.
- `staging`: private/metadata URL safety checks, public REST loopbacks still work (including WPML); mail requires **Company Communication approval AND separately verified SMTP transport**, and never accepts a prior `pre_wp_mail=true` as evidence of delivery. WordPress traffic-triggered Cron is independently disabled on this lab; any backlog must have separate execution authority.
- `production`: **explicit, matching host Production only** bypasses these test-only MU hooks. No Production sites were edited by this patch. Do not copy this site-specific file to Production merely to add a generic safety policy.
- The `SHARED_PRODUCT_LAB_ONLY` WooCommerce purchase/payment restrictions remain unchanged in `woo-lab-isolation.php`. No legacy trial checkout, real payment or external mail was enabled.

## P0 regression protection

1. `pre_http_request` at PHP_INT_MAX must preserve every non-false preceding result: prior WP_Error, proxy/intercepted response, or other short-circuit. The test-environment guard may add a denial, but must never change an existing denial into a native HTTP request.
2. `pre_wp_mail` on Staging must deny mail if company policy or the *independent* `smtpConfigured()` certification fails, including when the previous filter returned `true`. In a permitted configuration, only `null` may pass through to native `wp_mail`; previous `true` must not be treated as a verified send.
3. A mismatch or absent host environment must fail closed before any public HTTP request. An explicit, matching Production host registers no test hooks.

## Verification

Run `php policy-contract.php` for `local`, `development`, `staging`, `production` and mismatched/unset native-host pairs in CI; pass `MOCK_RAW_ENV` and `WP_ENVIRONMENT_TYPE` explicitly. The CI workflow checks source hashes through `verify-runtime-policy.py --source`. For a governed Staging host, `verify-runtime-policy.py --runtime <actual-mu-directory>` verifies byte-for-byte disk equality. Tests never send mail, run Cron, mutate WordPress, or make outbound HTTP requests.

On ETG Staging the isolated test passed for all eight environments/conflict configurations. PHP 8.3 lint passed. The real WPML same-origin REST loopback returned HTTP 200 with both `status` and `get_parameters` valid. An upstream denied HTTP request and a preceding proxy response survived the guard. Previous mail-true and mail-null calls were rejected without actually sending email. WooCommerce clone checkout/payment locks and `DISABLE_WP_CRON=true` remained in place.

## Rollback / limits

Prior on-disk files and metadata are backed up under `/opt/staging/mad4b-environment-guard-repair-20261029/backups/`. Restore **only** the two targeted MU files after exact SHA preflight if reverting, then retest WPML. Do not revert the complete Control Plane plugin. No Production file or database was changed.

This policy does **not** provide DNS pinning, redirect-chain isolation or a strict public provider allowlist. It is a defense-in-depth private-address guard. Provider egress certification and governed Cron backlog processing require dedicated policy/runner integration and separate approvals. Do not merge the legacy PR #533 as a whole: its plugin source is older than the site runtime.
