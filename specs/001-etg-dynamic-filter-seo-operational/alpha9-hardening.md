# Alpha9 Hardening Notes

`0.4.0-alpha.9` applies the actionable objections raised during Alpha8 review while preserving the Draft/dark-validation boundary.

Implemented:
- default profile disabled with separate disabled-profile evidence resolution;
- live provider/query observation hard deny;
- evidence-backed provider and result-count parity gates for background publication;
- WPML language switching/restoration for background counts;
- explicit existing `tax_query` AND merge;
- depth-aware content minimums and section-count gate;
- publication hard cap reduced from 5,000 to 500, first-rollout default 100;
- persistent transient candidate cache plus invalidation epoch;
- candidate-vs-emitted metadata/schema separation;
- structured Profile Manager locked while Global is ON;
- tabbed publication Admin UX with a dedicated explanation marker for every structured profile selector/option and every publication/evidence action;
- verification evidence IDs for provider, Elementor, and result-count parity;
- read-only Publication Evidence Bundle;
- preview performance/query/memory/cache telemetry.

Deliberately not activated:
- clean canonical URL rewriting. This stays deferred until real multilingual route/collision evidence exists.

Runtime evidence remains mandatory. Static CI can prove contracts and fail-closed behavior, not actual Elementor rendering, WPML permalink correctness, JetSmartFilters count parity, or live sitemap performance.
