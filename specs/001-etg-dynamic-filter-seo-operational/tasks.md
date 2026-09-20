# Tasks — Generic Operationalization

## Completed baseline hardening
- [x] T001 Default global bridge disabled.
- [x] T002 Hard-deny and soft-policy separation.
- [x] T003 Exact JetEngine filtered result-count authority.
- [x] T004 Exact combination authority and content readiness.
- [x] T005 WPML/canonical/query-state/runtime readiness hardening.
- [x] T006 Exact-head CI and deterministic `0.3.0-alpha.1` package provenance on `d033e60b…`.

## Generic profile tranche
- [x] T070 Add bounded `profiles_json` authority.
- [x] T071 Add normalized `ProfileRegistry` and duplicate-ID detection.
- [x] T072 Add exact Unicode/WPML-aware archive path authority; reject arbitrary suffix matches.
- [x] T073 Add exact provider/query route pairs and restrict legacy Cartesian fallback to inherited profile.
- [x] T074 Add Query-Builder-first `PostTypeObserver` with `query_builder|main_query|either|both` modes.
- [x] T075 Reject missing/non-post/`any`/mixed-foreign Post Type authority.
- [x] T076 Add runtime Post Type/taxonomy relation readiness checks.
- [x] T077 Replace travel-only shape policy with generic taxonomy-set authority.
- [x] T078 Add per-taxonomy roles, priorities, single-index permission and thresholds.
- [x] T079 Add optional taxonomy term-meta constraints.
- [x] T080 Add per-taxonomy canonical field maps and multi-gallery aggregation.
- [x] T081 Make exact combination registry profile-aware while retaining migrated Tours legacy signatures.
- [x] T082 Add profile-specific content/canonical policies and deduplicated content corpus.
- [x] T083 Make content-readiness extension veto-only.
- [x] T084 Generalize content/gallery/breadcrumb composition while retaining `travel` mode.
- [x] T085 Add identity guards preventing metadata/shortcode mutation on invalid provider/Post Type/translation state.
- [x] T086 Add read-only Post Type/taxonomy discovery.
- [x] T087 Add disabled, non-authorizing Profile Blueprint generation.
- [x] T088 Add non-mutating Synthetic Scenario Lab.
- [x] T089 Add realistic multi-profile simulations and configuration-failure scenarios.
- [x] T090 Preserve previous profile authority on invalid/oversized JSON; new profiles default disabled.
- [x] T091 Re-normalize config/profile extension filter output.
- [x] T092 Expand Spec Kit with profile/Post-Type/taxonomy/field-map/blueprint/growth/simulation contracts.
- [x] T093 Add bounded read-only runtime inventory collector/export contract for T120–T123 evidence.
- [x] T094 Add public drift objections for taxonomy slug, route ID, URL grammar, language inventory and mixed Post Types.

## Exact-head publication gates
- [x] T100 Fold generic tranche into one clean operational commit above MVP; remove temporary probe/staging history from PR head.
- [x] T101 Keep PR #1 Draft and maintain exact-head title/body through operational-alpha publication.
- [x] T102 Run PHP 7.4 + 8.3 exact-head CI with all eight+ smoke/simulation suites.
- [x] T103 Confirm vendor compatibility including Query Builder Posts Query `get_query_type/get_query_args` surface.
- [x] T104 Confirm final diff only allowed paths and no vendor/core changes.
- [x] T105 Capture deterministic `0.4.0-alpha.1` ZIP + exact-head provenance.
- [x] T106 Re-squash runtime-inventory tranche into the single operational commit and keep PR Draft.
- [x] T107 Run PHP 7.4 + 8.3 exact-head CI including runtime-inventory smoke coverage.
- [x] T108 Re-probe bundled Query Builder `get_queries()` inventory authority on exact vendor blob.
- [x] T109 Capture deterministic `0.4.0-alpha.2` ZIP + exact-head provenance.

## Inventory reconciliation / controlled growth tranche
- [x] T110 Add bounded `InventoryReconciler` with non-authorizing drift classification.
- [x] T111 Recompute/verify runtime-inventory fingerprints and reject oversized or tampered snapshots.
- [x] T112 Generate disabled candidate profiles with empty archive/routes/taxonomy-set/combination authorities.
- [x] T113 Add read-only admin reconciliation export and WP-CLI inventory/reconcile commands.
- [x] T114 Add realistic reconciliation smoke coverage for missing taxonomy/query, unbounded/mixed Post Types, language/archive drift, invalid previous snapshots and candidate safety.
- [x] T115 Fold alpha3 reconciliation tranche into the single operational commit above MVP.
- [x] T116 Run PHP 7.4 + 8.3 exact-head CI including reconciliation suite.
- [x] T117 Reconfirm final change boundary and absence of transport/probe artifacts.
- [x] T118 Capture deterministic `0.4.0-alpha.3` ZIP + exact-head provenance.
- [x] T119 Update PR #1 exact-head metadata while keeping Draft/merge lock.


## Inventory completeness / identity collision hardening
- [x] T130 Add explicit completeness metadata for every bounded runtime inventory section.
- [x] T131 Sort Query Builder identities before bounded slicing and preserve deterministic fingerprints across source-order changes.
- [x] T132 Detect duplicate effective Query Builder identities and exclude collided IDs from exact route authority.
- [x] T133 Replace false missing/removal conclusions with incomplete-inventory findings when evidence is truncated.
- [x] T134 Suppress disabled candidate generation for truncated, unavailable, or identity-ambiguous inventory.
- [x] T135 Add regression coverage for query overflow, collision, unavailable inventory, comparison skipping, and completeness tampering.
- [x] T136 Publish `0.4.0-alpha.4` as one clean operational commit above MVP with exact-head CI/provenance.

## Staging profile acceptance
- [ ] T120 Inventory actual Post Types, taxonomies and WordPress relations.
- [ ] T121 Build disabled candidate profiles from discovery; no automatic authorization.
- [ ] T122 Bind actual translated archive paths and exact provider/query routes.
- [ ] T123 Prove Query Builder Post Type authority for each profile.
- [ ] T124 Validate term field maps, ACF/native meta and multi-gallery sources.
- [ ] T125 Validate representative exact combinations and taxonomy meta business rules.
- [ ] T126 Prove filtered count equals rendered listing count per representative profile URL.
- [ ] T127 Run objection matrix against real staging URLs including EN/AR/IT and Unicode paths.
- [ ] T128 Inspect rendered title/description/canonical/robots/OG/shortcodes/breadcrumb and preserve vendor hreflang ownership.
- [ ] T129 Confirm no PHP warnings/notices and rehearse per-profile disable + global kill-switch rollback.

## Later scaling
- [ ] T150 Design persistent/importable registry if exact combination volume exceeds bounded Alpha configuration.
- [ ] T151 Design cache/invalidation after correctness evidence.
- [ ] T152 Sitemap/indexable URL registry as separate governed feature.
- [ ] T153 Native Elementor Dynamic Tags as separate governed feature.
- [ ] T154 Clean URL migration as separate feature/spec.

## Merge gate
- [ ] T190 Produce RC handoff with exact SHA, artifact digest, staging evidence and limitations.
- [ ] T191 Obtain explicit user authorization before merge to `master`.
