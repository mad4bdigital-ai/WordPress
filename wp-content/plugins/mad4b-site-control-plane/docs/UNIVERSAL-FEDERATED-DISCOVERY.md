# Universal solution federation (any site)

`tools/solution-discovery/federation.mjs` implements a CMS- and vendor-independent **read-only** federation between connected catalog readers and an exact site target. All source inspectors must be provided by the host's already-authorized tool layer; a WordPress plugin cannot discover ChatGPT connections, Hostinger SSH secrets or all connected apps by itself.

## Input and site identity
- Target: opaque `site_id`, `environment`, `origin_sha256`, `runtime_generation` (no hostname, vendor slug, tenant name, or credential is hardcoded).
- `enumerate(site)`: callback to the available read-authorized source registry. Each returned descriptor must be bound to that exact site and environment, `connected=true`, `read_authorized=true`, `lane=read`.
- `inspect({site, source_id, kind, lane:'read'})`: callback to an authorized, externally implemented **read-only** inspector returning site-bound metadata and an observation digest. No arbitrary code from that metadata is run. No hidden authorization is inferred from `read_authorized`: the host must enforce actual tool-specific policies.
- Returns candidate labels and bounded `external_hints` that can be passed to the existing `mad4b/assistant-solution-discover` WordPress read ability via `toWordPressRouterInput`. Other CMSs may consume the generic candidate output directly; no WordPress requirement.

## Failing safely
Disallow wrong site/origin/environment, inactive sources, missing read scope, corrupted discovery receipts, code-like labels and oversized catalogs. Keep partial-source failures explicit. Candidate descriptions and matching scores are **untrusted, lexical-only, unverified**. Do not treat a source-reported observation SHA as a signed receipt; this is continuity metadata only.

## Limitations and acceptance
This is an external *library*, not a deployed collector or a connector installation: it does not enumerate the user's apps by itself, call Hostinger, open files, verify browser flows, select a driver, create grants or execute writes. Actual eligible app catalogs and current tool permissions must be obtained from the host during an authorized request.

Native test command: `node tools/solution-discovery/test-federation.mjs`.
Independent Staging acceptance must check exact installed plugin/build, live site identity, native PHP/Node, permission boundaries, and verified readback before any GA release claim. CI queue status is not acceptance evidence.
