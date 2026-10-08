# Native WordPress Semantic Oracle + Browser Attestation (Feature 007)

Status: source implemented on child branch; not Staging runtime certified. Scope: published *public page canonical* parity. This is not a universal oracle for booking, payments, multilingual relationships or arbitrary plugin behavior.

## Contract ownership
- WordPress `MAD4B_SCP_Native_Capability_Browser_Provider` registers through the existing governed `mad4b_browser_acceptance_providers` filter, identified by functional contract `mad4b.capability-browser-provider.v1`, not the site hostname.
- WordPress holds the trusted **public** RSA key in `MAD4B_BROWSER_ATTESTATION_PUBLIC_KEY_PEM`. The browser worker holds the independently provisioned **private** key as base64 PEM in `MAD4B_BROWSER_EVIDENCE_SIGNING_KEY_PEM_BASE64`.
- The two keys must form a certified pair, at least RSA-2048. Neither the plugin nor the user browser is allowed to generate, export or install the private key through a read-only MCP call. Missing key or bad provenance => BLOCKED.
- The WordPress native oracle only queries `page` + `publish`, verifies each returned row is public/no password, obtains `wp_get_canonical_url` and `get_permalink`, and accepts only exact same-origin public paths.
- WordPress build source SHA, build fingerprint, runtime manifest match, current plugin/theme discovery fingerprint, active operator configuration revision and each semantic expectation are included in the HMAC-signed plan.
- Plan nonce/expiry are stateless and derived using server `wp_salt('auth')`; reducer reconstructs exact plan from the *signed agent's issued-at epoch* and compares SHA-256 digest and HMAC before accepting evidence. Within 600 seconds and without material drift, the result is repeatable and idempotent.
- The Node driver is a review-owned finite vocabulary of passive operations. It emits `mad4b.capability-browser-evidence.v1` with exact challenge nonce, origin and build identity, with no authorization claims.
- The external worker signs canonical evidence using RSA-SHA256 over the entire evidence map **before** adding attestation. WordPress verifies this signature independently using its pinned public key and recomputes the expected canonical path per case; `matches_expected` alone is not trusted.
- WordPress signs the reducer's evidence digest and verdict with HMAC; the existing Core validates PASS receipt shape and the external orchestrator compares the digest to the local evidence.

## Trust boundaries and remaining risk
- Agent signing proves possession of an approved browser-runner private key, not absolute proof that all browser actions happened. Provider audit, network egress enforcement, physical/runtime isolation and anti-replay telemetry are required for stronger assurance.
- The replay window is bounded by 600 seconds. Results are read-only, but release certification must bind execution receipts to a unique deployment and an independent runtime attestation.
- Current provider offers **one** semantic primitive, `public.canonical_path`, backed by published WordPress pages and current canonical URLs. Its existence does not certify site-wide SEO or business behavior.
- A site can have both this provider and a specialized ETG provider. Without explicit functional intent, ambiguous selection is blocked; requested declared profile `public-canonical` selects the generic provider, `tours` can select ETG. The selection logic contains no site/brand mapping.
- HTTPS webroot is currently required by the existing generic browser-origin contract; subdirectory installations need separate path-scoped origin acceptance tests and must not be silently widened.
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
