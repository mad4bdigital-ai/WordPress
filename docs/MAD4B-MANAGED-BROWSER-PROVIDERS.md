# MAD4B Managed Browser Execution Providers

This layer supplies a real external browser engine for the existing ETG Browser Acceptance contract. It does not move browser execution into WordPress and does not create another authority plane.

## Priority

Default `auto` order:

1. Cloudflare Browser Run
2. Browserbase
3. Browserless
4. Steel

Selection first skips providers whose credentials are absent. Runtime quota/rate/capacity failures are recorded and the runner advances to the next configured provider.

Provider limits in `provider-contracts.json` are advisory scheduling metadata only. Service-side responses remain authoritative.

## Current free-tier scheduling hints

- Cloudflare Browser Run: recurring free tier, 10 browser minutes/day, 3 concurrent browsers; session keep-alive capped at 10 minutes.
- Browserbase: recurring free tier, 1 browser hour, 3 concurrent browsers, up to 15 minutes/session.
- Browserless: recurring free tier, 1,000 units/month, 2 concurrent browsers, up to 2 minutes/session.
- Steel: Launch includes one-time $30 usage credits; up to 10 concurrent sessions and 15 minutes/session.

These values are deliberately kept outside execution logic so they can be revised without changing browser-evidence semantics.

## Credential names

```text
CLOUDFLARE_ACCOUNT_ID
CLOUDFLARE_BROWSER_RUN_API_TOKEN

BROWSERBASE_API_KEY

BROWSERLESS_TOKEN
BROWSERLESS_REGION          optional; default production-sfo

STEEL_API_KEY
```

Cloudflare sessions additionally use server-side hostname guardrails. `staging.egypttourgates.com` is derived from the signed plan. Extra required asset hostnames may be deployment-configured through `MAD4B_CLOUDFLARE_ALLOWED_DOMAINS`; they are not caller plan inputs.

## Authority boundary

The runner accepts:

```text
fresh signed MAD4B Browser Acceptance plan
provider preference: auto or one registered provider
```

It does not accept caller supplied:

```text
target URL
selector
JavaScript
taxonomy
term
query ID
```

Origin, routes, cases, taxonomy terms, Query ID, build identity and freshness challenge all come from the signed plan.

The external engine performs real JetSmartFilters UI clicks, observes the package-owned passive observer and collects pagination-backed result IDs. The result is an `etg.dfsb.browser-acceptance-evidence.v1` envelope for submission through the already-authenticated MAD4B Browser Acceptance result ability.

## Transport separation

This PR intentionally does not create an evidence-ingest REST endpoint and does not store a WordPress OAuth bearer token in source.

The authenticated MAD4B transport remains responsible for:

1. generating a fresh Browser Acceptance plan;
2. materializing that plan for the browser runner;
3. submitting returned evidence to `mad4b/browser-acceptance-result`.

That separation prevents cloud-browser credentials from becoming WordPress authority and prevents WordPress credentials from becoming browser-provider authority.

## Fallback behavior

`auto` tries configured providers in priority order.

Typical quota/capacity failures such as HTTP 402/429/5xx, browser-time exhaustion, concurrency exhaustion or temporary provider unavailability move to the next provider. Every attempt is written to `browser-provider-attempts.json`.

An explicitly selected provider does not silently change to another provider.

## Live workflow

`.github/workflows/mad4b-managed-browser-providers.yml` always runs the provider contract tests on PRs.

The live job is manual and requires a fresh signed plan to already be materialized in the runner environment. GitHub Actions never fabricates a plan or freshness challenge.
