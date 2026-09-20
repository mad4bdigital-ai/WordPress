# Elementor Content Publication Contract

## Purpose

Use Elementor Theme Builder as the visible presentation layer for dynamic archive/filter pages while keeping the ETG bridge as the structural SEO/context authority.

The visible content and SEO metadata should come from the same resolved Term context instead of maintaining separate copy for crawlers and users.

## Supported server-rendered content

The plugin exposes server-side shortcodes suitable for Elementor Shortcode widgets or shortcode-capable fields:

```text
[etg_filter_h1]
[etg_filter_intro]
[etg_filter_sections]
[etg_filter_gallery]
[etg_filter_breadcrumb_context]
[etg_filter_term role="location" field="description"]
[etg_filter_term_section role="location" field="description"]
```

`etg_filter_term` may address a resolved Term by profile role or taxonomy and may return bounded fields such as name, description, short description, SEO title, meta description, focus keyword, image URL/ID, count and location level.

`etg_filter_term_section` renders semantic server-side `<section>` content with a bounded heading level. Search crawlers and users receive the same HTML output.

## Source-of-truth alignment

Standard taxonomy `description` is read directly from the resolved translated Term. `short_description`, SEO title, meta description, image and gallery may come from the profile field map and safe built-in Term meta fallbacks.

When content is stored in JetEngine/ACF Term fields, operators should map the existing field keys through `taxonomy_rules.<taxonomy>.field_map` rather than duplicating copy into a second SEO-only store.

Content readiness evaluates the same resolved Term content used by the Elementor sections.

## Global-OFF dark presentation

Profile flag:

```json
"elementor_render_when_global_off": false
```

When explicitly set to `true`, ETG shortcodes MAY use the non-authorizing evidence context while the normal request was stopped specifically by the Global kill switch. This allows the Theme Builder presentation to be inspected on real dynamic URLs without enabling Rank Math metadata, robots index authority or the live sitemap.

Dark presentation MUST NOT bypass a live provider/query/Post Type/scope failure after Global is enabled.

## Verification gate

Profiles that require Elementor content use:

```json
"require_elementor_content": true,
"elementor_content_verified": false
```

`elementor_content_verified=false` is a hard indexing deny with reason `elementor_content_not_verified`.

The flag may become `true` only after an operator verifies on representative real dynamic URLs that the Theme Builder template visibly renders the intended Term-based sections, headings and supporting content. The plugin does not auto-verify a visual Elementor template.

## Separation of responsibilities

- Elementor controls layout and visual placement.
- ETG resolves exact filter Terms, language, profile identity and content fields.
- ETG ContentComposer produces shared H1/intro/section/metadata composition.
- Rank Math outputs metadata/Schema and consumes the ETG publication sitemap provider.
- WPML provides translated Term identity and multilingual URL authority.

No visual template state alone grants indexing or sitemap authority.
