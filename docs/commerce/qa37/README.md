# QA37 — Commerce Qualification Rate Eligibility (ETG Woo Lab)

**Executed on 2026-10-28; scope:** VPS container `etg-woo-lab-php`, DB `etg_woocommerce_lab`, WordPress environment `local`, `SHARED_PRODUCT_LAB_ONLY=true`. **No Production or public ETG Staging deployment.**

## Actual deployed code and ownership

Canonical QA-specific Commerce Composition worktree currently lives at:
`/opt/commerce-components/composition/includes/InquiryQualification.php`

Installed Lab plugin copy:
`/opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/site-copy/wp-content/plugins/commerce-composition/includes/InquiryQualification.php`

The Commerce Composition plugin is not yet represented as a complete, owned, deployable source tree in this WordPress repository; accordingly, the reviewed **exact patch** is attached as `InquiryQualification.rate-eligibility.patch`, not masquerading as an installable WordPress plugin. Source onboarding and a separately certified deployment process are required before distributing it to other sites. Do not treat this docs patch as an automated deployment.

**Before SHA256:** `b1fef8b0daac486712f28a19a353ea328e6fa5bc8c2725b767365eba15d4be63`

**After SHA256 (source and installed Lab copy identical):** `f9e5e81cdcd88f21443dc860f2cb2c853ff2bdfe8cad8f67495ef4fdafe3e2f7`

Rollback backup, actual PHP source, WP-CLI test and results:
`/opt/commerce-components/evidence/etg-qa37-wpcli-20261028/`

## WP-CLI verification (executed, not simulated)

Used an ephemeral, official WP-CLI Phar (`/tmp/wp-cli-qa37.phar`) inside the Lab PHP container:

```bash
docker exec -u www-data etg-woo-lab-php php /tmp/wp-cli-qa37.phar --path=/var/www/html eval-file /tmp/qa37-runtime-wpcli.php
```

**PASS: 15/15** including valid USD/EUR Rate, Stop Sale denial, foreign Rate denial, missing season denial, outside date window, impossible calendar date, inactive tour denial, and a real Fluent Form #8 submission denied **before** any immutable Qualification claim.

Readback after the test: zero QA claims, 9 original Commerce Requests, 3 native FluentBoards tasks, one pre-existing Woo Order; none created or changed by the test.

## Price and operational limitations

- Rate checks now run before the immutable operator claim and again on preview.
- These checks do **not** certify supplier inventory, age/child/infant policy, availability hold, taxes/fees, supplier cost, margin, final commercial quote or active payment.
- Form #7 multi-service programs and child/infant quotes remain `Requires Review`.
- No manager-approved final Quote or traveler acceptance contract is certified; no Woo order or supplier booking was authorized.
- A previously accepted invalid Qualification needs a separately approved audit/supersede transaction, never a silent DB update.
- The pre-existing Webhook Gateway site-bound hash differs from the Lab's current `siteurl`; do not silently rotate/rebind its secret.
- Existing failed Workflow job and unassigned tasks require provenance-backed operational reconciliation, not blind retry or assignment.

## GitHub release and governance

PR #534 covers **MAD4B Skills** and governance guidance only. Seed version expectations were corrected to version 15 (Brand Context still enforces minimum compatibility 13). The Production Readiness coverage change is **CI-only**, source-bound and does not authorize Production promotion.

A separate release decision is required for Commerce Composition source ownership and runtime CI coverage in the correct plugin repository. Release gates remain closed until exact-head provider certification, financial contracts, traveler acceptance, and real site-bound Staging QA are recorded.
