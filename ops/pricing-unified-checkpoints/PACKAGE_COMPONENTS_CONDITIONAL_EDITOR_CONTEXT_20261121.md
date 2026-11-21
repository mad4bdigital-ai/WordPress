# Package Components — Conditional Tour Editor Context v1

Date: 2026-11-21.

## Decision

Implement a **conditional, read-only WordPress editor guidance panel** for Tours whose canonical `tour-types` taxonomy contains **`packages`**. This is **NOT** a permanent JetEngine tab, a duplicated Rate inventory, a new JetEngine Relation, an editable meta-box, or a second source for supplier costs. The visual context lives in the existing WordPress Tour edit screen, and links out to original JetEngine and provider editors.

For a brand-new Tour without an already-saved Package taxonomy term, the contextual panel is deliberately absent: classify as Packages and save the Tour draft first. Never infer a Package from the title or from `tour_mode=tour`.

## Data owners and contextual steps

1. **Itinerary & Guide Days:** native Tour or Program itinerary + existing `MAD4B · Guide Service Cost Days` editor and Guide Days Bulk Review. Source of truth: `MAD4B_Guide_Service_Composition_V1::source/saved`. Guide cost is per guide/day; do not re-add existing retail Rate costs. The contextual panel only shows reviewed vs unknown day counts.
2. **Accommodation:** read original `_mad4b_package_stay_verified_room_program_links_v1` as editorial composition evidence, and count existing native Tour Rate `_booking_experience_stay_stages_v1` rows. The actual Property/cabin selection and pricing are edited only in Tour Rates and Properties; an editorial stage does not imply an approved supplier cost.
3. **Transportation:** read native `_mad4b_transport_stages_v1` repeater **on existing Tour Rates only**, linked by scalar `related_tour_id`. Transport Rate editor remains the original save location. If none, show “Transport stages not yet assigned,” without inventing route/vehicle costs.
4. **Cruise program & cabin:** **conditionally displayed** only if a serialized `lodging_kind=cruise` stage is present in original package stay bindings or the Tour is explicitly `cruise_tour`. No inference from title, destination, marketing copy, or an arbitrary tour keyword. If absent, no Cruise UI appears.
5. **Cost & financial readiness:** links to the existing Dynamic Pricing Controls where authorized, with a clear **not evaluated / not certified by this panel** label. Never creates totals, emits sellable quotes, overrides policy, or approves checkout.

The **`related_tour_id` Tour Rate scalar postmeta** remains the only authoritative stored Rate–Tour association; `tour_queried_rates` (existing Query 313) is preserved as the **one parent Tour Rate inventory UI**. The context panel doesn't reproduce that inventory or generate a second list of Rate IDs; it provides a shortcut to the first linked Rate, clearly labeled as such, with instructions to select the correct tier from the original Rate inventory.

No new fields are registered in the JetEngine CPT, no meta updates happen in this panel, and no WPML-translated review evidence is stored separately: it reads canonical English source guide/stay evidence and the current Tour's native linked Rates.

## Existing panels and duplicate avoidance

On a Package Tour with `tour_mode != cruise_tour`, the previously global **Nile Program First** and **Nile Cruise Ready to Publish** meta-boxes are hidden **only in the editor UI**. This removes misleading advice to change Package Tour mode to standalone Cruise Tour. Their original plugin providers and publication guards remain active, and standalone Nile Cruise Tour editing retains both original panels. The Guide Days original editor remains in place and is linked (not cloned). Existing **Extras & Transfers**, Rate display, and Pricing panels remain untouched.

## Implementation

- MU plugin: `wp-content/mu-plugins/mad4b-package-components-editor-context-v1.php`
- Admin-only CSS: `wp-content/mu-plugins/assets/mad4b-package-components-editor-context-v1.css`
- Re-runnable WP-CLI smoke: `ops/pricing-unified-checkpoints/tests/verify-package-components-context-live.php`

**LIVE** on `https://tours.dev.mad4b.com` and `https://wayfatours.com`: the MU plugin and CSS files were copied to the live WordPress MU-plugin directories. No WordPress core or commercial pricing code was changed.

Verified on both live WordPress sites:
- PHP lint PASS, MU plugin loader reports class available, CSS readable.
- True Packages editor: original contextual box registered and rendered read-only with original edit links; misleading standalone cruise meta-boxes suppressed in Package `tour_mode=tour`.
- Day Tour / standalone Cruise Tour: contextual box not registered and renders no output.
- Source-stage/term-specific Cruise visibility: on Wayfatours, **7/8 Package Tours show the Cruise section, 1/8 does not**, exactly matching recorded package stay bindings. On Tours.dev examples without verified cruise stay records, no Cruise section is inferred.
- WPML translation: source/canonical guide and stay authority reused, while the translated Tour keeps its own `related_tour_id` Rate identity.
- **Wayfatours actual counts** for these 8 Packages: 66 Guide days unknown/unreviewed, 28 editorial accommodation stages, 32 linked native Rates, zero saved native transport stages. These are editorial input state, NOT a completion or supplier cost approval.
- WP-CLI read-only smoke tests: **20/20 PASS on Tours.dev**, **31/31 PASS on Wayfatours**. The smoke probes both meta-box registration and rendered HTML (no input/select/textarea/form fields), the original Guide and Rate links, and absence of cost authorization.
- Draft/editor changes have no Rate save/automatic creation, no new meta or relation, no quoting or checkout. Involved file writes are plugin code/CSS only, not database meta.
- The content is not injected into the front-end website: only the WordPress admin Tour editor registers this meta-box.

### Remaining acceptance

An **authenticated browser UI visual and actual native form Save** on an editor session has not been verified by this change. No such success is claimed. The source files are also committed in Draft PR #987 for controlled reconciliation; PR state is separate from the manual live file install. WP release gates are not bypassed in the logic of this panel because it has no commercial write actions.
