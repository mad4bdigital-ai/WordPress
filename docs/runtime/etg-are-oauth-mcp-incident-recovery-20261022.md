# ETG / ARE — external OAuth/MCP recovery, 22 October 2026

**Status:** source diagnostics prepared; external Client acceptance **NOT VERIFIED**. Read-only operator procedure; no credentials, WordPress changes, host commands, Production changes, write approvals or authority grants are performed by this document.

## Evidence and exact boundaries

- ETG browser attempted `https://staging.egypttourgates.com/oauth/mcp/authorize` and displayed `ERR_CONNECTION_TIMED_OUT`, with **no reliable HTTP status**. A prior valid consent screen was rendered and its optional AJAX authority projection refreshed successfully. This is consistent with intermittent failure; it does not prove an OAuth policy refusal.
- ARE admin screenshot displayed nginx `504 Gateway Time-out` for `admin.php?page=mad4b-control-plane-chatgpt`. Admin Diagnostics captured frontend PHP incidents at `includes/class-mad4b-scp-frontend-origin-diagnostic.php:47` on 19 October; those are *historical* until correlated with fresh request IDs.
- Local ARE MCP Endpoints test: 9/9 locally ready, ChatGPT tool catalog 36/36, server version `0.4.0-rc.102`. Local registration is **not** external session validation.
- ChatGPT `site_info` on ETG and ARE returned `The tool failed internally.` Neither the outer client error nor a browser timeout is sufficient to identify the HTTP phase without host logs.
- Existing OAuth `handle_authorize()` validates the allowlisted client and PKCE before issuing the consent page. On a CIMD cache miss, `fetch_cimd_client()` makes one allowlisted HTTPS GET with a bounded 5-second timeout. This may contribute to slow requests but is **not proven** to cause the browser timeout.
- Staging `runtime_restart_grace` is a self-update checkpoint barrier: 20 seconds is a **minimum quiet interval**, not an automatic expiration. Pending/manual/blocked states can continue until governed convergence completes.
- Write drift is independent: historical write-source binding is not a current exact authorization; foreign REST MCP routes are review candidates, **not evidence of active write access**.

## First: classify ingress without bypassing OAuth

Run the following *read-only, public*, low-volume probes from the host operator environment and, independently, from a client network. Do not pass credentials, OAuth `state`, `code`, token, authorization headers or a full consent URL to shared logs:

```bash
for host in staging.egypttourgates.com staging.allroyalegypt.com; do
  for path in / /wp-json/ /oauth/mcp/authorize; do
    curl --max-time 15 --connect-timeout 5 --silent --show-error \
      --output /dev/null --write-out "host=$host path=$path http=%{http_code} connect=%{time_connect} starttransfer=%{time_starttransfer} total=%{time_total}\n" \
      "https://$host$path"
  done
done
```

**Interpretation:** a quick 400 for an authorize GET *without valid OAuth parameters* can mean the route and validation execute; it is not a successful authorization. `http=000` or curl timeout must be diagnosed at TLS/proxy/worker level. Never equate a successful homepage GET to a functional OAuth round trip.

For one **legitimate** user-triggered consent attempt, correlate the precise UTC timestamp and sanitized path in host nginx/reverse-proxy access logs, nginx error logs, and PHP-FPM access/slow logs. Record `upstream_status`, `request_time`, `upstream_response_time`, PHP worker saturation/queue, PHP fatal and memory-failure indicators. **Do not log authorization query strings or tokens.** Check separately whether the request reached the proxy, PHP bootstrap, `handle_authorize`, client metadata lookup, consent rendering, token POST and protected MCP request. If host does not expose these observability fields, mark evidence unavailable rather than infer a cause.

## Next: distinguish restart quiet period from stuck checkpoint

Read the plugin's `restart_grace_status()` and `reconnect_status()` through local **governed read diagnostics** after the exact new package is installed. Requested fields: `diagnostic_classification`, `checkpoint_age_seconds`, `checkpoint_update_age_seconds`, `state`, `exact_runtime_identity_match`, `resume_blocker`, `last_error_code`, `transient_retry_count`, `resume_cron_scheduled`, `resume_cron_overdue_seconds`, `wp_cron_disabled`, `maintenance_lease` and current source/build digest. Avoid dumping raw options containing unrelated values.

- `post_update_quiet_period`: wait until its fixed `resume_not_before`; **no blind loops**.
- `runtime_identity_unverified` / `waiting_for_exact_runtime_restart`: compare actual installed ZIP/source against checkpoint target; do not replay an old update or pretend a reboot occurred.
- `operator_action_required` / `pending_manual_resume`: inspect the named `resume_blocker`, Cron and independent Automation SLO admission; request a **fresh exact governed plan** for any mutation.
- `blocked`: inspect `last_error_code` and rollback/readback receipts; **do not clear** or reset the checkpoint to make MCP return 200.
- `inactive`: checkpoint is not the active blocker; investigate ingress, OAuth client metadata, PHP-FPM and MCP/JSON-RPC.

## Read-only external MCP acceptance sequence

Once the authorization path and token endpoint reliably complete, run *one* authenticated external client:
1. OAuth code + PKCE and redirect complete with expected resource and `mad4b:read`; authority step-up, if granted separately, never implies writes.
2. MCP `initialize` response records actual HTTP status, protocol version and server response time.
3. MCP `tools/list` returns registered catalog within agreed byte/time budget; validate tool names against catalog.
4. MCP `tools/call` of **one read-only** `site_info` returns without timeout; repeat serially after the session is established.

Correlate `X-MAD4B-MCP-Correlation`, when present, to bounded Staging journal, and to host logs by UTC timestamp. No correlation header when nginx times out **outside** WordPress does not prove no incoming request. Do not perform parallel retries or automatic OAuth reconfiguration.

## Foreign MCP routes — review ownership, not disable

| Prefix / route family | Likely component (not independently certified) | Review |
| --- | --- | --- |
| `/fluentform/v1/mcp/*` | Fluent Forms | Identify provider, active plugin, route permission callback and whether toggle/install actions can mutate |
| `/elementor-mcp-composer/v1.0.19*` | Elementor MCP Composer | Verify version, permission/authentication and credential routes |
| `/hfe/v1/mcp-*` | HFE / Elementor Header Footer integration | Identify installed provider and effective server-side permissions |
| `/uael/v1/mcp-*` | Ultimate Addons for Elementor | Verify plugin and abilities surface |
| `/elementor/v1/mcp-proxy` | Elementor | Verify whether proxy forwards authenticated execution |

The inventory contains **12 routes, 0 observed MCP peers, 0 observed foreign MCP plugins and no detected write side-channel**. Route count alone is no authorization or compromise finding. Keep `mcp_foreign_transport_unreviewed` fail-closed for governed **writes** until provider-specific evidence is reviewed. Do not disable extensions automatically.

## Release and safety

Before Staging upgrade: require PR exact-head CI, repository owner attestation, certified plugin artifact and rollback, and separate environment approval. After deploy compare **actual installed** source commit/build manifest on ETG and ARE independently. Do not use the old `6614091c...` write grant binding against `f958565c...` or newer source. Build-bound Write Authority Convergence is a separate plan/approval; no Write, Developer, raw-SQL Breakglass or Production mutation is needed to recover basic MCP read.

Acceptance is **not** established by GitHub merge, nine locally registered Endpoints, a green PHP lint, a valid audit chain or a locally rendered consent page. Only live external handshake + host correlation + stable serial read can close this incident.
