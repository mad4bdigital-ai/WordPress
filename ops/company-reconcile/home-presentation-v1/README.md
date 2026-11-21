# MAD4B Company Homepage Sections — Portable Runtime v1

Owner: the source-owned MAD4B Platform homepage module. Service Activation, Destination Activation, Company Operating Scope, and Look & Feel retain distinct responsibilities. This is presentation, not permission to publish or charge.

Source canonical code: wp-content/plugins/mad4b-platform/modules/operations/mad4b-central-homepage-sections.php.

Verified Wayfatours alias: /var/www/html/wp-content/mu-plugins/041-mad4b-company-home-sections-v1.php, an exact-byte copy of the canonical module, because the source Platform plugin is not installed on this tenant. Do NOT place a second copy in the source site's wp-content/mu-plugins tree: duplicated function definitions would conflict with the original Platform module.

Removed the hostname/staging-only early return. The module now activates for a company's actual WordPress front page, matched through its own page_on_front and WPML page translation. All existing native Elementor templates remain unchanged. Template widgets and shortcode widgets (Hero and Top Cities) resolve their per-language WPML Elementor template IDs, in every WPML language enabled for that company, with locale-specific memoization.

The original Look & Feel switches for Home Hero, Cities, Tours, Day Trips, Nile, Combined Tours, Branding, Locations, Attractions, Reviews, Blog, Newsletter, SEO Intro and FAQs continue to control each native Elementor widget. Existing Presentation Policy decides service availability; combined/Nile content is hidden if not eligible; filtered JetEngine tour grids use Shared Product Core's public Tour query contract. This module must never enable a disabled country or publish a draft Combined Tour.

Cache contract: only when actual Home section controls change, invalidate its WordPress/WPML page caches and ask the existing public-HTML-only cache lifecycle to invalidate FastCGI/LiteSpeed HTML. Do not flush Redis or the object cache. Service/Destination Activation have their own verified lifecycle hooks; do not add duplicate perpetual polling or extra global purges.

Acceptance: python3 verify_home_sections.py is read-only; independently checks enrolled source/target origins, site profiles and module SHA, service/content decisions for all active WPML languages (six in source, two in Wayfatours at last readback), actual per-language public Elementor Home HTML for Combined and Nile, and reports header-country choices as a readback. Source/Wayfatours current multilingual frontend fixtures: source Combined visible, Nile hidden in all six active languages; Wayfatours Combined and Nile hidden in both active languages (country scope Egypt only; Combined tour parents remain draft). These fixtures are not permanent assumptions for additional tenants. First qualify their actual template identifiers and page eligibility before deploying the adapter.

Current rollout boundary: source staging + Wayfatours production deployed and checked, original master branch / Original313 signed company release not certified. No changes to booking, rate, supplier, filters, navbar design or Elementor saved template data. Future company release must preserve tenant-owned templates and validate this module as a portable source-owned presentation adapter.

## Dynamic WPML languages (no fleet-wide hardcoded language list)

The production source currently has six active WPML languages (EN, ES, DE, FR, AR, IT), whereas Wayfatours currently has EN and ES. These are runtime observations, NOT code configuration. The canonical helper mad4b_home_sections_active_languages() reads the current company's icl_sitepress_settings.active_languages, falls back to wpml_active_languages in frontend contexts, and finally uses only that company's default locale if WPML is unavailable. The same helper drives Look & Feel cache invalidation across the actual translated Home pages instead of iterating a six-language constant.

The rendered Home section's native Elementor template and shortcode IDs continue to be translated via wpml_object_id, cached by current WPML language. On WPML language activation or default-language change, the module requests a public-HTML-only invalidation through the existing cache lifecycle. This hook does not run per request and does not flush Redis or alter object-cache policy. The external acceptance tool builds Home URLs from WPML's own bound language data, requires actual translated published front pages, and rejects un-enrolled language domains rather than fabricating a working language route. An active but untranslated Home is HOLD, not a synthetic pass.

Last live acceptance: all six source-language and both tenant-language pages responded HTTP 200, with eligibility-consistent Combined/Nile section visibility. Future companies, languages, templates or domains must be re-qualified under their site profiles; Original313 has not certified generalized fleet deployment.
