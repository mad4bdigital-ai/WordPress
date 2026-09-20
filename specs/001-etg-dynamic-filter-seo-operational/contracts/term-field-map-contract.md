# Term Field Map Contract v1

Each taxonomy rule may provide a bounded canonical `field_map`. Custom keys are sanitized and prepended to safe built-in fallbacks.

Canonical fields: `seo_title`, `meta_description`, `focus_keyword`, `short_description`, `image`, `gallery`, `location_level`.

Multiple configured gallery fields are aggregated, normalized to attachment IDs and deduplicated. Arbitrary unconfigured meta is never emitted automatically.
