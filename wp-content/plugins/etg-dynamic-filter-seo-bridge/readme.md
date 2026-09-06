# ETG Dynamic Filter SEO Bridge

Phase-1 MVP for Egypt Tour Gates archive pages that use JetSmartFilters, JetEngine, WPML, Elementor, and Rank Math. It does not modify any vendor plugin.

## Verified bundled versions

The implementation was inspected against the ZIPs already committed in this repository:

| Plugin | Version |
| --- | --- |
| JetSmartFilters | 3.8.3.1 |
| JetEngine | 3.8.11.2 |
| Rank Math SEO | 1.0.275 |
| Rank Math SEO PRO | 3.0.118 |
| WPML Multilingual CMS | 4.9.6 |
| WPML SEO | 2.2.5 |
| WPML String Translation | 3.5.3 |

Inspection confirmed `wpml_current_language`, `wpml_object_id`, Rank Math's frontend title/description/canonical/robots hooks, and the current JetSmartFilters provider API exposed through `jet_smart_filters()->query`.

## Supported URL form

```text
/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo/
/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo;tour-types_jet:day-tours/
/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/location_jet:cairo;tour-types_jet:day-tours;tour-style_jet:luxury/
```

The MVP reads the path and does not rewrite URLs.

## Shortcodes

```text
[etg_filter_h1]
[etg_filter_intro]
[etg_filter_sections]
[etg_filter_gallery]
[etg_filter_gallery mode="priority" limit="8" size="large"]
[etg_filter_keyword]
[etg_filter_breadcrumb_context]
```

Typical Elementor placement:

- Heading: `[etg_filter_h1]`
- Text/Shortcode: `[etg_filter_intro]`
- Gallery: `[etg_filter_gallery mode="combined" limit="9" size="large"]`
- Below listing: `[etg_filter_sections]`

## Default term field mapping

```text
SEO title: rank_math_title, seo_title, _title
Meta description: rank_math_description, seo_description, _description
Focus keyword: rank_math_focus_keyword, focus_keyword
Short description: short_description, short_desc, intro
Image: thumbnail_id, image, hero_image
Gallery: gallery, hero_gallery, term_gallery
Location level: location_level, level
```

Native term meta is checked first. If ACF is available, the same candidate field is also read from `{$taxonomy}_{$term_id}`.

## WPML behavior

The current language is read with `wpml_current_language`. `wpml_object_id` is called without automatic original-language fallback first, allowing the bridge to detect a missing translation. Missing translations can still render from the original term but are marked as a fallback and default to `noindex, follow`.

## Rank Math integration

The plugin registers:

```text
rank_math/frontend/title
rank_math/frontend/description
rank_math/frontend/canonical
rank_math/frontend/robots
rank_math/opengraph/facebook/image
```

Compatibility inspection against the bundled Rank Math SEO 1.0.275 confirmed that the OpenGraph image filter is constructed dynamically as `opengraph/{$this->network}/image`; the Facebook runtime network therefore resolves to `rank_math/opengraph/facebook/image`.

The canonical remains the current filtered URL in Phase 1.

## Conservative indexing policy

- unknown or malformed filter: noindex, follow
- unresolved term: noindex, follow
- missing WPML translation: noindex, follow
- more than three filters: noindex, follow
- zero results (when a result count is supplied): noindex, follow
- location only: index only for `city` or `landmark` with results
- tour type only: noindex until site-specific opt-in
- location + tour type: index only when explicit result count >= 3
- location + tour type + style: index only when explicit result count >= 3
- other combinations: noindex by default

Provide the JetSmartFilters/JetEngine result count through `etg_filter_seo_result_count`. A final site-specific override is available through `etg_filter_seo_should_index`.

## Extension filters

```text
etg_filter_seo_allowed_taxonomies
etg_filter_seo_taxonomy_role_map
etg_filter_seo_term_field_map
etg_filter_seo_h1
etg_filter_seo_intro
etg_filter_seo_sections
etg_filter_seo_meta_title
etg_filter_seo_meta_description
etg_filter_seo_keyword
etg_filter_seo_result_count
etg_filter_seo_should_index
```

## Security boundaries

Taxonomies are allowlisted, taxonomies/slugs are sanitized, plain output is escaped, rich term content is passed through `wp_kses_post`, unknown filters fail closed for indexing, and term descriptions are not executed as shortcodes.

## Runtime boundary

The MVP regenerates server-side page content and metadata when the filtered URL is loaded. This matches crawler requests and JetSmartFilters Page Reload / Mixed flows. A purely client-side AJAX state change that never loads the filtered URL will not regenerate server-side SEO metadata until that URL is loaded.

## Deferred phases

Not included in this MVP: Admin Settings, Admin Preview, native Elementor Dynamic Tags, clean URL rewrites, sitemap generation, CollectionPage/ItemList schema, and FAQ schema.
