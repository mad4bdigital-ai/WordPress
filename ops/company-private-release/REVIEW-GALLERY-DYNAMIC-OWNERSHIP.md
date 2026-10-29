# Our Traveler Reviews: portable template, tenant-owned dynamic data

Decision (2026-10-29): **share the visual template structure and widgets; never copy the image gallery or its dynamic data from one company to another.** This is a field-level rule, **not** a blanket exclusion of the whole template.

## Verified actual source bindings (read-only production inspection)

- **Egypt Tour Gates:** published Elementor document ID `27219`, *Business Reviews Section*. It contains an `uael-image-gallery` widget. The `wp_gallery` setting uses Elementor dynamic tag **`jet-options-gallery`**, binding to JetEngine Options Page **`look-and-feel::review_gellery_slider`** (retain that exact existing spelling).
- On the original Egypt Tour Gates database, `wp_options.option_name = 'look-and-feel'`, field `review_gellery_slider` holds **19 comma-delimited image URLs**, all with host `egypttourgates.com`. They are **not WordPress attachment IDs**. Neither image URLs nor attachment identifiers should be moved to another company. The value is independent of the visual gallery structure.
- **All Royal Egypt:** published Elementor document ID `43268`, *Custom Business Reviews*, uses a `jet-listing-grid` with `custom_query_id` and listing template settings. Its `look-and-feel` options page did **not** contain the `review_gellery_slider` field when inspected. Do **not** fill this absent field with Egypt Tour Gates' gallery.
- Both sites have separate Trustindex/Tripadvisor review integrations; those accounts and data are **not** the same as the `jet-options-gallery` visual traveler image gallery.

## Rules for reuse

| Portable presentation | Tenant-owned dynamic values (must not transfer) |
| --- | --- |
| Section, containers, tabs and responsive layout | JetEngine option-page gallery source values |
| UAEL image-gallery widget type, columns, transitions, spacing | `wp_gallery` static content, URL lists, attachment/media IDs |
| Heading and presentation configuration (reviewed for branding) | `__dynamic__` source-field binding (must be verified/rebound on target) |
| Jet Listing Grid widget shell and layout | Listing ID, Query Builder IDs, post/content source, CPT/term IDs |
| General accessibility and widget configuration | Each tenant's actual Media Library, tenant media hostname and CDN |

- **On the SAME tenant upgrade:** preserve the tenant's own `look-and-feel.review_gellery_slider`, gallery images and associated local media relationships by using its existing tenant DB snapshot. Validate the source URLs and new VPS-based tenant media delivery before cutover. Never change them to another tenant's values.
- **On cross-tenant template reuse:** retain only the template layout. All media values and dynamic data-source IDs/tags require independent target-tenancy resolution. If the target has no matching option field or authorized media source, leave the gallery **unbound and blocked from publication**, not populated from the donor.
- Avoid ID-based assumptions: Elementor document IDs `27219` and `43268` were observations on their respective databases, not global template IDs.
- A photo gallery is independent from the Trustindex account credentials / reviews. Both remain tenant-bound even if embedded in the same section.
- No cross-company copying of `wp_options.look-and-feel` as a complete array, because it contains other company-specific header images and design assets. On an existing company's clone, preserve **its own entire options snapshot** and validate relevant fields. Copying layout from another company does **not** authorize writing option-page values.

## Release gate

Before promoting a reusable visual section, produce a review manifest that lists each dynamic binding by **field path / provider / kind** (no URLs or secret values), confirm its target-tenant source and check that any resolved images belong to that tenant and remain reachable through its approved media hostname. Missing or mismatched gallery/gallery query bindings are **NO-GO** for that section, never a reason to fall back to a different company.

The read-only template audit `audit_elementor_template_portability.py` now identifies dynamic tags and local image/listing data bindings separately from template structure. It does not rewrite or import templates by itself. Existing Production sites, database values and Media Library assets remain unchanged.


**Field-level distinction:** JetEngine **Options Page definitions** (including Brand and Look & Feel) are shared, while all company-specific content source values in those pages remain tenant-owned. The gallery widget template itself and its source-binding contract can be shared. `review_gellery_slider` field values must only originate from the destination company's options. Absent values require review, never donor fallback. `JETENGINE-OPTIONS-SCHEMA-OWNERSHIP.md` is authoritative.
