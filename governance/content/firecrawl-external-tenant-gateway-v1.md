# Firecrawl: central and external company-sites connection model

Status: infrastructure readiness contract; not a deployment instruction.

- Public endpoint: `https://firecrawl.mad4b.com` served by a dedicated authenticated gateway, never expose the API container directly.
- MAD4B Control Plane and WordPress sites running outside the VPS use the external HTTPS endpoint. Local services may use a *separately approved* private endpoint for efficiency; internal Docker access is never required.
- Identity is a tenant-scoped access token or signed short-lived service credential issued to a specific company/site identity. Bind `tenant_id`, `site_uuid`, `environment`, `actor`, `operation_key`, `allowed_methods`, rate/credit budgets; revoke and rotate independently.
- Credential must not be stored in the content bundle, research record, WP option plaintext, URL or logs. Encrypt at rest and display write-only admin controls.
- Proxy route: TLS SNI host `firecrawl.mad4b.com` -> protected gateway service port 8080 (host loopback mapping currently 127.0.0.1:13082). Coolify Traefik must have an explicit host router and network-reachable service destination; returning 503 is NOT ready.
- Gateway must authenticate external caller prior to invoking Firecrawl, enforce per-tenant quota, request/response size caps, timeouts and SSRF protections, and emit auditable request IDs. `401` for no credentials; authorized health endpoint `200` when healthy.
- API path/version `/v1` vs `/v2` must be probed against the deployed self-hosted version, not guessed from Firecrawl Cloud docs.
- WordPress native provider uses WordPress HTTP API for HTTPS external calls; request is associated with research `group_uid` and stage `run_id`. Save resulting evidence to task-scoped records; no implicit publication.
- Prevent cross-tenant reads, unbounded crawl fanout and credential forwarding in redirects; test revocation, site mismatch, 401/403/429/5xx, retries, duplicate submission, response provenance and budget enforcement.
- Acceptance: external call from a company-site context with short-lived credential, correct tenant-bound record, isolated access, and readback. HTTP 503 means NOT READY; verify routing without relaxing authentication.
- The existing Tour Staging provider is currently `configuration_missing`; production Firecrawl gateway currently replies `401` through local loopback and public domain `503`. This document does not claim integration is live.
