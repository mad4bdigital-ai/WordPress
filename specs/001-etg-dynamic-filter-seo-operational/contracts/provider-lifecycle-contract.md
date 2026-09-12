# Provider Lifecycle Contract v2

Parsed URL provider/query is profile route authority. Runtime provider observation uses exact `get_current_provider('provider')` + `get_current_provider('query_id')` when available; contradictory observation hard-denies.

Verified JetEngine 3.8.11.2 behavior:
- custom query IDs map through Query Builder Manager `get_query_by_id()`;
- filtered request props are exposed by JetSmartFilters and applied through `set_filtered_prop()`;
- filtered result count uses `get_items_total_count()`;
- Posts Query exposes `get_query_type()` and public `get_query_args()`; its final `post_type` is the preferred Post Type authority for JetEngine profiles.

No unfiltered result or main-page Post Type may substitute for a missing Query Builder authority when the profile requires it.
