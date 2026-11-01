# Company Options Registry v1 — JetEngine, company-scoped

The **MAD4B Control Plane** is the authority for the *definition* of company-editable Option Page fields. Each company-owned WordPress database is the authority for *values*. This is a one-way schema-only registration; **no source branding, email, phone numbers, images, pricing, authentication or operational settings are ever copied**.

## Registered, source-backed JetEngine Options Pages

| Page slug | Source schema fields | Company editing |
| --- | ---: | --- |
| `contact-and-help-info` | 16 | `admin.php?page=contact-and-help-info` |
| `look-and-feel` | 54 | `admin.php?page=look-and-feel` |
| `company-communication-operations` | 24 | `admin.php?page=company-communication-operations` |
| `global-offers` | 18 | `admin.php?page=global-offers` |

All 112 fields were verified byte-for-byte by field key, type and label against source and tenant definitions. The source schema fingerprint is persisted under WordPress option `mad4b_company_options_field_registry_v1` at **both** central Staging and the Wayfatours tenant. The schema includes no source values, media IDs, credentials, or defaults.

### User experience

A tenant owner can open **Company Configuration** under `wp-admin/admin.php?page=mad4b-company-configuration` to see each Options Page, configured and pending counts, and an **Edit in JetEngine** link. Existing Elementor `jet-options-page` dynamic tags retain stable identifiers such as `contact-and-help-info::site_email`. When the owner supplies a value in JetEngine, those bindings resolve in the owning company context without changes to Elementor JSON.

- Authenticated, administrator-only summary: `GET /wp-json/mad4b/v1/company-configuration/fields`, returns *metadata/status only*. Source values are never exposed.
- Anonymous request: 401/403; admin request: 200 verified.
- The tenant's `contact-and-help-info`, `look-and-feel`, `company-communication-operations`, and `global-offers` option records were absent at the initial snapshot. They have **not been initialized with source data**.
- Control-plane readiness marks schema equality `PASS` and tenant owner input `AVAILABLE_FOR_OWNER_INPUT`; missing optional company values **must not block design template publication**.

### Explicit boundary

This registry handles **existing JetEngine Options Page fields and existing dynamic binders**. Additional static Elementor URLs, source image IDs, or custom per-template image slots must be attached to a named JetEngine option key (or registered as a new company field) before a future input is capable of automatically updating that widget. A missing mapping **must never be falsely treated as a working dynamic binding**. The design resolver may publish a deliberately blank widget only when it also stores a resolvable deferred field target; otherwise report the unmatched field separately for a later schema extension.

Protect source and tenant boundaries. Do not change unrelated WooCommerce or booking/pricing settings to blank just because they use the same JetEngine page system. Preserve shared query IDs, active Kit 4, WPML group isolation and company-specific value storage.

### Rebuild/source-of-truth sync

On the enrolled VPS (authorized environment only):

```bash
python3 /opt/mad4b-company-provisioning/bin/company-options-registry.py
# Persist the resulting registry via registry-store.php with exact central/tenant verification
python3 /opt/mad4b-company-provisioning/bin/company-readiness-workflow --refresh
```

The registry generator does not copy or print the business option contents; it only checks whether fields have been filled. The store step validates field IDs and both site identities and writes only schema metadata.

The GitHub PR is source preservation; it remains separate from any merge/release and the current live tenant installation.

## Verified deferred-fallback publication (Wayfatours)

The Elementor resolver now consults the **same schema registry fingerprint** before blanking an inherited fallback. It only clears a source-host static value when the widget has an existing `jet-options-image` or `jet-options-page` Dynamic Tag referencing a *registered* JetEngine Options Page field. The tag remains untouched. Source-only references without a proven field mapping stay in the compatibility-resolution queue.

On 2026-10-30, five multilingual Business Reviews section templates (IDs 43750, 43755, 43757, 43759, 43761) were published with a **blank** background image fallback and preserved dynamic binding `look-and-feel::review_hero_image`. Exact pre-change Elementor JSON was backed up before publication and the deferred binding written to each template's `_mad4b_deferred_option_bindings_v1` metadata. Live readback confirmed 5/5 published, 5/5 empty image URL/ID, 5/5 correct JetEngine binding; no source-image URLs in published Elementor JSON. The tenant Options Page still contains no inherited `look-and-feel` values.

Current installed tenant admin UI: `/wp-admin/admin.php?page=mad4b-company-configuration`. The owner can enter the shared `review_hero_image` field on `/wp-admin/admin.php?page=look-and-feel`; the five templates use the same value automatically when present.

**Not yet complete:** old static media URLs without an existing dynamic field binding are not yet ready for input through JetEngine; register and map those slots before publishing their templates. No misleading assumption that blanking a source URL by itself creates an editable JetEngine option.
