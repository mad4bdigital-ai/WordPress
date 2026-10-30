# JetEngine Options Pages: shared form schema, tenant-owned field values

## Decision — 2026-10-29

JetEngine **Options Page definitions** (including the pages presented as **Brand** and **Look & Feel**) are part of the shared MAD4B platform. Their menu layout, field keys, field labels/help, input type, conditional logic, repeaters, validation, user permissions, and dynamic field contracts can be versioned and distributed to each company.

**The values stored in those fields are not shared.** Each company's own existing database is authoritative for its brand identity and content source values: logos, colors, typography, gallery image URLs, WordPress media/attachment IDs, background/header images, company contacts, social and SEO identity, listing/query selections, etc.

This is not a blanket ban on transferring a whole Options Page. It is a ban on transporting **another tenant's field values** with that page definition.

## Real observed values — read-only company source inspection

- **All Royal Egypt:** `wp_options['look-and-feel']` exists and is a 16-field associative array.
- **Egypt Tour Gates:** `wp_options['look-and-feel']` exists and is a 21-field associative array; the additional observed keys include `busness_reviews_section_tab`, `busness_reviews_section`, `review_hero_image`, `company_about_customization`, `review_gellery_slider`. **These names preserve the spellings in the currently stored database.**
- In ETG, `look-and-feel::review_gellery_slider` contains 19 company-hosted URLs. It is consumed by Elementor `uael-image-gallery` through the `jet-options-gallery` dynamic tag. A cross-tenant template migration must retain the widget shell, validate/remap the tag to the destination's own Options Page field, and never copy those 19 values.
- A separate registered JetEngine Options Page with slug `brand` was **not** found in either company's registered page definitions. Company-contact and brand-adjacent fields are present in the registered `contact-and-help-info` page; Elementor Brand Kit remains separate. Other brand management may be provided by other components.
- The saved `wp_options` keys are value keys. We independently discovered the **authoritative registered Options Page definitions** in `wp_jet_post_types` where `status='page'` and inspected field keys/types with no saved field values or defaults exported. See the next section.

## Contract for installation and updates

| Shared schema (portable) | Company-owned value/source (preserved, never cross-copied) |
| --- | --- |
| Options Page registration, slug/route and menu placement | Existing tenant-specific `wp_options[page_slug]` field values |
| Field key, field type and field label | Logo, brand palette, font and company imagery |
| Repeater definition, validation and conditional display | Gallery image lists and background/header media URLs |
| Generic JetEngine source/dynamic tag contract | Target-company WP Media Library and attachment IDs |
| Presentation/widget structure consuming the fields | Target-company Jet Query Builder, listing IDs and content records |
| Access role/capability schema after review | Tenant-owned external provider/account secrets and IDs |

**Schema-only migration**: export definitions without defaults that contain real account/company values, preserve field names and data types, and add any new fields to the target **without deleting or overwriting the existing local field values**. If a schema definition introduces a new field, leave its company-specific value unset until supplied/approved by that company. Distinguish harmless generic control defaults from defaults containing logos, colors, URLs, media IDs or account references.

**No global value merge**: never copy entire `look-and-feel`, Brand values, or `jet_engine_*options*` records from a donor company onto the target company's database. Do not use `wp option update` with another company's serialized array.

## Required pre-merge / pre-promotion verification

1. Identify the actual JetEngine Options Page definition owner and storage (database, exported config or code) and compare structural schema across companies.
2. Generate a **schema-only manifest** with field types and keys, with any default value redacted and media-source paths explicitly marked as tenant-owned.
3. Verify that current company values remain byte/semantic equivalent after a schema update, except explicitly reviewed and approved migration transformations.
4. Check all resolved dynamic media references, including the `look-and-feel::review_gellery_slider` key, for the correct target company's media source, plus the separate VPS Media Gateway's domain/tenant ACL.
5. Block a shared visual section from publication if its required target data source is absent. **Never fall back to a source company's sample values.**
6. Test Elementor, JetEngine, theme, SEO/brand and company-owned marketing integrations with target-owned data. Keep Production and existing domains unchanged until approved cutover.

The associated admission gate audits current `look-and-feel` media references in read-only mode. This policy does not represent an automatic Options Page import or a Production data migration.

## Verified registered definitions and additive shared schema plan

JetEngine's `Jet_Engine_Options_Data` sets table `post_types` with a `status=page` scope; the actual WordPress table is **`wp_jet_post_types`**, not `wp_posts` or `wp_jet_engine_post_types`. Both Production company source DBs contain exactly these registered Options Pages: `contact-and-help-info`, `look-and-feel`, and `calculate-booking-price`. The registered menu labels are `Contact and Help Info`, `Look and Feel`, and `Calculate Booking Price`. None has the exact registered slug `brand`.

| Registered page | All Royal Egypt definition fields | Egypt Tour Gates definition fields | Type conflicts |
| --- | ---: | ---: | ---: |
| `contact-and-help-info` | 16 | 16 | 0 |
| `calculate-booking-price` | 2 | 2 | 0 |
| `look-and-feel` | 18 | 20 | 0 |

The shared Look & Feel definition can add two fields **to All Royal Egypt's future isolated clone only**, without importing any saved value: `company_about_customization` (`media`) and `review_gellery_slider` (`gallery`). Existing field values must be unchanged; new fields remain unset until each company's sources are assigned. Current saved-value counts (16 and 21) do not need to match schema field counts because absent and historical saved entries may exist.

Read-only source: `audit_jetengine_options_schema.php`. Tested structural additive plan: `compare_jetengine_options_schemas.py` with `schema_structure_can_be_reviewed=true`, no type conflicts, no donor saved values and `applied_to_wordpress=false`. This only prepares a governed plan — it does **not** install schema or enable production forms. The full release still requires definition-level compatibility (labels, conditional logic, roles, defaults removed), target-clone test and acceptance.
