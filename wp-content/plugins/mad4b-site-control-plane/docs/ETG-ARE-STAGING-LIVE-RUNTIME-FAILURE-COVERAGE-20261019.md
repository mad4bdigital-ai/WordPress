# ETG + ARE Staging — live operational error evidence and regression coverage

**Observation:** 2026-10-19. **Authority:** read-only diagnostics and bounded exact-site same-origin HTTPS probes. **Source-only candidate:** PR #438. **No release acceptance, no Production or Developer/Breakglass grant.**

## Limits and source identity

Both sites independently reported MAD4B `0.4.0-rc.102`, canonical source `b9ffd800239d40cbc1147dffe7106ad5e5323655`, and MCP Adapter `0.7.0`. PR #438 fixes source behavior but was **not installed** when the observations were collected.

The public browser / external HTTPS requests from the diagnostic client were **not certified**. Its DNS failure is not proof of a public site outage. The live paired origin probe runs **on WordPress itself**, with exactly two fixed HTTPS GET requests, TLS verification, six-second per-request timeout, zero redirects/cookies, response-body suppression, and a signed synthetic marker. No arbitrary URL or Production origin is admitted.

| Failure domain | ETG Staging live | ARE Staging live | Existing/added hermetic coverage | Required independent acceptance |
|---|---|---|---|---|
| Staging package / WP runtime | rc.102 / WP 7.1.3 / PHP 8.5.11 | rc.102 / WP 7.1.3 / PHP 8.5.11 | Existing runtime identity and catalog tests | Read installed file / manifest hashes and check server logs |
| MCP client transport | Repeated intermittent connector-internal errors with successful reads between errors | Intermittent errors with successful governed reads | `mcp-connection-failsoft-runtime.php`, `session-safe-transport-resilience-runtime.php`, `connector-resilience-runtime.php` | Correlate authenticated server request ID, HTTP status, transport, upstream logs |
| HTTP homepage / REST parity | Exact paired probe could not be completed through connector | **Homepage HTTP 500 in 269.65ms; REST root HTTP 200 in 1043.47ms**; state `FRONTEND_LOOPBACK_5XX_REST_REACHABLE` | `frontend-origin-diagnostic-runtime.php` includes 500/200 and 500/502, redirects and denied Production | Capture sanitized PHP FPM, webserver, theme/plugin hooks, and independently attested browser result |
| HTTP transport classes | External/public diagnostic client failed DNS lookup; not reliable origin evidence | WordPress-origin paired probe reached both HTTP endpoints | Added fixture categories for DNS resolution, TLS certificate, timeout, TCP failure, local-policy block and unclassified network failure | One independent error code per true failed request; never weaken TLS |
| Early PHP fatal incident recorder | Diagnostic read failed through intermittent connector | Recorder initially reported `NO_CAPTURED_INCIDENTS`; independent later read returned one frontend `E_ERROR`, suspect `mad4b-site-control-plane`, 2026-10-19 06:23:09Z; not proof of root PHP file/line | `native-incident-mu-runtime.php` and `native-incident-early-runtime.php` | Correlate exact **homepage request** timestamp with PHP-FPM/webserver error logs; late fatal and nonfatal errors may not be captured |
| WordPress Site Health | No executed core Site Health suite evidenced | `site-health.php` available, tests not executed in diagnostic read | Native diagnostics source contract | Native Site Health result with timestamp/URL and safe redacted summary |
| Query Monitor | No current collector snapshot evidenced | Plugin and bridge available in diagnostic request; collector snapshot **not taken** | Existing native diagnostics / QMonitor bridge tests | Produce request-local Query Monitor collection for 500, if available without enabling unsafe debugging |
| OAuth / step-up and owner decision | Owner intent expired; exact write-only owner review currently matches expected plan but receipt no longer valid | Owner intent expired and stored owner review points to another plan | `dynamic-first-party-enrollment-runtime.php`, `staging-write-owner-intent-runtime.php`, `staging-write-owner-transport-runtime.php` | Fresh explicit per-site owner approval on current exact plan, single-use TTL receipt |
| Governed enrollment WP_Error, Throwable, lost outcome | Previous attempt produced opaque internal error; child journal readback did **not** verify new write authority | Previous attempt produced opaque internal error; no new verified owner receipt | `enrollment-dispatch-runtime.php`, redacted bounded result on PR #438 | Exact journal + candidate binding + grants readback; no blind retry |
| Exact write grants and candidate identity | 100/101 exact grants; `mad4b/optional-governance-apply` missing; 2 stale allow grants; stored SHA differs | Same missing ability and 2 stale grants; stored SHA differs | `staging-write-authority-postcondition-runtime.php` | Observe 101/101 current grants, stale 0, zero wildcard and exact candidate SHA; requires separately approved write-only convergence |
| Skills checkpoint | Managed Skills reconciliation previously returned certified; live deeper evaluation remains separate | Candidate-bound certificate matches rc.102; deep skills evaluation deferred | Existing skills runtime certification tests | New-request deep functional skill and provider verification |
| Developer host prerequisites | proc_open, resource limiter, network isolation unavailable in prior handshake | Same class of unresolved prerequisites in prior handshake | Existing developer runtime contracts | Host-admin independent assessment; Developer/Breakglass remain disabled |
| Hostinger / Desktop external fallback | Hostinger last returned HTTP 401; desktop previously offline | Same external access limitation | Not testable by WordPress local fixture | Reconnect separately without credential exposure; no SSH claim |

