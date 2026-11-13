---
name: nile-cruise-jetengine-lifecycle
description: "Use when creating or reconciling an Egypt Nile Cruise Tour, JetEngine Tour/Property/Rate fields, Program taxonomy itineraries, or Draft and Publish field requirements across MAD4B companies."
---

# MAD4B Nile Cruise JetEngine authoring lifecycle

This Skill is diagnostic and planning guidance. It does not authorize data changes, publish content, or bypass the company's operation admission policy.

1. Identify the actual company, site UUID, environment, site language and current CPT/schema. Read native JetEngine `jet_post_types` definitions for `tours-and-activities`, `properties`, `tour-rates`; compare by field NAME, not local CPT ID, Query ID, label or order.
2. `tour_mode=cruise_tour` selects the Nile Cruise authoring model. Require a `programs` taxonomy child of `egypt-nile-cruises` and a published Nile vessel `properties` post through `tour_cruise_property_id`; no separate 7-night itinerary authoring when the Program Composer can combine 3+4 nights or reverse routes.
3. `related_program_terms` is the itinerary authority. Keep `cruise_runs` as legacy schedule evidence; Tour Rate is the authority for season, sailing direction, occupancy, property allocation and pricing. Do not convert duration-options IDs into Program taxonomy IDs.
4. The Property is the source of amenities and its provenance. Read `amenities` terms from the Property and merge only verified Tour overrides. Missing amenities need a Content Job + approved web-scraping source, NEVER fabrication or an inferred supplier promise.
5. Draft saves must be possible while meta is incomplete: native `is_required=false` for progressively completed fields, preserve JetEngine `conditional_logic` and helpful descriptions. Ready to Publish is a separate data-dependency check, not a second field builder. Field selections remain site-local and dynamic.
6. Before reconciling a schema, call `wp mad4b nile-jet-schema inspect`, capture both full SHA256 fingerprints and a private preimage backup. A write requires an approved company operation and the exact SHA256 of both schemas, not merely a newer plugin version. Never write on `admin_init`, frontend GET, or automatic page load.
7. Preserve WPML locale-specific Tour content and site-local Property/Program/Rate identities; do not clone company metadata or overwrite existing attachments, forms, rate settings, or custom fields.
8. After an authorized change, read the native JetEngine schema back and verify unique fields, conditional logic, Program choices, vessel options, Draft saving, and a controlled blocked publish attempt. Do not claim visual completion without actual browser acceptance on desktop/mobile.

Return: `site`, `tour_id`, `mode`, `schema_before/after`, `programs`, `property`, `missing_fields`, `dependencies`, `approval_required`, `rollback`, `test_receipts`. Prefer an explicit partial result over guessed data.
