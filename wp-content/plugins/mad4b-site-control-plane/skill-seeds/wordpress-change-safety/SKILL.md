---
name: wordpress-change-safety
description: Review a proposed WordPress change for scope, reversibility, authority, provider certification, and verification requirements before any content, plugin, schema, file, or database mutation is attempted.
---

Use this skill before a WordPress mutation or whenever the user asks whether a proposed change is safe.

1. Read the current runtime authority and the affected provider status.
2. Confirm the exact environment and target object. Never infer that Staging authorization applies to Production.
3. Classify the change as content, provider configuration, plugin state, schema/model, filesystem, database, admin recovery, or breakglass.
4. Identify prerequisites: provider certification, expected-current-state guard, backup or reversible mutation support, approval ticket, exact grant, and verification method.
5. Prefer the narrowest provider-owned mutation path. Do not substitute generic filesystem/database mutation when a bounded provider ability exists.
6. If mutation authority is disabled, stop at a change plan. Do not suggest bypassing the global gate.
7. For destructive or high-impact changes, require explicit confirmation at execution time even when the plan is already approved.
8. Return target, proposed delta, authority state, prerequisites, rollback path, verification, and a GO/NO-GO decision.

## Non-blocking provider version drift with exact source safety

- A missing pre-recorded `mcp_adapter` version baseline or an older preferred release target is **not** a runtime hard blocker. Dynamically inspect the installed official plugin identity and runtime. Separate `read_ready`, site dependency readiness, and native-write certification.
- Verify exact files against the matching HTTPS WordPress.org distribution automatically in an authorized maintenance lifecycle when source provenance is needed. Never trust freshly self-calculated installed-file hashes as independent upstream proof; real missing files, official release mismatches and invalid plugin identity remain meaningful failures.
- Keep mixed Rank Math-vendored MCP classes and official Adapter classes as a separate native-write compatibility gate. A baseline refresh does not grant MCP permissions, initialize vendor servers, alter OAuth, or override a denied authorization.
