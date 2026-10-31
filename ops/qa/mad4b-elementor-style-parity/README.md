# Elementor/WPML interactive style parity — Staging

Incident: EN header source Elementor template #26212 contained correct `__globals__` color tokens for two button widgets (`c45dc14`, `4203ab4`); WPML copies in AR, FR, DE, ES and IT bound normal text and hover background to `secondary`, and desktop hover text to `primary`. The generic layout sync intentionally skipped the complete `__globals__` property, leaving different computed colors and hover states across languages.

This fix copies only a narrow allowlist of interactive visual-state references and button style controls from the original Elementor widget with the same ID and type. It never copies translated text, translated links, RTL direction/layout, media, or a widget absent in the target. It preserves an exact rollback copy per translated document, validates the write readback, invalidates the affected Elementor CSS asset, and caps changes at 35 translated documents per run. Read-only `preview` is available through WP-CLI; an hourly reconciliation and separate event-triggered post-edit scan are registered. The MAD4B Content Readiness external scanner emits high-severity `loc_style_{lang}` issues for eligible published pages/templates.

### Live Staging evidence (2026-10-31)
- 306 translated documents checked; 163 stable-ID button pairs.
- 5 translated header documents contained 25 divergent normal/hover settings across 10 buttons; all fixed via guarded WP-CLI apply and exact backups.
- Repeated preview: 0 documents/0 widgets/0 settings drift, no warnings.
- Chromium: desktop EN, AR, FR, DE, ES, IT CTA `c45dc14` now rendered normal #102D40 / #F9F7F5, hover #F6AE22 / #102D40; EN and AR mobile CTA `4203ab4` rendered the same palette in both states.
- No Production mutation. The smoke coverage is real browser sampled pages and does not imply exhaustive cross-browser/device certification.
