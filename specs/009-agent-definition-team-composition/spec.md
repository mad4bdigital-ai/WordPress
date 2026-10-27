# Specification — Agent Definitions, External Explorer & Teams

**Feature:** 009 | **Status:** Proposed / not implemented | **Target:** MAD4B Site Control Plane / portable nonauthorizing surfaces.

## Problem

Existing Agent Registry models **who** connects and what they may do. Existing Skill Registry models **how** tasks are performed. Neither is a portable, versioned definition of an Agent role with instructions, selected Skills, task boundaries, context requirements and team relationships. PR #528's Skill Composition does not create a multi-Agent runner.

## Outcomes

- O-01 Manage reusable, audit/versioned Agent **definitions** without changing NHI identity.
- O-02 Discover external Agent candidates from explicitly configured, read-only, provenance-pinned repositories.
- O-03 Select appropriate managed Agents and companion Skills for a task, with explainable ranking and incomplete-source warnings.
- O-04 Compose bounded, nonauthorizing multi-Agent plans and detect ambiguous/unsafe delegation.
- O-05 Offer a governed handoff design reusing existing runtime and approval machinery; execution is out of scope until a separately approved stage.

## Actors and priorities

- Administrator (P0): create, review, version, disable definition; see reason, source and exact revision.
- Operator (P0): request Agent match or Team Plan through read-only Abilities; receive trustworthy provenance, omissions and no authority.
- Source curator (P1): configure/unconfigure public external Agent sources with exact origin/repo/ref; remote markdown remains untrusted.
- Execution owner (P2): optionally bind a reviewed Team Plan to existing NHI/runner; never by implicit import.

## User stories and acceptance

### US-01 Managed definitions (P0)
Given an authorized admin, save a disabled draft with role, mission, instructions, Skill references and constraints; read back exact revision and content digest. Updating requires expected revision. Invalid references, colliding enabled IDs and unsafe characters are refused. Disabling leaves identity/grants untouched.

### US-02 External explorer (P0)
Given an allowed source, list bounded Agent files, read exact verified content and display format classification and provenance. A renamed/moved/stale SHA, provider host drift, unsafe path or incomplete tree is denied/marked incomplete. No external scripts execute.

### US-03 Match task to Agent (P0)
A task with an optional known context digest returns ranked managed candidates, external advisory candidates, matched terms, scorer version and candidate/source coverage. Disabled/incompatible/environment-ineligible candidates never become executable merely due to score.

### US-04 Compose team (P1)
An operator requests roles/Skills for a bounded task; planner returns supervisor, optional specialist slots, order, handoffs, assumed dependencies, warnings, selected Skill identities and immutable digest. All steps carry execution_authorized=false. External instruction conflicts remain unresolved and visible.

### US-05 Agent Studio preview (P1)
Admin can preview definition and audit changes via WordPress UI without sending prompts or executing the imported text. Read-only connection and effective-access projection are linked, never merged into one implied grant.

### US-06 Governed execution (P2, future)
Only after a distinct approved plan/identity binding and runtime policy check may existing provider execution be used. Disallowed scope, stale revision, absent NHI, stale source, changed Site Profile, replay or incomplete approval fail closed with no side effects.

## Functional requirements

- **FR-001** Definition MUST be a separate typed entity; fields id, revision, status, role, mission, instructions digest, owner scope, tags, compatible Skills, context requirements, allowed operation classes as declarations, limits, provenance and audit pointers.
- **FR-002** Managed definition validation MUST be admin-only and governed, with atomic write/readback, revision CAS, audit and rollback on failure; drafts disabled by default.
- **FR-003** External Agent discovery MUST reuse governed GitHub read/egress and exact SHA path validation. No arbitrary URLs, remote shell, unsigned tool definitions or secrets are imported into managed state.
- **FR-004** Source results MUST report complete/page_complete, next offset, budget_exhausted, error codes and canonical per-item source digest; any partial source MUST remain advisory.
- **FR-005** v1 recognized paths MUST be bounded, allowlisted and explicitly format-versioned; AGENTS.md is project-scoped context instructions, not a standalone agent.
- **FR-006** Matcher MUST provide deterministic score/explanation, environment incompatibility diagnostics, immutable normalized input digest and requested page information; no tool authorization based on matching.
- **FR-007** Team plans MUST contain at most 1 supervisor plus 4 specialists, at most 8 proposed steps, no cycles, no unbounded delegation/recursive replan, and explicit per-role Skill/context bindings.
- **FR-008** Composition MUST preserve local managed precedence, show external companions as attributed untrusted context, mark unresolved semantic conflicts, and prohibit automatic merging of contradictory policies.
- **FR-009** Context packing MUST enforce bounded input bytes and per-role budgets; cite context snapshot/digest, do not send whole chat transcripts/secrets implicitly.
- **FR-010** A team plan MUST be immutable, nonauthorizing, reproducible against the same exact snapshots and include scorer/plan-contract versions; no run ID or execution receipt is fabricated.
- **FR-011** No imported definition, provider model preference, task match, Team Plan or UI activation may create NHI, OAuth subject, grant, approval ticket or executable workflow.
- **FR-012** Future execution MUST intersect per-step actual authenticated NHI, exact server/ability/provider, Site Profile/environment, WordPress permission, budgets, approvals, provider readiness and plan digest before side effects; no inherited permissions.
- **FR-013** All errors/partial results MUST expose bounded reason codes and redacted provenance without leaking file bodies, secrets or private content.
- **FR-014** Configuration, Studio UI, MCP reads and future runtime MUST remain optional and compatible with PHP 7.4 and supported WordPress versions (per release policy).
- **FR-015** External source cache MAY improve performance but MUST NOT authorize/override exact version or source identity.
- **FR-016** Import/conversion adapters MUST strip executable side effects and avoid interpreting markdown body, yaml keys or scripts as instructions to the Control Plane.
- **FR-017** Do not modify Feature 001 global .specify files, third-party plugins, existing managed Skill authority or registered NHI grants during initial feature delivery.

## Security, privacy & quality

- SEC-01 SSRF/redirect/DNS-private/host-lookalike/cross-repo/path traversal/symlink and SHA mismatch denial.
- SEC-02 Prompt injection and role spoofing in external metadata have no impact on policy or privileged tool calls.
- SEC-03 Least privilege per NHI with deny precedence; no shared team credentials.
- SEC-04 Version drift, task/context identity drift and race under concurrency fail closed.
- SEC-05 Max resource use on tree scan, bytes, HTTP calls, deadlines, team size and tool effects.
- SEC-06 Audit records reason, agent-definition digest, identities and plan lifecycle without retaining unbounded sensitive inputs.

## Non-goals

New proprietary LLM inference/embedding engine, unattended Production deployment, generic script installation, importing vendor-specific tools as executable grants, replacing Skill Registry, executing external agents as processes, creating a parallel MCP/REST write surface.

## Definition of done

DOD-01 Spec/test/contracts have traceability and owner review; DOD-02 all negative fixtures and admitted format tests pass; DOD-03 existing Skills, NHI grants, OAuth and access governance unchanged; DOD-04 read-only WordPress/MCP acceptance verified against exact build; DOD-05 any future Stage-4 execution has separate signed preflight/approval, concurrency/replay tests and staged rollback; DOD-06 Production never implicitly enabled.
