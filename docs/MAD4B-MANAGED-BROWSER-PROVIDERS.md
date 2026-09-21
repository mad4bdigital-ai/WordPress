# MAD4B Managed Browser Execution Providers

This layer supplies a real external browser engine for the existing MAD4B / ETG Browser Acceptance contract. It does not move browser execution into WordPress and it does not create another write, OAuth, SEO-publication, or Production-activation authority plane.

## Governing contracts

```text
mad4b.browser-execution-providers.v2
mad4b.browser-run-budget.v1
mad4b.browser-provider-attempts.v2
mad4b.browser-live-execution.v1
mad4b.browser-execution-receipt.v1
```

The WordPress side remains authoritative for:

```text
mad4b/browser-acceptance-capabilities
mad4b/browser-acceptance-plan
mad4b/browser-acceptance-result
```

The external browser layer is execution + observation only.

## Adaptive provider policy

Default provider family:

1. Cloudflare Browser Run
2. Browserbase
3. Browserless
4. Steel

The scheduler does not blindly consume this list. It first evaluates:

- credential availability;
- recurring-free vs one-time-credit billing class;
- exact case count;
- provider session constraints;
- estimated case execution time;
- maximum browser sessions allowed in one MAD4B run;
- the remaining execution deadline;
- providers whose circuit has already opened during the current execution.

Recurring free capacity is preferred over one-time credit. A provider that cannot fit the signed plan inside the run budget is skipped before opening a browser session.

Service-side quota/capacity responses remain authoritative. Static limits in `provider-contracts.json` are scheduling hints, not a local claim about remaining quota.

## Current verified free-tier scheduling metadata

Verified against provider documentation on 2026-09-22.

### Cloudflare Browser Run

- Workers Free: 10 browser minutes/day.
- 3 concurrent browser sessions/account.
- one new browser instance every 20 seconds on Workers Free.
- default inactivity timeout: 60 seconds.
- `keep_alive` can extend inactivity timeout to 10 minutes.
- 10 minutes is not a hard maximum session lifetime; an active session may continue longer.

References:

- https://developers.cloudflare.com/browser-run/limits/
- https://developers.cloudflare.com/browser-run/cdp/
- https://developers.cloudflare.com/browser-run/features/guardrails/

### Browserbase

- Free plan: 1 browser hour.
- 3 concurrent browsers.
- 15 minute maximum session duration.

Reference:

- https://www.browserbase.com/pricing

### Browserless

- Free plan: 1,000 units/month.
- 2 concurrent browsers.
- 2 minute maximum session duration.
- MAD4B therefore uses bounded case chunking for multi-case plans when Browserless is selected.

References:

- https://www.browserless.io/pricing
- https://docs.browserless.io/examples/playwright-connection

### Steel

- Launch: $30 one-time usage credit, currently valid for 90 days.
- up to 10 concurrent browser sessions.
- 15 minute maximum session duration.

References:

- https://docs.steel.dev/overview/pricinglimits
- https://docs.steel.dev/integrations/playwright

## Run budget

Default policy:

```text
max provider attempts       = 4
max browser sessions        = 12
estimated case runtime      = 45 seconds
session startup overhead    = 10 seconds
challenge reserve           = 30 seconds
OAuth result-submit reserve = 60 seconds
```

These defaults are intentionally conservative and live outside browser evidence semantics.

Browserless with eight cases, for example, is estimated as eight separate browser sessions. Cloudflare and Browserbase normally fit the same eight cases in one session.

## Dual deadline

MAD4B local OAuth currently issues access tokens with a 600-second TTL. ETG Browser Acceptance challenges may remain valid for up to 900 seconds.

Therefore challenge validity alone is insufficient.

The live runner calculates:

```text
execution_deadline =
min(
  MCP access-token exp,
  Browser Acceptance challenge expires_at
)
- result-submit reserve
```

Before a provider starts, and before every chunked session, the runner verifies that its estimated work fits inside the remaining execution window.

A provider is not started when there is insufficient time to finish browser observation and return evidence through `mad4b/browser-acceptance-result`.

The JWT expiry is used only as a scheduling deadline. Authentication and authorization remain enforced by the MAD4B MCP server.

## Credential names

Browser providers:

```text
CLOUDFLARE_ACCOUNT_ID
CLOUDFLARE_BROWSER_RUN_API_TOKEN

BROWSERBASE_API_KEY

BROWSERLESS_TOKEN
BROWSERLESS_REGION          optional; default production-sfo

STEEL_API_KEY
```

Live MAD4B transport:

