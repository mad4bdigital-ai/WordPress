# MAD4B SEO Content + Image Lifecycle — Staging v0.2.6

## Scope
- Central Staging only: https://tours.dev.mad4b.com/
- WordPress and Rank Math own native SEO metadata.
- MAD4B controls review, evidence, permissions, object/term language, CAS and readback.
- No changes to Production or public publishing through this module.

## Dynamic Brand Name
- Site name is read from WordPress Settings > General > Site Title, with `get_bloginfo('name')`.
- Rank Math supports native `%sitename%` for post/term SEO titles; retain the literal token in stored Rank Math title so future Site Title changes propagate.
- `{{site_name}}` is a MAD4B usage-level image ALT template, dynamically resolved only by the contextual WordPress image attribute filter.
- WordPress global Media Library metadata does **not** expand `%sitename%` or `{{site_name}}` on its own. Do not store unresolved templates there.
- Brand tokens are not automatically inserted into ALT text for generic pictures. Only explicit, visually reviewed brand logos should use `{{site_name}} logo`.

## Image Fields
- Global attachment: Title, Caption, Description, ALT. Owner: native `media/update-metadata` governed reversible adapter. No direct global attachment metadata fallback.
- Context usage: draft-only post-specific ALT template in `_mad4b_seo_image_usage_v1`, under current WordPress post's scope.
- Shared image files and original attachment metadata remain unchanged when saving contextual ALT.
- Image discovery: featured images, WP image block/class, known gallery ID fields, supported Elementor image/carousel widgets; custom galleries are not automatically guaranteed.
- Dynamic ALT rendering: via `wp_get_attachment_image_attributes` on singular post pages; raw HTML, existing Gutenberg HTML, Elementor custom and theme renderers require separate rendered acceptance.
- Global attachment updates and post SEO updates can be refused by native adapter authorizations; the module does not bypass those refusals.
- No AI visual assertion without viewing the actual image. Suggested text is metadata-based, requires visual confirmation, and no Rank Math SEO score is fabricated.

## Abilities and REST
- `mad4b/seo-cycle-{plan,generate,apply,verify}`
- `mad4b/seo-image-{plan,generate,apply,verify}`
- `/wp-json/mad4b/content-creation/v1/seo-image-{plan,generate,apply,verify}`
- Admin UI: `/wp-admin/admin.php?page=mad4b-seo-cycle`

## Gates
- Exact Staging origin, brand and optional job identity, WPML language match, exact content and image revision SHA, WordPress user permission, explicit image review on apply.
- Published-page contextual ALT cannot be edited through this flow; draft edits require authorized manual publication.
- A `research_observation` ContentJob is not content mutation authority.
- Contextual ALT writes require exact image membership in first-party post reference discovery.
- On failed image-usage readback, restore previous scoped post meta without writing to shared media.
- Native provider writes are allowed only when the existing provider adapter independently authorizes them.

## Verified E2E
- Temporary WordPress draft + existing image: dynamic Rank Math title token, contextual image token, rejected unconfirmed ALT, rejected stale SHA, context ALT save and readback, media global original values unchanged, fixture cleanup.
- Source-only contract: 49 checks.
- Remaining certification: governed native Rank Math post metadata write, Rank Math score recompute, full browser rendered ALT/Elementor/gallery acceptance, published content review.
