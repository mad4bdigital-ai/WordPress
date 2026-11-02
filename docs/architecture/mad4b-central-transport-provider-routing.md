# MAD4B central capability transport routing

Contract: `mad4b.central-capability-transport-routing.v1`. This is shared Control Plane policy for every enrolled site/company, not a site-specific router. ETG Staging is the first deployed acceptance environment. Production write authority and source deployment remain separate.

## Architecture

The same client/agent may access WordPress over HTTP MCP/OAuth, or a registered local VPS stdio MCP Client. A ContentJob's AI model provider (ChatGPT or an external connected API) is independent of the communication transport. Neither registered MCP clients nor WordPress CLI users automatically impersonate an active ChatGPT session.

For READ: authenticated HTTP MCP, then authenticated same-site VPS stdio MCP read, then independently authenticated REST read. For WRITE: authenticated current HTTP MCP with exact write grant, then same-site VPS stdio MCP with **current, independent per-operation write proof**. REST discovery/read grants are never a write fallback.

Each write requires both proof of authenticated underlying transport and proof of current exact WordPress mutation rights. A historical VPS READONLY_LOCAL_MCP_VERIFIED receipt is not proof of write authority. Never trust a user-provided mcp_session_active boolean, a WP-CLI --user flag, a provider plugin being active, or a historical host log as current execution authorization.

The shared `MAD4B_SCP_Capability_Transport_Routing::choose()` gives a bounded decision. `public_manifest()` and `negotiate()` of the Unified Capability Gateway expose the central routing contract; their result is informational, never authorization. Feature-specific routers must consume the central contract instead of duplicating the transport decision. Translation now does.

## Failover and provider isolation

If an operation is independently confirmed not started, retry on another currently authorized transport can be considered. If previous outcome is unknown or partial, hold for reconciliation; if committed, verify the original side effect. Never blindly repeat a write or paid external API request. No implicit background translation queue.

An external model API may be chosen by a **separate provider router** after live connection, consent, model capability, per-site binding, budget and idempotency verification. A viable model API does not confer WordPress mutation authority.

The original `mad4b.portfolio-vps-mcp-client.v1` remains read-only, and is not silently promoted to a write adapter. The site-independent `MAD4B_Translation_Transport_Adapter` contract supports a future individually authorized VPS write bridge, but none was claimed from the historical read-only probe.

## Similar gaps addressed

- Unified Gateway advertised transport hints without one shared cross-capability read/write decision: centralized and exposed a common manifest.
- Translation relied on HTTP MCP protocol hotpath as if this were the only ChatGPT transport: separated model and transport and now consumes central decision.
- Hybrid MCP supports reviewed read fallback only: retained separate write gate, no automatic mutation fallback.
- Existing adaptive REST discovery is not a write permission: explicitly read-only in central route selection.
- A verified VPS read is not per-company write authority: explicitly rejected in regression.
- Unknown, partial or committed side effects are never replayed on a different transport without readback.
- Write Ability's `idempotent` annotation corrected to false; only exact independently verified results may be treated as repeat-safe.

## Acceptance and limitations

18/18 central routing regression tests PASS, including true/false cross-transport proof, REST versus write, historical audit denial, uncertain writes, Gateway public manifest and negotiation, and translation integration. Translation provider and transport test fixtures PASS; no network calls or source writes were performed by regression tests. ETG Staging remains noindex; DFSB publication and managed routes OFF.

Direct WordPress MCP from this ChatGPT connector still reports internal tool errors; VPS stdio MCP has read-only success, but **no remotely authorized live VPS write bridge has been demonstrated**. This code fixes central *selection and eligibility policy*; cross-transport operational write failover is held until exact current write authorization, integration testing, and independent readback. No Production rollout is claimed.
