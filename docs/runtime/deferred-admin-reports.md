# Deferred administrator reports

Operator Control Center and ChatGPT Connection render an unmeasured shell without calling their aggregate status callbacks. Administrators explicitly request one report at a time through a nonce-protected POST, with no unauthenticated AJAX action or generic dispatcher. The existing MCP operator Ability and ChatGPT status contract remain unchanged.

Operator reports separate Write Authority, Database Topology, Full Authority, Provider Closure and a bounded persisted Adaptive Runtime projection. The full legacy Operator workspace remains available as an explicit aggregate report (which can still be expensive); it is never loaded automatically. The existing ChatGPT renderer runs only on demand, preserving connection instructions and controls. Neither initial shell certifies readiness. Only an explicit boolean readiness from the selected report may be displayed as observed locally.

Each completed report measures callback elapsed milliseconds and query-count delta; it does not attribute full-request bootstrap time or SQL duration. A 5-second soft budget marks an overrun after return, not an interruptible PHP deadline. The browser stops waiting after 12 seconds and disables further checks after timeout, HTTP failure, invalid response or failed/over-budget report. It never automatically retries. PHP or external work may continue after a browser abort.

The supplied ARE screenshot identifies `mad4b-control-plane-chatgpt` with nginx HTTP 504. A separate Diagnostics screenshot shows about 25.08 seconds, 525 queries and 0.17 seconds SQL time. These support profiling PHP/wait time, not blaming query count or asserting that OAuth caused the 504. The transport-error fix in the same PR only observes responses that reach WordPress; it cannot record an nginx timeout outside PHP.

Acceptance remains separate: deploy the approved package, compare initial page timings and per-report metrics, then correlate a failing request with nginx/PHP-FPM logs. This source change does not prove recovery of external MCP or authorize any site mutation.

Regression driver: `tests/deferred-admin-reports-contract.py` executes actual PHP guards/lazy-page/component tests and a Node browser-flow fixture for serialization, HTTP 504 and late completion after timeout.
