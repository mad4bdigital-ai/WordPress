# MAD4B automatic Template-Aware CSS policy — 18 November 2026

## Active policy is not a browser test verdict

The original Production/Source WordPress public-CSS policy and LiteSpeed/Elementor
source adapters remain active independently of browser acceptance. This release
makes its source-group/variant registry and cheap schedule **event-driven and
always on**. Neither a missing Chromium verifier, failed FCP/LCP/CLS visual
comparison nor a temporarily busy shared browser lock disables this policy.

The original route-specific Critical CSS publication remains an independent
asset-integrity boundary. No unsampled tour, WPML page, market, currency or
signed/private visitor can inherit another route's compressed CSS or certificate.
That is a production HTML trust guarantee, not permission from QA to choose a
policy. We do not claim that every public route already has an optimized CSS
certificate.

## What is now automatic

- Compute source families from first-party WordPress+Elementor+WPML graph,
  language and WooCommerce/WCML context. Preserve original event generations.
- When two genuinely certified canaries in one shared template reveal different
  actual Elementor DOMs, split their cohort automatically by exact observed
  document SHA and market/currency. No more freezing the entire shared template
  with `source_variants_need_split`.
- Discover the other group members incrementally from **anonymous, first-party
  200 HTML**, matching the precise WordPress queried object and local LiteSpeed
  CSS source bytes. Sample only 4 routes per worker cycle (8 per existing
  twice-hourly source-registry refresh), with a rotating cursor so a 404 or
  timeout cannot indefinitely starve other routes. Skip already browser-proven
  source canaries entirely.
- Private observation records contain SHA-256 digests, original route identity
  and server-resolved market/currency, not HTML bodies, cookie/session data or
  payment information. They become stale when the original route dependency
  fingerprint changes or at the 24h light-integrity horizon.
- If a source is unmapped, report `source_variant_mapping_pending`; this is
  low-cost discovery work, **not a failed QA gate** and never a reason to
  disable template or business policy.
- A missing/malformed optional Chromium verifier is reported as an observation
  problem and can defer expensive browser work, but the source-family report
  and native template policy remain active.
- Protect the shared private observation index with its own per-company
  non-blocking file lock and atomic writes. The original Resource CPU guard
  and cross-company Chromium lock continue protecting the host.

## Visual and interaction checks are monitoring only

An independent systemd timer invokes `post-policy-visual-watch.py` daily
at 03:41 UTC (staggered). It takes one bounded, original-WordPress public source
route per company and observes Pixel-width 390 and tablet 768:
- visible body/H1, horizontal overflow, CSS availability/failures,
  FCP/LCP/CLS, JS errors, safely opening the visible mobile menu,
  Escape key, and focusing a visible search/select control;
- no booking, payment, form submission or user preference mutation;
- private per-company JSON quality receipts with alerts.

Its failure/timeout or quality warnings **never call** WordPress, the public
CSS issuer, any settings mutator, source-family eligibility, or Git release
approval. QA reports are independent operational feedback. The observer uses
the original browser global lock and CPU budget, so it skips a busy window
rather than slowing travel booking traffic.

## Safety and rollback

Native WPML language routing, WCML signed/user-specific context, dynamic
booking forms and FastCGI private response isolation remain unchanged.
Missing CSS/market/source-integrity proof does not create a fake public
critical-CSS certificate. Original valid certificates are not rewritten
or invalidated by this scheduler-only upgrade.

Live runner and registry scripts can be restored individually from the
versioned backups recorded during rollout. The new visual timer is
independently disabled with `systemctl disable --now
mad4b-post-policy-visual-watch.timer` without affecting the always-on
template policy, original source workers or certified visitor assets.

Source regression cases: 23 template/variant safety, 40 original
source-family assertions, 13 source-refresh, 14 visual-monitor
independence checks. These code sanity checks do not control the ongoing
policy or certification once the software is deployed.
