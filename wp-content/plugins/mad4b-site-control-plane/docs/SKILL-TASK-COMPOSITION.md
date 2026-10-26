# Task-aware Skill matching and composition v1

This read-only follow-on to the external Skill source explorer does not expand the write surface or convert third-party text to trusted instructions.

## MCP read interfaces

- `mad4b/skill-match-task`: bounded `task` (3–4000 UTF-8 bytes); optional `topics`, `source_ids`, `source_offset` and `max_results` (1–12). Returns candidates with matched terms, explicit source-page coverage and failures.
- `mad4b/skill-composition-plan`: same input; returns an auditable, non-authorizing sequence and plan digest. `execution_ready=false` by design.
- Both use the existing read permission and the same Abilities/MCP bridge for ChatGPT, Codex and other enrolled clients.

## Constraints and provenance

- Local candidates come from enabled managed Skill Registry entries, preserving logical ID and content SHA-256.
- External candidates come only from administrator-configured sources. Their provenance includes repository, ref, tree SHA, file path and Git blob SHA.
- No upstream instructions or scripts are downloaded as part of matching; external descriptions are inferred from path/name alone.
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
