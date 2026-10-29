# MAD4B Control Plane — governed Cloudflare company domain launcher (v1)

**Source implementation:** `MAD4B_SCP_Company_Domain_Cloudflare`, registered in MAD4B Site Control Plane's early, lightweight REST bootstrap. This is not a separate domain manager with its own credentials or company identity source.

**Staging integration:** installed at `tours.dev.mad4b.com` with an additive staging-only MU bridge because GitHub `master` is at SCP `rc.104` while Staging is running `rc.118`. Do not overwrite that live plugin entrypoint. Actual registered routes count: **5**; unauthenticated `GET /status` returns **403**. Provider token and zone ID are **not currently connected**.

## Contract

Base `/wp-json/mad4b/v1/company-domains`:

| Method | Route | Effect |
|---|---|---|
| GET | `/status` | Admin-only provider capability and connection status; never return token or account secrets |
| GET | `/plan?company_code=wayfatours&mode=subdomain&hostname=wayfatours.mad4b.com` | Owner plan; verified registry, site and zone status, expected A record, exact plan SHA, blockers. Read-only |
| POST | `/zones/enroll` | Custom-domain *Cloudflare DNS zone* enrollment, not registrar domain purchase; exact confirmation, account-scoped API token. Returns pending nameservers |
| POST | `/dns/commit` | Owner-approved A record creation, with idempotency key, concurrent lock, provider readback and conflict protection |
| GET | `/readback?company_code=...&mode=...&hostname=...` | Verify provider DNS A record, public origin A resolution and HTTPS with CA certificate validation. Does not enable checkout or mark commercial launch approved |

### Choosing a domain

**MAD4B-managed subdomain.** Select `mode=subdomain` and the exact `{company_code}.mad4b.com` hostname, for example `wayfatours.mad4b.com`. The destination address comes only from `MAD4B_COMPANY_ORIGIN_IPV4` in server-owned configuration. The module fetches the MAD4B Cloudflare zone by its configured exact zone ID and confirms its name is `mad4b.com` and it is active. It writes one **A**, DNS-only (not proxied), TTL Auto; never overwrites a conflicting A/CNAME. Subsequent exact requests return an idempotent readback.

**Company-provided domain.** Select `mode=custom_domain` and an exact registered apex domain that is explicitly listed in the *company-specific approved custom domains* inside the server-owned launch registry. The Control Plane lists Cloudflare zones for that exact domain; if absent and a verified account ID is configured, `/zones/enroll` can create a *full Cloudflare DNS zone* with separate explicit owner confirmation. The domain owner must update nameservers at the registrar and wait for Cloudflare zone `active`. An exact `_mad4b-verify.<domain>` TXT with the HMAC-derived `mad4b-owner=...` returned by the plan is required before DNS mutation; its value is bound to company code, hostname and origin identity. No other company's domain can be selected just because the token has account-wide zone visibility.

**Important:** registering a DNS zone with Cloudflare does **not purchase or register ownership** of the internet domain. Purchasing or transferring domain registrar ownership is a separate task.

### Contract examples

Authenticated GET plan:

```http
GET /wp-json/mad4b/v1/company-domains/plan?company_code=wayfatours&mode=subdomain&hostname=wayfatours.mad4b.com
X-WP-Nonce: <wp-rest-session-nonce>
```

Admin-browser POST to create a DNS record (use *fresh plan* digest, not a copied one):

```json
{
  "company_code": "wayfatours",
  "mode": "subdomain",
  "hostname": "wayfatours.mad4b.com",
  "expected_plan_sha256": "<fresh-plan-sha256>",
  "idempotency_key": "<stable-unique-operator-intent-key>",
  "confirmation": "CREATE DNS FOR wayfatours.mad4b.com"
}
```

For zone onboarding:

```json
{
  "company_code": "wayfatours",
  "mode": "custom_domain",
  "hostname": "wayfatours.com",
  "expected_plan_sha256": "<fresh-plan-sha256>",
  "confirmation": "ENROLL CLOUDFLARE ZONE wayfatours.com"
}
```

