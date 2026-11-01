# Task-aware Skill matching and composition v1

This read-only follow-on to the external Skill source explorer does not expand the write surface or convert third-party text to trusted instructions.

## MCP read interfaces

- `mad4b/skill-match-task`: bounded `task` (3–4000 UTF-8 bytes); optional `topics`, `source_ids`, `source_offset` and `max_results` (1–12). Returns candidates with matched terms, explicit source-page coverage and failures.
- `mad4b/skill-composition-plan`: same input; returns an auditable, non-authorizing sequence and plan digest. `execution_ready=false` by design.
- Both use the existing read permission and the same Abilities/MCP bridge for ChatGPT, Codex and other enrolled clients.

## Constraints and provenance

- Local candidates come from enabled managed Skill Registry entries, preserving logical ID and content SHA-256.
- External candidates come only from administrator-configured sources. Their provenance includes repository, ref, tree SHA, file path and Git blob SHA.
- Matching reads source paths and names only. Composition optionally retrieves at most two exact-blob verified external Skill documents per request, extracts bounded frontmatter descriptions/compatibility and heading labels as **untrusted advisory metadata**, and never treats the body as executable instructions.
- Ranking is deterministic lexical matching, NOT embeddings or reliable semantic intent inference. A client may supply topic hints, but hints convey no authorization.
- Up to six external sources are queried per source page. Follow `next_offset` for additional sources. Page-local rankings are not global rankings.
- External errors (including GitHub rate limits) are reported; no silent full-coverage claim.
- No executable plan, installation, grants, implicit production scope or cross-environment authorization. Any future Runner bridge must reverify exact content and separate permission/owner gates.

## Verification

```sh
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-skill-task-composition.php
php wp-content/plugins/mad4b-site-control-plane/tests/skill-task-composition-runtime.php
php wp-content/plugins/mad4b-site-control-plane/tests/external-skill-sources-runtime.php
```

Fixture tests do not certify live MCP, GitHub egress, host dispatch, Staging or Production. Before any merge, require current-head CI, live read permissions and zero regression in managed reconciliation and snapshot identity.

## Next phases (not implemented in this PR)

- Provenance-pinned metadata/YAML enrichment and semantic reranking.
- Dependency and conflict graph composition across multiple sources.
- Governed preflight and authorized handoff to the existing Control Plane Runner.
- Evaluation harness, outcome telemetry and human-reviewed routing accuracy.


## Routing refinement (2026-10-26)

- Direct Arabic tasks and common English variants use a **small, explicit bilingual intent lexicon** to align terms like `عالج`, `اختبر`, and `الأمان` with `debug`, `test`, and `security`. This is terminology normalization, not a trained semantic model, translation API, or a guarantee of correct matching.
- Composition can choose up to two nonredundant Skills in the same lifecycle stage when their matched terms offer additional coverage. It does **not** prove actual interoperability or parallel execution; the plan remains sequential and not authorized to run.
- TDD belongs in the implementation stage, with separately selected verification and security reviews afterward.
- Added regression fixtures cover bilingual direct tasks, overlapping phase candidates, complementarity and monotonic plan ordering.
- Staged integration still requires current-head PHP compatibility, WordPress Abilities registration tests, external source permission tests and live MCP/egress acceptance before merge.


## Continuation and coverage guarantees

- Clients may provide optional `task_context` (at most 4000 valid UTF-8 bytes) alongside commands such as `تابع`. The router tokenizes the supplied task and context together and binds both to the SHA-256 request identity. Context is supplied by the caller; the Control Plane does not invent earlier conversation state or silently query a user conversation.
- `source_page.page_complete` means that the *requested page* was fully attempted with no source failures. `source_page.complete` is conservative: it is `true` only when one request starts at offset 0, exhausts all selected sources, and has no failures. Multiple pages are **not** certified complete merely because the last page was fetched. Callers must assemble a complete, provenance-checked inventory independently before claiming global coverage.
- No selection from this endpoint grants execution, imports external scripts or settles the compatibility of independently authored Skill instructions.

## Smart context composition and conflict precedence (candidate)

When a matching local managed Skill and an external Skill share a normalized name, the result creates a **knowledge family** rather than suppressing external material. The managed Skill remains the primary source for any subsequent authorized execution. Up to three external source references (each tied to source ID, repository, Git tree, path and Git blob SHA) are attached; extra candidates are counted in `context_companions_omitted`. Matching is still lexical and does not claim semantic equivalence from names alone.

For selected families, `skill-composition-plan` reads no more than two already-registered external `SKILL.md` resources using the existing verified source API. It checks source ID, path, blob, file length, SHA-256, UTF-8, and untrusted provenance before extracting only short descriptive metadata and headings. It does **not** copy or execute external instructions/scripts or import third-party Skills into Managed Registry.

`composition_context` contains:
- `managed_context`: canonical local logical ID, SHA-256, and description
- `external_contexts`: exact provenance and optional `verified_untrusted_metadata` or `unavailable/not_loaded_budget` status
- `shared_topics` and `additional_external_topics`: bounded lexical hints, not automatically approved operations
- `execution_precedence=managed`, `external_instructions_applied=false`, `review_required_before_mutation=true`
- `conflict_status=potential_conflicts_not_semantically_verified` and explicit resolution policy to retain advisory knowledge but follow managed execution constraints

**Important limit:** This is an evidence-backed composition and safe default for conflicts, **not** an LLM-derived comparison of full documents, verified compatibility resolution, or a Runner. Genuine contradictory requirements remain open for review. Execution and authorization remain independent of these plans. Error and budget states never silently become trusted content.

The previous response field `external_name_collisions_suppressed` remains zero for compatibility with existing callers; `external_contexts_attached` records retained context, and omitted sources are counted explicitly.

## Shared outbound time budget (integration candidate)

Task matching and composition use **one request-relative deadline** of 18 seconds for source inventory and advisory enrichment together. The library still defaults to a 12-second HTTPS request timeout for direct source reads, but the composer now forwards its same deadline through source enumeration, Git tree reads, and Git blob reads. The outbound transport timeout is conservatively capped to the integer seconds remaining at each network dispatch. When under one second remains, no new outbound request starts.

Discovery exposes `source_page.budget_exhausted` and marks the page and global coverage incomplete when that budget stops a source scan. An advisory read that runs out of budget stays unavailable or `not_loaded_budget`; the managed Skill remains the primary source and `execution_ready` remains false. Matching/pagination remain non-authorizing and **partial content is not certified complete**.

This is **bounded network dispatch**, not a real-time hard wall-clock guarantee: transport libraries, DNS, PHP scheduling, and response processing can add overhead. CI should verify end-to-end service latency and the performance reviewer must approve the final timeout policy independently.
