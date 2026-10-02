# Unified Capability Gateway v1

The gateway chooses discovery, schema transfer and exposure strategy per client and per operation while keeping WordPress Abilities as the single source of truth.

## Invariants

- Client capability claims are compatibility evidence only. They never grant authority.
- REST is selected for catalog/schema work only after the client has successfully reached the OAuth-protected REST gateway.
- MCP remains the governed execution transport.
- Clients that prove they can refresh a changing tool catalog may use explicit dynamic projection when the existing projection authority gates also pass.
- Other clients remain on the stable discovery/info/dispatch tool set.
- Preparation serializes only selected Ability schemas. Matching schema fingerprints are reused; large schemas are chunked with bounded adaptive size/parallelism recommendations.
- Every execution plan points back to the existing governed dispatcher and revalidation boundary. The gateway never invokes a target Ability directly.
- Unclassified/internal/unsupported lanes remain visible for diagnosis but fail closed for projection and execution.

The MCP specification exposes tools/list pagination and list-changed notifications, but the gateway does not infer that a client will consume either feature. Client behavior is negotiated explicitly and remains non-authorizing.