```text
MAD4B_MCP_RESOURCE
MAD4B_MCP_ACCESS_TOKEN
```

`MAD4B_MCP_ACCESS_TOKEN` must be a just-in-time short-lived access token. The managed browser workflow deliberately does not persist or rotate a MAD4B OAuth refresh token.

For an opaque access token, an external trusted orchestrator may additionally provide:

```text
MAD4B_MCP_ACCESS_TOKEN_EXPIRES_AT
```

as an epoch timestamp. It is scheduling metadata only.

## Cloudflare network guardrails

Cloudflare sessions use provider-side hostname guardrails.

The primary hostname comes from the signed Browser Acceptance plan origin. Additional required asset hosts may be deployment-configured using:

```text
MAD4B_CLOUDFLARE_ALLOWED_DOMAINS
```

Caller-supplied arbitrary URL/navigation is not supported.

## Authority boundary

The browser runner may consume:

```text
fresh signed MAD4B Browser Acceptance plan
browser-provider preference: auto or one registered provider
profile ID accepted by the MAD4B Browser Acceptance provider
```

It does not accept caller-supplied:

```text
target URL
DOM selector
JavaScript payload
taxonomy
term
query ID
SEO publication target
Production target
WordPress write operation
```

Origin, routes, cases, taxonomy terms, Query ID, build identity and freshness challenge come from the plan generated by MAD4B.

## MCP live path

The live path is:

```text
short-lived MAD4B access token
        ↓
MCP initialize
        ↓
tools/list
        ↓
mad4b/browser-acceptance-plan
        ↓
fresh signed plan
        ↓
adaptive provider scheduler
        ↓
Cloudflare / Browserbase / Browserless / Steel
        ↓
real JetSmartFilters browser interaction
        ↓
package-owned passive observer
        ↓
evidence
        ↓
mad4b/browser-acceptance-result
        ↓
reducer verdict
        ↓
tamper-evident execution receipt
```

The MCP access token is removed from the child browser process environment before provider execution.

## Fallback rules

Fallback is intentionally narrow.

May fall back in `auto` mode:

- missing provider credentials: skip;
- quota/rate exhaustion;
- capacity/concurrency exhaustion;
- provider authentication/configuration failure;
- temporary provider/CDP/WebSocket transport failure;
- provider-side 402/408/409/425/429/5xx conditions listed in the provider contract.

Must fail closed without consuming another provider:

- invalid/expired plan;
- insufficient dual-deadline window;
- observer failure;
- JetSmartFilters control not found;
- DOM/result-count failure;
- reset behavior failure;
- URL-state failure;
- acceptance execution defect;
- other plan/UI/semantic evidence failures.

Once a provider fails inside an execution, its circuit is opened for the rest of that run.

An explicitly selected provider never silently changes to another provider.

## Evidence and receipt

Browser evidence remains:

```text
etg.dfsb.browser-acceptance-evidence.v1
```

Provider attempts are stored separately from acceptance evidence so provider mechanics cannot change product-evidence semantics.

The final execution receipt contains hashes binding:

- signed plan identity;
- provider attempt ledger;
- browser evidence;
- MAD4B reducer result;
- selected provider;
- run-budget usage;
- source Git HEAD.

Provider exception text is redacted before it can enter attempt artifacts. Credential-bearing query parameters, bearer tokens and common API-key forms are replaced with `[REDACTED]`.

## Canonical Skill

The portable and runtime seed packs include:

```text
wordpress-browser-acceptance
```

This Skill describes the governed workflow but grants no execution authority. The canonical Skill explicitly requires a fresh MAD4B plan, bounded provider execution, package-owned observation, evidence reduction and independent downstream release gates.

## GitHub Actions

`.github/workflows/mad4b-managed-browser-providers.yml` always runs static/contract tests on relevant PR changes.

The manual live job requires:

- `MAD4B_MCP_RESOURCE` repository variable;
- a just-in-time `MAD4B_MCP_ACCESS_TOKEN` secret;
- at least one configured managed-browser provider credential.

The workflow generates the fresh plan through MCP itself. GitHub Actions does not fabricate a Browser Acceptance plan or freshness challenge.

Artifacts:

```text
browser-evidence.json
browser-provider-attempts.json
browser-acceptance-result.json
browser-execution-receipt.json
```

## Independent release gates

A successful Browser Acceptance result proves browser-runtime parity only.

It does not authorize:

- SEO publication;
- Production activation;
- plugin activation/deactivation;
- content/database/filesystem mutation;
- Breakglass;
- any other MAD4B release gate.
