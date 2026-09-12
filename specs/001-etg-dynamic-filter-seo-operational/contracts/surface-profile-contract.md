# Surface Profile Contract v3

`etg.dfsb.surface-profile.v1` remains the profile identity contract. A profile is the smallest independent SEO governance unit.

Required safety semantics:
- new profiles default disabled;
- every enabled profile requires explicit `archive_paths[]` and explicit `{provider,query_id}` `routes[]`;
- `providers[]`, `query_ids[]`, and `archive_slugs[]` are compatibility/configuration inputs only and MUST NOT authorize a request or synthesize Cartesian route authority;
- `inherit_global_defaults` may inherit non-identity policy defaults only; it MUST NOT create archive paths, routes, taxonomy identities, taxonomy sets, or exact combinations;
- profile IDs are unique; duplicates degrade readiness;
- any authority-bearing Alpha bound overflow is a validation error and the registry resolves fail-closed;
- path authorities are Unicode-safe and may match a known WPML language prefix wrapping the exact base path, but arbitrary suffix matching is forbidden;
- profile extensions are normalized after filters and are subject to the same visible bounds as persisted profiles.
