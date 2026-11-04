---
name: wordpress-connection-diagnostics
description: Verify and diagnose the MAD4B WordPress connection when the user asks about MCP reachability, OAuth, ChatGPT connection certification, transport binding, external handshake, or read-gateway readiness.
---

Use this skill only for the connection layer.

1. Read `mad4b/connection-status` first.
2. Confirm environment identity, expected MCP server, local transport readiness, remote preflight, and connection certification.
3. Read `mad4b/runtime-authority-status` to confirm the effective authority without treating read connectivity as write authorization.
4. Use `mad4b/diagnostics-health` only to distinguish connection failures from provider/runtime degradation.
5. If the external handshake is already verified and certification blockers are empty, do not reopen OAuth, PKCE, token, or MCP endpoint work merely because a provider adapter is degraded.
6. Never expose access tokens, refresh tokens, authorization headers, private keys, or raw session identifiers.
7. Return a gate-by-gate result: environment, transport, OAuth/preflight, external certification, authority, blockers, and the next layer that actually needs work.

## Dynamic MCP Adapter releases and provider isolation

- Treat `baseline_missing` or a new installed MCP Adapter version as **non-blocking discovery**, not corruption. Read the actual plugin version and layout; prefer upstream package comparison when a privileged runtime change is needed. A fixed `target_adapter_version` is an update preference, not a runtime allowlist.
- A never-before-seen release can remain `read_ready=true` while `write_certification_ready=false` until its exact official WordPress.org release has been independently verified. Do not label checksums taken only from installed files as verified upstream evidence.
- Preserve the separation between the MAD4B MCP server and Rank Math vendor OAuth. Mixed PHP class origins remain a specific native-write compatibility concern, not a reason to disable unrelated Content Jobs or silently reorder plugins.
