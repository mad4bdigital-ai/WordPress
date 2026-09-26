# Research — Agent-Governed Reversible Control Plane

Source policy: competitor conclusions in this document are derived from the four ZIP attachments reviewed for this work only: Easy MCP AI 1.7.17, AI Engine 3.7.6, Royal MCP 1.5.0 and miniOrange Secure MCP Server 1.4.10. This file records architectural decisions, not vendor claims as facts beyond reviewed code.

## Normative evidence reference

The full attachments-only competitive review is preserved at:

`references/competitive-deep-research-attachments-only.md`

That reference is the evidence source for competitor-derived decisions in this feature. If this summary and the full reference ever diverge, implementation/security decisions must be reconciled against the full reference and the exact reviewed sample identities recorded there. No external vendor/web/CVE claims are implicitly imported into this feature by the reference.

## Decision R1 — Adopt NHI as a first-class authorization primitive

Evidence pattern: miniOrange contains a concrete NHI/ability gating model and normalized grants. Easy demonstrates practical per-token tool restriction. The current MAD4B model is stronger on provider trust but needs comparable per-agent least privilege.

Decision: introduce MAD4B Agent/NHI registry with exact grants and authenticated-subject bindings. Keep WordPress capability checks underneath it.

Rejected alternatives:
- authenticated administrator implies all admin abilities — too broad for autonomous clients;
- server-only separation — useful but does not distinguish agents sharing a server;
- wildcard grants — unacceptable as a Production default with a growing ability universe.

## Decision R2 — Do not implement a parallel OAuth server in this feature

Evidence pattern: competitor implementations show that OAuth/token lifecycle quickly becomes a substantial security subsystem. MAD4B already deliberately uses the official MCP Adapter as protocol/session/transport authority.

Decision: transport/authentication remains upstream. MAD4B receives a normalized authenticated subject context through a narrow integration hook and maps it to NHI. Subject bindings store identifiers/fingerprints, never bearer secrets.

Rejected alternative: copying competitor OAuth implementations into the plugin. This would duplicate transport security and expand attack surface.

## Decision R3 — No secret-in-URL compatibility path

Evidence pattern: reviewed Easy and AI Engine samples include token-in-path fallback patterns. Even strong random tokens become operationally exposed to URL logging surfaces.

Decision: MAD4B does not add token/query/path secret support. Authentication context must originate from an approved transport/header/OAuth mechanism.

## Decision R4 — Provider trust and agent authority are separate dimensions

Provider certification answers “is this the exact code/runtime we reviewed?” NHI answers “is this actor allowed to invoke this exact capability?” Neither substitutes for the other.

Decision: authorization is an intersection:

NHI grant ∩ subject/token scope ∩ server policy ∩ provider certification ∩ WordPress capability ∩ resource policy ∩ mutation policy ∩ approval.

## Decision R5 — Reversible mutation envelope inspired by Royal, hardened for drift

Evidence pattern: Royal has practical reverse-state/undo semantics, but rollback cannot be assumed universal and direct provider storage writes may drift across versions.

Decision: use provider-neutral mutation records and provider-specific snapshot/readback/restore contracts. Undo requires current state == recorded after-state. Oversized/unsafe operations are explicitly non-reversible before execution.

Rejected alternative: “undo token means rollback is always safe”. This can overwrite newer work.

## Decision R6 — Approval tickets are exact-operation capabilities

A human approval for “update Elementor” is too broad. Approval must be tied to one canonical operation.

Decision: ticket hash covers contract version, site identity, NHI, server, ability, provider, target and canonical input. Ticket is short-lived and single-use. Breakglass has a separate ticket class.

## Decision R7 — Conservative annotations and explicit impact policy

Evidence pattern: heuristic classification by ability name is weaker than explicit metadata. Easy’s conservative defaults reinforce the design choice already made in MAD4B.

Decision: every mutation is destructive for client confirmation semantics unless a future contract proves a narrower classification. Separate internal impact levels (`low`, `high`, `exceptional`) drive approval policy.

## Decision R8 — Side-channel write planes are governance blockers

A strong MAD4B deny policy is meaningless if another active MCP plugin can independently mutate the same WordPress resources.

Decision: self-test inventories declared/known MCP peers. Unknown write-capable peer = blocker. MAD4B does not uninstall peers automatically; operator must disable, make read-only or explicitly federate them.

## Decision R9 — Start with exact grants, not policy DSL

A flexible DSL adds parsing and semantic ambiguity before the authority model is stable.

Decision: first implementation stores normalized exact ability grants plus versioned resource constraints JSON. Policy DSL/visual builder is deferred.

## Decision R10 — Site-local storage first, explicit multisite evolution

The plugin already protects network plugin operations through network capability. NHI persistence must not accidentally create network-wide authority.

Decision: v1 NHI/grants/approvals/mutations are site-local tables using the site `$wpdb->prefix`. Network identities/grants require a later explicit schema and migration.

## Decision R11 — Append-only audit is a target, but identity/approval/mutation tables land first

Current audit hash chain is useful but bounded option storage has concurrency limits.

Decision: new authorization and mutation records are normalized tables now. Existing audit remains compatible in first implementation. Migration to append-only audit table is a planned P1/P2 task, not a prerequisite for defining NHI.

## Decision R12 — No generic rollback serialization

PHP serialization can introduce unsafe object handling and unbounded state.

Decision: rollback payloads are bounded JSON/plain scalar structures generated only by certified provider snapshot contracts. No unserialize-based restore.

## Decision R13 — One privileged mutation authority per site unless federation is explicit

Evidence pattern: the competitive review found that installing a second MCP product with independent write capabilities can bypass a MAD4B policy denial while still mutating the same WordPress objects legitimately through another server.

Decision: target-site self-test must eventually treat unknown independent MCP write planes as governance blockers. Coexistence is read-only by default or must be explicitly federated through the same policy authority.

## Decision R14 — Product breadth is deferred behind governance closure

Evidence pattern: competitors already expose hundreds of tools. Their advantage in breadth does not answer provider identity, actor identity, exact approval or reversible recovery simultaneously.

Decision: finish NHI, approvals, reversible mutation, budget/rate policy, side-channel detection and target certification before expanding adapter breadth merely to match tool counts.

## Open research items for target-site staging

1. Identify the exact authenticated-subject context exposed by MCP Adapter 0.6.1 during HTTP tool calls without patching upstream transport.
2. Confirm whether an upstream hook/context object can expose OAuth client/subject identity safely; otherwise implement a minimal certified adapter bridge that supplies only non-secret subject evidence.
3. Inventory real target MCP peers and classify write authority.
4. Benchmark table writes/locking on Hostinger MySQL/MariaDB runtime.
5. Decide acceptable default approval TTL and mutation/undo retention after observing operational flows.
