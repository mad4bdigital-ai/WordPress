# MAD4B Read-only Pricing Rule Visibility (20 Nov 2026)

## Problem and factual audit

The Pricing Controls page displayed Tour Types as 1/2 active, but that counter actually meant a selected targeting dimension, not an activated price rule. The nested Configure targeting & pricing policy form creates a new inactive Pricing Lab Draft instead of listing rules already applied.

Verified Wayfatours Production state:
- Pricing Engine ON; Booking OFF in inquiry-only mode.
- Dimensions saved as custom: taxonomy:tour-types and taxonomy:package_levels (revision 1).
- Pricing Lab saved strategies: 0.
- Native Booking Experience preset rows: 0, approved-enabled assignments: 0.
- Policy Bindings: 0 global, 0 term, 0 post records.
- Published Tour Rates: 0; Draft 106; Auto-draft 2.
- Therefore no active Strategy price rules are evidenced by these three inspected registries. This does not purport to disable other commercial provider sources.

## Source-neutral implementation

- Read-only MU plugin: wp-content/mu-plugins/mad4b-pricing-rules-visibility-v1.php
- UI: wp-content/mu-plugins/assets/mad4b-pricing-rules-visibility-v1.js
- Scope-protected admin AJAX: mad4b_pricing_rules_readback_v1.
- Capability required: commerce_manage_pricing and manage_woocommerce. CSRF nonce enforced.
- Never expose private pricing source fields to guests.

Uses the existing MAD4B JetEngine functional editor, without cloning any native fields or changing native save paths. Shows an evidence-backed summary by the Pricing Dimensions controller with engine, Booking, selected dimensions, saved Lab strategies, approved-enabled native rows, published Rates and Policy Bindings reference count. Within each Configure targeting & pricing policy editor, displays saved Lab strategies targeting that dimension or a grounded No saved Strategy state. The group counter is relabeled selected rather than active.

Includes loading, refresh, empty, error and permission states. Summaries and dimension annotations render as read-only content only.

The native price evaluator remains commercial authority. A selected dimension, Draft, Policy Binding reference, or staged disabled assignment cannot be treated as active live price.

## Verification

- tests/verify-readback.php: real Wayfatours source states and guest access rejection.
- tests/verify-assets.php: correct admin page, AJAX and original functional-editor dependency.
- tests/verify-future-draft-readback.php: fake saved Draft and staged rows are displayed by dimension without creating a real Policy.
- tests/verify-ui.js: deterministic DOM readback UI, native Tour Types empty state and selected label.
- Node syntax check and WP CLI runtime readbacks on Tours.dev and Wayfatours.

No Tour Rates, options, Checkout, bookings, policies, discounts or campaigns were updated. Browser tab context was unavailable from the connected Browser source during this session; automated DOM tests do not substitute for a real logged-in Edge interaction test.
