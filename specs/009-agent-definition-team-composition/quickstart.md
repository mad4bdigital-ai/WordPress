# Quickstart — Reviewing Feature 009 Without Execution

**Scope:** specification and code-review rehearsal only. No WordPress site connection, plugin update, Agent import, NHI or grant mutation is included.

1. Check repository branch: spec/009-agent-definition-team-composition-20261027, forked from master commit 176667ec044be272146b243c37a82e7b6f7f2db4.
2. Read README.md, constitution.md, spec.md and research.md.
3. Review the separation between AgentDefinition, NhiAgentPublicId, TeamPlanId and actual operation identity in data-model.md.
4. Evaluate the three contract groups: definition validation, GitHub source reads, team planning; check failures in governed-handoff.md.
5. Walk every negative case in checklists/requirements.md and link each to tasks.md. All unchecked tasks remain unimplemented.
6. Confirm the feature does not change .specify/feature.json or .specify/memory/constitution.md (Feature 001 owner) and does not add PHP runtime, grants or activation.
7. Later implementation: run exact-head hermetic tests on PHP 7.4/8.3/current, and only separately run live WP/MCP acceptance for a matching authorized build.

## Read-only example scenario

Input: "Review source changes, verify PHP and WordPress compatibility, and propose a safe implementation plan." The matcher would choose a managed QA reviewer definition with compatible Skills, then the composer could suggest a QA specialist and a WordPress/runtime specialist.

Expected result: explainable ranked candidates, bounded DAG with roles and Skill/context snapshots, source completeness and a content/plan SHA. `execution_authorized=false`, `execution_ready=false`, no WP site mutation and no result claiming tests were actually run.

## Nonexample

Do not translate an external Agent file that says "run this shell command" into a direct command. A remote `tools` or `model` field is a declaration from untrusted input, not permission or an installed runtime. Do not conflate project AGENTS.md guidance with an executable agent.
