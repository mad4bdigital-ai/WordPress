# Research & Baseline — Feature 009

**Checked:** 2026-10-27, repository master SHA 176667ec044be272146b243c37a82e7b6f7f2db4. Source observations are file-backed, not live WordPress claims.

## Verified current code

| Existing component | Responsibility | Feature 009 decision |
|---|---|---|
| includes/class-mad4b-scp-agent-registry.php | NHI agent create/disable, authenticated subjects, exact grants, deny precedence, revision CAS | Retain; an Agent Definition is a different object and MUST NOT extend grant semantics by default |
| includes/class-mad4b-scp-skill-registry.php | File-backed managed Skills, directory hierarchy, bounded SKILL.md, save/audit gates | Reuse file-backed patterns for definition content, not Skill IDs or authoring authority |
| includes/class-external-skill-sources.php | Configured public GitHub origins, exact tree/blob verification, bounded external reads | Reuse shared read/egress primitive after extracting source-neutral bounded reader; avoid copy/fork |
| includes/class-skill-task-composition.php | Task matching; managed-source precedence; 18-second shared source read budget; nonauthorizing plan | Reuse scoring/provenance patterns, create new typed Team Plan instead of treating agents as skills |
| includes/class-mad4b-scp-unified-agent-connection.php | Authenticated connection setup, subject binding, read grants | Remains identity/connection layer, not a definition importer |
| includes/class-mad4b-scp-durable-execution.php | Idempotency/leases/outbox and reconciliation after authorization | Reuse on separately approved Phase 4 only |
| specs/006-agent-governed-reversible-control-plane | Identity/grants/audit/approvals | Governing authority for any execution |
| specs/007-content-intelligence-workflow-platform | Workflow provider and execution design | Compose with, do not replace |
| specs/008-dynamic-content-runtime-hardening | Runtime recovery and evidence semantics | Honor on execute path |

## Upstream guidance consulted

- addyosmani/agent-skills, skills/api-and-interface-design/SKILL.md, main at Git blob 3e2e37f8f4fba2c66f9f1599eaf01a55ae3f3e33: contract-first, input/output separation, error semantics, idempotency.
- addyosmani/agent-skills, skills/context-engineering/SKILL.md, main at Git blob fb8bb5f85128302b231d301c1201da1e223f6869: layered scoped context and conflict handling.
- addyosmani/agent-skills, skills/documentation-and-adrs/SKILL.md, main at Git blob ecc28cef82b65be804329b242f6399bce46987fd: document ADR rationale and existing repo conventions.

Third-party source instructions are references, NOT runtime policy.

## Architecture decisions (proposed)

- ADR-009-01: **Separate Agent Definition from NHI Agent Identity.** Existing table is authoritative for principal/permissions; definition metadata is nonauthorizing and can be reused by multiple authorized principals.
- ADR-009-02: **Shared source adapter, not cloned GitHub client.** Agent explorers use the existing pinned provider/egress control with their own allowlisted relative paths and parser.
- ADR-009-03: **Local managed precedence + external companions.** Companions are attributed advisory metadata and cannot silently override the managed definition.
- ADR-009-04: **Two-phase control.** Build read-only matching/Team Plans first, then propose an independent governed execution handoff after separate review and acceptance.
- ADR-009-05: **Format adapters are explicit.** Supported profile candidates: Claude-style .claude/agents/*.md, .github/agents/*.agent.md (only when format verified) and a portable AGENT.md managed schema. AGENTS.md is project instructions/context only, not automatically an executable Agent Definition.
- ADR-009-06: **Identity-aware delegation is explicit.** A specialist can only execute through an explicitly selected enrolled runtime identity after grants/preflight; the planner never issues credentials.

## Open questions for implementation, not blockers to this specification

- Which external Agent file dialects and frontmatter keys are sufficiently stable? Admit only after fixture validation, feature-by-feature.
- Which subset of Model provider metadata is suitable for portable definitions? Treat unsupported model preferences as advisory/not-supported.
- Where best to persist managed definitions under WP_CONTENT_DIR without colliding with NHI registry and with reversible lifecycle? Phase 1 characterization and security review decide.
- How to represent context artifact handles and retention without duplicating Context Registry? Use references to existing context authority.
- When a team has multiple valid specialists, should ties be deterministic or delegated to human review? V1 deterministic ranking, warn on tied/ambiguous candidates.
