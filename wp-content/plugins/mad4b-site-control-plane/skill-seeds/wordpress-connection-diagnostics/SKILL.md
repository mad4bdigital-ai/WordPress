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

## Provider-neutral MCP and Rank Math / Control Plane conflict inheritance
- Use `mad4b/mcp-provider-readiness-matrix` to discover currently registered provider namespaces, transport descriptors, pinned native peers and shared MCP PHP class provenance. For any exact provider or Content Job, use `mad4b/mcp-provider-readiness-preflight` or `mad4b/content-job-mcp-provider-preflight`.
- Dynamic discovery is passive; neither an installed plugin, an `elementor/*` / `rank-math/*` Ability, a pinned peer manifest, nor an MCP server PHP instance is proof of authenticated protocol `initialize` and `tools/list`. A provider-specific client must prove fresh session identity, schema, site and authority before any native execution path.
- Rank Math bundles its own WordPress MCP Adapter classes. If shared loaded DTO classes come from Rank Math and official Adapter together, mark `MIXED_SHARED_CLASS_PROVENANCE` as a runtime compatibility risk. Observe only; do not bootstrap another Adapter, force a new class owner, reorder plugins or rewrite OAuth settings during connection/handshake hotpaths.
- MAD4B Control Plane OAuth identity belongs to the addressed MAD4B MCP server. It does not authorize Rank Math, Elementor or another vendor MCP server; use provider-scoped consent/grants and isolation. The provider compatibility metadata mirror may copy missing legacy Rank Math resource keys but may not alter tool registration, credentials, permissions, or vendor callback semantics.
- Preserve existing authorized MAD4B connection independently of provider health. A vendor MCP fault is not proof the MAD4B OAuth session failed; do not reopen user consent or deny unrelated Content Jobs reflexively.

## Dynamic MCP provider updates: missing static baseline must not stop operations
- A new official provider/Adapter version can appear at any time after a native update. Resolve the *installed* plugin version and its active runtime dynamically; never interpret an absent hardcoded version entry, an old preferred release target, or `baseline_missing` as proof of corruption or a global Control Plane/Content Jobs blocker.
- For unknown MCP Adapter releases, check the installed main-file version and real source layout, preserve read-only discovery/ordinary Content Jobs, and report `updated_release_layout_ready_source_unverified` as a non-blocking advisory. Do not certify the new release by simply hashing untrusted installed bytes.
- On an authorized non-protocol maintenance request, verify installed files against the exact HTTPS official WordPress.org distribution for that release, cache a version-scoped digest of the verified files, and invalidate/revalidate when any verified byte changes. This is dynamic source integrity, not a mandatory manual release certificate.
- Provider-native writes or runtime bootstrap replacement still require original authorization, independent source provenance and a matching authenticated MCP protocol handshake. A mixed Rank Math 0.5 / MCP Adapter 0.7 PHP class set is a real compatibility finding, kept separate from release baseline freshness and MAD4B OAuth.
- Distinguish `read_ready`, `dependency_ready`, and `write_certification_ready` in each receipt. A missing profile may set the last false while the first two stay true. Genuine missing code, mismatched official bytes, mixed vendor APIs, wrong site, or failed provider authorization remain meaningful failures for the affected operation.
