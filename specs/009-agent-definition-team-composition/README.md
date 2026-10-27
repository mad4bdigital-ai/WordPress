# Feature 009 — Agent Definitions, Discovery & Team Composition

**Status:** specification only / proposed. **Base:** master at 176667ec044be272146b243c37a82e7b6f7f2db4 (PR #528 merged). **Date:** 2026-10-27.

## Purpose

Extend the existing MAD4B Skill pattern to reusable **Agent Definitions**, not to another identity/authorization system. Agents can be found in supported external repositories, defined locally, matched to user requests, composed into reviewable teams and (in a later separately authorized phase) dispatched via the existing Control Plane execution gates.

## Normative documents

- [constitution.md](constitution.md): non-negotiable safety and ownership boundaries
- [spec.md](spec.md): goals, user stories, normative requirements and acceptance
- [research.md](research.md): verified current repository baseline, decisions and unknowns
- [data-model.md](data-model.md): definition/version, provenance and composition identity
- [plan.md](plan.md): phases, owned components, migrations and roll-out
- [tasks.md](tasks.md): trackable dependency-ordered implementation backlog
- [quickstart.md](quickstart.md): review and implementation rehearsal (no execution)
- [contracts/agent-definition.md](contracts/agent-definition.md): validated definition envelope and portable import boundary
- [contracts/agent-discovery.md](contracts/agent-discovery.md): read-only source and catalog Abilities
- [contracts/agent-composition.md](contracts/agent-composition.md): matcher and team plan contract
- [contracts/governed-handoff.md](contracts/governed-handoff.md): explicit separation of planning from execution
- [checklists/requirements.md](checklists/requirements.md): design review / verification checklist

## Existing platform, do not fork it

- NHI Agent Registry / identity, subject bindings, exact grants: includes/class-mad4b-scp-agent-registry.php; Feature 006
- Managed file-backed Skills + external GitHub source explorer + task-aware composition: Skill Registry, external source reader, Task Composition (PR #528)
- Existing policy, governed execution, approvals, budgets, idempotency, audit, kill-switch and execution receipts: Control Plane / Features 006–008
- Existing unified Agent connection and Site Profile are **identity/connection surfaces**, not Agent instruction libraries.

## Cross-feature isolation

Do **not** modify shared .specify/feature.json or .specify/memory/constitution.md: those remain owned by Feature 001. This feature owns only specs/009-agent-definition-team-composition/** until a separate implementation PR explicitly selects runtime paths.

## Scope and release meaning

Spec Kit does not create Agents, alter NHI identity, modify grants, register Abilities, send prompts, install external files, run workflows, change WordPress, or authorize merges/deployments. External definitions remain untrusted advisory data. Phase 1–3 are read-only/default-disabled. Phase 4 execution requires independent owner and environment authorization.

## Dependencies

- [Feature 006](../006-agent-governed-reversible-control-plane/spec.md): exact grants, approvals, NHI, authorization
- [Feature 007](../007-content-intelligence-workflow-platform/README.md): execution orchestration where applicable
- [Feature 008](../008-dynamic-content-runtime-hardening/spec.md): durable operation and effect safety where applicable
- [Merged PR #528](https://github.com/mad4bdigital-ai/WordPress/pull/528): read-only external Skills + matching/composition baseline

## Decision record

This Spec Kit adopts a **Definition ≠ Identity ≠ Authority** architecture. It does not certify that any external file format, runner, or vendor is compatible until source-provenance and adapter tests are provided.
