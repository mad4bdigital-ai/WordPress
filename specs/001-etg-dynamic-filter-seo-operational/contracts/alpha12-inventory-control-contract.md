# Alpha12 Inventory Control Contract

Version target: `0.4.0-alpha.12`.

## Purpose

Alpha12 turns Runtime Inventory into a governed operator control plane without turning discovery into indexing authority.

## Required behavior

1. Runtime Inventory may build an `etg.dfsb.inventory-profile-plan.v1` proposal for an existing Surface Profile.
2. A proposal is always `authorizing=false`, `read_only=true`, `profile_mutation=false`, and requires operator review.
3. Provider Query ID and JetEngine Query Builder custom Query ID remain separate namespaces. Internal numeric Query Builder IDs remain evidence only.
4. A structural proposal requires a complete Query Builder identity index and may fill an empty `post_types` list only when every governed route resolves uniquely to the same bounded `posts` Query Builder Post Type set.
5. A structural proposal may enforce `require_post_type_binding=true`, `post_type_authority=query_builder`, and persist explicit `provider_query_id` / `query_builder_query_id` route evidence.
6. Existing non-empty Post Type authority that conflicts with runtime evidence is never overwritten automatically; the proposal is blocked.
7. Unrelated Query Builder identity collisions remain visible evidence but do not block a uniquely resolved profile route.
8. Applying a proposal is allowed only for an administrator while Global is OFF and only when both the configuration revision and Runtime Inventory fingerprint still match the reviewed proposal.
9. Applying a proposal always forces the affected profile disabled and keeps the Global bridge OFF.
10. Any structural authority change invalidates Elementor-content, provider-observation, and result-count-parity publication verification evidence.
11. Runtime-attached taxonomies may be offered as explicit operator opt-ins for dynamic content. Adding one creates a taxonomy rule with `index_single=false` but never adds `allowed_taxonomy_sets` or `indexable_combinations`.
12. Dynamic Content Slots continue to consume the profile/Inventory token catalog through Elementor Dynamic Tags, shortcodes, and PHP API. Inventory taxonomy opt-in expands presentation capability only after explicit operator selection.
13. No planner or control action may enable sitemap publication, canonical authority, hreflang/schema publication, a profile, Global, or an exact indexing combination.
14. All writes remain reversible through the authoritative Surface Profiles JSON and are followed by fresh Reconciliation before activation.

## Acceptance

- PHP 7.4 and PHP 8.3 lint + existing smoke suites pass.
- Alpha12 smoke proves the Production-like `tours_query_archive -> Query Builder 5 -> tours-and-activities` case.
- Alpha12 smoke proves `durations_jet` can be offered as opt-in content structure without adding an indexing set.
- Governance statically verifies Global-OFF locking, inventory/config concurrency guards, forced disabled profile state, explicit route namespaces, and stale publication-evidence invalidation.
- `merge_authorized=false` and `production_activation_authorized=false` remain in force.
