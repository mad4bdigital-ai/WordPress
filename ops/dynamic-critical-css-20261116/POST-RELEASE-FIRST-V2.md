# Critical CSS v2 — release first, then test

Policy: A matching current WordPress route, locale/market/currency, original
ordered CSS sources and actual SHA-256 critical assets are sufficient to issue
a provisional public v2 certificate. No browser performance test or
five-viewport parity report can delay initial issuance.

## Exact lifecycle

1. Source-only build + immutable checks generate candidate slots. These are
   not browser performance QA; they prevent publishing tampered or cross-market CSS.
2. The certificate publisher in release mode issues status=released_pending_qa
   BEFORE five-viewport QA, with a fixed six-hour provisional lease.
3. WordPress 422 plus the companion serves a matching provisional certificate
   only for verified anonymous/mobile context, without shared cache:
   X-MAD4B-Public-CCSS-Phase: pending-qa;
   X-MAD4B-Public-CCSS-Ready: 0;
   Cache-Control: private, no-store;
   X-Accel-Expires: 0.
4. Full browser QA runs AFTER publication: cascade parity, FCP, LCP, CLS,
   original CSS source identity, and booking interaction.
5. The verify action atomically promotes the same source-bound cert to certified
   if fresh post-release evidence passes. A failed run triggers the revert action,
   which changes status to reverted_post_release_qa; this falls back to Native CSS.
6. Missing QA remains provisionally uncacheable, then expires after six hours
   without granting shared HTML cache. It never silently becomes certified.

## Existing first-party worker mode

MAD4B_MULTI_CSS_V2_RELEASE_FIRST=1
MAD4B_MULTI_CSS_V2_PUBLISH=1

The original exact source/runtime release identity remains required, but
performance reports are not issuance gates. After source publication succeeds,
post-release QA is attempted in the same site-owned task with existing CPU and
Chromium locks. The old mode remains compatible for other sites.

Deploy 422+companion before enabling this mode. The exact market-bound canary
should be measured after release; mismatched HTML/CSS/locale/currency always
fails closed to Native CSS. Never claim optimized delivery solely from HIT.

## Live Wayfatours Cron reconciliation (20 November 2026)

The company-owned Wayfatours renderer uses exact native 421/422 v2 SHA
admission and first-party source-bound CSS. Explicit release-first flags
are enrolled ONLY for Wayfa public tasks. Tours.dev is not enabled.

The immutable runner MUST include the matching original
tools/assemble.js, which exports assemble safely and executes the legacy
CLI only when run as its main script. Copying capture-ordered-critical.js
without that companion causes ENOENT /out/capture-index.json before a
provisional certificate can be issued. A Node module import test now
checks this dependency without running browser performance tests.

Source-bound v2 certificates publish with a bounded pending lease.
After publication, the five-viewport post-release QA and fresh native
WordPress readback promote or revert. If public HTML proof fails AFTER
promotion, the separate revoke action atomically disables the
certificate, then Native CSS fallback applies.

Public job timeout is 1250 seconds, inside the existing 1380s host wake
budget. Chrome 1.9GB/1CPU/256MB SHM is scoped ONLY to Wayfa ordered-CSS
scripts; source company and legacy scripts retain the original limits.

Direct production operation receipts and preimage backups:
 /var/lib/mad4b/company-code-release/receipts/wayfa-css-v2-cron-autopublish-20261120/
Company-owned renderer identity was read back as
 e3cd64e0ea1a1b55f608ec2e14bcf25c77ec34869e4abcaf07e81ad1b3a0088d.

Independent outstanding issue: original Home CSS MU plugin 415 has
unexpected production SHA 60fc91116aff versus historical audit expected
cdb55bd9. Do not whitelist it or overwrite it without first-party
source parity and a separately tested home release.
