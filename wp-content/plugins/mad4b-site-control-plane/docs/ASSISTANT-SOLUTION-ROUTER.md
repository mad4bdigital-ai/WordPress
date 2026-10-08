## Assistant automatic solution routing (review-only)

The optional new read-only Ability `mad4b/assistant-solution-discover` accepts the exact `Assistant_Planning::read_plan` input plus optional related terms and per-gap cap. It calls the **existing** planning policy, expands only unresolved capability gaps via the generic local WordPress inventory, and emits candidate identity, matching evidence, uncertainty and strict `execution_allowed=false`. It is not an action executor, installer, approval or a new authority plane.

Private WordPress Abilities (`show_in_rest=false`) are excluded entirely, in accordance with WordPress' REST visibility boundary. MAD4B private/admin tools must use their existing governed MCP catalog/projection, not the general WordPress metadata read. No discovered Ability is executed.

**Hardcoding boundary:** only structural schema, registered WordPress APIs and security enums are fixed. Plugin slugs, vendor adapters, hostnames, goal-to-vendor maps and executable targets are not fixed. The optional `related_terms` is caller-authored query expansion; no automated synonym or semantic correctness is implied. A result is always potential, never certified.

**Rejection cases:** stale profile/runtime/restore binding, insufficient WordPress admin privilege, malformed/untrusted inputs, duplicate plugin aliases, unknown provider behaviors, incomplete inventory and unavailable external discovery all remain explicitly non-authorizing. Expanding across connector Skills/Host or certification still needs trusted registry transport and independent evidence.

Native fixture:
`php wp-content/plugins/mad4b-site-control-plane/tests/assistant-solution-router-runtime.php`

This source-only change is not deployed or runtime certified by a GitHub tree update.
### Unmapped external catalog candidates

Optional `external_hints` can contain vetted-shape descriptions from an eligible connector, skill, external service or operator. They are untrusted candidates, never authoritative grants. The router validates them even on plans with no actionable gaps. It does not see ChatGPT's plugin list itself; the external client must collect approved public/safe descriptors and pass them through the governed MCP invocation. No automatic Hostinger/SSH session is inferred.


When there are no candidates, the router distinguishes `INVENTORY_INCOMPLETE_RETRY` from `EXPAND_DISCOVERY` using the local registry completeness flags. Separate `extension_inventory_complete` reports MU/drop-in registry availability. If an existing WordPress extension cannot be examined, the assistant must explore a permissioned external provider inventory rather than falsely conclude no solution exists.
