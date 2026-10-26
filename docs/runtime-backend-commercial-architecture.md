# MAD4B Portable Runtime Backend Registry — implementation and acceptance

This is **commercial core**, not an ETG/ARE integration. No back-end availability grants WordPress, Developer, Shell, SQL, Production or Breakglass execution rights.

## Implemented in PR #507
- Runtime Backend Registry (`MAD4B_SCP_Runtime_Backend_Registry`) with types: `local_php`, `docker`, `ssh`, `https_runner`, `queue_worker`, `custom`.
- Preset priorities: Shared Hosting, Managed WordPress, VPS, Containerized, External Runner; administrator-gated override/reset and strict validation.
- Extensibility through `mad4b_runtime_backend_candidates` WordPress filter. Providers supply sanitized bounded candidate metadata, not arbitrary shell commands or credentials.
- Normalized lifecycle stages: discovered / configured / reachable / certified / authorized / operation-ready; deterministic selection only when *all* gates are true. Uncertified backends cannot be selected.
- Operation-specific safe fallback only for allowlisted read/lint tasks and an explicit `confirmed_not_started` previous outcome; uncertain writes are never replayed.
- Existing Developer host capability probe and external candidate discovery feed the registry; built-in candidates are not authorized by default.
- Existing bounded external HTTPS availability observer operates on WP-Cron, uses short timeout, no redirects, SSRF-safe WordPress HTTP client and cooldown. Availability is not an attestation.
- Unit contract and CI checks include a fail-closed selection and preset test; code is not auto-activated on live ETG Staging without an exact certified release.

## Provider SDK guidance
Provider plugins should hook `mad4b_runtime_backend_candidates` to report sanitized candidates. The registry strips unexpected fields and never reveals hostnames, paths, endpoints, tokens, or keys. Providers MUST validate exact enrolled site identity, an authenticated transport, detached signed capability attestations, isolation, resource limits, operation contract versions, approval tickets, replay resistance, response receipts, and rollback obligations *outside* this registry before setting certification/authorization flags. Assertions by a third-party provider are NOT, by themselves, sufficient for core authorization.

## Lifecycle
Activation and updates register the catalog without contacting remote hosts in the protocol hot path. Configured external provider discovery can be refreshed periodically through cron and the `mad4b_runtime_backend_probe_due` action. Provider identity/health/certification should expire independently and fail closed if stale. Disable/uninstall policies must cancel cron and respect site retention policy; an individual backend never brings down MAD4B core.

## Deployment gap — NOT YET CERTIFIED
- No general-purpose authenticated WordPress -> VPS/HTTPS/SSH/Docker Developer execution Adapter, task signature verification, deduplicated job queue, or signed receipt path has been certified.
- Live Staging Owner Write authority remains separate. Developer may not execute until an isolated nonroot worker and site-bound authorization/attestation pass.
- CI, end-to-end MCP execution, production upgrade/rollback, commercial SDK compatibility across hosting providers, and operator/GUI acceptance all require independent evidence.
- Existing ETG VPS Docker and pinned SSH diagnostics are *diagnostic proof of available components*, not proof of a secure universal remote execution product.
