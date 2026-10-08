# ACI01 — Adaptive Content Intelligence OS | Spec Kit

Contract: `mad4b.aci-os.spec-kit.v1`. Parent: Feature 007. Target: PR #258 Integration Hub. State: **SPEC_BACKLOG_ONLY**. `authorizing=false`, `production_authorized=false`, `release_closure_included=false`.

## Purpose

A general, multi-site, multi-brand, multi-language, provider-neutral operating system for evidence-based content work: discover opportunities → gather corroborated evidence → plan useful experiences → draft and verify → publish through governed native WordPress capabilities → learn from comparable growth signals. Existing content, tourism products and relationship graphs are first-class citizens, not just generated articles.

This is a **complete target architecture and acceptance specification**, not a claim that its runtime exists. The root Feature 007 Spec Kit already defines Content Jobs, artifacts, providers, execution controls and publication; CE01 defines Adaptive Operations L0–L5. ACI01 refines their interaction into product journeys and explicitly names remaining gaps without rebuilding MAD4B governance.

## Reading order

1. `constitution.md` — immutable boundaries, autonomy and acceptance truth.
2. `spec.md` — functional/nonfunctional outcomes and user stories.
3. `architecture.md` — topology, five feedback loops and component ownership.
4. `data-model.md` — entities, identities, evidence and mutation boundaries.
5. `research.md` — architecture decisions, known evidence and unknowns.
6. `ui.md` — operator workspaces, Arabic/RTL and approval experiences.
7. `contracts/` — intelligence, relations, orchestration and publishing interfaces.
8. `requirements.json`, `acceptance-gates.json`, `system-map.json` — typed, machine-checkable inventory and five-loop topology.
9. `plan.md` — incremental delivery, rollback boundaries and exact gate exits.
10. `tasks.md` + `traceability.md` — implementable, independently evidenced work.
11. `acceptance.md`, `quickstart.md` — gate/negative cases and safe validation journey.

## Truth and scope

- `SOURCE_EXISTING`: parent Feature 007 / CE01 architecture or repository source; existence does **not** certify live behavior.
- `OBSERVED_READ_ONLY`: a bounded, environment/date-bound read receipt; cannot authorize writing or imply all locales are correct.
- `DESIGN_DERIVED`: new ACI01 proposal requiring later implementation and independent review.
- `EXTERNAL_PENDING`: provider, host, account or browser fact not certified.
- All task states start `OPEN`; no inherited `DONE` and no modification of frozen parent task counts.
- No dependency on a single SEO plugin, SerpApi, Firecrawl, AI model, Bit Flows, Google Drive, site URL, WordPress theme or hotel/tourism taxonomy.

## Parent contracts and integration

- `../../spec.md`, `../../plan.md`, `../../data-model.md`, `../../contracts/content-job.md`, `../../contracts/skills-orchestration.md`, `../../contracts/publishing.md`
- `../competitive-experience/adaptive-operations.md` (L0–L5, authority and runtime adaptivity)
- `../competitive-experience/contracts/acceptance.md` (external/browser/runtime evidence layers)
- PR #303 native relation inspector is an **untrusted structural evidence precursor**, not a semantic resolver or write authority.

Implementation must first map to existing classes/abilities and only introduce missing typed interfaces. Unknown ownership or capability is quarantined. New capabilities, grants, publication and Production require separate authorizing actions.

## Proposed system shape

```mermaid
flowchart LR
  O[Operator Action Center] --> D[Decision & Policy Plane]
  D --> J[Durable Job and Artifact DAG]
  J --> R[Research / Evidence Providers]
  J --> K[Context and Writer Profile Registry]
  J --> C[Content + Relation Intelligence]
  C --> Q[Independent Quality Gates]
  Q --> P[Governed WordPress Preview/Publish]
  P --> G[Search / Engagement / Conversion Observation]
  G --> D
  D --> A[Existing MAD4B authority / budgets / journal]
  P --> A
```

No direct arrow from growth signals, LLM output, crawled content, or relationship observations to WordPress mutation. Each passes through independent admission, human/agent approvals when applicable, commit-time CAS and native readback.
