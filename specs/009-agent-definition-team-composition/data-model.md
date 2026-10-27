# Data Model — Definition ≠ NHI ≠ Authority

**Status:** Proposed shape for future implementation, not an existing DB migration. No new schema is authorized by this Spec Kit.

## Identity separation

- **AgentDefinitionId**: stable slug/UUID scoped to Site Profile and owner namespace. Represents instructions/role, never an authenticated actor.
- **AgentDefinitionRevision**: monotonically increasing revision with immutable content SHA-256. CAS required for edit/disable.
- **AgentSourceIdentity**: repository/source reference + tree/path/blob SHA + parser version; purely read-only provenance.
- **NhiAgentPublicId**: existing authoritative MAD4B Agent Registry principal, managed independently.
- **AgentExecutionBinding**: future explicitly approved mapping (definition digest + NHI public ID + Site Profile + effective environment + runtime availability); never stored as an unreviewed annotation on the definition.
- **TeamPlanId**: SHA-256 digest of canonical read-only plan input/output and all selected definition/skill/context snapshots. Not a job, grant or approval token.
- **OperationId**: only issued by the existing runtime on independently authorized execution, outside initial phases.

## Managed AgentDefinition (proposed)

| Field | Type | Invariants |
|---|---|---|
| definition_id | bounded identifier | unique within Site Profile/owner namespace |
| schema_version | string | fixed supported v1; unknown versions fail |
| revision | positive integer | monotonic CAS |
| state | draft, reviewed, enabled, disabled | imported definition starts draft/disabled |
| role, mission | bounded UTF-8 text | no secrets or runnable config |
| instructions | bounded Markdown | stored as text; sanitized preview; never authority |
| instruction_sha256 | 64 hex | exact raw/normalized digest documented |
| skill_refs | list | explicit logical managed Skill ID + content digest; external references are advisory |
| context_refs | list | existing Context Registry references/snapshot digest, least privilege |
| capabilities_declared | list | informational requirements only; never grants |
| environments_declared | list | compatibility declarations only; never environment authorization |
| constraints | bounded object | max task length, context bytes, handoff count, timeout hints |
| provenance | object | local revision or remote immutable source coordinates |
| audit | pointer | existing append-only audit lineage / change receipt |
| created_by, updated_at | opaque actor/time | no secrets |

**Default behavior:** disabled draft, no inferred tool access, no automatic NHI creation. The content digest does not represent trust or approval. Enabling the definition affects candidate eligibility ONLY, not authority.

## External AgentCandidate (read only)

```json
{
  "kind": "agent_candidate",
  "format": "claude-agent-md",
  "source_id": "sample-public-source",
  "repository": "owner/repo",
  "resolved_ref": "refs/heads/main",
  "tree_sha": "40-hex-git-tree",
  "path": ".claude/agents/reviewer.md",
  "blob_sha": "40-hex-git-blob",
  "content_sha256": "64-hex-content-digest",
  "parse_status": "recognized",
  "trust": "external_untrusted",
  "execution_authorized": false
}
```

The illustrative digest strings above are placeholders, not valid runtime hashes. Parser MUST return a typed failure if actual hashes do not match exact hexadecimal constraints. Unsupported dialects remain `unknown_format`; ambiguous AGENTS.md becomes `project_instructions`, not AgentCandidate.

## TeamPlan (read-only)

- request: normalized task digest, optional bounded context digest and Site Profile scope, plan version.
- selected: one supervisor role plus zero-to-four named specialist role slots.
- each slot: definition_id + revision/content digest + matched Skill IDs/digests + context handles/digests + role responsibility + explicit read-only eligibility.
- steps: at most eight deterministic proposed steps, dependency IDs, handoff reason and expected artifact type; DAG only, no cycles.
- result: plan SHA-256, warnings, conflicts, source coverage, ignored candidates, budgets, execution_ready=false, execution_authorized=false.
- execution_plan: absent in phase 1–3. An independent Phase 4 mapping must produce new bound identity and approval receipts, not mutate this proposal.

## State transitions

Managed definition: absent → draft(disabled) → reviewed → enabled → disabled; a reviewed update produces a **new revision requiring renewed review**, never silently maintains a previous approval. Deletion (if ever implemented) requires tombstone, author check and audit.

External candidate: discovered → parsed → validated or rejected → advisory only. It has no enabled/executing state.

Team plan: proposed → reviewed or abandoned/stale. An executable operation is a separate immutable linked object, created only by the existing approved runtime.

## Persistence decision

Prefer bounded file-backed managed-definition storage outside vendor plugin roots using existing Skill Registry atomic/audit patterns, subject to path and CAS tests. Reuse NHI relational schema without adding role/instruction text to its grant columns. Do not create production tables in Phase 0.
