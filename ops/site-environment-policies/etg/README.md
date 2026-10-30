# MAD4B ETG environment policy — 2026-10-28

This is a **site-specific runtime MU policy**, not an automatically distributable WordPress plugin. Publish to each controlled site only after exact environment/target identity checks.

## Purpose

The original The legacy `000-sandbox-guard.php` blocked all HTTP outside `local`, breaking WPML on `staging`. After its first repair, the guard mistakenly applied only to `local`; this corrective revision activates its private/metadata-destination restrictions in **both `local` and `staging`** while leaving native Production networking unchanged. It also added global `pre_wp_mail` and `cron_request` filters. A second MU file, `woo-lab-isolation.php`, independently suppressed cron even in staging; it also contains important safeguards preventing purchases and payments on the clone.

## Policy

- **Local:** retain the original private/metadata-destination filtering with guarded local exceptions; deny mail and automatic Cron. In the lab clone, keep WooCommerce purchase and payment restrictions.
- **Staging:** register the same sandbox HTTP destination guard as Local, rejecting private/metadata destinations and malformed/userinfo URLs but permitting valid public destinations, including the canonical first-party HTTPS WPML REST loopback. Do not register the old unconditional HTTP deny. Keep the clone purchase and payment restrictions. A separate `005-mad4b-staging-mail-admission.php` admits WordPress mail only when both MAD4B Company Communication approves sending and native Fluent SMTP configuration is present. An unapproved mail call returns `false`, not a fake success. No SMTP connection or email was created by this patch.
- **Production:** the test-only HTTP guard and Staging mail policy register no hooks, thus native WordPress HTTP, Cron, and mail policies apply. The identified legacy `egypttourgates.com` Production public_html did not contain the sandbox guard; nothing was changed there.

## Files

- `000-sandbox-guard.php` — sandbox destination guard registered in Local and Staging; no restrictions registered in Production. Local-only AI transport exceptions remain scoped to Local.
- `woo-lab-isolation.php` — moves only email/Cron suppression to local; keeps clone purchase/payment restrictions.
- `005-mad4b-staging-mail-admission.php` — independent Staging email admission.
- `policy-contract.php` — hermetic contract checking local, staging and production hook registrations, and verifying that Local and Staging reject private/credential-bearing URLs while allowing public routable destinations, without real network/mailer/cron execution.

## Verified Staging (`tours.dev.mad4b.com` / `etg_commerce_staging`)

- With the Staging HTTP guard active, WordPress WPML `wp_remote_get()` loopback returned HTTP 200 with `success=true`, `data.status=valid` and `data.get_parameters=valid`.
- Public WPML REST endpoint and homepage HTTP 200.
- `cron_request` policy filters are absent on Staging, but `DISABLE_WP_CRON=true` continues to prevent automatic WordPress Cron dispatch; scheduled work must use a separately approved bounded runner.
- `pre_wp_mail` dry-run policy check returned `false` with Fluent SMTP unconfigured, and MAD4B email dispatch remains HTTP 423; no mail sent.
- WooCommerce purchase/payment clone restrictions remain registered.
- Existing `mad4b-commerce-staging-content-readiness.timer` stays active. **Important:** site `DISABLE_WP_CRON=true` remains unchanged, and roughly 29 WordPress scheduled hook types were overdue at assessment. Do not turn on native traffic-triggered WP-Cron or run all due jobs without a separate bounded scheduler plan and approval; this patch removes MU interference but does not certify/replay the backlog.
- No Production changes, no WordPress site database changes, no customer-facing email, no task executions. **This HTTP guard is not a complete public-internet egress ban:** the existing Local policy permits validated public destinations. A strict external allowlist, if required, needs a separate compatibility review of the site integrations.

## Rollback (Staging only)

Original files backed up on VPS under `/opt/staging/mad4b-environment-sandbox-policy-20261028/backups/` with original metadata. Restore these to the Staging MU directory and remove only newly introduced `005-mad4b-staging-mail-admission.php`. Verify SHA checks and rerun the same local WPML policy test. Restoring the immediate preceding version would turn off the Staging HTTP destination sandbox; restoring the initial version would also reintroduce the WPML loopback defect.

## Release safety

The site may be concurrently updated through a different Control Plane release. These MU overlays are intentionally separate from Control Plane `rc.114`; reconcile with site-neutral deployment tooling before promotion. Do not auto-copy local sandbox policies to other companies.
