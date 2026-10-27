# Implementation Plan — Feature 009

**Status:** Spec-only. Implementation PRs, deployment, migrations, registry writes and MCP Ability registration are **not** authorized by this plan.

## Architecture and responsibility boundaries

```text
User task + authorized context references
  -> Agent Catalog [Managed Definition Reader + External Explorer]
  -> Agent Matcher [bounded, provenance/eligibility, nonauthorizing]
  -> Team Composer [1 supervisor, <=4 specialists, DAG <=8 steps]
  -> Immutable advisory Team Plan [digest, conflicts, incomplete coverage]
  -- separate explicitly approved Phase-4 boundary -->
  Existing NHI Agent Registry + policy and scoped grants
  -> Existing workflow/preflight, approval, budgets and provider checks
  -> Existing durable runner / receipt / audit
```

## Owned modules (proposed)

1. **Agent Definition Reader/Registry**: new typed definition metadata, revisioned CRUD under admin governance. No modifications to NHI Agent Registry grants/subjects.
2. **External Agent Source Adapter**: use the currently pinned public GitHub origin + Egress Policy and Git tree/blob identity. Do not copy the Skill source HTTP client; share the bounded reader after a reviewed extraction.
3. **Agent Format Parser**: allowlisted path/dialect adapters, strict frontmatter validation, max file bytes, no scripts/tool installation or prompt execution; AGENTS.md categorized project context.
4. **Agent Matcher**: deterministic lexical first, optional later semantic module as advisory and separately tested; match against enabled reviewed definitions and external companions.
5. **Team Composer**: role assignment, Skill binding (via existing Skill Registry/Composition), context refs and safe handoff DAG; immutable digest; no new runner.
6. **Agent Studio**: admin-only authoring/read/preview with nonces and audit, separate Identity and Effective Access panels showing existing NHI data as *read-only projections*.
7. **Governed Handoff Adapter** (Phase 4 only): exact context/definition/Skill/site/plan/NHI/grant revalidation; existing execution/approval/receipt implementation. Off by default; new approval/spec review required.

## Phased delivery

### Phase 0 — Characterize contracts (implementation prerequisite)
- Freeze authoritative read-only schema, source adapters and denial matrix. Record source blobs and current tests from PR #528.
- Verify no collision with Skill Registry IDs, NHI identity or .specify Feature 001.
- Create isolated fixtures for malicious frontmatter and source path cases; design traceability and runtime acceptance.
- Deliverable: approved implementation contract and negative fixtures, not a new ability.

### Phase 1 — Agent Explorer (P0)
- Add read-only format-specific catalog on configured sources with pagination/coverage/error budgets; list/read and provenance.
- Discovery cannot create managed records or approve a candidate.
- Acceptance: exact-tree/blob, HTTP security, file format, truncation, invalid UTF-8, stale source and 18s-budget inherited policy tests.

### Phase 2 — Managed Definition Studio (P1)
- File-backed or equivalent existing durable storage only after safety characterization; revision CAS, status, audit, rollback.
- Admin editor and preview render untrusted Markdown safely; effective-grant access shown separately via existing Registry.
- Acceptance: unauthorized author denied, duplicate/revision race fail, plugin upgrade persistence, no grant/subject change.

### Phase 3 — Agent Matching & Team Plans (P1)
- Deterministic ranked selection, same-name companion signals, bounded context and Skill snapshots.
- One supervisor and at most four specialists, acyclic dependency DAG, explicit no authorization, digest and incompleteness.
- Acceptance: repeated inputs/snapshots repeat same digest, ties stable, conflict/scope/omission visible, no tool dispatch.

### Phase 4 — Governed Handoff (P2; separate authorization)
- Review deployment-specific bound identity and per-step permission intersections.
- Reuse existing preflight approvals, Site Profile evidence, budgets, idempotency and runtime execution receipts.
- Separate runtime test branch/PR and approved live staging acceptance. No generic execution route.

## Dependency graph

P0 contracts → P1 source explorer → P2 managed definition Studio → P3 matcher → P3 team DAG → (owner decision and independent governance) → P4 runtime handoff.

## Interface layout

Read-only future MCP Abilities (illustrative, NOT registered):
- mad4b/agent-definitions-list
- mad4b/agent-definition-get
- mad4b/agent-sources-list
- mad4b/agent-source-candidates
- mad4b/agent-source-get
- mad4b/agent-match-task
- mad4b/agent-team-plan

Keep input/output contracts under contracts/*.md; exact names require adapter collision/inventory review before implementation. No write-ability is specified for these read interfaces.

## Rollout and rollback

New modules default off; tests run isolated and no credential provisioning occurs. Disabling discovery or Studio cannot revoke/rewrite unrelated NHI identities and grants. If a later runtime bridge is unsafe, disable the bridge and preserve full immutable evidence and current managed records. Do not assume local CI proves deployed version or Production authority.

## CI/evidence

PHP 7.4 + current runtime, schema conformance, immutable/source SHA, negative SSRF/prompt injection, no grant mutation, admin nonces/roles, 18-second budget, concurrency/CAS, deterministic plan and no-cycle, generic WordPress disposable integration, official MCP/client read shape, exact-head governance/owner review and post-change runtime acceptance where authorized.

## Dependencies / forward compatibility

Existing Features 006/007/008, #528 Skill reader and composition, Schema/Agent Registry only as read projections. No separate LLM vendor adapter or new persistence DB is a prerequisite for read-only v1.
