# MAD4B Form #6 — permanent, source-owned Rate → Stay UI contract

Reconciled on 18 November 2026 against the **live Tours.dev staging and Wayfatours Production** WordPress plugin/MU-plugin files. This is a **new, independently verified runtime snapshot**; it does not rewrite the historic `form6-native-rate-stay-20261118` snapshot or its pinned manifest.

## Decision and data ownership

1. `BookingExperience.tour_stay_rate_plan` and verified Rate ↔ Tour / Property relationships are the only source of truth for stage and property identity. The PHP accommodation policy emits `shared.form6.stay-ui.v1` for each verified stage.
2. A `simple_or` stage with **one eligible Property**, `price_policy=inquiry`, and **zero assigned nights** is a **Rate-owned, review-only stay**. Form #6 retains the linked Property, but **does not instantiate** misleading Room Type, Meals, Rooms or Price on Request controls. The main Rate UI remains the owner of the single Property.
3. A real multistage, multi-property or priced/included stay may show a traveler selector only when its first-party stay-catalog endpoint confirms a valid room/unit; zero eligible units deletes the provisional DOM row. Do not manufacture a room price or imply chargeability from an inquiry-only stage.
4. Rate, date, ages, stock, WPML relationships and prices remain validated by their existing server-side systems; this change does **not** enable Production booking/payment or modify WooCommerce orders. The client receives **only a UI presentation contract**.

## Durable delivery

- The original JS path remains the **canonical editable source**. The loader selects `presentation-fluent-quote-v1-<SHA256-prefix>.js` only when the immutable file content hash exactly matches the canonical file, otherwise fails safely to the canonical source. SHA URLs prevent Chrome's old JS from masquerading as the new policy after a page refresh.
- A host service is enabled for each company. A `systemd.path` monitors the canonical JS, PHP stay policy and loader; a five-minute `systemd.timer` reconciles missed events. The reconciler lints JS/PHP, publishes SHA-based assets atomically, checks the original inventory contract and bumps only public routes through the **existing bounded origin cache-generation socket**. It stores no credentials and writes no WP data.
- Existing public route invalidation also recognizes stay/property/room/unit/board edits and Unit / Board Type changes. This addresses stale HTML when future Rate/Property configuration changes while preserving pricing and booking authority.
- Do **not** clear global FastCGI/Redis/LiteSpeed caches or modify WooCommerce templates for this feature. Already-open browser tabs still need normal navigation/reload to load a new JS entrypoint; no system can rewrite an executed script in-place.

## Delivery boundaries

`payload/wordpress` paths are relative to `wp-content`, and `payload/host` contains the original site-specific host files. Installation to a different environment requires path, hostname, ownership and route-inventory validation. The manifest **does not authorize deployment** or override newer live code. Deploy via selective, source-first reconciliation and verify exact hashes; do not replace an entire older plugin package over Production.

## Acceptance

Run `python3 verify.py`, `node tests/stay-policy-contract.test.js`, and `php tests/stay-ui-policy.test.php` in this directory. Both test matrices cover 7 cases; the PHP test provides only an offline `absint()` stub and never boots WordPress. The PHP policy matrix also runs when `php` exists. Live acceptance previously passed on both sites at 390px and 427px after Rate 60820 → 60830 → 60820; no duplicate stay cards, visible source hotel selectors, horizontal overflow or JS exceptions. No booking submission was made. Future changes must repeat UI + server catalog + date/price readiness checks. Historic release governance and owner approval remain separate.
