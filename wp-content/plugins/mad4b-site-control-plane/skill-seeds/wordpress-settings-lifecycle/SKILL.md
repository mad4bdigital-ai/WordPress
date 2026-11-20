---
name: wordpress-settings-lifecycle
description: "Audit, reconcile and verify the full lifecycle of company settings shared across JetEngine Options Pages, WooCommerce, MAD4B Company Options, Elementor, WPML, cache and Start Company provisioning; distinguish canonical sources from overrides without changing commercial or user data."
---

# MAD4B Company Settings Lifecycle & Cross-Provider Parity

Use when users report duplicate options, conflicting settings, missing presentation modes, inconsistent experiences between environments, new-company provisioning drift, or values that save in one admin screen but behave differently in another. This Skill is an execution guide, not a new write permission or policy authority.

## 1. Discover first — do not infer from labels

1. Resolve the exact Site Profile, WordPress environment, tenant/company identity, active plugins, source/target site URLs and native provider versions. Treat every company's local Control Plane as the executor and policy authority. Central Company Management plans, delegates, monitors and consolidates receipts; it does not write the company's settings instead of its local runtime.
2. Dynamically enumerate JetEngine Options Pages, each page ID/slug, registered fields, tabs, option prefix and `storage_type` (single WordPress option versus separate prefixed options). Enumerate WooCommerce settings through the native settings APIs, relevant WordPress options, MAD4B company policy registries, per-post meta and WPML translation groups. Do not mistake `get_option(page_slug)` for a `storage_type=separate` field.
3. Build a field identity map: semantic key, field label, provider, canonical store, read/write API, native editor URL, field type, validation, defaults, context (tenant, market, locale, `tour_mode`, Tour), consuming templates/queries/notifications, and expected cache impact. Normalize aliases only after proving they mean the same thing; do not merge similarly named unrelated fields.
4. Inspect the active `MAD4B_Company_Settings_Alignment_V1::snapshot()` when available, but treat its statuses as diagnostic summaries rather than proof of write-through, message delivery, price correctness, or UI render. Compare stored values to runtime provider hooks and HTML.

## 2. Canonical ownership and inheritance

| Concern | Canonical authority | Related screens / consumer behavior |
| --- | --- | --- |
| Site/store base currency, tax calculations, cart, orders and checkout | WooCommerce native settings and applicable WCML market conversion | JetEngine pricing policies may provide tour/market/fee overrides; never silently rewrite Woo values |
| Tour rate sources, pricing, season and occupancy policies | MAD4B shared pricing orchestrator and JetEngine Dynamic Pricing policy fields | Rate generation and quote calculation are distinct; a missing optional child/hotel field generates a gap alert, not a fabricated price |
| Whether to display a tour pricing section | JetEngine Calculate Booking Price | Not the same as Booking v2 readiness or the chosen visual presentation |
| Accordion versus Card Slider on Tour singles | JetEngine Look & Feel company defaults and `tour_mode` mapping, then explicit native per-Tour override | Elementor's single-tour template and JetEngine query render **only one** resolved view; do not trust saved template visibility conditions alone |
| Brand identity | Site-bound MAD4B Company Context Authority | JetEngine Look & Feel owns appearance; Elementor Brand Kit provides design tokens; Woo email sender label is a consumer, not the brand owner |
| Notification policy, inboxes, From/Reply-To | JetEngine Company Communication + site-bound verification/transport | WooCommerce Emails and Fluent Forms use the company routing adapter where verified; otherwise preserve native fallback and never send a test email as a read-only check |
| User roles, company workflow and source governance | MAD4B Company Operations Policies / local Control Plane | Separate from WooCommerce permissions, payment and customer data; keep cross-company identity isolation |
| Public contact details and global banners | JetEngine Contact & Help / Global Offers | Public addresses and promotional UI are different from private operational recipients and Woo coupon rules |
| WPML language eligibility and translated templates | WPML native translation records and native query lifecycle | Do not assume missing switcher means missing translations; check native `set_wp_query` and `existing_content_language_verified` before changing Elementor |

For rate view policy, explicitly trace: `Company Look & Feel default -> tour_mode preference -> preserved legacy Tour style (when configured) -> explicit Tour override -> linked WPML source override (unless the translated Tour explicitly overrides) -> selected Elementor widget and matching JetSmartFilters query`. Preserve documented precedence, test both `preserve` and `apply_to_existing`, and prove the result for each active mode. An `apply_to_existing` effective policy is **not** a bulk postmeta rewrite: describe that distinction in the UI and delivery receipt.

