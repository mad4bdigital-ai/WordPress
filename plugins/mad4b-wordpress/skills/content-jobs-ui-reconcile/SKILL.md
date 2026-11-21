---
name: content-jobs-ui-reconcile
description: "Mandatory scoped Start Company Reconcile preflight and post-apply acceptance using original MAD4B Content Jobs, Provider Registry and browser evidence."
---

# MAD4B Start Company Reconcile — mandatory Content Job skill

Scope: company provisioning/reconcile and governed repair jobs only, never unrelated editorial jobs. This file grants no permissions and defines no parallel executor.

## Before any reconcile mutation
1. Bind the exact Company Manager company code, tenant/site UUID, target environment, canonical source/target origins, current release hashes and active WPML languages from original registries.
2. Discover original native provider abilities via Content Jobs, Relationship Graph, JetEngine/Elementor, WPML, Astra, Market/Nationality, JetSmartFilters and WordPress. An unavailable provider is BLOCKED_PROVIDER_NOT_BOUND, not PASS.
3. Plan native Content Jobs by graph dependency; retain each job ID, provider ID, identity, content ownership, term/post/media relationships, locale, source hash, rollback and readback requirements.
4. Validate destinations (public status, featured where required, featured image, live published-tour relations), translated term mapping, and derived counts. Never accept stale term count alone or publish Draft QA tours.
5. Verify Top Bar structure, Country/Passport switcher bootstrap, menu source and presentation, Elementor data, Astra customizer and generated CSS/cache before determining source deltas.
6. Permit repair planning when browser acceptance has not yet passed. Actual repair mutation still requires the original site-native authorization; never grant approval from this skill.

## After native apply; before closing Reconcile
7. Require source-backed readback per provider, company, site UUID, locale and job ID. Missing/stale evidence is UNTESTED and blocks final admission.
8. Test actual interactions independently: menu trigger/navigation, tour cards/CTAs, Grid-to-List and List-to-Grid, country/nationality popups, share action, Extra select/remove, JetSmartFilters AJAX and visible condition rendering. Check overlay hit testing and keyboard/touch activation.
9. Verify no literal shortcodes (term_pick, extra_select_actions or others), destination eligibility/count parity, translated menus/templates, HTML structure, computed CSS and required assets.
10. Inspect enabled languages and responsive viewports; a single HTTP 200 or identical file SHA never certifies functional parity.
11. If FAIL, UNTESTED or BLOCKED, leave Company Manager Reconcile open and emit exact defects into existing Content Jobs/Alerts. Do not create a financial Woo Order, supplier request, or commercial publication receipt.
12. Never mark PASS by creating synthetic evidence. Preserve existing signed Native Executor, Central Write Acceptance, post-write browser and rollback receipts.

See references/content-jobs-reconcile-contract-v1.json for the provider intent and acceptance matrix.

## Dynamic provider and DevOps skill selection

Company Manager invokes the source-native ContentJob Skill Set planner before
its company reconcile flow. Selection is the UNION of always-required skills,
active source WordPress plugin families, theme, service/backend capabilities
and task dimensions. The standard dimensions include backend, frontend,
data, translations, interactions, security, DevOps, CI/CD, deployment,
infrastructure, observability and QA. A caller cannot remove mandatory ones.

Registry sources:
- references/dynamic-skill-family-policy-v1.json (family and dimension rules)
- references/external-skill-discovery-cache-v1.json (untrusted candidate proposals)

The exact registered native abilities are:
- mad4b/content-job-reconcile-skill-plan
- mad4b/content-job-reconcile-skill-acceptance

A missing mandatory installed skill is HOLD. An unreviewed GitHub or external
search candidate is RECOMMENDED_NOT_INSTALLED and cannot satisfy the gate.
External discovery must preserve repository/path/blob SHA/provenance and never
execute, install or grant permissions to external code automatically.

Post-apply acceptance requires independent original provider receipts for
each selected backend/frontend/DevOps dimension and live browser clickability
and locale checks. Neither matching source hashes nor a completed ContentJob
nor an available GitHub Skill can substitute for native write/readback evidence.

Authorized Repair Jobs remain plannable when a tenant fails acceptance; final
Company Reconcile closure must remain BLOCKED/UNTESTED until every required
provider acceptance is independently demonstrated. The original Content Jobs
and Central Write Acceptance retain all mutation authority.

