# Unified Capability Gateway v1

The gateway chooses discovery, schema transfer and exposure strategy per client and per operation while keeping WordPress Abilities as the single source of truth.

## Invariants

- Client capability claims are compatibility evidence only. They never grant authority.
- REST is selected for catalog/schema work only after the client has successfully reached the OAuth-protected REST gateway.
- MCP remains the governed execution transport.
- Clients that prove they can refresh a changing tool catalog may use explicit dynamic projection when the existing projection authority gates also pass.
- Direct projection has an independent serialized `tools/list` budget (96 KiB for tool DTOs). Schema chunking does not bypass this budget: an oversized optional Ability remains available through task discovery, preparation and the governed dispatcher instead of being forced into `tools/list`.
- Dynamic projection is currently an explicit site-enrollment projection, not a per-session catalog. Negotiation is per client; projection application is never implicit and does not claim per-client projection isolation.
- Other clients remain on the stable discovery/info/dispatch tool set.
- Search is metadata-only: it ranks bounded labels, descriptions, categories and declared metadata without loading schemas or making final execution/projection decisions.
- Preparation serializes only selected Ability schemas. Matching schema fingerprints are reused; large schemas are chunked with bounded adaptive size/parallelism recommendations.
- Every governed read/write/developer execution carries the prepared input-schema identity back to the existing dispatcher; read execution without an exact schema pin fails closed. The host refreshes discovery before execution. Direct execution additionally requires the wire schema/tool identity to remain unchanged.
- The gateway never invokes a target Ability directly.
- Unclassified/internal/unsupported lanes remain visible for diagnosis but fail closed for projection and execution.
- Projected Breakglass is structurally registered without consulting pre-auth request identity, but authenticated visibility and call admission require exact ChatGPT step-up plus `server:mad4b-breakglass` or the exact `ability:<name>` scope. Original NHI grant, runtime gate, approval and Ability permission checks still run.

The MCP specification exposes tools/list pagination and list-changed notifications, but the gateway does not infer that a client will consume either feature. Client behavior is negotiated explicitly and remains non-authorizing.

## Resource bounds

The host client defaults to at most 1,024 manifest pages, 32 MiB for one assembled schema and four parallel REST schema fetches. Parallelism is adaptive and bounded; MCP schema transfer remains sequential by default unless a future host contract proves safe concurrent tool calls. Hosts may lower these limits or explicitly raise them within the client hard ceilings. Server response budgets, retained-storage capacity, chunk sizes, preparation batch sizes, the 36-tool count ceiling and the 96 KiB direct-tool DTO ceiling remain separately bounded. WordPress 6.9 is exercised with the declared minimum PHP 7.4 runtime; the latest WordPress matrix lane runs on PHP 8.3.
