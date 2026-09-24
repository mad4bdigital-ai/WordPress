---
name: wordpress-browser-acceptance
description: Execute governed external-browser acceptance from a fresh MAD4B plan through quota-aware managed browser providers and return evidence to the MAD4B reducer without granting the browser layer independent authority.
---

Use this skill when a WordPress release or provider integration requires real browser-runtime evidence beyond server-side semantic acceptance.

1. Read `mad4b/browser-acceptance-capabilities` first. Browser execution is external and non-authorizing; do not treat browser access as WordPress write authority.
2. Request a fresh `mad4b/browser-acceptance-plan` for the exact provider/profile through the authenticated MAD4B transport. Never fabricate, edit, or reuse an expired plan, challenge, route, taxonomy term, query ID, origin, or build identity.
3. Require the plan state to be `ready`, the expected provider contract to be present, and the freshness challenge to remain valid for the planned execution window.
4. Execute only plan-derived navigation and UI interaction. Do not accept caller-supplied URL, selector, JavaScript, taxonomy, term, query ID, SEO target, or arbitrary browser command.
5. Select browser infrastructure through the MAD4B managed-browser provider policy. Prefer recurring free capacity, require session-fit within the run budget, and keep provider limits advisory because live provider responses are authoritative.
6. A missing provider credential may be skipped. Quota, rate, capacity, provider authentication/configuration, or temporary provider transport failure may open that provider's circuit and fall back when `auto` policy permits.
7. A plan, observer, ETG/WordPress UI, DOM, filter binding, reset, semantic, or acceptance-execution error is not a provider outage. Fail closed and do not consume another provider quota attempting the same defect.
8. Enforce the per-run provider-attempt and browser-session budgets. Short-session providers may split the signed plan into bounded case chunks only while the original challenge remains valid; never create a replacement case or broaden scope.
9. Keep MAD4B MCP credentials separate from browser-provider credentials. Use a just-in-time short-lived MCP access token for live orchestration, never persist a refresh token in the browser runner, and never pass the MCP bearer into the browser subprocess.
10. Enforce the provider-neutral network boundary before navigation. Active cross-origin document, script, XHR, fetch, WebSocket, EventSource, manifest, or equivalent requests must fail closed unless the hostname is explicitly deployment-allowlisted; do not accept wildcard, URL, path, port, or caller-provided network scope.
11. Collect evidence only through the package-owned passive observer and complete result identity/order observation required by the signed case. Provider attempt logs must redact tokens, API keys, bearer values, and credential-bearing WebSocket query parameters.
12. Preflight the evidence against the same bounded contract used by the MAD4B reducer, then submit it through `mad4b/browser-acceptance-result` with the original `plan_digest` and `plan_signature`. Do not self-declare PASS from the external runner.
13. Treat `browser_runtime_parity_verified=true` and reducer `verdict=PASS` as browser evidence only. SEO publication, Production activation, write authority, plugin lifecycle mutation, and other release gates remain independent.
14. Return the selected provider, provider-attempt ledger, run-budget usage, plan digest, reducer verdict, parity verification, unresolved observation gaps, and the next governed release gate.
