# Cache Contract v1

Operational Alpha uses request-local memoization only. Persistent context/result caches are disabled. A future persistent cache key must include language, config revision, archive/provider/query, normalized filters and relevant query state; invalidation must cover term/meta/translation/config/listing-result changes before enablement.
