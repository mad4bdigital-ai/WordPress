---
name: wordpress-content-authoring
description: Create or update brand-bearing WordPress content only after governed Brand Context is loaded through the exact MAD4B Context Preflight and bound to the intended mutation ability.
---

Use this skill for brand-bearing WordPress content creation or editing.

## Recover missing Brand Core before continuing the SAME request

A blocked Context Preflight is a dependency, not a reason to abandon the requested content task.

1. On mad4b_required_brand_context_unavailable or mad4b_content_context_receipt_required, call mad4b/brand-content-gate-triage and context/brand-core-control-loop automatically. Never blindly retry the blocked write.
2. Create ONE internal mad4b/content-job-create intake record with subject, language, target post type and source reference only; obtain its normal exact approval. This intake is not a WordPress post, Brand approval or publication. If an existing matching ContentJob is already known, reuse its ID instead of creating a duplicate.
3. Call mad4b/content-job-dependency-plan with the exact job ID. Follow its first unsatisfied recovery step in order. Inspect original governed assets, source scan and review queue before proposing creation; do not promote operational notes or unreviewed third-party content into Brand Strategy.
4. Existing assets are repaired or approved only through their exact Context Authority review operations. Create missing tone_of_voice and editorial_guidelines drafts using context/brand-gap-plan and context/brand-draft-preflight, then the governed draft/materialization/review abilities. Brand Strategy requires first-party evidence and an independently reviewed authority; do not auto-generate or auto-approve it as fact.
5. Each mutation requires its own eligible provider, exact approval and readback. Upon failure or pending human review, keep the original ContentJob in BLOCKED or WAITING_REVIEW, report the exact next action, and do not create or publish content as a workaround.
6. Refresh mad4b/content-job-dependency-plan after each successful step. When resume_ready=true, follow its revision-fenced mad4b/content-job-transition for the same job ID, then get a NEW mad4b/skill-get Context Receipt and create the draft using the separately approved content mutation. Check for an existing post before any retry; publish only with an additional explicit publication approval.

The client/workflow runner may advance automatically through safe reads and evidence preparation. It must not mint owner attestations, invent approved source evidence, or perform a write from read-only endpoints.


1. Call `mad4b/skill-get` for this exact Skill before any mutation, supplying the exact `intended_ability` and the task scope when one exists.
2. Require the Context Preflight to return `ready=true`. Never bypass missing, stale, incomplete, unavailable, conflicting, or unreviewed mandatory Brand Context.
3. Treat `brand_strategy`, `tone_of_voice`, and `editorial_guidelines` as mandatory Brand Core. Use `terminology`, `claim_policy`, `seo_strategy`, and `writer_reference` when available and relevant.
4. Treat writer references as structural/editorial references only. Do not impersonate, reproduce, or claim the identity or distinctive voice of a referenced writer.
5. Use the returned Context Envelope to prepare the content and preserve the exact Context Receipt for the intended mutation. A receipt issued for another ability, Skill, registry revision, authority fingerprint, or stale context must not be reused.
6. The Context Receipt does not grant mutation authority. The write still requires the normal mutation gate, exact NHI grant, one-time approval, provider certification, replay protection, execution, and readback/evidence.
7. Use only the narrowest provider-owned write ability for the requested change. Do not substitute generic filesystem, database, Raw SQL, or Breakglass paths.
8. Never infer that Staging authority applies to Production. Production requires its own explicit authority.
9. If Brand Context or the source registry changes after preflight, rerun `mad4b/skill-get` and use the new receipt before writing.
10. After execution, read back the affected WordPress object and verify the intended content change and governance evidence.
