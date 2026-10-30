# Centralized remote Content Hub orchestration — temporary Control Plane host

**Central hostname:** `https://tours.dev.mad4b.com/`  
**Contract:** `mad4b.hub.company-remote-control.v1`  
**Source PR:** #631  
**Status:** Source-code candidate in GitHub; **not yet certified or deployed on the VPS or the three company sites**.

## User decision

All source-company release decisions, selective language/content policy, and eventual JetEngine provider linking are **initiated and monitored from the single MAD4B Central Control Plane**. The currently designated central host is `tours.dev.mad4b.com`; this is a control-plane hostname, not a permanent assumption that all companies will eventually be hosted on that server.

WordPress source/consumer sites remain separately owned and carry a minimal signed remote agent. A central operator must not manually edit Elementor on each site or modify vendor JetEngine files. The existing EC P-256 identity pairing is reused for **mutual transport authentication**, but the previous `authenticated_read_only` state by itself is **not** a write grant.

## Central and remote modules

- `ops/central-company-rest/central/230-mad4b-central-hub-remote-control.php`
  - Enabled **only** when the central WordPress hostname is exactly `tours.dev.mad4b.com`.
  - Pin source URLs/hostnames to `MAD4B_Central_Company_REST_V1::COMPANIES`; no user-supplied arbitrary remote URLs.
  - `GET /wp-json/mad4b-central/v1/hub-remote-control/status` — available sites and trust/readiness flags, admin-only.
  - `POST /wp-json/mad4b-central/v1/hub-remote-control/plan` — an immutable, SHA256 intent derived from exact company, destination, selected source IDs, languages, content type and reference-only expiry. Plans have **no side effects**.
  - `POST /wp-json/mad4b-central/v1/hub-remote-control/operator` — company-scoped central administrator delegation, default absent. Expires within 90 days; applies only to enumerated sources/consumers/actions/operations.
  - `POST /wp-json/mad4b-central/v1/hub-remote-control/dispatch` — dispatches preflight/apply/revoke only after exact plan hash, company-scoped operator authority, and previously paired identity. Source responses require a trusted remote **signature and SHA256-bound response body**. Does not mutate JetEngine or turn on checkout.
- `ops/central-company-rest/remote/230-mad4b-company-hub-control-agent.php`
  - Loaded on the known source company host **only**, and requires its previously paired central P-256 public key.
  - `POST /wp-json/mad4b-company/v1/hub-control/delegation` — **one-time source-local owner/admin enrollment**, intentionally independent from transport pairing. Explicit allowed consumer companies, source post types, reference-content action and ≤90-day expiry; source administrator can disable delegation. This is not silently enabled by installation.
  - `POST /wp-json/mad4b-company/v1/hub-control` — accepts only exact signed central requests; signs its own receipt with the source-local P-256 private key. Request signature binds method, route, source company, exact source hostname, timestamp (±120 sec), nonce and hash of the exact raw JSON body.
  - Atomic nonce claim with unique `wp_options.option_name`; no replay. An atomic registry lock serializes writes to prevent lost updates. No arbitrary commands, raw SQL, custom user-supplied route, plugin install, source metadata mutation, or template edits.
  - Allowed first action is only `content_release_reference`. `preflight` verifies ownership, post publish state, WPML language visibility and explicit source post IDs, without releasing content. `apply` writes a narrowly scoped reference-content grant to `mad4b_company_hub_content_grants_v1` **only if** source-local delegation and central signed intent authorize it. `revoke` marks the matching immutable intent revoked. Identical apply is idempotent and a revoked intent cannot be reactivated; use a new intent.
  - All grants are `reference_only=true`, `checkout_enabled=false`, time-bounded and company/consumer scoped. Signature proves the command origin, not price/sale rights.

## Orchestration phases and future JetEngine binding

```text
Central Control Plane / owner
   ├─ Discover providers, WPML, Queries, Listings, Filters
   ├─ Selection Manifest + Language Baseline / Market Policy
   ├─ Plan (immutable SHA256 content-selection intent)
   ├─ Central operator company delegation
   ├─ Signed PRE-FLIGHT → source local delegation + content IDs / WPML
   ├─ Signed APPLY → source-local reference-content release grant
   ├─ Verify independently signed receipt, record an audit journal
   ├─ [FUTURE] source-signed public distribution data endpoint
   ├─ [FUTURE] signed consumer-site JetEngine Endpoint / Query / Listing / Filter binding
   ├─ [FUTURE] browser + pagination + WPML/price/media QA
   └─ Signed REVOKE + audit & cache invalidation
```

**Important:** The separate *source* grant to share selected reference content is NOT a license to publish historical rates, and it does NOT by itself change the earlier Tourism signed catalog response's `may_publish=false`. That response must be replaced/extended by a source-signed, scope-aware release proof with a compatible control-plane runtime authorization. Its public route and the target JetEngine's actual Endpoint/Query/Filter binding remain **unimplemented**. Do not misrepresent an applied source grant as a completed frontend listing.

The current Add-on `0.4.1` remains installed on Staging from earlier work; the new `230` modules are **GitHub artifacts only**, pending secure rollout and integration acceptance. **No new remote agent is installed** by the code in this PR.

