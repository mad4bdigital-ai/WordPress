# Adaptive MCP Catalog Budget — MAD4B

## Scope

This feature limits **directly materialized** tools on the `mad4b-chatgpt` MCP endpoint. It never limits the WordPress Abilities registry, JetEngine or WooCommerce providers, or the governed discovery/info/execute dispatcher. Tool execution permissions, OAuth grants, approvals and Site Profiles are unchanged.

## Measured, site-scoped policy

The default remains **36** direct tools and **98,304 serialized bytes** (96 KiB), matching the previously tested envelope. Optional direct tools may be trimmed; the required discovery and dispatch transports must not be dropped to meet a budget.

Site administrators may explicitly set these private `wp-config.php` constants (never MCP or an unauthenticated REST parameter):

| Constant | Default | Valid range |
| --- | ---: | --- |
| `MAD4B_MCP_CATALOG_MAX_TOOLS` | 36 | 8–64 |
| `MAD4B_MCP_CATALOG_MAX_BYTES` | 98304 | 32768–196608 |
| `MAD4B_MCP_CATALOG_TARGET_MS` | 1200 | 300–5000 |

Values outside these bounds fall back to defaults (rather than expanding or granting anything).

**Growth:** A higher configured maximum does not immediately expand the initial direct catalog. Until at least three successful, authenticated `tools/list` requests on that enrolled site's current WordPress origin/environment have been sampled, the legacy 36/96 KiB envelope is used. If the measured exponentially weighted average end-to-end request time is at or below the configured latency target, the configured higher maximum becomes eligible for optional direct materialization. If average time exceeds the target, optional count and serialized-byte budgets are conservatively reduced (to 75% of the smaller configured/legacy envelope). These are **policy ceilings**, not promises of specific counts.

**Safety:** Required tools keep their full *configured* ceilings even during latency backoff; if the number or serialized size of required tools exceeds configured maxima, validation blocks rather than silently excluding them. For arbitrary extra capabilities, use the standard governed discovery/info/execute paths. No code automatically changes OAuth exposure, production authorization or the Ability registry.

The observation uses the PHP server's `REQUEST_TIME_FLOAT` through `rest_post_dispatch` for an authenticated and successfully validated `tools/list`, so it measures WordPress bootstrap + catalog preparation + serialization, not client network RTT. Samples contain only EWMA latency, count, sample total, and a SHA-256 site binding; no bearer, operation arguments, query strings or client identity. The transient is site-local, limited to one write per 30 seconds and expires after two hours. Reads are cached per PHP request to avoid extra DB queries. This is not a benchmark of network round-trip latency or end-user experience.

## Release and testing

- `php wp-content/plugins/mad4b-site-control-plane/tests/mcp-catalog-adaptive-budget.php` tests the backwards-compatible default.
- `MAD4B_CATALOG_TEST_SCENARIO=healthy php ...` and `slow`, `unmeasured`, `site_drift`, `invalid` cover bounds, fallback, transport preservation and isolation.
- `.github/workflows/mad4b-mcp-adaptive-catalog.yml` runs these checks automatically.
- The existing `MCP Client Compatibility` / `Local OAuth Standalone` workflows must still pass on the same commit before treating the new candidate as ready for **Staging certification**.
- Benchmark against real ARE Staging exact SHA: `tools/list` latency p50/p95, response bytes, tool count, PHP CPU / peak memory, DB queries, required-tool parity, optional exclusions, and discovery/execute success. Observe both cold bootstrap and warmed calls.
- Raising configured maxima requires explicit operator action and evidence from the enrolled Staging site; do **not** enlarge them on Production based solely on synthetic CI.
- A successful ZIP build does not automatically repair plugin manifest drift or authorize publication, Shell, Raw SQL, OAuth grants or any Production write.
