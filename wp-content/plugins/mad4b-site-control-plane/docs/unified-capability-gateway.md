# Unified Capability Gateway v1

The gateway chooses discovery, schema transfer and exposure strategy per client and per operation while keeping WordPress Abilities as the single source of truth.

## Invariants

- Client capability claims are compatibility evidence only. They never grant authority.
- REST is selected for catalog/schema work only after the client has successfully reached the OAuth-protected REST gateway.
- MCP remains the governed execution transport.
- Clients that prove they can refresh a changing tool catalog may use explicit dynamic projection when the existing projection authority gates also pass.
- Dynamic projection is currently an explicit site-enrollment projection, not a per-session catalog. Negotiation is per client; projection application is never implicit and does not claim per-client projection isolation.
- Other clients remain on the stable discovery/info/dispatch tool set.
- Search is metadata-only: it ranks bounded labels, descriptions, categories and declared metadata without loading schemas or making final execution/projection decisions.
- Preparation serializes only selected Ability schemas. Matching schema fingerprints are reused; large schemas are chunked with bounded adaptive size/parallelism recommendations.
- Every governed read/write/developer execution carries the prepared input-schema identity back to the existing dispatcher; read execution without an exact schema pin fails closed. The host refreshes discovery before execution. Direct execution additionally requires the wire schema/tool identity to remain unchanged.
- The gateway never invokes a target Ability directly.
- Unclassified/internal/unsupported lanes remain visible for diagnosis but fail closed for projection and execution.

The MCP specification exposes tools/list pagination and list-changed notifications, but the gateway does not infer that a client will consume either feature. Client behavior is negotiated explicitly and remains non-authorizing.

## Resource bounds

The host client defaults to at most 1,024 manifest pages and 32 MiB for one assembled schema. Hosts may lower these limits or explicitly raise them within the client hard ceilings. Server response budgets, retained-storage capacity, chunk sizes and preparation batch sizes remain separately bounded.
