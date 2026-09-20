# ETG Dynamic Filter SEO Bridge Constitution

## Scope
This constitution governs the ETG Dynamic Filter SEO Bridge plugin, its tests, dedicated CI, and `specs/001-etg-dynamic-filter-seo-operational/**`. It does not authorize edits to WordPress core or vendor plugins.

## Non-Negotiable Principles

### I. Vendor immutability
Elementor, JetEngine, JetSmartFilters, Rank Math, WPML, ACF, and other third-party packages are external authorities. Integration MUST use runtime APIs/hooks/adapters. Vendor source MUST NOT be patched.

### II. Discovery is not authorization
Discovering a Post Type, taxonomy, route, term, traffic pattern, or search demand MUST NOT create or enable a profile, taxonomy shape, exact combination, canonical policy, or index permission. Generated profile blueprints MUST start disabled and non-authorizing.

### III. Profile isolation
A Surface Profile is the smallest SEO-governance authority. Archive path, exact provider+query route, Post Type authority, taxonomy rules and structural taxonomy sets MUST remain profile-bound. Cross-profile provider/query/taxonomy/Post Type bleed is a hard deny.

### IV. Hard fail-closed indexing
Unsupported URL state, malformed filters, unknown or foreign taxonomies, invalid profile state, runtime degradation, provider/query mismatch, Post Type mismatch/unobserved authority, unapproved taxonomy sets/combinations, required term-meta failure, translation fallback, content failure, zero results, or missing authoritative result count are HARD DENIES. Ordinary soft policy hooks MUST NOT promote them to `index`.

### V. Monotonic authority
Extensions may narrow safety decisions but MUST NOT silently widen hard authority. Configuration and profile extension filters are re-normalized. Content-readiness extensions may veto a ready page but may not promote a failed base content check.

### VI. Positive evidence before index
Indexability requires every applicable authority: exact profile selection, runtime readiness, provider/query identity, Post Type identity when required, term/translation authority, taxonomy-set authority, taxonomy meta authority, exact combination authority when required, content readiness, authoritative filtered result count, and threshold policy. Result count alone is never an index permission.

### VII. Exact bounded scope
New non-legacy profiles require exact archive-path authorities and exact provider+query route pairs. Arbitrary suffix archive matching and provider/query Cartesian fallback are forbidden. Alpha limits MUST fail visibly rather than silently grant authority.

### VIII. Multilingual correctness
WPML owns language/translation identity. Archive authority and canonicals MUST support Unicode and language-prefixed paths. A known WPML language prefix may wrap an exact base authority; unrelated path suffixes MUST NOT match. Missing translation MAY render a human fallback but MUST remain noindex.

### IX. Explainable decisions
Every decision MUST expose stable reason, policy class (`hard|soft|neutral`), profile ID, taxonomy set, Post Type authority/source/reason when applicable, base/final index decision, override evidence, result authority, combination signature, content readiness, and configuration revision.

### X. Evidence before merge and activation
Simulation is objection testing, not staging acceptance. No merge is authorized by lint/unit/simulation alone. Exact head SHA, CI evidence, vendor capability evidence, live/staging profile inventory, rendered Rank Math/WPML verification, result-count equality, rollback rehearsal and artifact digest are required. Production activation is a separate explicit operation.

### XI. Reversible operations
Global kill switch and per-profile disable MUST restore vendor behavior without code deletion. Invalid profile JSON MUST preserve the previous valid authority snapshot. Persistent caches remain disabled until their invalidation contract is proven.

## Change Boundary
Allowed paths:
- `wp-content/plugins/etg-dynamic-filter-seo-bridge/**`
- `.specify/**`
- `specs/001-etg-dynamic-filter-seo-operational/**`
- `.github/workflows/etg-dfsb-ci.yml`

Forbidden without a separate approved spec:
- WordPress core edits
- vendor plugin edits
- automatic profile/combination authorization
- global rewrite migrations
- production activation
- custom database schema

**Version:** 3.0.0
**Ratified:** 2026-09-04
**Last amended:** 2026-09-04
