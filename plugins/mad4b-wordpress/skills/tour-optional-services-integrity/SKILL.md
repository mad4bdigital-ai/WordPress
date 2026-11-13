---
name: tour-optional-services-integrity
description: "Use when MAD4B Tour activities, custom extras, vehicle/train/air transfers or Fluent Form selections are stored but hidden, misclassified, unpriced, or absent from Elementor widgets."
---

# MAD4B Extras and optional transport visibility

This Skill is a read-first troubleshooting and repair-planning workflow. It does not grant access to publish Draft services or charge guests.

1. Inspect the parent Tour switches: `tour_extras_status`, `custom_optional_extras`, `tour_vehicles_transport_status`, `tour_train_transport_status`, `tour_air_transport_status`; and the stored relations `tour_optional_extras`, `custom_extra_items`, `vehicles_transport_services`, `train_transport_services`, `air_transport_services`.
2. Enumerate every referenced post, post type, post status, Tour Mode, transport classification (`trans_mode`), eligibility and public service channel. Distinguish `linked`, `editor_previewable`, `public_eligible`, `price_verified`, `checkout_ready` in independent fields.
3. Use `MAD4B_Tour_Optional_Authoring_Preview_V1::audit()` and `MAD4B_Tour_Optional_Sections_Lazy_V1::available()` as two distinct checks. Authoring previews may show Draft children; public pages must not.
4. Check that Custom Extras repeater rows are not assumed to be JetEngine activity posts. A populated custom-only selection must get a supported rendering path; do not force an unrelated Activity, invent a price, or bypass the public gate.
5. Trace Query Builder source, its site-local Query ID, listing template, Elementor section settings, WPML locale, lazy-loading and client click handling. Avoid hardcoding Query IDs such as the source/tenant's different Program IDs.
6. Check Fluent Form #6's actual fields, date and traveler inputs, optional selections, pricing responses, and WooCommerce order/Stripe flow independently. A rendered form does not imply a valid, payable quote.
7. Diagnose transport by mode: vehicles, train, air. Never allow a published vehicle to satisfy the air category or a Draft service to appear in a guest selectable list. Produce precise reason codes instead of hiding the parent section without explanation.
8. Test actual browser desktop/mobile markup, interaction, AJAX responses and accessible controls. Require an authorized signed execution path for code changes; preserve original UAEL/Elementor templates and company customizations.

Return: `tour_id`, `locales`, `section_flags`, `linked_items`, `eligible_items`, `excluded_reasons`, `widget_binding`, `form_quote`, `frontend_result`, `repair_plan`, `test_evidence`.