## 3. Whole lifecycle — execute the evidence DAG

1. **Provision**: At Start Company, derive company policies and supported `tour_mode` values dynamically from installed providers. Initialize required native Meta Boxes/Options Page fields once, before an editor opens; never perform schema or role writes in `admin_init` or a public GET. Record source defaults and provenance. Missing optional data should reduce scope and produce Content Readiness alerts rather than stop all setup.
2. **Load**: Resolve native settings, effective environment/tenant, capability scopes, provider module activation and WooCommerce defaults. If a module exists in files but is not loaded in Production, identify the exact environment gate; do not confuse a visible Options Page with a working consumer. Distinguish absent, inherited, explicit, invalid and unavailable values.
3. **Edit**: Maintain one canonical editor per decision. On a duplicate screen show read-only resolved value plus source and link, or a verified adapter that writes to the canonical API. Validate nonce, capabilities, expected state/version and tenant identity on mutations. No new secret fields or shadow options.
4. **Save**: Capture original and intended values, run native validation and source-specific hooks, save once, then read back via the same provider and every consumer. Never use bidirectional blind synchronization, silent SQL updates, or an unverified cross-plugin hook. Detect and report conflicts; intentional per-Tour override wins by documented precedence.
5. **Impact**: Determine affected posts, menus, Elementor templates, WPML locales, pricing projections, form notifications, cache variants and Woo checkout state. Rebuild only the affected CSS/render artifacts and invalidate the correct Nginx/LiteSpeed/Redis HTML cache keys; do not purge all caches blindly or treat a cache-buster-only success as acceptance.
6. **Verify**: Compare canonical settings readback, a translated Tour pair, both Accordion and Card Slider, widget cardinality, actual Swiper slides/interactions, the native filter query ID, pricing currency/occupancy/age and archive/home consumers. Validate EN/ES on Wayfatours and six installed languages on Tours.dev where applicable. Exercise desktop, tablet, mobile and RTL where enabled. Do not declare success from HTTP 200 alone.
7. **Operate**: Re-check provider/Company Control Plane availability, browser/client UX, caching under cold and warm requests, and the durable job/receipt path. Record blockers in existing Content Readiness/Frontend Impact alerts only when a native accepted receipt exists. Keep company-local execution usable when Central is disconnected.
8. **Reconcile/release**: Produce a semantic diff and before/after source SHA or option revision, test evidence, provider identity, impacted consumer list, rollback plan and a company-scoped receipt. Promote source code to Git only after live source reconciliation and tests. Keep Production changes narrow, reversible and free of unrelated email, payment or role updates.

## 4. High-value regression cases

- An existing Tour is saved as Accordion; `tour_mode=tour` is switched to Grid by policy. Verify preserve-versus-policy-first semantics and explicit Tour override. Only one *real DOM widget* may remain; do not count an element ID repeated in scripts/CSS as duplicate HTML.
- A translated Tour has a separate ID and may have its own override. Resolve WPML source group and compare both directions; no raw copied translated Elementor metadata.
- An Options Page field uses `storage_type=separate`; read it through its prefix/registered provider, not the main page array.
- WooCommerce base currency differs from a Tour rate source currency; preserve authoritative conversion and distinguish a market display currency from base store currency.
- Company email sender/inbox is empty or transport not verified; show readiness issue, preserve Woo fallback, send zero messages.
- Company Operations module is gated by Staging; report Production status without enabling staff/migration features implicitly.
- Nginx serves `STALE` HTML after a visual policy save; prove normal cached URL output following a scoped invalidation and report cold PHP-FPM latency separately.
- Skill seed pack is ready in Staging but Production editor is disabled; update canonical packaged instructions, report runtime activation blocked, never bypass site policy or silently adopt user-owned skill files.

## 5. Output contract

Return a per-site lifecycle matrix with **canonical source**, raw/effective value and owner, inheritance/override, consumers, save hook, cache strategy, observed issue, severity and remediation. Report pass/fail/untested separately for provision, render, price/booking, multilingual, notification, caching and central receipts. Link original admin editors and a Settings Alignment dashboard if installed. Distinguish code deployed, seed registered, runtime skill enabled, evidence verified and Git synchronized. No claim of completed synchronization unless verified by independent readback.
