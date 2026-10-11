# Design Research and Architecture Decision Records

Contract: `mad4b.aci-os.research.v1`.

The current repository Feature 007 documents a durable Content OS, provider-neutral workflows, governed native publishing and multi-tenant rights. CE01 documents optional adaptive capability behavior and L0–L5 governance. PR #303 provides an offline, evidence-only structural relation checker on a separate branch; its implementation does not certify WPML mapper settings or allow mutation. These are SOURCE_EXISTING repo observations, not live acceptance.

## Key decisions

| ADR | Choice | Rejected alternative | Evidence needed to change |
|---|---|---|---|
| ACI-ADR-01 | Add an additive specification over Feature 007 instead of forked control plane | separate all-access MCP runtime | reviewed authority/rollback integration |
| ACI-ADR-02 | Native WordPress IDs, meta and taxonomy as implementation truth | duplicate `relations` DB as master | semantics, adapter and migration proof |
| ACI-ADR-03 | WPML as translation identity owner; mapper remains untrusted until certified | guess translated IDs via title or locale | actual plugin/hook/settings verification |
| ACI-ADR-04 | Separate structural vs semantic vs authority gates | single `valid=true` | impossible to prevent false-positive publish |
| ACI-ADR-05 | Provider-neutral research normalization | hardcoded SerpApi/Firecrawl/one model | true parity with evidence and cost |
| ACI-ADR-06 | Reserve paid effects before execution | call external API then account for spend | shared account budget/uncertain delivery safeguards |
| ACI-ADR-07 | Durable DAG, operation journal, independent stage checkpoints | single request/Make scenario as state owner | retries, cancellation, restores, idempotency |
| ACI-ADR-08 | Human-owned content wins unless exact review allows replacement | silent rewrite after SEO drop | ownership and audit |
| ACI-ADR-09 | Independent editorial/publishing/Production approvals | automatic final publish from draft approval | separation-of-duties certification |
| ACI-ADR-10 | Incremental, measured optimization | autonomous article edits on ranking swings | comparable GSC/GA4, controlled attribution |
| ACI-ADR-11 | One logical brand knowledge profile per site/brand with locale variants | unrestricted shared Google Drive corpus | access/retention/citation policy |
| ACI-ADR-12 | Distinct safe read, preauthorized reversible write, human external action lanes | arbitrary shell under WordPress admin | host sandbox and exact capability certificates |

## Observed example (historical read-only, NOT current certification)

On All Royal Egypt Staging during 2026-10-08, WPML tour-rate group `638158` exposed EN 45005 and FR 45007. `related_tour_id` values differed (25675 / 27819), `related_package_term_id` differed (2288 / 2294), and property refs were both [36475, 36005]. Native post identity confirmed source/target types; target locale mapping, WPML Meta ID Mapper behavior and semantic correctness remain UNVERIFIED. Never auto-repair these sample records or use these IDs as generic program fixtures outside a clearly labeled test.

## Research questions still open

1. Which plugin registers each relation field, its cardinality/serialization, owner and WPML translation preference?
2. Is WPML Meta ID Mapper hooking copied metadata, UI-triggered actions, or both, and how does it handle missing translations?
3. Which evidence proves that translated tours/products/property records denote the same commercial entity?
4. Which existing MAD4B artifact/operation journal contracts can store all needed receipts without schema duplication?
5. Which provider datasets may be legally retained, excerpted, embedded or used to train/generate?
6. How are paid API reservations shared across sites using the same provider account?
7. What are real site-specific latency, memory, query, queue and cost budgets under representative native workloads?
8. What minimum controlled experiment prevents ranking seasonality being mistaken for AI-generated content gains?
9. What is the fully external proof for browser/SEO/WPML render parity and postpublish verification?
10. What governance and human UX path works when external host isolation, grants or managed Skills are not effective?

These questions are **release blockers for the relevant scope**, not invitations to mark records DONE.
