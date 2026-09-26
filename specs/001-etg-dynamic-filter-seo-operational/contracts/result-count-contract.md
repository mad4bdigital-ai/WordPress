# Result Count Authority Contract v2

Preferred extension filter: `etg_filter_seo_result_count_authority` returning `{count, source, authoritative, detail}`. `authoritative=true` must be explicit. Built-in JetEngine adapter is authoritative only after applying current JSF filtered request to an isolated Query Builder object. Legacy numeric `etg_filter_seo_result_count` is untrusted unless `trust_legacy_result_count=true`.
