# Contract — Managed Agent Definition v1

**Proposal only. No Agent Registry schema or executable Ability is changed by this specification.**

## Boundary

An Agent Definition is a configuration/instruction artifact. It is **not** an authenticated NHI Agent, transport Subject, human user, provider, OAuth app, grant, scope, approval or job. Its status governs *catalog eligibility*, not runtime authority.

## Canonical input model

```json
{
  "schema_version": "mad4b.agent-definition.v1",
  "definition_id": "wordpress-quality-reviewer",
  "revision": 1,
  "status": "draft",
  "role": "WordPress quality reviewer",
  "mission": "Propose safe, verifiable code reviews",
  "instructions_markdown": "Inspect code, identify risks, report evidence.",
  "skill_refs": [
    {"source_kind": "managed", "logical_id": "site/example/code-review", "content_sha256": "<digest>"}
  ],
  "context_refs": [{"source": "context_registry", "id": "public-doc-handle", "snapshot_sha256": "<digest>"}],
  "declared_capabilities": ["read-code", "propose-tests"],
  "declared_environments": ["staging", "local"],
  "constraints": {"max_context_bytes": 16384, "max_handoffs": 4},
  "provenance": {"kind": "managed", "scope": "site"},
  "execution_authorized": false
}
```

Placeholders above are illustrative, not valid SHA values. The future API MUST reject unresolved placeholders, unknown enum members, embedded secrets, arbitrary urls and runnable tool configs. Declarations never override effective grants.

## Validation

- Require explicit fixed contract/version, normalized safe ID, role, mission, instructions and expected Site Profile scope.
- Role and mission: bounded valid UTF-8; instructions text <=64 KiB. Maximum 16 Skill references, 8 Context references, 16 tags and 16 declared capabilities. Each reference must be typed, resolvable and digest-pinned when enabled.
- Reject unsanitized path segments, symlink escapes, absolute external path, NUL/control chars, duplicate IDs and unsupported parser versions.
- Canonical bytes/hash rules versioned; normalize LF and UTF-8 once for digest, preserve untrusted raw source separately only if policy allows. A digest proves content identity, not truth/trust.
- Revise via compare-and-swap with expected_revision and expected_content_sha256. A new instruction/source/Skill revision invalidates old review status; do not silently auto-enable.
- Managed authoring is administrator-governed; write/readback/audit must either complete atomically or return a durable denial/rollback-required receipt.
- Never store access tokens, private keys, application passwords, arbitrary environment variables or provider credentials in the definition.

## Operations (future)

- definitions-list and definition-get: read-only authenticated surfaces returning a bounded summary and redactable detail; no hidden NHI grants.
- Admin-only create/update/review/enable/disable: distinct governed actions with nonce, capability, expected revision, audit and policy gate; not exposed through phase-1 read MCP.
- Preview returns sanitized Markdown as **untrusted content**, separately from explicitly authorized system/policy instructions.

## Compatibility and precedence

Managed local definition is execution-instruction authority **only after a separate permissioned execution binding**. External same-name definitions may add attributed context, never silently overwrite managed rules. Duplicate enabled definition IDs fail review.

## Error codes

agent_definition_invalid, agent_definition_duplicate, agent_revision_stale, agent_skill_reference_stale, agent_context_reference_stale, agent_authoring_denied, agent_audit_unavailable, agent_definition_disabled, agent_definition_unsupported_version.

**Postcondition:** no NHI/subject/grant/approval side effects; a successful definition save alone never authorizes a tool call.
