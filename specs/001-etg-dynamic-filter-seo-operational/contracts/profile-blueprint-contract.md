# Disabled Profile Blueprint Contract v1

`etg.dfsb.profile-blueprint.v1`

Blueprint generation is read-only and non-authorizing. It accepts one discovered Post Type and selected taxonomies attached to that Post Type, omits foreign taxonomies with warnings, sets `enabled=false`, defaults Post Type authority to `query_builder`, and intentionally leaves archive paths, routes, taxonomy sets and exact combinations empty.

A blueprint is a configuration starting point, not an activation action.
