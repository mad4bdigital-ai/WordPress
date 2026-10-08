## Assistant automatic solution routing (review-only)

The optional new read-only Ability `mad4b/assistant-solution-discover` accepts the exact `Assistant_Planning::read_plan` input plus optional related terms and per-gap cap. It calls the **existing** planning policy, expands only unresolved capability gaps via the generic local WordPress inventory, and emits candidate identity, matching evidence, uncertainty and strict `execution_allowed=false`. It is not an action executor, installer, approval or a new authority plane.

Private WordPress Abilities are represented to an administrator as opaque canonical names with private descriptions and labels redacted. No private Ability is executed. This allows the assistant to notice governed MAD4B admin tools as potential routes without exposing configuration or granting their scopes.

**Hardcoding boundary:** only structural schema, registered WordPress APIs and security enums are fixed. Plugin slugs, vendor adapters, hostnames, goal-to-vendor maps and executable targets are not fixed. The optional `related_terms` is caller-authored query expansion; no automated synonym or semantic correctness is implied. A result is always potential, never certified.

**Rejection cases:** stale profile/runtime/restore binding, insufficient WordPress admin privilege, malformed/untrusted inputs, duplicate plugin aliases, unknown provider behaviors, incomplete inventory and unavailable external discovery all remain explicitly non-authorizing. Expanding across connector Skills/Host or certification still needs trusted registry transport and independent evidence.

Native fixture:
`php wp-content/plugins/mad4b-site-control-plane/tests/assistant-solution-router-runtime.php`

This source-only change is not deployed or runtime certified by a GitHub tree update.