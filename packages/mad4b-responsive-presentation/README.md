# Responsive archive and tour-rate presentation

Brand-neutral, Staging-first WordPress must-use plugin. Source-controlled independently of the frozen shared-product-core package and its signed checksum manifest.

## Scope

- Responsive search, filter, sort and Grid/List flow in existing Elementor destination tour archive template `45804`.
- Wider content with reduced nested padding for the targeted tour, blog and destination pages. Preserve Astra headers and footers (do not use Elementor Canvas).
- Accordion presentation `57381` inherits canonical Tour Rate listing `46193`. Only CSS parity is applied; price, inventory, eligibility and selection actions remain in the shared core.

## QA / rollout

Test widths 320, 375, 390, 768, 1024, 1440. Check search/sort/switcher geometry and no horizontal scrolling; open Accordion, select/deselect rate, switch Grid/List, and verify source prices. The PHP file is gated on `MAD4B_COMMERCE_RUNTIME_ENABLED` and checks matching WordPress route types. Do not deploy to Production without separate authorization.

Source deployment destination: `wp-content/mu-plugins/mad4b-responsive-presentation.php` in an explicitly selected tenant. Changes to Elementor JSON/database are deliberately avoided.

## 2026-10-29 canvas and Brand Kit extension

- On public pages authored through Elementor (`body[class*=elementor-page-]`), the Astra **content container** expands to viewport width. Elementor authored inner container limits remain intact; this is *Full Width*, **not Elementor Canvas**. Header/footer remain unchanged.
- Archive H1 stays visible, semantic and single, with centered alignment, responsive size and contrasting `--e-global-color-primary` (no `display:none`, duplicate text or SEO tag edits).
- Jet Search/Check Availability submit and apply actions use `--e-global-color-accent` for backgrounds and `--e-global-color-primary` for text, with dynamic per-company Elementor Kit inheritance and keyboard focus treatment.
- Verify 390px mobile and 1694px desktop, Blog/Tours archive, single tour, destination, H1 count, and contrast before Production rollout.
