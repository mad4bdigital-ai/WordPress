# Surface Profile Contract v2

`etg.dfsb.surface-profile.v1` remains the profile identity contract. A profile is the smallest independent SEO governance unit.

Required safety semantics:
- new profiles default disabled;
- new non-legacy profiles require exact `archive_paths[]` and exact `{provider,query_id}` `routes[]`;
- provider/query arrays are legacy inheritance only and cannot authorize a new profile by Cartesian product;
- profile IDs are unique; duplicates degrade readiness;
- path authorities are Unicode-safe and may match a known WPML language prefix wrapping the exact base path, but arbitrary suffix matching is forbidden;
- profile extensions are normalized after filters.
