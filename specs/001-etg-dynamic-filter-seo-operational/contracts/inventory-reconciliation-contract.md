# Inventory Reconciliation Contract

Contract: `etg.dfsb.inventory-reconciliation.v2`

## Invariants
- `authorizing=false`, `read_only=true`, `profile_mutation=false` are mandatory.
- Current and previous runtime inventories must satisfy `etg.dfsb.runtime-inventory.v2`, bounded collection limits, completeness metadata, and a recomputed `snapshot_fingerprint`.
- Tampered, structurally inconsistent, oversized, or authorizing snapshots are rejected before drift analysis.
- Reconciliation classifies findings as `info`, `warning`, or `blocking`; it never writes settings or enables Profiles.
- Any truncated current inventory section is blocking inventory-quality evidence.
- A missing configured item in a truncated section is reported as **unresolved due to incomplete inventory**, never as proven missing.
- Snapshot comparisons skip truncated sections instead of producing false `removed` findings.
- Duplicate effective Query Builder IDs are blocking identity collisions; collided identities are excluded from the query index and cannot satisfy exact routes.
- Query Builder inventory unavailable is distinct from `profile_query_missing`.
- Enabled Profiles with proven missing Post Types/taxonomies, proven missing JetEngine queries, unbounded `post_type=any`, non-post queries, or mixed foreign Post Types are blocking drift.
- CPT archive-link mismatch is evidence-only warning because governed listing surfaces may be Elementor/JetEngine Pages rather than native CPT archives.
- Query Builder taxonomies are structural hints only; absence does not prove a dynamic JetSmartFilters taxonomy is unused.
- Invalid previous snapshots are surfaced explicitly and never silently treated as “no drift”.

## Disabled candidate generation
Candidate generation is allowed only when the inventory is complete for all bounded sections, Query Builder inventory is available, and `identity_conflict_count=0`. Otherwise `disabled_candidates=[]`.

When allowed:
- Candidate `enabled=false`.
- Candidate `archive_paths=[]`, `routes=[]`, `allowed_taxonomy_sets=[]`, and `indexable_combinations=[]`.
- Observed archive paths and exact single-Post-Type Query Builder routes may be returned under `evidence`, never copied into authority fields.
- Candidate output remains `requires_operator_review=true`.
