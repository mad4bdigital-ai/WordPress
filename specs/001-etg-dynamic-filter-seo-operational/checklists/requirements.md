# Requirements Checklist — Generic Operational Alpha

## Repository / immutable integrations
- [x] Vendor plugin source immutable
- [x] Global bridge defaults disabled
- [x] New Profiles default disabled
- [x] PR/production authorization flags remain false

## Surface identity
- [x] Bounded Profile Registry
- [x] Duplicate Profile IDs rejected
- [x] Exact Unicode/WPML-aware archive-path authority
- [x] No arbitrary suffix archive matching
- [x] Exact provider+query route pairs for new Profiles
- [x] Query-Builder-first Post Type authority
- [x] `post_type=any`, non-post, unobserved and mixed-foreign Post Types rejected
- [x] WordPress taxonomy↔Post Type relation readiness checks

## Taxonomy growth
- [x] Profile-scoped arbitrary taxonomy rules
- [x] Exact allowed taxonomy sets
- [x] Exact combination registry remains editorial authority
- [x] Per-taxonomy thresholds/single-index policy
- [x] Optional term business-meta constraints
- [x] Per-taxonomy native/ACF field maps
- [x] Multi-gallery source aggregation/deduplication
- [x] Generic content/gallery/breadcrumb ordering
- [x] Legacy travel composition retained explicitly

## Fail-closed / monotonic authority
- [x] Hard vs soft indexing separation
- [x] Hard denials cannot be promoted by ordinary index override
- [x] Content readiness extension is veto-only
- [x] Configuration/Profile filters re-normalized after extension
- [x] Invalid/oversized Profile JSON preserves prior valid authority
- [x] Deduplicated unique content evidence
- [x] Metadata/shortcodes share structural identity guards

## Discovery / simulation
- [x] Discovery is read-only
- [x] Blueprint is disabled and non-authorizing
- [x] Scenario Lab is non-mutating and marks `synthetic=true`
- [x] Simulation is explicitly not staging evidence

## Existing operational gates
- [x] Structured authoritative result count
- [x] Legacy numeric count untrusted by default
- [x] Bundled JetEngine/JSF lifecycle inspected
- [x] Query-state/tracking/pagination governance
- [x] WPML-aware preview/canonical behavior
- [x] Persistent decision cache absent in Alpha

## Runtime inventory / reconciliation
- [x] Runtime Inventory is bounded, read-only and non-authorizing
- [x] Inventory fingerprint is recomputed before reconciliation
- [x] Oversized/tampered snapshots are rejected
- [x] Enabled-profile Post Type/taxonomy/query identity loss is classified as blocking drift
- [x] Query boundedness downgrade to `any` is blocking
- [x] Native CPT archive mismatch is warning evidence only
- [x] Newly discovered surfaces produce disabled candidates only
- [x] Candidate archive/routes/taxonomy-sets/combinations remain empty
- [x] Suggested routes remain evidence-only
- [x] WP-CLI inventory/reconcile operations are read-only and prior input is size-bounded
- [x] Every bounded inventory section exposes observed/included/limit/truncated completeness metadata
- [x] Truncated inventory cannot prove missing/removed state
- [x] Query identities are sorted before slicing for deterministic snapshots
- [x] Duplicate effective Query Builder IDs are blocking and excluded from route authority
- [x] Candidate generation is suppressed for truncated, unavailable, or identity-ambiguous inventory

## Publication / live gates
- [ ] CI green on new clean exact remote head
- [ ] PHP 7.4 and PHP 8.3 all ten generic/inventory/reconciliation test suites green
- [ ] Vendor gate confirms Query Builder Posts Query/Post Type APIs
- [ ] Deterministic `0.4.0-alpha.4` artifact/provenance captured
- [ ] Staging inventory/profile binding complete
- [ ] Real filtered count equals rendered listing
- [ ] Rendered Rank Math/WPML output verified
- [ ] No PHP warnings/notices
- [ ] Per-profile + global kill-switch rollback rehearsed
- [ ] Explicit merge authorization received