Custom domain example is **illustrative only**. Ownership of `wayfatours.com` has not been verified, it resolves elsewhere, and it is not authorized in the production registry. Do not submit an enrollment for it until owner proof.

### Fail-closed security

- Read routes: `manage_options` required. Mutating routes: `manage_options + activate_plugins`, a real logged-in browser cookie, active session token, and same-origin `X-WP-Nonce`. Neither app password nor arbitrary Bearer token grants write authority. Owner must explicitly approve an exact, short-lived (5-minute bucket) HMAC-signed plan.
- The registry is injected via server-controlled `MAD4B_COMPANY_LAUNCH_REGISTRY_JSON`, **not supplied by the request**. Each company entry requires `production_admitted=true`, `origin_host`, and approved custom domains. The registry must be updated only after actual production-host admission and site UUID audit.
- Public IPv4 is configured server-side via `MAD4B_COMPANY_ORIGIN_IPV4`. Client requests can never supply an arbitrary target IP or URL. Cloudflare calls go **only** to `https://api.cloudflare.com/client/v4`. Provider responses are validated.
- Cloudflare writes require a root-provided read-only token file mounted inside the PHP service as `/run/secrets/...` and referenced by `MAD4B_CLOUDFLARE_TOKEN_FILE` (preferred). Explicit container injected `MAD4B_CLOUDFLARE_API_TOKEN` is a fallback only when no token-file path is configured. Never store credentials in WP options, Elementor, source repository, the DNS launch intent or REST responses.
- `MAD4B_CLOUDFLARE_ZONE_ID`: the actual 32-hex Cloudflare zone identifier for `mad4b.com`; `MAD4B_CLOUDFLARE_ACCOUNT_ID`: account ID only when creating a customer-owned zone. No guessed or invented zone IDs.
- Cloudflare token scopes: **Zone Read**, **DNS Edit** for managed zones, plus **Zone Edit** scoped to the intended account/zone enrollment when custom-zone creation is enabled. Limit resource access to only the required zones, not an all-account global API key.
- Existing conflicting DNS records return 409; never delete/update them. `add_option` atomic claim, MySQL lock per hostname, and provider state reconciliation prevent blind repeat POSTs. An ambiguous Cloudflare response remains `remote_outcome_unknown`; manual readback is required.
- Zone creation requires the custom domain to be preapproved in company registry and returns pending nameservers. Never modify registrar NS automatically.
- Readback checks DNS, Cloudflare DNS data and cert-valid HTTPS; it reports `public_tls_ready` but **never** enables WooCommerce, transactions or changes commercial publication status.

### Wayfatours runtime context

A fresh isolated Wayfatours WordPress Production already exists on `srv2030908`, separate database `wayfatours_production`, UUID and Kit admission passed. Local origin `127.0.0.1:8107` HTTP 200; Traefik HTTPS route works internally but presents default TLS certificate. `wayfatours.mad4b.com` DNS was absent at verification. Connected Hostinger accounts cannot manage `mad4b.com` (both returned DNS 403), so authoritative Cloudflare access must be supplied by the actual zone controller.

**At present:** routes deployed and permission-tested on Staging, simulations verify the provider mutations. No Cloudflare write executed because there is **no authenticated Cloudflare Token / real Zone ID / control-plane registry configured**. No domain was created or pointed. Cloudflare connection and public TLS remain OPEN; see [Wayfatours public cutover Issue #646](https://github.com/mad4bdigital-ai/WordPress/issues/646). The source and tests are governed through this PR, not merged to Production.

### Source promotion

The production SCP loader change is deliberately additive to `master`, but GitHub `master` is not currently on the same SCP release as VPS `rc.118`. Staging uses the MU bridge to avoid downgrading/replacing that SCP. After CI and release parity certification, merge through the prescribed governed deployment mechanism, removing the compatibility bridge when version equivalence is certified.