## Mandatory three-layer defect closure (source code / dynamic skills / alerts)
Every newly discovered frontend or translation defect must have three independent acceptance lanes:
1. **Code and data root cause:** identify the real owning Elementor template (including nested `elementor-template` references), JetEngine query/taxonomy/provider, WPML translation family, runtime condition and render. Correct at the original source; never mask an orphan content card with permanent CSS as the sole repair. Check raw `term_relationships` for source+translated term pairs: language-filtered `wp_get_object_terms` can conceal stored duplicates. Preserve exact rollback and verify post-write readback.
2. **Skill coverage:** Company Manager/Content Jobs must select the already registered skills for each plugin family and affected dimensions (WPML translations, Elementor frontend, JetEngine data, browser acceptance, observability). Missing installed skills or incomplete provider coverage is HOLD, not PASS. Every accepted new defect pattern becomes a reusable Skill acceptance case rather than ad-hoc instructions.
3. **Monitoring and task closure:** scan only observed, published frontend surfaces, indexed by frontend surface → page context/type → WPML locale → issue family; stable issue fingerprints, severity from visitor impact, deduplicated native Content Readiness and Fluent Boards linkage where authorized. Never assert a task was created without its native Board task ID. Require rendered mobile/desktop/browser proof and native post-write readback before resolving an issue.
Mandatory regression cases: duplicate translated term relationships (test raw DB); empty information cards even when Elementor contains a dynamic widget but it renders empty; obsolete locale-only grids/containers; mobile booking form position; responsive visibility/RTL; missing translated references; Rate selector, pricing state and JS assets. Page HTTP 200 and matching source code hashes are insufficient. Do not claim complete synchronization if the source-localized nested template graph differs.

## Elementor MCP handshake and CSS authoring awareness
- For every Content Job involving Elementor documents, templates, Listings, translations or presentation, include the read-only `mad4b/content-job-elementor-executor-preflight` and cite the native Elementor MCP state separately from MAD4B Adapter state. Native plugin installed != enabled != an authenticated `initialize` + `tools/list` + schema handshake.
- The official Elementor MCP is a provider-owned route; it must pass its own session identity, target permissions, exact schema and source document revision before routing mutations. Do not create WordPress application passwords or switch Elementor MCP on without the original authorized Elementor admin flow.
- For native Elementor Custom CSS use the visual editor, its responsive controls and native save with actor `unfiltered_html` where required. Do not send CSS through raw `_elementor_data`, WP-CLI, direct SQL or style overrides. Detect entity conversion (`>` -> `&gt;`) before and after, and report a CSS corruption issue with a rollback reference if detected.
- Continue ordinary non-Elementor Content Jobs when native Elementor MCP is disabled. Hold only the affected Elementor MCP-dependent step. After native apply require Elementor/JetEngine/WPML and browser acceptance receipts before resolving.

## Dynamically inherited MCP readiness for every provider family
- Before choosing any native MCP executor for a target Content Job, read `mad4b/content-job-mcp-provider-preflight` with the exact provider_id from the active source provider/ability/transport registry. The generic discovery source is `mad4b/mcp-provider-readiness-matrix`. Never invent or pin provider ids from filenames or assume that every WordPress Ability namespace has a native MCP server.
- Require separate proofs of provider installation, MCP enablement, authenticated initialize, authenticated tools/list, tool schema digest, client/site/tenant identity, capability authorization, cache freshness, and source-native readback. Every provider inherits this workflow; any provider-specific addendum may strengthen, never weaken, identity or write checks.
- Rank Math's vendored MCP DTOs can be loaded alongside the official MCP Adapter; mixed class provenance is a runtime conflict risk. Do not solve it by reinitializing the shared Adapter, changing plugin load order, disabling Rank Math, reusing MAD4B OAuth, or issuing unscoped tokens. Route degraded provider steps to a provider-specific HOLD while independent content work remains plannable.
- Skill selection is the union of active provider families and execution dimensions (backend/frontend/auth/DevOps/QA). Inherited MCP readiness is read-only evidence, never a grant, and a missing external handshake never converts local WP Abilities into vendor-native permission.
- The Elementor special case remains: Custom CSS must be edited in the authorized native Elementor Editor and verified verbatim after native save; do not mutate raw Elementor document metadata.

## Dynamic MCP provider updates: missing static baseline must not stop operations
- A new official provider/Adapter version can appear at any time after a native update. Resolve the *installed* plugin version and its active runtime dynamically; never interpret an absent hardcoded version entry, an old preferred release target, or `baseline_missing` as proof of corruption or a global Control Plane/Content Jobs blocker.
- For unknown MCP Adapter releases, check the installed main-file version and real source layout, preserve read-only discovery/ordinary Content Jobs, and report `updated_release_layout_ready_source_unverified` as a non-blocking advisory. Do not certify the new release by simply hashing untrusted installed bytes.
- On an authorized non-protocol maintenance request, verify installed files against the exact HTTPS official WordPress.org distribution for that release, cache a version-scoped digest of the verified files, and invalidate/revalidate when any verified byte changes. This is dynamic source integrity, not a mandatory manual release certificate.
- Provider-native writes or runtime bootstrap replacement still require original authorization, independent source provenance and a matching authenticated MCP protocol handshake. A mixed Rank Math 0.5 / MCP Adapter 0.7 PHP class set is a real compatibility finding, kept separate from release baseline freshness and MAD4B OAuth.
- Distinguish `read_ready`, `dependency_ready`, and `write_certification_ready` in each receipt. A missing profile may set the last false while the first two stay true. Genuine missing code, mismatched official bytes, mixed vendor APIs, wrong site, or failed provider authorization remain meaningful failures for the affected operation.
