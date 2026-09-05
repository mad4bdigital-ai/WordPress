# Runtime Inventory Contract

Normal evidence contract: `etg.dfsb.runtime-inventory.v2`

Unavailable-source contract: `etg.dfsb.runtime-inventory-unavailable.v1`

## Purpose
Provide bounded, deterministic, read-only evidence for T120–T123 without turning discovery into SEO authority, mistaking bounded output for complete runtime truth, or treating an unavailable runtime source as an observed empty set.

## Safety invariants
- `authorizing=false`, `read_only=true`, and `profile_mutation=false` are mandatory.
- Inventory generation MUST require an administrator capability when exposed in wp-admin.
- The exporter MUST NOT persist, enable, merge, or modify Surface Profiles.
- JetEngine Query Builder inventory MUST use the verified `Jet_Engine\\Query_Builder\\Manager::instance()->get_queries()` interface.
- Raw Query Builder arguments MUST NOT be exported. Only structural identity may be emitted: stored ID, custom query ID, effective identity key, query type, observed Post Types, bounded/unbounded status and referenced taxonomy names.
- `post_type=any` is evidence of an unbounded query, never authorization.
- Public Post Type/taxonomy discovery is bounded and contains only structural registration metadata.
- WPML inventory contains bounded language identity/path evidence only.
- A stable `snapshot_fingerprint` excludes collection time so equivalent runtime inventories compare deterministically.

## Source availability contract
A normal T120 snapshot MUST have `evidence_complete=true`, `availability_errors=[]`, and `available=true` for all mandatory structural sources:

- `post_types`
- `taxonomies`
- `languages`
- `query_builder`
- `archive_path_translations`

Every source exposes a bounded `available/source` record under `inventory.availability`.

An unavailable, invalid, or exception-producing mandatory source MUST NOT be represented as a trustworthy empty inventory. The exporter MUST return `etg.dfsb.runtime-inventory-unavailable.v1`, set `evidence_complete=false`, and list the unavailable sections in `availability_errors`.

The unavailable-source contract is non-authorizing partial evidence only. Reconciliation MUST reject it as `invalid_inventory`; it cannot generate disabled candidates, establish removals, or close T120/T121.

A valid empty Query Builder array is distinct from an unavailable Query Builder source and MAY remain available evidence. An unavailable/invalid WPML active-language source is blocking because a multilingual installation cannot prove language authority from an empty observation.

Language-specific archive paths MUST be emitted only when the WPML `wpml_permalink` authority is available and returns a valid translated permalink. The exporter MUST NOT repeat the current/native archive path under language keys as a fallback when translated-permalink authority is unavailable.

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

Required completeness sections: `post_types`, `taxonomies`, `languages`, `query_builder`, and `archive_path_translations`. `truncated=true` means absence from the included payload MUST NOT be interpreted as runtime absence. Availability and completeness are independent: `truncated=false` does not mean the evidence source was available.

## Query identity collision contract
- Effective identity is `custom_query_id` when present, otherwise sanitized stored query ID.
- Query identities MUST be structurally normalized before sorting and bounded slicing.
- Sort order MUST include a deterministic structural tie-break derived from Post Types, boundedness, and referenced taxonomies so equal identity/stored-ID/type records are provider-order independent.
- Duplicate effective identities MUST be reported under `identity_conflict_count` and bounded `identity_conflicts`.
- Collision records may expose only the same bounded structural identity fields; raw Query Builder args remain forbidden.
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
