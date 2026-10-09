# Native WordPress Semantic Oracle + Browser Attestation (Feature 007)

Status: source implemented on child branch; not Staging runtime certified. Scope: published *public page canonical* parity. This is not a universal oracle for booking, payments, multilingual relationships or arbitrary plugin behavior.

## Contract ownership
- WordPress `MAD4B_SCP_Native_Capability_Browser_Provider` registers through the existing governed `mad4b_browser_acceptance_providers` filter, identified by functional contract `mad4b.capability-browser-provider.v1`, not the site hostname.
- WordPress holds the trusted **public** RSA key in `MAD4B_BROWSER_ATTESTATION_PUBLIC_KEY_PEM`. The browser worker holds the independently provisioned **private** key as base64 PEM in `MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64`.
- The two keys must form a certified pair, at least RSA-2048. WordPress parses and checks the real RSA public key; the agent derives an SPKI SHA-256 key ID from its private key. The orchestration process checks matching key IDs before requesting a plan. Public key presence alone is **not** browser readiness. Neither the plugin nor the user browser is allowed to generate, export or install the private key through a read-only MCP call. Missing key or bad provenance => BLOCKED.
- The WordPress native oracle only queries `page` + `publish`, verifies each returned row is public/no password, obtains `wp_get_canonical_url` and `get_permalink`, and accepts only exact same-origin public paths.
- WordPress build source SHA, build fingerprint, runtime manifest match, current plugin/theme discovery fingerprint **using the actual registered Provider inventory**, active operator configuration revision and each semantic expectation are included in the HMAC-signed plan. The external resolver rejects generic plans whose snapshot/revision differ from its original authenticated capabilities readback.
- Each issued plan contains a fresh cryptographically random nonce even when two requests occur in the same second. The server HMAC signs the plan and nonce/challenge epoch; the reducer reconstructs the exact plan from the agent-signed epoch and nonce without mutating WordPress storage. This provides unique issuance, **not** single-use replay prevention. Duplicate submission inside the 600-second window remains a release blocker until a separately trusted consumption ledger is certified.
- The generic driver enables strict passive-only network enforcement: GET/HEAD, no WordPress admin or API endpoints, and no script/document loaded via blob/data or streaming transport. Specialist ETG AJAX acceptance stays on a separately reviewed policy. The Node driver is a review-owned finite vocabulary of passive operations. It emits `mad4b.capability-browser-evidence.v1` with exact challenge nonce, origin and build identity, with no authorization claims.
- The external worker signs canonical evidence using RSA-SHA256 over the entire evidence map **before** adding attestation. WordPress verifies this signature independently using its pinned public key and recomputes the expected canonical path per case; `matches_expected` alone is not trusted.
- WordPress signs the reducer's evidence digest and verdict with HMAC; the existing Core validates PASS receipt shape and the external orchestrator compares the digest to the local evidence.

## Trust boundaries and remaining risk
- Agent signing proves possession of an approved browser-runner private key, not absolute proof that all browser actions happened. Provider audit, network egress enforcement, physical/runtime isolation and anti-replay telemetry are required for stronger assurance.
- The replay window is bounded by 600 seconds. Results are read-only, but release certification must bind execution receipts to a unique deployment and an independent runtime attestation.
- Current provider offers **one** semantic primitive, `public.canonical_path`, backed by published WordPress pages and current canonical URLs. Its existence does not certify site-wide SEO or business behavior.
- The generic Provider advertises `selection_role=supplemental` and any specialist defaults to `primary`. With no explicit operator choice, the resolver prefers the single recognized primary contract over supplemental observers. Multiple primary contracts remain **ambiguous and BLOCKED**. An explicit declared profile or operator-selected Provider remains authoritative; there is no hostname/site-name rule. One generic-only provider can expose its narrow public-canonical profile but **never certifies booking, multilingual parity or full-site behavior**.
- HTTPS WordPress installations at the webroot or bounded *subdirectory paths* (e.g. `/travel/`) are accepted. Both PHP and JS validate the declared scope, refuse path traversal and require every planned public document to remain inside the WordPress installation prefix. A redirected document outside its declared path fails observation.
- Path-scoped acceptance is source-tested only; provider-level IP/DNS egress and exact Staging runtime acceptance are still required.
- Plugin/theme header versions and build fingerprints are not a substitute for full asset provenance; control-plane runtime_manifest_match gates the native plan.

