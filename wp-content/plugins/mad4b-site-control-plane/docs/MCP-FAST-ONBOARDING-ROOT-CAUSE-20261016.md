# MAD4B — Resilient MCP onboarding and checkpoint recovery (2026-10-16)

## Evidence / root-cause classification

Observed on **both Staging sites** using the previously deployed rc.102 package, exact source `303d4d1001b3021faf65f474b32e294b40f2df91`:

- Direct MCP call `mad4b_reconcile_managed_skills` can report `Tool not found`, even while the governed Enrollment dispatcher registers `managed_skills_reconciliation`. This is a provider/tool-registration or client-refresh mismatch, not proof the target operation is absent. Use the versioned Enrollment dispatcher as the authoritative command; do not blindly call stale direct aliases.
- Multiple `The tool failed internally` results coincided with **durable Skills seed cursor progression** and later `ready=true` certifications. Therefore a tool exception is NOT evidence of rolled-back or failed WordPress work. The client must read the exact checkpoint after an uncertain write.
- Planning/prepare and deep status calls intermittently failed, even though `site_info`, Session Safe Diagnostics and Enrollment Information could succeed. These failures span request admission, projection and execution; exact upstream transport cause is **not proven** without edge-layer request traces.
- Existing `site-autopilot-status` performed Full Staging Authority and live Skills evaluations for a routine next-step read. Repeating those heavyweight synchronous reads increases latency and transport exposure. These are confirmed source-code hotspots, not definitive proof that they caused every connector failure.
- The Enrollment dispatcher did not locally contain thrown child exceptions from its governed child execution. A Throwable could propagate to the MCP boundary as an opaque internal failure even if the child checkpoint persisted. This is a correctable source-level failure mode.

## Implemented

1. `mad4b/site-autopilot-fast-status`: new read-only, compact endpoint. Reads the exact Site Profile and saved managed Skills checkpoint only. No Full Authority, no live Skills inventory, no plugin fleet scan or Browser/HTTP request in the fast path.
2. Pure `fast_plan` with dynamic next-step selection, exact existing operation ID and source-bound checkpoint state. Unknown Write authority is NEVER inferred ready from a Skills checkpoint, and Browser/provider acceptance remains independent.
3. Enrollment child exceptions become bounded, redacted, non-authorizing `reconciliation_required` envelopes. For a write whose outcome may already have persisted, `blind_retry_allowed=false`, `mutation_performed=null` and the original owner gate is preserved.
4. MCP full candidate inventory advertises the new fast read ability in the read and ChatGPT full discovery surfaces.
5. PHP 7.4 and 8.3 tests cover: stale vs current checkpoints; fast endpoint never invoking deep authority/skills; exact owner approval retained after a child exception; no leakage of exception messages; no unexpected Production execution.

## Recommended operating architecture: one intake, event-driven continuation

- **Discovery (fast):** one request `mad4b/site-autopilot-fast-status` to identify site, deployment binding and current Skills checkpoint. Only call deeper diagnostics if a gate is unknown or unhealthy.
- **Plan:** construct a version-bound DAG: Deployment Binding → identity/OAuth → managed Skills → exact Governed Write → dynamic plugin coverage → external Browser/performance/backup acceptance. Each node has an evidence requirement, a permitted execution lane and a stable operation ID.
- **Execute safe eligible steps:** use already-registered exact, step-up-fenced Enrollment operations, one checkpoint batch per invocation. Continue from the same durable operation ID; do not create new grants automatically. If a response is lost, query the checkpoint before another authorized invocation. Batching can be adaptively tuned **after collecting latency distributions**, not by uncontrolled parallel writes.
- **Approval inbox:** group independent human-only owner decisions into a clear review queue, but keep exact target digest, expiry and actor eligibility per ticket; a single broad consent is not a substitute.
- **Provider compatibility:** discover ALL installed plugins dynamically; use bounded paginated L0–L4 inventory, certify each reversible capability contract, never auto-enable an unknown or high-risk plugin. Provider failure must not block unrelated core diagnostics.
- **Release acceptance:** independent public/browser/host/performance/rollback evidence; core Write ready is not release-certified.
- **Performance:** one initial fast observation; follow-up read bundles per generation with budgets; request latency and error-class counters separated by client edge, WordPress dispatcher and provider target. Use correlation IDs and redacted receipts; no raw secrets.

## Boundaries and deployment

Changes are source-level only until the new build is installed and verified on each Staging site. Do not claim the direct MCP adapter's externally cached fixed tool set will update without a connector metadata refresh/reconnect. No Production, Developer, Breakglass or generic approval bypass is included.



## Follow-up: consent first action and source-bound runbook

### OAuth consent surface (ETG and reusable on every Staging resource)

- A request with an empty client_id is not a resumable OAuth login. The actual unauthenticated ETG endpoint returns HTTP 400 for /oauth/mcp/authorize?response_type=code&client_id=; do not fabricate a trusted client or bypass PKCE. Restart the connector's OAuth handshake to obtain a complete valid request.
- The approve/deny POST form is now rendered before the optional Live Authority list, regardless of JavaScript/CSS availability. Its existing nonce, hidden request values, authenticated subject, redirect allowlist and PKCE checks stay with Local_OAuth_Server.
- Live Authority AJAX stays independent of the authorization decision. A 12-second AbortController deadline is applied to each status refresh, with bounded backoff and fail-closed freshness on error. The UI now correctly describes its 60-second periodic refresh (plus focus/visibility), rather than promising 15 seconds.
- 102/102 existing exact grants may coexist with candidate_binding_mismatch; OAuth approval is not write authority. proc_open_unavailable, resource_limiter_unavailable and network_isolation_unavailable are host prerequisites and cannot be repaired by consenting to OAuth.
- Acceptance requires a fresh full OAuth URL, a real consenting administrator, POST/redirect success, and a trusted redirect code exchange. Never log raw authorization code, access token, refresh token, state or PKCE verifier. Live browser completion was not available in this source-only phase.

### Intelligent Reconciliation read-only phase

The mad4b/site-autopilot-fast-status ability now emits a runbook using exact WordPress runtime provenance, not facts inferred from the conversation. It computes the current candidate's source SHA, build fingerprint and package manifest only if identity_ready, exact Staging Site Profile, explicit WordPress environment, origin and deployment binding are verified. It recognizes an existing resumable checkpoint only with matching current identity, a UUID-format operation ID, a recognized stage, no active lock, and a fresh-permission requirement.

The plan directs callers to versioned Enrollment Info/Execute for Skills, exact Staging Write handshake for owner-reviewed Write, and paginated Provider Closure Matrix for every discovered installed provider, rather than hardcoding 28 or automatically granting an uncertified provider ability. It neither runs a mutation nor creates an approval ticket or grant. A failed MCP write remains outcome uncertain until a readback matches the existing operation ID; do not blindly retry or switch executors to circumvent an approval refusal.

### Remaining independent acceptance

- Connected-WordPress OAuth interactive authorization and browser button/redirect tests
- Managed Skills checkpoint completion and independent exact-candidate Governed Write convergence on each site
- JetSmartFilters / Elementor and other provider behavioral tests, reversible rollback and owner-reviewed exact ability grants
- Host-internal homepage HTTP 500 versus public HTTP 200, real browser AJAX parity, frontend performance and backup/restore certification
- GitHub CI on this exact source head; new exact-head ZIP and deployment are separate controlled steps

Production, Developer, Breakglass, raw-SQL and unsupervised owner ticket approvals remain excluded.
