# Wayfatours — independently provisioned WordPress Production

Date: **2026-10-30 Africa/Cairo**. Owner-directed **Production straight away** (not Staging).

## Proven deployment

The Hostinger VPS `srv2030908` at `187.7.76.129` has a fresh **Wayfatours WordPress Production runtime**, separate from Wo Vacation's `tours.dev.mad4b.com` Staging.

| Scope | Exact deployment |
| --- | --- |
| Company | `Wayfatours` |
| WordPress origin | `wayfatours.mad4b.com` (**provisional MAD4B-controlled hostname; DNS not configured**) |
| Environment | `WP_ENVIRONMENT_TYPE=production` |
| Root | `/opt/hostinger-migrations/hosting-3/wayfatours-production` |
| Stack | Dedicated `wayfatours-production-db`, `wayfatours-production-php`, `wayfatours-production-nginx` Docker containers |
| WordPress DB | **New** `wayfatours_production`; independent MariaDB on an internal-only Docker network |
| Local HTTP | `127.0.0.1:8107` → Nginx `8080` (host loopback only) |
| Traefik router | Exact host `wayfatours.mad4b.com`; HTTP redirects 301 to HTTPS; HTTPS routes to independent Nginx |
| Public DNS | **Not configured** at verification time; `wayfatours.mad4b.com` has no public A/AAAA resolution |
| Public TLS | **Not certified**: Traefik presents its default cert; ACME challenge fails while DNS is missing |
| Theme | Astra |
| Active plugins | Elementor and JetEngine only. Elementor Pro, JetSmartFilters, WooCommerce, WPML and Astra addon **source code available, not active** until business/provider certification |
| Company Kit | Newly initialized Elementor Kit ID `4`, four unique system color IDs, one unique `bnav001` surface ID; `mad4b_kit_integrity_admission()` PASS |
| Company identity | New `mad4b_company_site_identity_v1` with unique UUID, company code and host. **No Wo Vacation UUID or other company identity copied** |
| Admin | A single new local WordPress administrator; first-use credential stored exclusively root-side `runtime/secrets/wp-admin.initial-secret`; provisional `site-admin@wayfatours.mad4b.com` is **not verified as a real inbox** |
| Business data | **Zero** imported tours, orders, bookings, products, customer details, media or shared tenant setting values |
| Customer contact | No company-provided email/WhatsApp/telephone values inherited |
| Company policy page | JetEngine code-registered `mad4b-company-policy-library` present. All actual policy values unconfigured; no contractual cancellation terms copied |
| Email | Outbound email disabled by MU source until a company-owned sender is validated |
| Commerce | WooCommerce payments, bookings, pricing, external providers **not activated** |
| Public visibility | `blog_public=0`, no indexing. Neutral Wayfatours landing page displays that offers and prices are unverified. |
| Existing tenants | Wo Vacation Staging and all other existing sites untouched; no Production restore/clone of their databases or media |
| Backups | No new backup requested or created |

The Kit palette is **provisional**, not a verified Wayfatours brand identity. The company must authorize its colors, logos, contacts and terms before replacing these design tokens.

## Reproducible source, admission and promotion rules

- `scripts/provision_wayfatours_production_v1.sh`: one-time fresh runtime. Refuses overwrite, generates new DB/salt/admin credentials, copies **code only**, isolates private DB, provisions WordPress, Kit, and invokes company identity seed and admission. Includes an exact-host Coolify Traefik router, and never exposes unencrypted external HTTP.
- `scripts/wayfatours_post_install.php`: one-time UUID, safe landing, noindex settings; leaves company-owned business data blank.
- `scripts/gate_new_company_production.php`: checks exact company/hostname/database, production environment, unique site UUID, Elementor Kit integrity, JetEngine policy page, zero foreign media/commerce, outbound disabled, nonindexed site and approved active plugin set. Its verified result was:

```text
PRODUCTION_PRIVATE_ADMITTED_DNS_TLS_PENDING
issues = []
kit_valid = true
inherited_commerce_records = 0
media_records = 0
```

- `scripts/check_wayfatours_public_https.sh`: **read-only** DNS, origin trusted certificate, publicly trusted HTTPS, and exact tenant admission test. Fails on DNS missing or default TLS. Only after it succeeds can the team claim public go-live; no `curl -k` bypass.
- Elementor Kit save and activation guards live in the newly provisioned source-only `198-mad4b-elementor-kit-integrity.php`, which checks the current site's active Kit rather than another company's.

## Verified live probes on the VPS

- `docker compose up -d --wait` completed; all three containers healthy/running.
- WordPress `wp core is-installed` succeeded; `wp_get_environment_type() = production`, DB name `wayfatours_production`.
- Internal `http://127.0.0.1:8107/` with Host header `wayfatours.mad4b.com` returned **HTTP 200** and title `Wayfatours`.
- Traefik `http://wayfatours.mad4b.com/` resolved locally to proxy using `curl --resolve` and returned **HTTP 301** to HTTPS.
- Traefik HTTPS returned **HTTP 200** in an isolated *diagnostic* test that intentionally skipped certificate validation; its certificate was still `TRAEFIK DEFAULT CERT`. **This is NOT valid public HTTPS.**
- Hostinger Cloud Startup Primary and Secondary credentials both returned 403 `Customer does not own mad4b.com domain` when querying the DNS zone; **neither Hostinger account controls this domain's authoritative DNS**.
- Source/public DNS check: `wayfatours.mad4b.com` had no A/AAAA record. Do not infer that `wayfatours.com` is owned by the user; it resolves to unrelated IPs and was not modified.

## Remaining external prerequisite — not hidden

For this **provisional** hostname, the person with authorized Cloudflare zone access to `mad4b.com` must create the **A record**:

```text
Zone:   mad4b.com
Type:   A
Name:   wayfatours
Value:  187.7.76.129
TTL:    Auto
```

Prefer **DNS-only** while Let's Encrypt HTTP-01 origin certificate is issued, then optionally proxy via Cloudflare with **Full (strict)** SSL when both ends are valid. Do not set Cloudflare Flexible SSL. Do not repoint `wayfatours.com` without verifying ownership.

**After DNS change** re-run `scripts/check_wayfatours_public_https.sh` on the VPS. Until it passes, status remains **Production installed and privately admitted; public go-live blocked**. App migration of full design templates and company-specific approved offers, WPML onboarding, transaction integrations and licensed providers are separate release steps. Do not characterize the clean production base installation as a completed tourism ecommerce launch.

## Governance

This branch is a scoped source-backed new-site provisioning candidate; it does not authorize blanket rollout of unmerged PR #639 to existing Production sites. No DFSB RC15 release certification gates were bypassed; only a new isolated WordPress base site and its tested Kit admission were created. No staging or other tenant data were written.
