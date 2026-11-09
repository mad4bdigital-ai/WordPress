---
name: wordpress-change-safety
description: Review a proposed WordPress change for scope, reversibility, authority, provider certification, and verification requirements before any content, plugin, schema, file, or database mutation is attempted.
---

Use this skill before a WordPress mutation or whenever the user asks whether a proposed change is safe.

1. Read the current runtime authority and the affected provider status.
2. Confirm the exact environment and target object. Never infer that Staging authorization applies to Production.
3. Classify the change as content, provider configuration, plugin state, schema/model, filesystem, database, admin recovery, or breakglass.
4. Identify prerequisites: provider certification, expected-current-state guard, backup or reversible mutation support, approval ticket, exact grant, and verification method.
5. Prefer the narrowest provider-owned mutation path. Do not substitute generic filesystem/database mutation when a bounded provider ability exists.
6. If mutation authority is disabled, stop at a change plan. Do not suggest bypassing the global gate.
7. For destructive or high-impact changes, require explicit confirmation at execution time even when the plan is already approved.
8. Return target, proposed delta, authority state, prerequisites, rollback path, verification, and a GO/NO-GO decision.

## Elementor native MCP and CSS mutation gate
- A local `elementor/*` Ability is not the Elementor-owned MCP handshake. Verify native Elementor MCP `site_enabled`, an actual authenticated initialize/tools-list exchange, exact tool schema, session identity, WordPress role and target edit permission before selecting it.
- For Elementor Custom CSS, use the original editor UI (not raw `_elementor_data` meta, direct SQL, WP-CLI or global injected CSS), with a correctly authorized `unfiltered_html` actor as needed. The registered Elementor metadata sanitizer can replace `>` with `&gt;` for an anonymous operator.
- When a CSS write is rejected or an exact round trip fails, stop, preserve the original, use the native editor to restore it and require computed-style browser readback. Never turn off Elementor/WordPress sanitization or invent a passing receipt.
- Treat an unavailable Elementor MCP as a route-specific HOLD; do not block unrelated editorial jobs or grant site/production authority by changing a policy file.

## Multi-provider MCP isolation and inherited handshake preflight
- Inspect provider and shared class provenance with `mad4b/mcp-provider-readiness-matrix`. A mixed Rank Math / official Adapter class set is a compatibility finding, not authorization to rewrite loaded classes or run a repair in the OAuth/MCP protocol request.
- Every external native MCP peer needs its own authenticated initialize, tools/list, schema, principal, site/tenant and exact tool-binding evidence. A registered Abilities namespace or generic MCP Adapter installation never substitutes for those proofs.
- Do not share MAD4B Control Plane OAuth grants/tokens with Elementor, Rank Math or any third-party MCP provider. Never create credentials, enable a provider MCP endpoint, restart a class loader, or switch server identities automatically as a diagnostic.
- If a provider-specific MCP read/write route is unavailable, mark that route UNVERIFIED/HOLD; existing MAD4B Control Plane connectivity and unrelated Content Jobs stay independent. Native mutation uses only the original scoped authorized executor and readback.

## Dynamic MCP provider updates: missing static baseline must not stop operations
- A new official provider/Adapter version can appear at any time after a native update. Resolve the *installed* plugin version and its active runtime dynamically; never interpret an absent hardcoded version entry, an old preferred release target, or `baseline_missing` as proof of corruption or a global Control Plane/Content Jobs blocker.
- For unknown MCP Adapter releases, check the installed main-file version and real source layout, preserve read-only discovery/ordinary Content Jobs, and report `updated_release_layout_ready_source_unverified` as a non-blocking advisory. Do not certify the new release by simply hashing untrusted installed bytes.
- On an authorized non-protocol maintenance request, verify installed files against the exact HTTPS official WordPress.org distribution for that release, cache a version-scoped digest of the verified files, and invalidate/revalidate when any verified byte changes. This is dynamic source integrity, not a mandatory manual release certificate.
- Provider-native writes or runtime bootstrap replacement still require original authorization, independent source provenance and a matching authenticated MCP protocol handshake. A mixed Rank Math 0.5 / MCP Adapter 0.7 PHP class set is a real compatibility finding, kept separate from release baseline freshness and MAD4B OAuth.
- Distinguish `read_ready`, `dependency_ready`, and `write_certification_ready` in each receipt. A missing profile may set the last false while the first two stay true. Genuine missing code, mismatched official bytes, mixed vendor APIs, wrong site, or failed provider authorization remain meaningful failures for the affected operation.

## Cross-settings mutation safety and rollback

For any reconciliation involving JetEngine Options Pages, WooCommerce or MAD4B Company Options, first assemble a per-field owner/read-write-consumer matrix and run a read-only snapshot. Validate scope, current source fingerprint, native API availability, permissions and expected value before writing. Do not mirror unrelated fields just because labels overlap (Woo taxes versus Tour tax overrides; Woo order sender versus public contact; cart checkout versus Tour booking eligibility). Avoid side-effect writes in `admin_init`, plugin GET, WPML language discovery or render hooks. Preserve existing manual Tour overrides and original company/market/language boundaries. After a native bounded write, confirm value, downstream readback, templates/filters, Woo behavior, WPML translations, and cache invalidation; keep precise rollback metadata. A Skill is documentation, never a substitute for a provider or site owner's write authority.
