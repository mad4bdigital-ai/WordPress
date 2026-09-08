# Post Type Binding Contract v2

`etg.dfsb.post-type-binding.v2`

When `require_post_type_binding=true`, `post_types[]` MUST be non-empty and every observed Post Type MUST belong to the allowlist.

Supported authorities:
- `query_builder` — safe default; resolve exact JetEngine custom query, require query type `posts`, read final `get_query_args()['post_type']`;
- `main_query` — explicit opt-in for surfaces where the WordPress main query is authoritative;
- `either` — prefer Query Builder when observed, otherwise main query;
- `both` — both must be observed, allowed and identical.

Hard failures include missing query, non-post query, `post_type=any`, unobserved Post Type, any observed Post Type outside the allowlist, or `both` authority disagreement.
