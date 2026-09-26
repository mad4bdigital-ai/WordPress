---
name: wordpress-browser-acceptance
description: Execute governed external-browser acceptance from a durable exact-build MAD4B work job and return evidence through the server-owned reducer without granting the browser layer independent authority.
---

Use this skill when a WordPress release or provider integration requires real browser-runtime evidence beyond server-side semantic acceptance.

1. Read `mad4b/browser-acceptance-capabilities` and current exact build provenance first. Browser execution is external and non-authorizing; do not treat browser access as WordPress write authority.
2. Use `mad4b/browser-acceptance-run` as the primary operator entrypoint with the exact current source commit, build fingerprint, package-manifest digest, provider/profile, and the explicit `RUN BROWSER ACCEPTANCE` confirmation. This creates or reuses a durable `browser_acceptance_execution` work job that contains the server-signed plan and freshness challenge.
3. Do not fabricate, edit, or replace the queued plan, challenge, route, taxonomy term, query ID, origin, build identity, selector, JavaScript, URL, or SEO target. The job payload is the only execution scope.
4. An external browser executor must claim the job through `mad4b/remote-operation-work-claim` using the same exact build identity, a bounded executor ID, and an expiring fenced lease. Never execute work from an expired job or lease.
5. Execute only plan-derived navigation and UI interaction. Collect evidence only through the package-owned passive observer and the exact cases in the queued plan.
6. Select browser infrastructure through the MAD4B managed-browser provider policy. Prefer recurring free capacity, require session-fit within the remaining challenge/lease window, and keep provider-limit metadata advisory because live provider responses are authoritative.
7. Missing provider credentials may be skipped. Quota, rate, capacity, provider authentication/configuration, or temporary provider transport failure may open that provider's circuit and fall back when `auto` policy permits.
8. A plan, observer, ETG/WordPress UI, DOM, filter binding, reset, semantic, dataset-parity, SEO non-authority, or acceptance-execution error is not a provider outage. Fail closed and do not burn another provider quota attempting the same product defect.
9. Keep MAD4B MCP credentials separate from browser-provider credentials. Use a just-in-time short-lived MCP token, never persist a refresh token in the browser runner, and never pass the MCP bearer into the browser subprocess.
10. Enforce the provider-neutral network boundary before navigation. Active cross-origin document, script, XHR, fetch, WebSocket, EventSource, manifest, or equivalent requests fail closed unless the hostname is explicitly deployment-allowlisted. Wildcard or caller-provided network scope is forbidden.
11. Evidence must include the queued plan digest/signature, exact origin/build identity, browser/JavaScript observer identity, freshness challenge, all required case observations, JetSmartFilters events, AJAX presentation response, authoritative result count, dataset identity/order proof, URL/reset behavior, and unchanged canonical/robots/hreflang/Rank Math state.
12. Complete the claimed job through `mad4b/remote-operation-work-complete`, passing the lease token and bounded `browser_evidence`. WordPress must run the evidence through `mad4b/browser-acceptance-result` internally; completion is denied unless the reducer returns `verdict=PASS` and `browser_runtime_parity_verified=true`.
13. Never self-declare PASS from the external executor. A completed durable job is valid only while its source commit, build fingerprint, package-manifest digest, provider/profile, current plan digest/signature, and server-owned Browser Acceptance receipt still match.
14. Direct `mad4b/browser-acceptance-plan` / `mad4b/browser-acceptance-result` calls are diagnostic/fallback surfaces, not the preferred Staging-certification persistence path.
15. Treat Browser Acceptance as evidence only. SEO publication, Production activation, normal write authority, plugin lifecycle mutation, and other release gates remain independent.
16. Return job ID, executor ID, claim generation, provider-attempt ledger, run-budget usage, plan/evidence digests, reducer verdict, parity verification, unresolved gaps, and the next governed release gate.