## Required security, certification and migration

1. Confirm site hostname, source/target company identity and existing mutual P-256 public keys.
2. Run a **separate source-owner delegation enrollment**; the original identity-only pairing intentionally has `remote_writes_authorized=false`. Central user with `manage_options` alone does not override it.
3. Bind central operator to explicit companies; select source records, approved post type, WPML locales and expiry; dry-run first.
4. Deploy remote source agent and central controller to Staging/authorized companies through approved, reversible release pipelines. Production is out of scope until exact-head CI and owner approval.
5. On the first real test, expect `source_delegation_missing` until source-local enrollment and `central_operator_company_scope_missing` until central operator setup. Errors are an **intended safe outcome**, not a reason to disable permission checks.
6. Test tampered body, timestamp, nonce replay, wrong company/host, idempotent retries, concurrent intents, stale/deleted source record, mismatched WPML, expired delegation, lost receipt and revoke. Do not accept fake signed-receipt booleans in client payloads.
7. Add server-side source grant probe, central audit receipt journal, release rollback and content revision/drift detection.
8. Only then implement the **consumer-side** signed agent operation that resolves and preserves existing JetEngine REST API Endpoints, Queries, Listings, Filter Query IDs and WPML mappings, with strict compare-and-swap and compensation, per provider.
9. Until both parts are certified, public Hub distribution remains **HTTP 403**, and historical rates, checkout, form submissions and private option values remain denied.

## Contract tests and source custody

`ops/central-company-rest/tests/test-hub-remote-control-cli.php` runs standalone PHP negative tests without WordPress, credentials, remote requests or storage. `.github/workflows/mad4b-hub-remote-control-contract.yml` runs `php -l` on both modules and the contract tests for this PR. These tests **do not** constitute VPS or production deployment acceptance.

**Owner action is not self-authorization**: Central can draft and deliver an approved, signed instruction; each company remains independently able to revoke its delegation. The control plane must surface both readiness and the signed receipt, not an unverified green indicator.

## Policy override — signed REST connection is sufficient for reference sharing (2026-10-30)

**This section supersedes any older approval wording above.** The operator has explicitly selected the simplest current sharing contract:

- A **successful, mutually authenticated EC P-256 REST identity handshake** from the pinned registered source company is itself the **authority to make selected, reference-only content available to the MAD4B Content Hub**. Ordinary HTTP status `200`, an unverified hostname, an unsigned response, or a client-provided `signed=true` value is never sufficient.
- **No additional source-local delegation** and **no separate central operator company-scope grant** are required for `content_release_reference`. The central administrator still selects exact records, visible WPML languages, consumer company and expiry. This is *not* blanket authorization for private post types, customer data, changing WordPress site settings or WooCommerce commerce.
- Source company identity, signing keys and the last successful **P-256 two-way verification must be available and at most 24 hours old**. After 24 hours the reference-sharing eligibility fails closed. A central admin can renew the actual handshake through `POST /wp-json/mad4b-central/v1/hub-remote-control/revalidate` with registered `company_id`; it does not change the source site or grant any write privileges.
- Control Plane server-side hooks `mad4b_scp_hub_consumer_content_release_authority` and `mad4b_scp_hub_consumer_language_authority` are now provided **on the central host** and derive their approval from the verified company pairing and already-validated selective QueryPlan. The hooks do not let an untrusted HTTP request or a client manifest self-approve its source identity.
- The source-side remote agent, when eventually deployed, accepts a signed **reference-only** command after verifying the exact company, host, intent hash, timestamp and anti-replay nonce. The first action requires no source-local owner registration, but excludes unpublished/password-protected posts, private/non-public CPTs, orders, bookings and customers. It continues to reject checkout and price activation.
- The distinction between **permission to share safe source references** and **public JetEngine distribution actually activated** remains. As of this release, the public Hub catalog endpoint is still HTTP **403**, and no native JetEngine Endpoint/Query/Listing/Filter binding is automatically created. The legacy source's `may_publish=false` flag still disallows creating a new native product/post or exposing historical rates. It does not require an additional human release approval before an authenticated **reference-only** selection can be planned.
- Higher-priority denials, hidden/unmapped WPML languages, provider-incompatible queries and company/tenant isolation remain binding. Production source websites are not modified by this Staging Control Plane policy deployment.

### Verified Staging execution

On 2026-10-30, installed `230-mad4b-central-hub-remote-control.php` to the Staging Central MU plugin directory, matching GitHub source SHA256 `9c49f757e8246c213e655b47688ba4db2eeade6a5b2cdeea507a0da8ad4ecda8`. All three registered sources completed new remote P-256 signed identity checks with HTTP 200, with reference sharing eligible: All Royal Egypt, Egypt Tour Gates, Tourism Almallah. The central admin status REST returned 200, unauthorized user 401; direct unknown/stale identity checks returned false; inherited platform denial won. Site home HTTP 200, Hub 417 records unchanged; public catalog HTTP 403. No new remote agent was installed on any production source.

CI: `MAD4B Hub Remote Control Contract` passed for the connection-based policy head `150a008727bebbd595e0a693e4621096382e7e06` (also tested in the Staging PHP container). PR approval/merge and native JetEngine live binding are still pending.
