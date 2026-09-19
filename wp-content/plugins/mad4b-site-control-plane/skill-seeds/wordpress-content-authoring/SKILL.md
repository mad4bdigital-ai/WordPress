---
name: wordpress-content-authoring
description: Create or update brand-bearing WordPress content only after governed Brand Context is loaded through the exact MAD4B Context Preflight and bound to the intended mutation ability.
---

Use this skill for brand-bearing WordPress content creation or editing.

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
