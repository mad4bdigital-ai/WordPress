# Native JetEngine SSR shadow discovery — attached to existing Presentation Browser Audit

This adds read-only diagnostic coverage to the current MAD4B Presentation
Browser Audit service/timer. NO new timer, daemon, grants, mutation endpoint,
WCML market override, plugin installation or issued certificate.

Installed read-only probe:
  /opt/mad4b-company-provisioning/reports/presentation-integrity-20261108/browser-native-listing-shadow-v1.js

Installed existing audit runner:
  /opt/mad4b-company-provisioning/reports/presentation-integrity-20261108/refresh-browser-audits.sh

The probe discovers current WPML hreflang variants and JetEngine grid widgets,
rotates up to two locales per scheduled run and observes widths 320, 390, 768
and 1920 using isolated Chromium. It records per-viewport CSS readiness,
viewport overflow, first 150 listing items, measured section dimensions,
CLS and content/link SHA256, not browser cookies or raw HTML.
The report is kept private in the existing audit folder with mode 0600.

A first real run discovered ar/de/en/es/fr/it, sampled fr/it for 8/8 HTTP200
cases with no overflow. CSS Ready 0 means native fallback for these variants;
it is NOT a CSS failure assertion. NO native Ajax parity certification is
issued, and no lazy rollout can occur based on this observational evidence.

The existing runner validates the shadow report contract, byte size,
authorization=false and status=HOLD_AJAX_PARITY_NOT_MEASURED; on failure it
records a hold and does not affect the original scheduled presentation audits.

Preimage of runner is retained in private VPS ops rollback folder.
Production Wayfatours is not mutated by the added probe. No active release
certification was created.

## Scoped due-queue browser consumer (same scheduled audit)

The original twice-daily Presentation Browser Audit now reads at most 25
current first-party due jobs from WordPress, then rechecks at most 2 per run
in native Chromium at 390px and 768px. Private/commerce routes, invalid
paths or missing immutable source tokens are rejected. It runs inside the
existing low-priority Playwright service and uses the shared WP-CLI lock.

The verified read-only result can update the original Adaptive queue to
HOLD (valid native rendering but missing certified SSR/AJAX parity) or
FAILED (HTTP/render problem), with bounded exponential backoff. The result
is committed with its exact source_token under the WordPress options-row
transaction, so concurrent source changes invalidate old worker results.
The worker NEVER calls native/certified status, never creates a certificate,
never bypasses WCML/market/Original313 policy, and never promotes lazy mode.

No new timer, account, grant, HTML cache key or WordPress REST endpoint.

Verified one actual Staging public route with Chromium 390+768: HTTP200,
no overflow/script errors, CSS ready unreported but safe native rendering.
Its first-party queue receipt was HOLD with attempts=1; other queued jobs
remained untouched. Worker is installed in the existing enabled and active
mad4b-presentation-browser-audit.timer. The original browser link, locale
and contrast audits remain in the same existing runner.

Last renderer probe source SHA:
804a0d2dd90fda50cd3ecb5b4f0d576383ac86abb1026d2bb210ca7cf93df138  /opt/mad4b-company-provisioning/worktrees/fix-adaptive-intake-20261116/ops/native-browser-shadow-20261116/browser-native-route-recheck-v1.js

The connected browser runtime is never a replacement for actual SSR/AJAX
parity proof, whose multi-market/locale/viewport issuance remains on HOLD.