## New sanitized PHP fatal evidence after the ARE HTTP 500 probe

The first native read contained no incidents. **A subsequent independent read**
returned a persisted Staging frontend fatal:

- `type=1` (`E_ERROR`), `request_family=frontend`
- `component_family=plugin`, `suspected_component_slug=mad4b-site-control-plane`
- `last_at=1792390989` (2026-10-19 06:23:09 UTC)
- `incident_fingerprint=c38cd0e3ae21ddd9897fd57d71aa34b36e3c277515b9ad6982308e8ec15025f6`
- `root_cause_proven=false`: the record contains no PHP source file, line, stack or fatal message.

This is **new P0 diagnostic evidence** of a first-party-plugin-classified
fatal on a frontend request around the period when the WordPress-origin
homepage returned HTTP 500. The source file, precise error and causal
relationship are **not independently proven**. Correlate the fingerprint's
timestamp with PHP-FPM/server logs under controlled read-only Staging
access. Do not deactivate plugins, weaken error-redaction or publish raw
trace data merely to force a diagnosis.

## Additional 2026-10-19 live REST/WPML readback

Both Staging sites independently returned `mad4b/rest-compatibility-status` with
`ready=true`, `local_rest_isolation_ready=true`, and no Control Plane callback
globally disabling REST. In the **passive request-local WPML route snapshot**,
both had `wpml_active=true` but `route_registered=false` and
`wpml_route_missing`. This passive observation cannot alone establish a
visitor-facing WPML failure. Neither site's external WPML response acceptance
was verified: ETG has stale evidence from a different package; ARE has no
completed external evidence.

Repository-family readback confirms ETG WPML Multilingual CMS **4.9.5**
and ARE **4.9.7**, both active; this version difference is **not** proof
that either WPML build caused the homepage 500. Regressions must forbid
promotion of passive `REST enabled` or `WPML active` observations to
external acceptance. The suite adds exact canonical WPML receipt and
authority/composition fail-closed tests.

## New PR #438 operational-error regression suite

The fixed-path CI runner `tests/owner-enrollment-error-reconciliation-regression.py` executes **16 actual standalone PHP behavioral fixtures** and fails on a nonzero exit, PHP absence, missing fixture or timeout:

1. `enrollment-dispatch-runtime.php`
2. `dynamic-first-party-enrollment-runtime.php`
3. `staging-write-owner-intent-runtime.php`
4. `staging-write-owner-transport-runtime.php`
5. `staging-write-authority-postcondition-runtime.php`
6. `frontend-origin-diagnostic-runtime.php`
7. `native-incident-mu-runtime.php`
8. `native-incident-early-runtime.php`
9. `mcp-connection-failsoft-runtime.php`
10. `session-safe-transport-resilience-runtime.php`
11. `error-contract-registry-runtime.php`
12. `connector-resilience-runtime.php`
13. `mcp-preflight-registration-failure-runtime.php`
14. `wpml-response-contract-runtime.php`
15. `live-acceptance-wpml-authority-runtime.php`
16. `live-acceptance-wpml-composition-runtime.php`

Added negative cases distinguish DNS, TLS, timeout, TCP, local HTTP policy and unknown transport failures. The paired 500/200 result **never** certifies a browser, guesses the responsible plugin, or asserts an incident-free runtime.

## Fail-closed triage and remaining blockers

**Immediate site issue:** ARE homepage 500 with REST 200. Inspect sanitized PHP-FPM and webserver errors around the synthetic request, WordPress fatal shutdown and theme/frontend hooks before changing code or plugins. No proof currently identifies the failing PHP component.

**Transport issue:** An MCP `The tool failed internally` is an observation about the connector path, not a WordPress error classification. The PHP error envelope in PR #438 only applies **after the bounded enrolled ability is successfully invoked**. HTTP, TLS, proxy, auth-session failures and tool-host timeouts need separate request IDs and external transport diagnostics.

**Write authority:** Neither site is ready. Expired owner approvals cannot be replayed or inferred from this report. The exact write-only owner plan must be reread and reauthorized after the source fix is reviewed and installed. Never substitute Full Staging Authority or activate Developer/Breakglass. No Production mutation.

**Release gates:** Review exact PR head, complete all exact-head CI, independently approve merge, build canonical artifact, install Staging one site at a time and verify build identity; then complete per-site live HTTP, OAuth, MCP, skills and write-candidate readbacks. Hermetic tests are evidence for source safety, **not** a claim that every failure mode is covered in a live system.
