# G4 Delivery — Forms, Commerce, Builders, Site Operations and WordPress Breadth

Status: **Repository framework implemented; provider/live/browser acceptance pending.**

This slice establishes the typed, fail-closed planning and readiness foundation for G4. It does **not** claim provider execution parity, live acceptance, mutation readiness or Production authority.

## Implemented repository foundation

- One typed provider-family catalog for CPFORMS, CPWC, CPBUILD, CPOPS and CPCORE.
- Exact reviewed provider membership per family; unknown provider/family/operation fails closed.
- Exact-version readiness projection through the existing certified provider contracts when available.
- Runtime readiness reuses the existing Adapter Registry and Plugin Discovery instead of duplicating provider scanners.
- Existing adapters such as Fluent Forms, JetFormBuilder, WooCommerce, Elementor, Polylang and LiteSpeed can surface read abilities, reversible declarations and live plugin identities while execution remains denied here.
- Installed-but-unadapted providers remain explicit as adapter gaps instead of being inferred as supported.
- Core WordPress is modeled explicitly as a core runtime, not as a fabricated plugin contract.
- Read, sensitive-read, reviewed-write-plan, high-risk and irreversible-external-effect semantics are distinct.
- Every plan is non-authorizing and reports that provider execution and mutation did not occur.

## Safety boundaries

### Forms

- Submission data is separate PII authority.
- Export/delete require explicit review.
- Deletion never claims undo.
- Config mutation requires provider serialization and exact readback.

### Commerce

- Catalog, order/customer and financial surfaces are separate.
- Order/customer reads require object-level authority and PII masking.
- Stock plans require concurrency and hook-aware readback.
- Refund/gateway work is an irreversible external/financial effect and remains manual-gated.

### Builders

- Elementor, Divi, Kadence and WordPress core are catalog data, not execution branches.
- Structural changes require native schema, revision and editor-lock checks.
- Clone plans require fresh IDs.
- Template changes require explicit template-scope and dynamic-data leakage checks.

### Site operations

- Backup, cache, migration, security and redirect operations carry independent risk descriptors.
- Restore is readiness-only here: no automatic restore and no replay of stale authority.
- Migration inspection cannot disclose installers or credentials.
- Cache purge requires blast-radius and egress bounds.
- Security changes require self-lockout denial and reviewed handoff.

### WordPress breadth

- Core object inventory remains separate from optional provider surfaces.
- WPML hierarchy plans require source language, parent identity, duplicate and translation-group evidence.
- Media egress, hierarchy cycles and foreign-field ownership are explicit gates.
- BuddyPress private messages require separate private authority.
- Events require bounded pagination and timezone semantics.

## Remaining acceptance

- Implement provider-native inventory/schema/read adapters for the admitted families.
- Implement exact provider-native plan/apply/readback where mutation is allowed.
- Reuse G3 disposable canary machinery for eligible reversible G4 writes and prove cleanup/readback.
- Add HPOS, editor-lock, provider serialization, WPML hierarchy and object-ownership runtime probes.
- Add malicious upload/URL, redirect loop, stock concurrency, hook side-effect, private-message and role-escalation fault fixtures.
- Complete exact-head child CI and governance.
- Complete representative Staging provider and browser acceptance after merge into #258.
- Production remains unauthorized.
