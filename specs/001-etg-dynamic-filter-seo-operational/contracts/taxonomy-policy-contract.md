# Taxonomy Policy Contract v2

Knowing or discovering a taxonomy is not indexing authorization. A taxonomy must belong to the matched profile and its normalized taxonomy set must be allowlisted.

Each TaxonomyRule may configure role, content/gallery priority, single-taxonomy permission, minimum result threshold, optional required term-meta authority, and `field_map` for canonical term fields (`seo_title`, `meta_description`, `focus_keyword`, `short_description`, `image`, `gallery`, `location_level`).

Structural and required-meta failures are hard deny and cannot be reversed by soft indexing filters.