## Key provisioning (no keys committed)
A secure operator or managed credential authority must generate an RSA-2048+ keypair independently. Place public key PEM in wp-config or protected runtime config as `MAD4B_BROWSER_ATTESTATION_PUBLIC_KEY_PEM`, and privately inject corresponding base64-encoded PEM under `MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64` in the trusted browser-worker environment. Never echo private key, log it, put it in WordPress options, repo, a prompt, or a browser page.

Both ends must be provisioned under the existing environment-bound governance. The runner refuses to open billable browser sessions for generic plans without a valid private RSA signer; WordPress refuses ready plans without a trusted public key. No permissive unsigned fallback is provided.

## Runtime acceptance to perform
1. Deploy an exact SHA package to an approved staging site and read back build_provenance_status(), provider registry and origin.
2. Verify public-key readiness and managed signer fingerprint through a separately protected secret manager. Do not log private key.
3. Run the native PHP fixture and Node signer/driver/resolver suites at exact HEAD, including tamper and stale build tests.
4. Run external MCP initialize/tools/list and fetch a signed native plan (profile `public-canonical`) from two unlike WordPress sites.
5. Execute approved browser providers, verify RSA evidence, HMAC receipt, no cross-site egress, exact-origin scopes and independent page canonical parity.
6. Re-run after plugin/theme/source revision change, wrong signer, challenge expiration, public content status change and operator revision rotation. All must deny stale acceptance.
7. Reject master/Production release until all receipts and owner release gates are complete.

### WebSocket is a separate egress primitive
HTTP request routing does not intercept WebSockets in Playwright. The generic passive observer must register `browserContext.routeWebSocket('**/*')` and close every attempted connection with a policy-violation code **before creating a page**. Browser engines missing this API are blocked rather than silently downgraded. This is not a WebRTC/DNS/IP network firewall certification.

## Static generic observation invariants
- Generic passive BrowserContext runs with site JavaScript disabled, service workers blocked, downloads disabled and no granted permissions. It blocks script/xhr/fetch/worker/WebSocket active transports; navigation of document resources is limited to the exact unique case paths of the signed plan. Only approved static GET/HEAD asset resources remain eligible. This may intentionally BLOCK client-rendered pages that require JavaScript — those require a **separately reviewed capability-specific Driver and effect policy**, not relaxation of the generic read contract.
- Even GET can mutate a poorly designed external service. Browser request interception alone is not an infrastructure-wide assertion of no business-state changes; such proof needs a server/network audit.
- Native WordPress scans up to 32 deterministic published page records to identify at most 4 unique unprotected public canonical cases. Duplicate post IDs and canonical paths are skipped; lack of usable public cases BLOCKS.
- Evidence with unexpected top-level/observer fields, invalid typed HTTP status, malformed case schema or implausible browser engine metadata is BLOCKED as an infrastructure contract failure rather than producing a false product-defect verdict.
- All role metadata remains constrained in WordPress Provider Registry as well as external JS selection; the generic registration is additive and does not replace the specialist contract.

### Static DOM observation without evaluating site code
The bounded `public.semantic_marker_count` primitive builds only the fixed selector `[data-mad4b-capability-key="<validated-identifier>"]` and uses Playwright's native `locator.count()`. It does not call `evaluateAll` or execute untrusted site JavaScript. The identifier grammar excludes quotes, spaces, brackets and escapes. The native generic Provider may be recognized from core WordPress post type `page` even when **no installed plugin maps to that Provider**. It remains supplemental/read-only and only proves the specific public observation, not any site-specific bookings or integrations.
