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

## Scoped catalog completeness and pagination

For completeness statements, `enumerate(site)` must return a non-authorizing, read-only `mad4b.site-source-catalog.v1` envelope with **all four** target identity fields and `complete=true`. Legacy arrays are accepted for searching but always report unknown completeness. Individual source metadata and inspection outputs must match the exact site, environment, origin and runtime generation. Source status failures are reported as partial, not as "no solution".

The index supports `offset` + `expectedSnapshot` for consistent pagination. Its 64-bit checksum is **non-cryptographic continuity only**; it is not an authentication proof, signed receipt, trust level, or authorization grant. Returning to a modified registry with a stale cursor fails closed. Combined capability IDs are deterministically shortened when needed for the bounded WordPress hint schema.

`feature007-manual-preflight.py` now runs this native Node fixture when Node is available; otherwise the case is BLOCKED. The V8 in-process tests do not stand in for Node or for site acceptance.

## Universal remediation proposal (no implicit execution)

`planRemediation({target,discovery,operation_id,requested_effect,desired_state})` produces a CMS-neutral non-authorizing proposal. It pins the same site/environment/origin/generation (and optional WordPress profile), records the proposed effect, lists admission requirements and keeps every candidate `execution_allowed=false`.

For read-only issues, an independent capability/effect proof and scoped readback remain necessary. For any proposed mutation, the proposal requires an **externally verified backup, reviewed reversible action, compensating rollback, consent/authority and independent postcondition**. Production additionally requires separate promotion authority; staging approval is never inherited. The helper does not open an SSH session, use a file manager UI, call host APIs or assert that a backup exists.

### Risk provenance and qualification

Candidate `declared_risk` and `declared_effect` originate from an *untrusted source observation*. A reported `high` or `exceptional` risk, or a `write`/`execute` effect, moves that candidate to exceptional review and excludes it from automatic WordPress hint handoff. Missing risk fields remain `unknown`, never implicitly low. In WordPress, the existing governed Plugin Discovery report is joined by exact plugin main-file/version; the exceptional-risk gate must not be overwritten by a generic lexical relevance score.

### Freshness and catalog integrity

An inspector can return `observed_at` and `valid_until` Unix-second fields. Observations that are expired, future dated outside skew, or longer-lived than the one-day maximum are rejected from candidates and marked `STALE_OR_INVALID_OBSERVATION`. Undated observations remain visible only as unverified metadata, with `freshness_complete=false`. Explicit read authorization and exact site-bound source registry must be checked by the hosting runtime; descriptions are never instructions.

### Example: any site, no vendor-specific branch

```js
import {discoverFederated, planRemediation} from "./federation.mjs";
const discovery = await discoverFederated({
  target: siteBinding, query: "configuration environment",
  enumerate: siteScopedCatalogReader, inspect: permissionedReadInspector
});
const proposal = planRemediation({
  target: siteBinding, discovery,
  operation_id: "environment_configuration",
  requested_effect: "write", desired_state: "staging environment explicit"
});
// proposal.execution_allowed is always false.
// Execution belongs to a different verified, authorized host provider.
```

Production-safe defaults: no source discovery may mint credentials, call a writer, install a plugin, launch Browser Acceptance or auto-promote a release. These require separate authority, a verified plan and native functional acceptance.

## True completion evidence

Native Node fixture: `node tools/solution-discovery/test-federation.mjs` (included in the exact-source manual preflight); native PHP fixtures under 7.4 and 8.3; real site admission and connected-source registry evidence; current profile/host/permission mapping; a signed or independently verified execution receipt and rollback proof for any actual mutation. V8 structural source checks are valuable but do not certify those requirements.

## Qualification projection: no implicit execution or write grants

The WordPress Solution Discovery reducer now projects the **existing** governed Plugin Discovery signals by exact plugin main-file/version: risk, functional state, side-channel blocking, adapter runtime availability, available read abilities, certification status and reversible contract count. This information is advice only; **every candidate keeps `qualification_verified=false` and `execution_allowed=false`**, even if a provider declares `functional_ready`. Missing/stale plugin evidence is `EVIDENCE_INCOMPLETE`, not safe. Extraordinary risk and blocked side channels require dedicated review.

`inventory_incomplete` describes source coverage, while `qualification_incomplete` describes provider effect/risk evidence; neither is silently promoted to runtime-ready. G3 remains the sole owner of independent effects certification.
