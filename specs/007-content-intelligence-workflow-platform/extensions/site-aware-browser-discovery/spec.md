# Feature 007 — Adaptive Site-Aware Browser Discovery

**Status:** Implemented discovery and fail-closed dispatch design, not a live release certificate.
**Integration target:** PR #258 exclusively. **Authority:** discovery-only; WordPress writes, arbitrary shell, plugin installs, SEO changes, and production browser execution remain prohibited.

## Goal

Remove site-name-driven behavior from the Browser Acceptance *orchestrator* without pretending unknown plugins or business behavior can be automatically certified. ETG remains one reviewed adapter; All Royal Egypt and any other WordPress site produce the same bounded read-only discovery report, including unmapped capabilities. A new runtime adapter must publish reviewed recognition signals and a signed plan; an external driver must be bundled and reviewed separately.

## Contracts

- WordPress `MAD4B_SCP_Site_Capability_Discovery::observe()` returns `mad4b.site-capability-discovery.v1`, `discovery_complete`, `snapshot_sha256`, plugin IDs, CPTs, taxonomies, `provider_matches`, and `unmapped_plugins`.
- Provider descriptors may advertise `recognition.source_plugins`, `recognition.source_post_types`, `recognition.source_taxonomies`. No name/hostname heuristic executes a callback or confers authority.
- Browser Core `mad4b/browser-acceptance-capabilities` exposes the bounded snapshot only to the existing governed read scope.
- External `resolveSiteBrowserAdapter` compares discovery source requirements to the registered provider descriptor and `approvedSiteDrivers()` (compiled, review-owned allowlist). It enforces target/profile/revision/origin/snapshot equality.
- `run-live-site-browser-acceptance.mjs` obtains a signed WordPress provider plan, dispatches the matching locally audited driver through `run-site-browser-acceptance.mjs`, and returns evidence only to the provider-specific WordPress reducer.
- ETG legacy runner stays supported for existing explicit paths; it is **not** an All Royal Egypt driver. Driver registration never accepts site-supplied URLs, code, runtime module paths or selectors.

## State machine

`UNOBSERVED → DISCOVERED → RECOGNIZED → DRIVER_APPROVED → SIGNED_PLAN_READY → EXTERNALLY_OBSERVED → REDUCER_VERIFIED`

Failure states: `UNKNOWN_PLUGIN`, `ADAPTER_REQUIRED`, `DRIVER_NOT_APPROVED`, `PROFILE_UNRESOLVED`, `DISCOVERY_DRIFT`, `EVIDENCE_INCOMPLETE`, `BLOCKED`.

**Never map** `DISCOVERED` or `RECOGNIZED` to `PASS`. A test certificate is valid only for the exact signed plan, origin, authenticated build identity, approved provider evidence contract and external reducer receipt.

## Site-agnostic restrictions

Only HTTPS canonical origins are allowed in externally executed plan bindings. Discovery checks active plugin *basenames* without importing/activating anything. WordPress post types and taxonomies use the runtime's registered inventories; it never executes unknown plugin callbacks to "test what works". Plugin updates can change behavior without changing names; native provider build/version attestation and independent live acceptance remain required before certification. Plugin versions and UI behaviors not observed by this inventory are marked **unproven**, not assumed.

## Dynamic fallback rules

1. If exactly one provider advertises matching observed signals, and that provider's contract has a reviewed external driver, auto-select it and its declared default profile.
2. If multiple providers match, require exact operator selection. Never choose by recommendation ranking, plugin similarity or hostname.
3. If an Adapter is recognized but no audited external driver exists, block with `external_driver_not_approved`; no third-party module fetching.
4. If a plugin is unmapped, expose it in UI; continue other independent read-only observations, but never count its features as covered.
5. If an installed plugin changes, invalidate the snapshot only when observed signals change; no claim of full update/version detection until exact plugin version evidence is available.

## Review restriction

Auto-discovery is **not** full semantic adaptation. Captured theme/plugin header versions constrain known versioned drift, but cannot attest same-version file rebuilds or asset changes. Browser drivers are still review-owned, not learned, generated or installed from site hints. External browser network egress needs DNS/IP-level controls independent of this source-level allowlist. No one can claim release-ready solely because these negative source tests pass.
