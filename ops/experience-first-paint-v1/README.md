# MAD4B Experience First-Paint — reviewed release overlay

This package preserves the selective code deployed on Tours.dev Staging and Wayfatours Production on 21 November 2026. The complete mad4b-experience plugin does not exist as a tracked subtree in the inspected central WordPress Git source. This package is a pinned overlay for reconciliation, not an automatically installed plugin.

## City-image behavior
The new JavaScript scopes the MutationObserver to the Elementor home City slider widget instead of every child of document.body. It scopes Swiper discovery to the widget and disables global scroll rescans when IntersectionObserver exists. A permanent fallback timer is replaced with three bounded startup probes for modern browsers; legacy browsers retain their periodic fallback. Swiper slide navigation, AJAX-inserted cards and intent-based image warming are retained.

The PHP loader selects a content-addressed JS URL only if the exact full SHA-256 matches. Missing/altered candidate assets fall back to the unchanged legacy script. Both company live paths were verified with exact SHA; root-only backups of the old loader and legacy JS are held separately on VPS.

## Live-readback evidence
- Both PHP-FPM containers healthy; PHP syntax and Node.js syntax pass.
- After a scoped FastCGI HTML cache refresh, public EN/ES homepage HTML on both sites loads the content-addressed script.
- Chromium 1366px desktop and 390px mobile showed JS v2.3.0, visible 12/8 City cards, bounded fallback, widget observer, functional slide navigation and zero page errors on both sites.
- Cached HTML entries retired for homepage only: Wayfa 24, Tours.dev 22, with original entries retained as rollback in the individual Nginx container.
- Controlled slow-CPU Lighthouse is not conclusive proof of reduced overall TBT. Other active site JS and host CPU load changed between independent samples.

## Explicit CSS boundary
Existing Critical CSS runtimes are mobile-gated. New desktop Predictive CSS requires a fresh device/locale/template/source-CSS-SHA certificate, accepted browser performance evidence, valid company discovery digest and an unexpired policy receipt. Live CSS bundles currently do not match old certificate manifests. No desktop global async CSS was turned on by this overlay.

## Retained rollback
The original PHP loader and legacy JS remain in the secure site-specific report directory:
- /opt/mad4b-company-provisioning/reports/wayfa-desktop-render-pipeline-20261121/staging-release
- /opt/mad4b-company-provisioning/reports/wayfa-desktop-render-pipeline-20261121/wayfa-production-release

Before any future replay check exact old/new hashes, company identity, site-specific file path, JS asset availability, PHP lint, browser and booking/menu parity, then invalidate only the affected FastCGI page keys. This overlay does not authorize blind replacement of a newer live file.
