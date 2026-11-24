# MCP entity references — ID + Name + Type on MAD4B (23 Nov 2026)

**Scope:** Tours.dev live source and Wayfatours live target. The user requires identifiable references in every MAD4B ChatGPT answer; numeric IDs alone are not useful for editorial decisions or translating the right Program. This does **not** change WordPress titles or WPML ID-copy policy.

## Governed read contract

A read-only, authenticated MCP Ability has been added to the existing **MAD4B Read** server:

- Ability: \`mad4b/entity-reference-resolve\`
- Input: \`references: [{id, field? | entity_type?, entity_kind?}]\`, up to 30 per call; optional \`language\`.
- Output items: \`id\` (unchanged caller ID), \`resolved_id\` (localized WPML ID when available), \`name\`, \`display_label\`, \`type\`, \`type_label\`, \`entity_kind\`, \`language\`, \`status\`, \`resolution_status\`, \`translation_fallback\`.
- Supported WordPress posts: Tours, Properties, Units, Tour/Property/Transport Rates, Transportations, Guides and Rate Plans. Taxonomy references: Programs, Package Levels, Destinations, Room/Board Types and relevant tour/property taxonomies.
- Infers unambiguous kinds from fields such as \`program_id\`, \`tour_id\`, \`property_id\`, \`unit_id\`, \`rate_id\`, \`tier_id\`. A naked \`2348\` without type is *ambiguous* and cannot silently be called a Tour or Program. Rates use actual post type after verifying it is a Rate CPT.
- Honors both MAD4B governed read permissions and WordPress \`read_post\`. Unreadable/unknown/sensitive IDs are returned without invented names. No WooCommerce Order, customer PII, user records, arbitrary DB table or credential access.
- Language name resolution calls WPML and temporarily switches the language for the term read; it restores the previous language in \`finally\`. E.g. canonical Program ID 2348 and translated Program ID 2445 stay distinct fields.
- Additive API; existing read ability responses and input/output schemas remain unchanged. The identity resolver is an additional read and must be invoked by the assistant when a preceding MCP result contains only IDs. **Do not claim every unrelated MCP tool automatically changes its raw JSON output.**
- MCP resolver is discoverable with \`mad4b/tool-discover\` / \`mad4b/tool-info\`, then executed via \`mad4b/read-execute\` with its governed signed preparation receipt. Real ChatGPT Wayfatours connector verified 5/5 references, including localized Spanish Tour 60629.
- Read-only runtime tests verified on Tours.dev and Wayfatours with Programs (2348/2349), Tour 60629, Property 36084, Package Level 2354, an actual Tour Rate and an actual Unit. Invalid typed term IDs, absent IDs and ambiguous naked IDs were explicitly rejected; Spanish 2348 resolved to 2445 without changing source ID.

## ChatGPT presentation policy

In summaries and operational tables show **ID | Name | Type**, optionally **Language | Status**. For any ID-only WordPress MCP result, collect up to 30 references with their field contexts and call the resolver. Do not invent names, and do not use the displayed name as a stable identifier for writes. Show the exact site/environment when records differ. If name lookup fails, say "الاسم غير متاح" rather than omit the row or invent a name.

## Implementation and rollback

- New read-only MU file: \`wp-content/mu-plugins/mad4b-mcp-entity-labels-v1.php\`.
- Existing, source-first Read MCP core catalog mounts \`mad4b/entity-reference-resolve\` without changing old abilities.
- VPS live checkpoint, test artifacts, and prior core catalog backup: \`/opt/mad4b-package-checkpoints/2026-11-23-mcp-entity-labels-v1/\`.
- For rollback, remove only the new MU plugin and restore only the core catalog preimage after reconciling subsequent file changes; never overwrite other source fixes.
- Git branch: \`feature/itinerary-overnight-native-stages-20261122\`, PR #1046, still subject to approval and CI. The live source catalog contains additional, older host hardening not found in the previous Git branch snapshot; source-first reconciliation must **not** erase those fixes.

