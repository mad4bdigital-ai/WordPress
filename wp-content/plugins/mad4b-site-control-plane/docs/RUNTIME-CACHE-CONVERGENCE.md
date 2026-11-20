# Runtime Cache Convergence

## Scope

The canonical Self Update already invalidates package-indexed PHP opcodes.
Out-of-band deployments (WordPress upgrade, CLI, SSH, or file replacement)
can bypass that code path. An accurate disk build can therefore coexist
with an old OPcache executable generation. WP-CLI checks cannot certify
the LiteSpeed/web PHP cache.

The enrolled managed MU bootstrap provides a pre-plugin recovery lane.
On the first enrolled non-production MCP POST after a changed release:

1. Verify Site Profile, active plugin identity, and exact MCP route.
2. Check bounded canonical package provenance and all indexed file hashes.
3. Invalidate only manifest-listed PHP files in the web SAPI.
4. Persist a site/root/source/manifest-bound non-authorizing receipt.
5. Respond with one retryable HTTP 503; previously loaded PHP classes
   must not serve the updated release in the current request.

No global reset, shared PHP restart, arbitrary paths, automatic Developer authority,
owner ticket, write grant, or business-data mutation is part of this flow. Production only qualifies with an explicit matched Production host environment and exact Site Profile enrollment; early MCP adapter boot authority stays independently gated.
An unchanged receipt avoids repeating the full verification scan.
Failed integrity or opcode checks remain closed. With OPcache disabled,
a verified package can proceed without opcode invalidation.

## Adoption and acceptance

The new managed MU generation must first be installed using the existing
governed MU refresh (ownership, audit and transaction checks). Replacing
regular plugin files does not automatically replace the already-loaded MU
script in the current request.

Acceptance requires web-SAPI readback after a fresh MCP request.
On servers with multiple isolated PHP worker pools, independent pool
verification may be needed; one worker's receipt cannot certify another.
Build integrity, cache convergence, and write authority are separate gates.
A successful CI run is not the same as live acceptance on both sites.
