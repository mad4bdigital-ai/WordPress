# MAD4B Site Control Plane Constitution

Version: 1.0.0
Status: Normative
Scope: `wp-content/plugins/mad4b-site-control-plane`

## Purpose

MAD4B Site Control Plane is a governed AI control plane for WordPress. Its primary product promise is not tool breadth. It is the ability to prove, before and after a privileged operation, **who was allowed to act, through which authority, against which exact provider/runtime, on which exact state, with which approval, and with which recovery path**.

## Constitutional principles

### C1 — One privileged mutation authority

A production WordPress target MUST NOT have multiple independent MCP write planes that can mutate the same resources while bypassing MAD4B policy. Other MCP products may coexist read-only, or may be federated through MAD4B authorization, but `runtime-self-test` MUST report a blocking side-channel when an independent privileged write surface is detected.

### C2 — Transport is upstream; governance is MAD4B

MCP protocol/session/transport stays with the official `WordPress/mcp-adapter`. MAD4B MUST NOT implement a parallel MCP transport merely to add governance. Authentication evidence from the transport is converted into an authenticated subject context, then MAD4B adds NHI identity, grants, provider certification, WordPress capability checks, resource policy, approval and mutation controls.

### C3 — Fail closed by default

Every mutation surface is OFF until deliberately enabled. Missing identity, unknown provider state, version drift, missing critical files, invalid grants, expired approval, stale state, unverified write, unknown resource policy, or ambiguous authority MUST deny mutation. Configuration ambiguity is not permission.

### C4 — Least privilege is an intersection

Effective authority is the intersection of all relevant gates:

`NHI grant ∩ credential/token scopes ∩ MCP server policy ∩ provider certification ∩ WordPress capability ∩ resource constraints ∩ mutation policy ∩ approval policy`.

No layer may widen a narrower layer. Production wildcard grants are forbidden.

### C5 — Human identity and non-human identity are distinct

An authenticated WordPress administrator is not automatically an autonomous-agent identity. Privileged autonomous mutation requires an enabled MAD4B NHI/Agent identity bound to an authenticated transport subject. Audit evidence MUST retain both the NHI and any resolved WordPress principal.

### C6 — Source code is deployment-plane only

Normal WordPress MCP MUST NOT edit executable source code, server configuration, plugin/theme PHP, shell scripts, or equivalent execution surfaces. Source changes flow through GitHub → PR → CI → deployment. Host-level shell, SSH, services, cron and files outside PHP permissions belong to MAD4B Host Connector.

### C7 — Provider writes require exact trust establishment

A package-backed writer MUST be bound to an exact certified provider version/archive and runtime critical-file manifest. Drift or missing expected native abilities MUST disable provider mutation. Native/public provider APIs are preferred over private storage manipulation. Legacy/private mutations require an explicit additional gate.

### C8 — Every important mutation is planned, approved when required, verified and reversible when possible

High-impact mutation MUST follow: discover → plan → authorize → approve → precondition check → capture before-state → execute → read-after-write → verify → audit → return undo capability. An approval is bound to an exact canonical payload hash and expires. Undo is bound to the after-state and refuses to overwrite later drift.

### C9 — No blind writes

Mutable resources MUST use optimistic state guards appropriate to the provider: SHA-256, modified timestamp, object/version ID, flow fingerprint, expected active state, current thumbnail/language, or another exact precondition. Blind overwrite is not a supported normal mode.

### C10 — Database mutation is structured and bounded

Normal DB mutation is structured, column-aware, sensitive-field-aware, transaction-capable, bounded and verified. Raw SQL is Breakglass only. Breakglass is isolated, disabled by default and layered behind independent gates; privilege/user/password primitives and data exfiltration primitives remain hard denied.

### C11 — Credentials never belong in URLs or audit logs

MCP credentials MUST use authenticated headers/OAuth transport or another approved upstream mechanism. Secret-bearing URL paths/query parameters are forbidden. MAD4B stores only non-secret transport-subject fingerprints/bindings where possible. Audit/log summaries redact secret-like material.

### C12 — Audit is security evidence

Every privileged decision and mutation MUST emit correlated evidence including request ID, NHI identity, authenticated subject fingerprint/type, WordPress principal when known, server, ability, provider, target, decision, approval reference, before/after hashes and verification status as applicable. Evidence is hash-linked. The target architecture is append-only transactional storage with optional external/WORM export.

### C13 — Rate limits and mutation budgets limit blast radius

Authorization alone does not limit damage from a compromised but authorized agent. Production NHI profiles MUST support request rate limits plus mutation budgets such as operations, affected objects and external side effects per time window.

### C14 — Multisite boundaries are explicit

Site-level and network-level authority are separate. Network actions require network capabilities and explicit network grants. A subsite identity MUST NOT inherit network mutation authority.

### C15 — CI proves denials, not only success

Every security control requires negative tests. Required CI includes PHP compatibility, static adversarial contracts, packaged-provider security, exact runtime integration on supported WordPress versions, mutation-denial/no-side-effect tests, identity/grant denial tests, approval replay/expiry tests, stale-state tests, undo-drift tests and packaging provenance.

### C16 — Documentation cannot outrun implementation

A capability is considered supported only when implementation, tests and runtime evidence agree. Marketing/readme claims do not create authority. Unsupported or partially certified behavior is documented as deferred or degraded.

## Production admission rule

Production mutation is eligible only when all P0 requirements pass, all P1 blockers are zero, exact target runtime certification passes, an explicit NHI exists with non-wildcard grants, mutation is globally enabled deliberately, and required audit/approval/recovery controls are operational.

## Change governance

Changes that weaken any constitutional principle require an explicit architecture decision record in the feature spec, a security rationale, new negative tests, and review as a control-plane amendment. Convenience or compatibility alone is insufficient justification for widening authority.
