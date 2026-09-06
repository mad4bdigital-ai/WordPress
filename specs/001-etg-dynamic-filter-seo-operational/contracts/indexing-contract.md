# Indexing Contract v3

Policy classes remain `neutral|hard|soft`.

Hard denies include profile-scope violations, runtime/provider/query/Post-Type identity failures, unsupported query state, malformed/foreign taxonomy state, missing terms, translation fallback, disallowed taxonomy sets, required taxonomy-meta failures, single-taxonomy authorization failure, unapproved exact combinations, content failure, zero results and missing authoritative result count.

Only the final threshold decision is soft. `etg_filter_seo_should_index` is never called for hard denies. IndexDecision v3 includes profile, taxonomy set, minimum results, Post Type authority/source/reason, result authority, combination/content evidence and configuration revision.
