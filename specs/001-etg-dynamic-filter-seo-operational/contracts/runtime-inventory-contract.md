# Runtime Inventory Contract

Contract: `etg.dfsb.runtime-inventory.v2`

## Purpose
Provide bounded, deterministic, read-only evidence for T120–T123 without turning discovery into SEO authority or mistaking bounded output for complete runtime truth.

## Safety invariants
- `authorizing=false`, `read_only=true`, and `profile_mutation=false` are mandatory.
- Inventory generation MUST require an administrator capability when exposed in wp-admin.
- The exporter MUST NOT persist, enable, merge, or modify Surface Profiles.
- JetEngine Query Builder inventory MUST use the verified `Jet_Engine\Query_Builder\Manager::instance()->get_queries()` interface.
- Raw Query Builder arguments MUST NOT be exported. Only structural identity may be emitted: stored ID, custom query ID, effective identity key, query type, observed Post Types, bounded/unbounded status and referenced taxonomy names.
- `post_type=any` is evidence of an unbounded query, never authorization.
- Public Post Type/taxonomy discovery is bounded and contains only structural registration metadata.
- WPML inventory contains bounded language identity/path evidence only.
- A stable `snapshot_fingerprint` excludes collection time so equivalent runtime inventories compare deterministically.

## Completeness contract
Each bounded section MUST expose:

```json
{
  "observed_count": 125,
  "included_count": 100,
  "limit": 100,
  "truncated": true
}
```

Required completeness sections: `post_types`, `taxonomies`, `languages`, `query_builder`, and `archive_path_translations`. `truncated=true` means absence from the included payload MUST NOT be interpreted as runtime absence.

## Query identity collision contract
- Effective identity is `custom_query_id` when present, otherwise sanitized stored query ID.
- Query identities MUST be sorted before bounded slicing.
- Duplicate effective identities MUST be reported under `identity_conflict_count` and bounded `identity_conflicts`.
- A collided identity MUST NOT be treated as an exact route authority.
- Conflict evidence itself is bounded; `identity_conflicts_truncated=true` means more conflicts exist than were serialized.

## Bounds
- Post Types: 100
- Taxonomies: 150
- Query Builder queries: 100
- Languages: 50
- Archive-path translations: 500
- Serialized query-identity conflicts: 100

## Non-goals
The inventory does not certify result-count equality, term content readiness, exact combination business approval, rendered Rank Math output, or staging acceptance.
