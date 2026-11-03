# MAD4B ContentJob multilingual relationship dependencies

## Reuse the existing native foundation

This module is **not** a second translation group manager. Native source owners remain:

1. `MAD4B_SCP_Translation_Bridge_Adapter` (`mad4b.translation-bridge.v1`) for source and linked Post identity, WPML/Polylang provider selection and group readback. The new graph calls that existing adapter twice and requires a stable native snapshot.
2. `MAD4B_SCP_ACI01_Native_Relation_Audit` for its existing stricter external post-identity/readback certification where that governed surface is invoked; a group alone does not prove translated terms, media, metadata or publication.
3. `MAD4B_WPML_ContentJob_Adapter::binding()` in the earlier `350-wpml-job-adapter.php` for already-ingested WPML post translation jobs. It reads the original source/target IDs, locale, native WPML job, Elementor localization outbox, review blockers and original ContentJob; no second fragile parser for `wpml-ref` subject strings.
4. WPML native `icl_translations` and `term_taxonomy` for **term identity only**, because the existing Translation Bridge currently exposes Posts but not term translation groups.
5. Existing `MAD4B_SCP_Content_Jobs` and its Brand Core `dependency_plan` for job ownership, original revision, state, transport/authority. The new projection is *additional advisory evidence* in the same ContentJob; it never replaces the original plan, changes state or issues a write grant.
6. Existing `MAD4B_WPML_Structural_Sync_V2` and `MAD4B_WPML_Technical_Copy_V1` retain Elementor/widget layout and text-free template handling. This dependency graph does not copy or translate those items.
7. Enrolled site-owned resolver is the only source for **site-local** post and term identities. Never copy a numeric ID from another website or assign target-language IDs by a shared slug/name. Source-project hubs may supply catalog/dependency context but cannot override native target readback.

## Content Job workflow

`mad4b/content-job-relationship-plan` is a native **read** WordPress Ability registered by `MAD4B_SCP_Content_Jobs`. Inputs: exact `job_id`, optional local artifact ID and kind. It reuses original source identity (for a known Elementor section-job subject, or verified readiness-issue `wpml-url-issue-ref` bound to the same Job). No regex of arbitrary numbers in an article's title is accepted.

The graph resolves an existing family across all linked languages, not just English. Responses include: native provider and group ID, exact localized member and status, source evidence, graph fingerprint, current ContentJob revision, and a durable dependency state:

- `READY`: a provided artifact is exactly the native member in the requested locale and family.
- `WAITING_ARTIFACT`: native target exists but is not associated with the ContentJob's artifact; send a bounded binding/review task, **do not create a duplicate**.
- `WAITING_DEPENDENCY`: no linked local target; use the configured active-session ChatGPT/MCP or verified API translation provider, then original reviewed writeback/acceptance.
- `HOLD`: mismatched site, provider, translation group, type, language, artifact or ambiguous duplicate; require reviewed source resolution.

For typed relationships (taxonomy, post links, attached media, parent, template, query reference), source-owner modules may add read-only metadata using `mad4b/contentjob/relationship_graph_extensions`. No financial/supplier/rate relationship is guessed or copied by default. A proposed relationship mutation must pass the original native permission, the Central Write Acceptance proof and independent native readback, with uncertain/partial outcomes held rather than blindly retried.

The existing Brand Core `dependency_plan` adds `localized_relationship_graph` on translation jobs without overriding `resume_ready` or automatic continuation. The experimental parallel MU relationship write router (`364-...`) was disabled on Staging to prevent overlapping authority.

## Staging acceptance — 2026-11-02

14/14 PASS (read-only): source Post 57562 maps via existing Translation Bridge (WPML trid 1218037) to Spanish 57563 and Italian 57564; missing French holds; wrong type holds. Source destination term 2352 maps through exact native tax family trid 419051 to French term 2951; missing German holds. Existing Kom Ombo French ContentJob `fe3d871e-ee50-435c-93fb-63bcdb1bf3b7` sees existing target 2951 as `WAITING_ARTIFACT`. Existing Elementor French ContentJob `2158e5c3-7534-4435-a311-93b5da3542d4` finds native linked template 39535, while a foreign artifact is rejected. Existing native WPML ContentJob `ee20798f-4e3c-41de-aafc-96a73c05bae7` reuses the earlier binding's source 43704 and linked Spanish target 56139; the original outbox readback remains unverified and is **not** overridden. The original Kom Ombo Job remains `WAITING_REVIEW` rev 4. No new posts, terms, financial relationships or publication.

Existing localized objects are *not* proof of editorial quality or permission to publish. This is a general read-only dependency and identifier resolver with existing authorized writeback paths remaining separate.
