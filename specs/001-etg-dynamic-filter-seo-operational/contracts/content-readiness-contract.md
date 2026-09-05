# Content Readiness Contract v3

Content readiness is profile-specific with global fallback. Required title/meta/minimum-copy checks use a deduplicated corpus of normalized term descriptions/short descriptions so generated intro does not double-count the same term copy.

`etg_filter_seo_content_ready` is veto-only: it may change base-ready to false, but an attempted false→true promotion is blocked and recorded as `attempted_promote_blocked`.
