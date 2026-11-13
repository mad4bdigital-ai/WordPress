# MAD4B Taxonomy Evolution — governed reusable rollout

This is **a source-only draft of a reusable operation**. It does not alter live taxonomies or grant executor authority. The actual permanent migration must use the original MAD4B dispatcher, site-bound grant, certified provider adapters, and independently verified rollback.

## Four-site lifecycle
For each enrolled company site, inspect the native JetEngine/WPML/WordPress state and classify into exactly one branch: `MIGRATE_LEGACY`, `ADOPT_CANONICAL_NOOP`, `REVIEW_DUAL_IDENTITY`, or `PROVISION_SCHEMA`. Produce a separate exact inventory receipt, dependency graph, localization visibility result, plan hash, native execution grant, restore receipt, readback and audit event per site. Never copy one site's local term IDs, WPML `trid`, or authority into another site.

**Mandatory shared definitions:** post types, taxonomy definitions, queries, glossaries, CCT/meta schema. **Selected assets:** filters, terms, content, listings, Elementor templates and pages are derived from Product Activation. Hidden/active WPML languages do not imply publish eligibility.

## Observed real-data canaries (2026-11-01)
* **Egypt Tour Gates Staging**: `location_jet` legacy, target `destinations` absent. Real database rows in isolated temporary MySQL copies: 1 JetEngine definition, 438 taxonomy rows, 434 WPML translation records, all round-tripped with matching rollback SHA256. A separate test preserved 2,568 term relationships and round-tripped 12 direct JetSmartFilters bindings plus 46 menu bindings.
* **All Royal Egypt Staging**: same legacy-only state; separate temporary-table test passed 1 definition, 316 taxonomy rows and 316 WPML translation rows, with exact rollback hashes. Original WordPress tables were not updated. The published GitHub script was directly executed on ETG; its cross-site replay to ARE was blocked by transport safety, although a separate in-line native test had passed there.
* Third and fourth sites are **not independently identified or tested**. Four-site classification was separately exercised with local synthetic fixtures (48 assertions), not claimed as four live sites.

## Reproducible staging-only native shadow
The adjacent `wpcli-native-shadow.php` uses only connection-local MySQL TEMPORARY TABLES, and validates exact home/environment and source/target slugs through `MAD4B_EVOLVE_EXACT_HOME`, `MAD4B_EVOLVE_OLD`, and `MAD4B_EVOLVE_NEW`. It deliberately will not run on Production and never authorizes a persistent apply.

## Blockers to permanent apply
1. Canonical versioned `destinations` source schema and exact provider certifications.
2. Original site-bound authority plus an independently tested restore point.
3. Migrate/repair complex serialized and JSON config via certified Elementor, JetEngine, WPML, Rank Math and JetSmartFilters adapters, not blind SQL replacement.
4. Verify public rewrites (retain existing `destination` archive path), hidden languages, translation clusters, site-specific Product Activation, filters, listings, SEO, caching and frontend.
5. Four site-specific readbacks and independent release decisions; Production remains separately authorized rather than forbidden.

Do not assume four legacy sites share the same migration need. Per-object WPML identity gaps (four terms plus thirty independent tours on ETG) belong to Reviews and must never be auto-connected by matching title or numeric ID.


## Source hardening and remaining original-runtime integration (2026-11-01)

- The plan now checks **company_id + site_uuid + origin + environment** and an exact inventory revision. Cross-company/stale-snapshot input is rejected.
- Classification tests assert **each** of the four possible site states and test mutations of plan hashes, changes of company, hidden-language invariants, and stale inventory.
- **Canonical-only/no-op** never needs write/restore approval just for discovery. It still requires absence of legacy rows and references before a no-op readback can be accepted.
- Added `wpcli-native-census.php`: exact-bound, read-only, metadata-only Staging inventory; it explicitly returns `signed_original_executor_receipt=false` and `inventory_complete=false`. Do not pass it as an attested inventory.
- Added `wpcli-reference-shapes.php`: scoped structure-only inspection for known provider fields; PHP object materialization is disabled during serialized-value probing. Its ETG remote replay was blocked, so **no live nested-field certification** was established.
- Added `TaxonomyEvolutionNativeEvidence.php`: non-mutating evidence checker that requires the **original** `MAD4B_SCP_Execution_Receipt::verify`, exact site/plan fingerprint, resource-set binding, and readback-stage presence. Not wired as a registered executor or authorization grant. Receipt validation alone is not full backend/frontend or restore certification.
- Fixed GitHub workflow to use exact PR head checkout, PR-scoped concurrency, and stale-run cancellation per repository requirements.

### New live read-only ETG census

On `https://staging.egypttourgates.com`, the canonical `destinations` taxonomy remains unregistered, whereas `location_jet` remains registered with archive rewrite slug `destination`. Exact read-only metadata counts:

| Source | Rows |
|---|---:|
| Legacy JetEngine taxonomy definitions | 1 |
| Legacy terms | 438 |
| WPML taxonomy translation rows | 434 |
| Term relationships | 2568 |
| Legacy terms without matching WPML rows | 4 |
| Post-meta references | 171 |
| Options containing legacy text | 13 |

The 171 post-meta references include 12 direct filter-taxonomy fields, 46 menu fields, 6 listing fields, 84 Elementor data fields, 19 source-map fields and 4 query-variable fields. These are **reference counts, not certified write targets**. WPML hidden languages are `ar`, `de`, `fr`; hidden languages must not be published/indexed solely because the translation exists.

### Native apply acceptance still outstanding

1. Obtain approved `destinations` versioned schema and the original trusted company/site inventory/identity receipt.
2. Certify native provider-specific transformations for serialized, JSON and option/config references and preserve the existing `destination` rewrite contract.
3. Register a reviewed real ability and original dispatcher/executor pair. Do not add a placeholder runnable mutation operation to `config/operation-registry.json`.
4. Independently test a restore point, safe apply, post-state readback, frontend/SEO/WPML/WooCommerce/filters acceptance, and rollback on an isolated Staging clone.
5. Resolve or isolate legacy identity Reviews (30 ETG tours and 4 terms), then execute four **independent** site plans when the two remaining targets are identified.
6. Re-run repository release verdict after exact-head owner approval. No user authorization token or comment should be invented in source code.

**Gate: NOT RELEASE-CERTIFIED.** Passing the shadow and source/CI tests is not proof of permanent site mutation safety.
