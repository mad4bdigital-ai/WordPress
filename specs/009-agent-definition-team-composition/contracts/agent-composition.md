# Contract — Agent Matcher & Team Plan v1

**Advisory-only.** These planned interfaces return candidates and proposed teams. They MUST NOT create a Run, issue a grant, call a tool or synthesize an approval.

## Read interface candidates

- mad4b/agent-match-task
- mad4b/agent-team-plan

Both use existing canonical read permission, bounded JSON input, no write capability, no default Production execution. No new registered Ability is part of this Spec Kit.

## Input envelope

```json
{
  "contract": "mad4b.agent-task-request.v1",
  "task": "Review a plugin update for security and compatibility",
  "task_context": "A short operator-supplied scoped summary",
  "requested_roles": ["reviewer", "tester"],
  "source_ids": [],
  "environment_hint": "staging",
  "max_candidates": 12,
  "max_specialists": 4,
  "max_steps": 8,
  "source_offset": 0
}
```

Validation: task 3..4000 valid UTF-8 bytes, context <=4000 bytes, requested roles <=8, max_candidates 1..12, max_specialists 0..4, max_steps 1..8, source IDs only from registered sources, bounded pagination. `environment_hint` is a filter hint only, **never** a grant or proof of deployed environment.

## Matching algorithm requirements

- Start with deterministic lexical/name/tag/role overlap using the existing Skill matching approach. No probabilistic equivalence claim without independently evaluated semantic model.
- Disabled or policy-incompatible managed definitions may be shown only as disqualified with reasons (subject to read permission); never selected for execution merely from score.
- Source families sharing normalized names retain local managed primary with max 3 attributed external advisory companions; preserve relevant negative evidence and don't merge instruction bodies.
- Return explanation: matched terms, score components, source IDs/digests, supported constraints, rejected candidate IDs, scorer version and coverage caveats.
- Scores across independently paged sources are page-local. Do not present a global ranking unless all requested pages are exhaustively read or an explicit global scoring strategy is implemented.

## Team formation

- Exactly one supervisor role in a team; zero-to-four specialists; total <=5 roles.
- At most eight ordered proposed steps; unique IDs, declared dependencies form a DAG. Reject cycles and impossible handoffs; no agents can spawn unbounded subagents.
- An Agent slot binds definition snapshot, selected Skill identities, minimal context handles and declared expected deliverables.
- Distinct roles do not share tokens or automatically inherit an NHI's permissions.
- If candidate/Skill/source context is incomplete or conflicting, set `complete=false`, `conflict_status=requires_review`, and `execution_ready=false`.
- Truncated/budget-exhausted sources never silently turn into "no further candidates".

## Output envelope

```json
{
  "contract": "mad4b.agent-team-plan.v1",
  "plan_sha256": "<sha256-of-canonical-plan>",
  "task_sha256": "<sha256-of-normalized-input>",
  "roles": [
    {"slot": "supervisor", "definition_id": "managed/reviewer", "definition_sha256": "<sha>", "skill_refs": [], "context_refs": []}
  ],
  "steps": [
    {"id": "S1", "actor_slot": "supervisor", "depends_on": [], "kind": "inspect_and_propose"}
  ],
  "source_coverage": {"complete": false, "budget_exhausted": true},
  "conflict_status": "requires_review",
  "warnings": ["candidate coverage incomplete"],
  "execution_ready": false,
  "execution_authorized": false
}
```

All example hashes are placeholders. No output fields that could be mistaken for a successful run receipt, ticket, or deployed build proof.

## Digest/canonicalization

Hash explicit contract version, canonical task/context, requested filters, selected exact definition/Skill snapshots, source-version coordinates, source coverage, deterministic roles, steps and warnings. Exclude wall-clock timestamps and random IDs from plan identity. Compare same fixtures across PHP 7.4/8.3/current for stable digest. Store no secrets.

## Scenarios

- Same task/snapshots returns identical team plan digest.
- Ambiguous same-name external source yields companion advisory warning, local precedence, no silent override.
- Stale managed Skill/reference, missing context permission, unknown source or incomplete tree yields a visible reason, not execution.
- Team with >4 specialists, >8 steps, cycles, missing supervisor or duplicate role IDs is invalid.
- An injected external instruction cannot change capability grants or cause a tool dispatch.
